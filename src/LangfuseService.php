<?php

declare(strict_types=1);

namespace Drupal\ai_chat;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Service for interacting with Langfuse API.
 */
final class LangfuseService {

  const DATE_FORMAT = 'c';

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
    #[Autowire(service: 'logger.factory')]
    protected LoggerChannelFactoryInterface $loggerFactory,
    #[Autowire(service: 'tempstore.private')]
    protected PrivateTempStoreFactory $tempstore,
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
   * Gets the session ID.
   *
   * @return string
   *   The session ID.
   */
  protected function getSessionId(): string {
    $tempstore_data = $this->tempstore->get('ai_chat');
    $session_id = $tempstore_data->get('session_id');

    if (!$session_id) {
      $session_id = uniqid('', TRUE);
      $tempstore_data->set('session_id', $session_id);
    }

    return $session_id;
  }

  /**
   * Builds the trace data.
   *
   * @param string $name
   *   The name of the trace.
   * @param array $metadata
   *   Optional metadata to attach to the trace.
   *
   * @return array
   *   The trace data.
   */
  public function buildTraceData(string $name, array $metadata = []): array {
    $trace_id = $this->uuid->generate();
    $datetime = new \DateTime();
    $timestamp = $datetime->format(self::DATE_FORMAT);

    $event = [
      'type' => 'trace-create',
      'id' => $trace_id,
      'timestamp' => $timestamp,
      'body' => [
        'id' => $trace_id,
        'timestamp' => $timestamp,
        'name' => $name,
        'sessionId' => $this->getSessionId(),
      ],
    ];

    if (!empty($metadata)) {
      $event['body']['metadata'] = $metadata;
    }

    if ($this->currentUser->isAuthenticated()) {
      $event['body']['userId'] = $this->currentUser->id();
    }

    return $event;
  }

  /**
   * Builds the span data.
   *
   * @param string $trace_id
   *   The ID of the trace this span belongs to.
   * @param string $name
   *   The name of the span.
   * @param bool $set_end_time
   *   Flag that indicates if we need to set the end time.
   * @param string|null $parent_id
   *   Optional ID of the parent span.
   *
   * @return array
   *   The span data.
   */
  public function buildSpanData(
    string $trace_id,
    string $name,
    bool $set_end_time = FALSE,
    ?string $parent_id = NULL,
  ): array {
    $span_id = $this->uuid->generate();
    $datetime = new \DateTime();
    $timestamp = $datetime->format(self::DATE_FORMAT);

    $event = [
      'type' => 'span-create',
      'id' => $span_id,
      'timestamp' => $timestamp,
      'body' => [
        'id' => $span_id,
        'traceId' => $trace_id,
        'name' => $name,
        'startTime' => $timestamp,
      ],
    ];

    if ($set_end_time) {
      $event['body']['endTime'] = $timestamp;
    }

    if (!empty($parent_id)) {
      $event['body']['parentObservationId'] = $parent_id;
    }

    return $event;
  }

  /**
   * Builds the updated span data.
   *
   * @param array $span_data
   *   The span data to update.
   * @param mixed|null $input
   *   Optional input data to attach to the span.
   * @param mixed|null $output
   *   Optional output data to attach to the span.
   * @param array $metadata
   *   Optional metadata to attach to the span.
   *
   * @return array
   *   The updated span data.
   */
  public function buildUpdateSpan(array $span_data, mixed $input = NULL, mixed $output = NULL, array $metadata = []): array {
    $datetime = new \DateTime();
    $timestamp = $datetime->format(self::DATE_FORMAT);
    $span_data['body']['endTime'] = $timestamp;

    if (!empty($input)) {
      $span_data['body']['input'] = $input;
    }

    if (!empty($output)) {
      $span_data['body']['output'] = $output;
    }

    if (!empty($metadata)) {
      $span_data['body']['metadata'] = $metadata;
    }

    return $span_data;
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
   * @param string $model
   *   The name of the AI model used.
   * @param array $model_params
   *   The model params.
   * @param string|null $parent_id
   *   Optional ID of the parent observation.
   *
   * @return array
   *   The generation data.
   */
  public function buildGenerationData(
    string $trace_id,
    string $name,
    array $messages,
    array $response,
    array $usage,
    string $start_time,
    string $end_time,
    string $model,
    array $model_params,
    ?string $parent_id = NULL,
  ): array {
    $generation_id = $this->uuid->generate();
    $datetime = new \DateTime();
    $timestamp = $datetime->format(self::DATE_FORMAT);

    $event = [
      'type' => 'generation-create',
      'id' => $generation_id,
      'timestamp' => $timestamp,
      'body' => [
        'id' => $generation_id,
        'traceId' => $trace_id,
        'name' => $name,
        'startTime' => $start_time,
        'endTime' => $end_time,
        'model' => $model,
        'modelParameters' => $model_params,
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

    if (!empty($parent_id)) {
      $event['body']['parentObservationId'] = $parent_id;
    }

    return $event;
  }

  /**
   * Sends events to the Langfuse API.
   *
   * @param array $events
   *   Array of events to send to Langfuse.
   */
  public function sendEvent(array $events): void {
    $config = $this->getLangfuseConfig();

    try {
      $res = $this->httpClient->request('POST', $config['base_url'] . '/api/public/ingestion', [
        'headers' => [
          'Authorization' => 'Basic ' . base64_encode($config['public_key'] . ':' . $config['secret_key']),
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'batch' => $events,
        ],
      ]);
      $this->loggerFactory->get('ai_chat:sendEvent')->debug((string) $res->getStatusCode());
      $this->loggerFactory->get('ai_chat:sendEvent')->debug($res->getBody()->getContents());
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('ai_chat:sendEvent')->error('Something went wrong while sending data to Langfuse. Error: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

}
