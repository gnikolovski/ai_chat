<?php

declare(strict_types=1);

namespace Drupal\ai_chat;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Service for interacting with Langfuse API.
 */
final class LangfuseService {

  /**
   * Constructor for LangfuseService.
   */
  public function __construct(
    #[Autowire(service: 'config.factory')]
    protected ConfigFactoryInterface $configFactory,
    #[Autowire(service: 'http_client')]
    protected ClientInterface $httpClient,
    #[Autowire(service: 'uuid')]
    protected UuidInterface $uuid,
    #[Autowire(service: 'current_user')]
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * Gets Langfuse configuration from module settings.
   *
   * @return array
   *   An array containing Langfuse configuration.
   */
  protected function getLangfuseConfig(): array {
    $config = $this->configFactory->get('ai_chat.settings');
    return [
      'base_url' => $config->get('langfuse_host'),
      'secret_key' => $config->get('langfuse_secret_key'),
      'public_key' => $config->get('langfuse_public_key'),
    ];
  }

  /**
   * Creates a new trace in Langfuse.
   *
   * @param string $name
   *   The name of the trace.
   * @param array $metadata
   *   Optional metadata to attach to the trace.
   *
   * @return string
   *   The generated trace ID.
   */
  public function createTrace(string $name, array $metadata = []): string {
    $trace_id = $this->uuid->generate();
    $event = [
      'type' => 'trace-create',
      'id' => $this->uuid->generate(),
      'timestamp' => date('c'),
      'body' => [
        'id' => $trace_id,
        'timestamp' => date('c'),
        'name' => $name,
        'userId' => $this->currentUser->id(),
        'metadata' => $metadata,
      ],
    ];

    $this->sendEvent([$event]);
    return $trace_id;
  }

  /**
   * Creates a new span within a trace.
   *
   * @param string $trace_id
   *   The ID of the trace this span belongs to.
   * @param string $name
   *   The name of the span.
   * @param string|null $parent_id
   *   Optional ID of the parent span.
   *
   * @return string
   *   The generated span ID.
   */
  public function createSpan(
    string $trace_id,
    string $name,
    ?string $parent_id = NULL,
  ): string {
    $span_id = $this->uuid->generate();
    $event = [
      'type' => 'span-create',
      'id' => $this->uuid->generate(),
      'timestamp' => date('c'),
      'body' => [
        'id' => $span_id,
        'traceId' => $trace_id,
        'name' => $name,
        'startTime' => date('c'),
        'parentObservationId' => $parent_id,
      ],
    ];

    $this->sendEvent([$event]);
    return $span_id;
  }

  /**
   * Updates an existing span with metadata and end time.
   *
   * @param string $span_id
   *   The ID of the span to update.
   * @param string $trace_id
   *   The ID of the trace this span belongs to.
   * @param array $metadata
   *   Optional metadata to attach to the span.
   */
  public function updateSpan(
    string $span_id,
    string $trace_id,
    array $metadata = [],
  ): void {
    $event = [
      'type' => 'span-update',
      'id' => $this->uuid->generate(),
      'timestamp' => date('c'),
      'body' => [
        'id' => $span_id,
        'traceId' => $trace_id,
        'endTime' => date('c'),
        'metadata' => $metadata,
      ],
    ];

    $this->sendEvent([$event]);
  }

  /**
   * Records an AI generation event in Langfuse.
   *
   * @param string $trace_id
   *   The ID of the trace this generation belongs to.
   * @param string $name
   *   The name of the generation event.
   * @param array $messages
   *   The input messages for the generation.
   * @param array $response
   *   The response from the AI model.
   * @param array $usage
   *   Token usage statistics for the generation.
   * @param string $start_time
   *   The start time.
   * @param string $end_time
   *   The end time.
   * @param string|null $parent_id
   *   Optional ID of the parent observation.
   * @param string|null $model
   *   Optional name of the AI model used.
   */
  public function recordGeneration(
    string $trace_id,
    string $name,
    array $messages,
    array $response,
    array $usage,
    string $start_time,
    string $end_time,
    ?string $parent_id = NULL,
    ?string $model = NULL,
  ): void {
    $event = [
      'type' => 'generation-create',
      'id' => $this->uuid->generate(),
      'timestamp' => date('c'),
      'body' => [
        'id' => $this->uuid->generate(),
        'traceId' => $trace_id,
        'name' => $name,
        'startTime' => $start_time,
        'endTime' => $end_time,
        'model' => $model,
        'input' => ['messages' => $messages],
        'output' => $response,
        'usage' => [
          'promptTokens' => $usage['prompt_tokens'] ?? NULL,
          'completionTokens' => $usage['completion_tokens'] ?? NULL,
          'totalTokens' => $usage['total_tokens'] ?? NULL,
        ],
        'parentObservationId' => $parent_id,
      ],
    ];

    $this->sendEvent([$event]);
  }

  /**
   * Sends events to the Langfuse API.
   *
   * @param array $events
   *   Array of events to send to Langfuse.
   */
  protected function sendEvent(array $events): void {
    $config = $this->getLangfuseConfig();
    $this->httpClient->request('POST', $config['base_url'] . '/api/public/ingestion', [
      'headers' => [
        'Authorization' => 'Basic ' . base64_encode($config['public_key'] . ':' . $config['secret_key']),
        'Content-Type' => 'application/json',
      ],
      'json' => [
        'batch' => $events,
      ],
    ]);
  }

}
