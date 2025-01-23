<?php

declare(strict_types=1);

namespace Drupal\ai_chat;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use OpenAI\Client;
use Qdrant\Config;
use Qdrant\Http\Builder;
use Qdrant\Models\Request\SearchRequest;
use Qdrant\Models\VectorStruct;
use Qdrant\Qdrant;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Defines the AiChatService class.
 */
final class AiChatService {

  use StringTranslationTrait;

  public function __construct(
    #[Autowire(service: 'config.factory')]
    protected ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'ai_chat.langfuse_service')]
    protected LangfuseService $langfuse,
    #[Autowire(service: 'queue')]
    protected QueueFactory $queue,
  ) {}

  /**
   * Gets the Qdrant client.
   *
   * @param string|null $qdrant_host
   *   The Qdrant host.
   * @param int|null $qdrant_port
   *   The Qdrant port.
   * @param string|null $qdrant_api_key
   *   The Qdrant API key.
   *
   * @return \Qdrant\Qdrant
   *   The Qdrant client.
   */
  protected function getQdrantClient(
    ?string $qdrant_host = NULL,
    ?int $qdrant_port = NULL,
    ?string $qdrant_api_key = NULL,
  ): Qdrant {
    $config = $this->configFactory->get('ai_chat.settings');

    if ($qdrant_host === NULL) {
      $qdrant_host = $config->get('qdrant_host');
    }

    if ($qdrant_port === NULL) {
      $qdrant_port = (int) $config->get('qdrant_port');
    }

    if ($qdrant_api_key === NULL) {
      $qdrant_api_key = $config->get('qdrant_api_key');
    }

    $qdrant_config = new Config($qdrant_host, $qdrant_port);
    $qdrant_config->setApiKey($qdrant_api_key);

    $transport = (new Builder())->build($qdrant_config);
    return new Qdrant($transport);
  }

  /**
   * Gets the OpenAI client.
   *
   * @return \OpenAI\Client
   *   The OpenAI client.
   */
  protected function getOpenAiClient(): Client {
    $config = $this->configFactory->get('ai_chat.settings');
    $api_key = $config->get('openai_api_key');
    $organization_id = $config->get('openai_organization_id');
    return \OpenAI::client($api_key, $organization_id);
  }

  /**
   * Retrieves relevant context using RAG.
   *
   * @param array $messages
   *   The chat messages.
   * @param string $trace_id
   *   The trace ID.
   *
   * @return string
   *   The retrieved context.
   */
  public function retrieveContext(array $messages, string $trace_id): string {
    $span_data = $this->langfuse->buildSpanData($trace_id, 'retrieve_context');
    $span_id = $span_data['id'];

    $config = $this->configFactory->get('ai_chat.settings');
    $combined_messages_count = (int) $config->get('combined_messages_count');

    // Get user messages only, excluding system and assistant messages.
    $user_messages = array_filter($messages, function ($message) {
      return $message['role'] === 'user';
    });

    // Combine '$combined_messages_count' number of recent user messages for
    // getting embedding.
    $recent_messages = array_slice($user_messages, -$combined_messages_count);
    $combined_content = implode("\n", array_map(function ($message) {
      return $message['content'];
    }, $recent_messages));

    // Get embedding for the combined messages.
    $embedding_span_data = $this->langfuse->buildSpanData($trace_id, 'get_embedding', FALSE, $span_id);
    $content_embedding = $this->getEmbeddingForData($combined_content);
    $embedding_span_data = $this->langfuse->buildUpdateSpan($embedding_span_data, $combined_content, $content_embedding);
    $this->queue->get('ai_chat_langfuse_queue')->createItem($embedding_span_data);

    $top_k = (int) $config->get('top_k');
    $search_request = (new SearchRequest(new VectorStruct($content_embedding, 'content')))
      ->setLimit($top_k)
      ->setParams([
        'hnsw_ef' => 128,
        'exact' => FALSE,
      ])
      ->setWithPayload(TRUE);

    $qdrant_span_data = $this->langfuse->buildSpanData($trace_id, 'qdrant_search', FALSE, $span_id);
    $client = $this->getQdrantClient();
    $qdrant_collection_name = $config->get('qdrant_collection_name');
    $response = $client->collections($qdrant_collection_name)
      ->points()
      ->search($search_request);

    $context = [];
    foreach ($response['result'] as $item) {
      $context[] = $item['payload']['text'];
    }

    $result = implode("\n", $context);
    $qdrant_span_data = $this->langfuse->buildUpdateSpan($qdrant_span_data, $content_embedding, $result, ['context_length' => strlen($result)]);
    $this->queue->get('ai_chat_langfuse_queue')->createItem($qdrant_span_data);

    $span_data = $this->langfuse->buildUpdateSpan($span_data);
    $this->queue->get('ai_chat_langfuse_queue')->createItem($span_data);

    return $result;
  }

  /**
   * Prepares messages with context for OpenAI.
   *
   * @param array $messages
   *   The original messages.
   * @param string $context
   *   The retrieved context.
   * @param string $trace_id
   *   The trace ID.
   *
   * @return array
   *   The prepared messages.
   */
  public function prepareMessages(array $messages, string $context, string $trace_id): array {
    $span_data = $this->langfuse->buildSpanData($trace_id, 'prepare_messages');

    $config = $this->configFactory->get('ai_chat.settings');
    $system_instructions = $config->get('openai_system_instructions');

    // Create a system message with the context.
    $system_message = [
      'role' => 'system',
      'content' => $system_instructions . " Context:\n\n" . $context,
    ];

    // Return all messages with the system message at the start.
    $prepared_messages = array_merge([$system_message], $messages);

    $span_data = $this->langfuse->buildUpdateSpan($span_data, $messages, $prepared_messages, [
      'message_count' => count($prepared_messages),
      'system_instruction_length' => strlen($system_instructions),
    ]);
    $this->queue->get('ai_chat_langfuse_queue')->createItem($span_data);

    return $prepared_messages;
  }

  /**
   * Gets the response from OpenAI.
   *
   * @param array $messages
   *   The prepared messages.
   * @param string $trace_id
   *   The trace ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function getOpenAiResponse(array $messages, string $trace_id): JsonResponse {
    $config = $this->configFactory->get('ai_chat.settings');
    $chat_model = $config->get('openai_chat_model');
    $max_tokens = $config->get('openai_max_completion_tokens');
    $temperature = $config->get('openai_temperature');

    $openai_span_data = $this->langfuse->buildSpanData($trace_id, 'openai_request');
    $openai_span_id = $openai_span_data['id'];
    $datetime = new \DateTime();
    $start_time = $datetime->format(LangfuseService::DATE_FORMAT);
    $response = $this->getOpenAiClient()->chat()->create([
      'model' => $chat_model,
      'messages' => $messages,
      'max_tokens' => (int) $max_tokens,
      'temperature' => (float) $temperature,
    ]);
    $end_time = $datetime->format(LangfuseService::DATE_FORMAT);

    $response_data = [
      'role' => 'assistant',
      'content' => $response->choices[0]->message->content,
    ];

    $generation_data = $this->langfuse->buildGenerationData(
      $trace_id,
      'chat_completion',
      $messages,
      $response_data,
      [
        'prompt_tokens' => $response->usage->promptTokens,
        'completion_tokens' => $response->usage->completionTokens,
        'total_tokens' => $response->usage->totalTokens,
      ],
      $start_time,
      $end_time,
      $chat_model,
      [
        'max_tokens' => $max_tokens,
        'temperature' => $temperature,
      ],
      $openai_span_id,
    );
    $this->queue->get('ai_chat_langfuse_queue')->createItem($generation_data);

    $openai_span_data = $this->langfuse->buildUpdateSpan($openai_span_data, $messages, $response_data);
    $this->queue->get('ai_chat_langfuse_queue')->createItem($openai_span_data);

    return new JsonResponse($response_data);
  }

  /**
   * Gets the embedding for provided content.
   *
   * @param string $content
   *   The content.
   *
   * @return array
   *   The embedding for provided content.
   */
  protected function getEmbeddingForData(string $content): array {
    $config = $this->configFactory->get('ai_chat.settings');
    $embedding_model = $config->get('openai_embedding_model');

    $openai_client = $this->getOpenAiClient();
    $response = $openai_client->embeddings()->create([
      'model' => $embedding_model,
      'input' => $content,
    ]);

    return array_values($response->embeddings[0]->embedding);
  }

}
