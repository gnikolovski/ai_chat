<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_chat\Unit;

use Drupal\ai_chat\AiChatService;
use Drupal\ai_chat\ChatRateLimiter;
use Drupal\ai_chat\Controller\AiChatController;
use Drupal\ai_chat\Event\ObservabilityGenerationEvent;
use Drupal\ai_chat\Event\ObservabilityTraceEvent;
use Drupal\ai_chat\OpenAiClientFactory;
use Drupal\ai_chat\QdrantClientFactory;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Psr7\Response as HttpResponse;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse as ChatResponse;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsResponse;
use OpenAI\Testing\ClientFake;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Qdrant\Http\Transport;
use Qdrant\Qdrant;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the chat endpoint end to end against fake OpenAI and Qdrant clients.
 *
 * The service and the rate limiter are final, so the controller gets real
 * instances built on fakes rather than mocks.
 */
#[CoversClass(AiChatController::class)]
#[Group('ai_chat')]
final class AiChatControllerTest extends UnitTestCase {

  private const string IP = '203.0.113.7';

  private const array SETTINGS = [
    'ai_chat.settings' => [
      'qdrant_host' => 'qdrant.example.com',
      'qdrant_port' => 6333,
      'qdrant_api_key' => 'qdrant-key',
      'qdrant_collection_name' => 'site',
      'openai_api_key' => 'openai-key',
      'openai_organization_id' => 'org-1',
      'openai_embedding_model' => 'text-embedding-3-small',
      'openai_chat_model' => 'gpt-4o-mini',
      'openai_max_completion_tokens' => '256',
      'openai_temperature' => '0.2',
      'openai_system_instructions' => 'You are the site assistant.',
      'combined_messages_count' => 2,
      'top_k' => 3,
    ],
  ];

  /**
   * The fake OpenAI client.
   */
  private ClientFake $openai;

  /**
   * The events dispatched during the request, in order.
   *
   * @var object[]
   */
  private array $events = [];

  /**
   * Encodes a JSON body, failing the test instead of returning false.
   *
   * @param array<mixed> $data
   *   The data to encode.
   */
  private static function json(array $data): string {
    return json_encode($data, JSON_THROW_ON_ERROR);
  }

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
   * Builds the controller.
   *
   * @param \OpenAI\Responses\Chat\CreateResponse[]|\OpenAI\Responses\Embeddings\CreateResponse[] $openai_responses
   *   The responses the fake OpenAI client hands out, in order.
   * @param int $requests_in_window
   *   How many requests this IP already made in the current window.
   */
  private function createController(array $openai_responses = [], int $requests_in_window = 0): AiChatController {
    $this->openai = new ClientFake($openai_responses);
    $openai_factory = $this->createMock(OpenAiClientFactory::class);
    $openai_factory->method('create')->willReturn($this->openai);

    $transport = $this->createMock(Transport::class);
    $transport->method('sendRequest')->willReturn(new HttpResponse(200, ['content-type' => 'application/json'], self::json([
      'status' => 'ok',
      'result' => ['points' => [['id' => 'a', 'score' => 0.9, 'payload' => ['text' => 'Context chunk']]]],
    ])));
    $qdrant_factory = $this->createMock(QdrantClientFactory::class);
    $qdrant_factory->method('create')->willReturn(new Qdrant($transport));

    $dispatcher = $this->createMock(EventDispatcherInterface::class);
    $dispatcher->method('dispatch')->willReturnCallback(function (object $event): object {
      $this->events[] = $event;
      return $event;
    });

    $config_factory = $this->configFactory(self::SETTINGS);
    $queue = $this->createMock(QueueFactory::class);
    $service = new AiChatService($config_factory, $queue, $dispatcher, $qdrant_factory, $openai_factory);

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn($requests_in_window > 0 ? (object) ['data' => ['count' => $requests_in_window]] : FALSE);
    $request_stack = new RequestStack();
    $request_stack->push($this->createRequest(''));
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $rate_limiter = new ChatRateLimiter($cache, $request_stack, $logger_factory);

    $uuid = $this->createMock(UuidInterface::class);
    $uuid->method('generate')->willReturn('trace-1');

    $current_user = $this->createMock(AccountProxyInterface::class);
    $current_user->method('id')->willReturn(1);
    $container = new ContainerBuilder();
    $container->set('current_user', $current_user);
    $translation = $this->getStringTranslationStub();
    assert($translation instanceof TranslationInterface);
    $container->set('string_translation', $translation);
    \Drupal::setContainer($container);

    $controller = new AiChatController($service, $rate_limiter, $queue, $dispatcher, $uuid);
    $controller->setStringTranslation($translation);
    return $controller;
  }

