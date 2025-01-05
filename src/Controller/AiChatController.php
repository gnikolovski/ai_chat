<?php

declare(strict_types=1);

namespace Drupal\ai_chat\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ai_chat\AiChatService;
use Drupal\ai_chat\ChatRateLimiter;
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
   *
   * @param \Drupal\ai_chat\AiChatService $aiChatService
   *   The AI chat service.
   * @param \Drupal\ai_chat\ChatRateLimiter $rateLimiter
   *   The rate limiter service.
   */
  public function __construct(
    #[Autowire(service: 'ai_chat.ai_chat_service')]
    protected AiChatService $aiChatService,
    #[Autowire(service: 'ai_chat.rate_limiter')]
    protected ChatRateLimiter $rateLimiter,
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
      if ($this->rateLimiter->isAllowed() === FALSE) {
        $message = $this->t('Rate limit exceeded. Please try again later.');
        return new JsonResponse([
          'html' => '<span style="color: red">' . $message . '</span>',
          'role' => 'assistant',
        ]);
      }

      $content = $request->getContent();
      $data = json_decode($content, TRUE);

      if (!isset($data['messages']) || !is_array($data['messages'])) {
        $message = $this->t('Invalid message format.');
        return new JsonResponse([
          'html' => '<span style="color: red">' . $message . '</span>',
          'role' => 'assistant',
        ]);
      }

      // Transform messages to the format expected by OpenAI.
      $messages = array_map(function ($message) {
        return [
          'role' => $message['role'],
          'content' => $message['text'] ?? $message['html'],
        ];
      }, $data['messages']);

      $context = $this->aiChatService->retrieveContext($messages);
      $prepared_messages = $this->aiChatService->prepareMessages($messages, $context);

      $response = $this->aiChatService->handleResponse($prepared_messages);
      $response_data = json_decode($response->getContent(), TRUE);

      // Transform the response back to Deep Chat format.
      return new JsonResponse([
        'text' => $response_data['text'],
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
