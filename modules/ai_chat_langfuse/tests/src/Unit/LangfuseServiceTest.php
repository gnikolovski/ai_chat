<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_chat_langfuse\Unit;

use Drupal\ai_chat_langfuse\LangfuseService;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Tests the Langfuse event payloads and the ingestion call.
 */
#[CoversClass(LangfuseService::class)]
#[Group('ai_chat_langfuse')]
final class LangfuseServiceTest extends UnitTestCase {

  private const array SETTINGS = [
    'ai_chat.settings' => [
      'langfuse_host' => 'https://langfuse.example.com',
      'langfuse_public_key' => 'pk-1',
      'langfuse_secret_key' => 'sk-1',
    ],
  ];

  /**
   * The HTTP client the ingestion call goes through.
   */
  private ClientInterface&MockObject $httpClient;

  /**
   * The visitor's private tempstore.
   */
  private PrivateTempStore&MockObject $tempstore;

  /**
   * The logger channel the service writes to.
   */
  private LoggerChannelInterface&MockObject $logger;

  /**
   * Builds the config factory, typed so the service constructor accepts it.
   *
   * @param array<string, array<string, mixed>> $configs
   *   The config objects keyed by name.
   */
  private function configFactory(array $configs): ConfigFactoryInterface {
    $config_factory = $this->getConfigFactoryStub($configs);
    assert($config_factory instanceof ConfigFactoryInterface);
    return $config_factory;
  }

  /**
   * Builds the service with a tempstore that holds the given session id.
   */
  private function createService(?string $stored_session_id = 'sess-1'): LangfuseService {
    $this->httpClient = $this->createMock(ClientInterface::class);

    $uuid = $this->createMock(UuidInterface::class);
    $uuid->method('generate')->willReturn('gen-1');

    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->logger);

    $this->tempstore = $this->createMock(PrivateTempStore::class);
    $this->tempstore->method('get')->with('session_id')->willReturn($stored_session_id);
    $tempstore_factory = $this->createMock(PrivateTempStoreFactory::class);
    $tempstore_factory->method('get')->with('ai_chat')->willReturn($this->tempstore);

    return new LangfuseService(
      $this->configFactory(self::SETTINGS),
      $this->httpClient,
      $uuid,
      $this->createMock(AccountProxyInterface::class),
      $logger_factory,
      $tempstore_factory,
    );
  }

  /**
   * Asserts a timestamp is the ISO 8601 form Langfuse expects.
   */
  private function assertIsIso8601(string $timestamp): void {
    // 'c' is an output-only format, so parse with its ATOM equivalent.
    $this->assertInstanceOf(\DateTimeImmutable::class, \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $timestamp), "Not an ISO 8601 timestamp: $timestamp");
  }

  /**
   * A trace reuses the visitor's stored session id and carries the user.
   */
  public function testBuildTraceDataReusesTheStoredSession(): void {
    $service = $this->createService('sess-1');
    $this->tempstore->expects($this->never())->method('set');

    $event = $service->buildTraceData('chat_request', 'trace-1', ['request_ip' => '203.0.113.7'], '42');

    $this->assertSame('trace-create', $event['type']);
    $this->assertSame('trace-1', $event['id']);
    $this->assertIsIso8601($event['timestamp']);
    $this->assertSame($event['timestamp'], $event['body']['timestamp']);
    $this->assertSame([
      'id' => 'trace-1',
      'timestamp' => $event['timestamp'],
      'name' => 'chat_request',
      'sessionId' => 'sess-1',
      'metadata' => ['request_ip' => '203.0.113.7'],
      'userId' => '42',
    ], $event['body']);
  }

  /**
   * Without a stored session one is generated and kept for the next trace.
   */
  public function testBuildTraceDataStartsSessionWhenNoneIsStored(): void {
    $service = $this->createService(NULL);
    $generated = NULL;
    $this->tempstore->expects($this->once())
      ->method('set')
      ->with('session_id', $this->callback(function (string $session_id) use (&$generated): bool {
        $generated = $session_id;
        return $session_id !== '';
      }));

    $event = $service->buildTraceData('chat_request', 'trace-1', []);

    $this->assertSame($generated, $event['body']['sessionId']);
    $this->assertArrayNotHasKey('userId', $event['body'], 'Anonymous traces carry no user id.');
  }

  /**
   * A generation maps the chat exchange and its token usage.
   */
  public function testBuildGenerationDataMapsTheExchangeAndUsage(): void {
    $service = $this->createService();
    $messages = [['role' => 'user', 'content' => 'Hello']];
    $response = ['role' => 'assistant', 'content' => 'Hi'];

    $event = $service->buildGenerationData(
      'trace-1',
      'chat_completion',
      $messages,
      $response,
      ['prompt_tokens' => 9, 'completion_tokens' => 12, 'total_tokens' => 21],
      '2026-09-06T10:00:00+00:00',
      '2026-09-06T10:00:01+00:00',
      'gpt-4o-mini',
      ['temperature' => '0.2'],
    );

    $this->assertSame('generation-create', $event['type']);
    $this->assertSame('gen-1', $event['id']);
    $this->assertIsIso8601($event['timestamp']);
    $this->assertSame([
      'id' => 'gen-1',
      'traceId' => 'trace-1',
      'name' => 'chat_completion',
      'startTime' => '2026-09-06T10:00:00+00:00',
      'endTime' => '2026-09-06T10:00:01+00:00',
      'model' => 'gpt-4o-mini',
      'modelParameters' => ['temperature' => '0.2'],
      'input' => ['messages' => $messages],
      'output' => $response,
      'usage' => ['promptTokens' => 9, 'completionTokens' => 12, 'totalTokens' => 21],
    ], $event['body']);
  }

  /**
   * Missing usage figures become nulls rather than notices.
   */
  public function testBuildGenerationDataToleratesMissingUsage(): void {
    $service = $this->createService();

    $event = $service->buildGenerationData('trace-1', 'chat_completion', [], [], [], 'start', 'end', 'gpt-4o-mini', []);

    $this->assertSame(['promptTokens' => NULL, 'completionTokens' => NULL, 'totalTokens' => NULL], $event['body']['usage']);
  }

  /**
   * Events are posted as one batch with basic auth from the configured keys.
   */
  public function testSendEventPostsTheBatchWithBasicAuth(): void {
    $service = $this->createService();
    $events = [['type' => 'trace-create', 'id' => 'trace-1']];
    $this->httpClient->expects($this->once())
      ->method('request')
      ->with('POST', 'https://langfuse.example.com/api/public/ingestion', [
        'headers' => [
          'Authorization' => 'Basic ' . base64_encode('pk-1:sk-1'),
          'Content-Type' => 'application/json',
        ],
        'json' => ['batch' => $events],
      ])
      ->willReturn(new Response(207, [], '{"successes":[{"id":"trace-1","status":201}],"errors":[]}'));
    $this->logger->expects($this->once())->method('debug')->with($this->stringContains('"successes"'));
    $this->logger->expects($this->never())->method('error');

    $service->sendEvent($events);
  }

  /**
   * A failed ingestion is logged and does not break the queue run.
   */
  public function testSendEventLogsFailuresInsteadOfThrowing(): void {
    $service = $this->createService();
    $this->httpClient->method('request')->willThrowException(new TransferException('Connection refused'));
    $this->logger->expects($this->once())
      ->method('error')
      ->with($this->stringContains('Langfuse'), ['@error' => 'Connection refused']);

    $service->sendEvent([['type' => 'trace-create']]);
  }

}
