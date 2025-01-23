<?php

declare(strict_types=1);

namespace Drupal\ai_chat\Controller;

use Drupal\ai_chat\LangfuseService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\ai_chat\AiChatService;
use Drupal\ai_chat\ChatRateLimiter;
use Drupal\Core\Queue\QueueFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Returns responses for AiChatController routes.
 */
final class AiChatController extends ControllerBase {

  /**
   * Constructs an AiChatController object.
   */
  public function __construct(
    #[Autowire(service: 'ai_chat.ai_chat_service')]
    protected AiChatService $aiChatService,
    #[Autowire(service: 'ai_chat.rate_limiter')]
    protected ChatRateLimiter $rateLimiter,
    #[Autowire(service: 'ai_chat.langfuse_service')]
    protected LangfuseService $langfuse,
    #[Autowire(service: 'queue')]
    protected QueueFactory $queue,
  ) {}

  /**
   * Callback for the AI chat route.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
   *   The response.
   */
  public function aiChat(Request $request): JsonResponse|StreamedResponse {
    try {
      // Create trace data and add to the queue.
      $trace_metadata = [
        'request_ip' => $request->getClientIp(),
        'request_method' => $request->getMethod(),
        'user_agent' => $request->headers->get('User-Agent'),
        'user_id' => $this->currentUser()->id(),
        'user_roles' => $this->currentUser()->getRoles(),
        'is_anonymous' => $this->currentUser()->isAnonymous(),
      ];
      $trace_data = $this->langfuse->buildTraceData('chat_request', $trace_metadata);
      $trace_id = $trace_data['id'];
      $this->queue->get('ai_chat_langfuse_queue')->createItem($trace_data);

      // Check if rate limit is exceeded.
      if ($this->rateLimiter->isAllowed() === FALSE) {
        $span_data = $this->langfuse->buildSpanData($trace_id, 'rate_limit_exceeded', TRUE);
        $this->queue->get('ai_chat_langfuse_queue')->createItem($span_data);

        $message = $this->t('Rate limit exceeded. Please try again later.');
        return new JsonResponse([
          'html' => '<span style="color: red">' . $message . '</span>',
          'role' => 'assistant',
        ]);
      }

      $content = $request->getContent();
      $data = json_decode($content, TRUE);

      // Check if messages are properly formatted.
      if (!isset($data['messages']) || !is_array($data['messages'])) {
        $span_data = $this->langfuse->buildSpanData($trace_id, 'invalid_message_format', TRUE);
        $this->queue->get('ai_chat_langfuse_queue')->createItem($span_data);

        $message = $this->t('Invalid message format.');
        return new JsonResponse([
          'html' => '<span style="color: red">' . $message . '</span>',
          'role' => 'assistant',
        ]);
      }

      // Transform messages into the format expected by OpenAI.
      $messages = array_map(function ($message) {
        return [
          'role' => $message['role'],
          'content' => $message['text'] ?? $message['html'],
        ];
      }, $data['messages']);

      // Combine the last *n* user messages, generate an embedding for the
      // combined content, and then perform a vector search against the Qdrant.
      $context = $this->aiChatService->retrieveContext($messages, $trace_id);
      $prepared_messages = $this->aiChatService->prepareMessages($messages, $context, $trace_id);
      $response = $this->aiChatService->getOpenAIResponse($prepared_messages, $trace_id);
      $response_data = json_decode($response->getContent(), TRUE);

      // Transform the response back into Deep Chat format.
      return new JsonResponse([
        'text' => $response_data['content'],
        'role' => $response_data['role'],
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'error' => $e->getMessage(),
      ], 500);
    }
  }

}
