<?php

declare(strict_types=1);

namespace Drupal\ai_chat_langfuse;

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
   * @return array<string, mixed>
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
   * @param string $trace_id
   *   The ID of the trace.
   * @param array<string, mixed> $metadata
   *   Metadata to attach to the trace.
   * @param ?string $user_id
   *   Optional user ID to attach to the trace.
   *
   * @return array<string, mixed>
   *   The trace data.
   */
  public function buildTraceData(string $name, string $trace_id, array $metadata, ?string $user_id = NULL): array {
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
        'metadata' => $metadata,
      ],
    ];

    if ($user_id !== NULL) {
      $event['body']['userId'] = $user_id;
    }

    return $event;
  }

  /**
   * Records an AI generation event in Langfuse.
   *
   * @param string $trace_id
   *   The ID of the trace this generation belongs to.
   * @param string $name
   *   The name of the generation event.
   * @param array<int, array<string, mixed>> $messages
   *   The input messages for the generation.
   * @param array<string, mixed> $response
   *   The response from the AI model.
   * @param array<string, mixed> $usage
   *   Token usage statistics for the generation.
   * @param string $start_time
   *   The start time.
   * @param string $end_time
   *   The end time.
   * @param string $model
   *   The name of the AI model used.
   * @param array<string, mixed> $model_params
   *   The model params.
   *
   * @return array<string, mixed>
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
  ): array {
    $generation_id = $this->uuid->generate();
    $datetime = new \DateTime();
    $timestamp = $datetime->format(self::DATE_FORMAT);

    return [
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
      ],
    ];
  }

  /**
   * Sends events to the Langfuse API.
   *
   * @param array<int, array<string, mixed>> $events
   *   Array of events to send to Langfuse.
   */
  public function sendEvent(array $events): void {
    $config = $this->getLangfuseConfig();

    try {
      $response = $this->httpClient->request('POST', $config['base_url'] . '/api/public/ingestion', [
        'headers' => [
          'Authorization' => 'Basic ' . base64_encode($config['public_key'] . ':' . $config['secret_key']),
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'batch' => $events,
        ],
      ]);
      $this->loggerFactory->get('ai_chat:sendEvent')->debug($response->getBody()->getContents());
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('ai_chat:sendEvent')->error('Something went wrong while sending data to Langfuse. Error: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

}