  /**
   * Builds a POST to the chat endpoint with the given raw body.
   */
  private function createRequest(string $body): Request {
    return Request::create('/api/v1/ai-chat', 'POST', [], [], [], [
      'REMOTE_ADDR' => self::IP,
      'HTTP_USER_AGENT' => 'TestAgent/1.0',
    ], $body);
  }

  /**
   * A conversation is relayed to OpenAI and answered in Deep Chat format.
   */
  public function testMessagesAreRelayedAndAnsweredInDeepChatFormat(): void {
    $controller = $this->createController([
      EmbeddingsResponse::fake(),
      ChatResponse::fake(['choices' => [['message' => ['content' => 'The answer']]]]),
    ]);

    $response = $controller->aiChat($this->createRequest(self::json([
      'messages' => [
        ['role' => 'user', 'text' => 'Hello'],
        ['role' => 'assistant', 'html' => '<p>Hi</p>'],
        ['role' => 'user', 'text' => 'What is X?'],
      ],
    ])));

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['text' => 'The answer', 'role' => 'assistant'], json_decode((string) $response->getContent(), TRUE));

    // The Deep Chat turns become OpenAI messages behind the system prompt
    // that carries the retrieved context.
    $this->openai->assertSent(Chat::class, function (string $method, array $parameters): bool {
      return $parameters['messages'] === [
        ['role' => 'system', 'content' => "You are the site assistant. Context:\n\nContext chunk"],
        ['role' => 'user', 'content' => 'Hello'],
        ['role' => 'assistant', 'content' => '<p>Hi</p>'],
        ['role' => 'user', 'content' => 'What is X?'],
      ];
    });

    $this->assertCount(2, $this->events);
    $trace = $this->events[0];
    $this->assertInstanceOf(ObservabilityTraceEvent::class, $trace);
    $this->assertSame('trace-1', $trace->getTraceId());
    $this->assertSame('1', $trace->getUserId());
    $this->assertSame(self::IP, $trace->getRequestIp());
    $this->assertSame('TestAgent/1.0', $trace->getUserAgent());
    $generation = $this->events[1];
    $this->assertInstanceOf(ObservabilityGenerationEvent::class, $generation);
    $this->assertSame('trace-1', $generation->getTraceId());
  }

  /**
   * Past the rate limit the visitor gets a message and OpenAI is not called.
   */
  public function testRateLimitedRequestsAreAnsweredWithoutCallingOpenAi(): void {
    $controller = $this->createController([], ChatRateLimiter::REQUEST_COUNT_LIMIT);

    $response = $controller->aiChat($this->createRequest(self::json([
      'messages' => [['role' => 'user', 'text' => 'Hello']],
    ])));

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([
      'html' => '<span style="color: red">Rate limit exceeded. Please try again later.</span>',
      'role' => 'assistant',
    ], json_decode((string) $response->getContent(), TRUE));
    $this->openai->assertNothingSent();
  }

  /**
   * A body without a messages list is rejected before any API call.
   */
  public function testMalformedBodyIsRejected(): void {
    $controller = $this->createController();

    $response = $controller->aiChat($this->createRequest('{"messages":"not a list"}'));

    $this->assertSame([
      'html' => '<span style="color: red">Invalid message format.</span>',
      'role' => 'assistant',
    ], json_decode((string) $response->getContent(), TRUE));
    $this->openai->assertNothingSent();
  }

  /**
   * A failing upstream call is reported as a 500 with the error message.
   */
  public function testUpstreamFailuresComeBackAsServerErrors(): void {
    // No fake responses queued, so the first OpenAI call throws.
    $controller = $this->createController();

    $response = $controller->aiChat($this->createRequest(self::json([
      'messages' => [['role' => 'user', 'text' => 'Hello']],
    ])));

    $this->assertSame(500, $response->getStatusCode());
    $this->assertArrayHasKey('error', json_decode((string) $response->getContent(), TRUE));
  }

}
