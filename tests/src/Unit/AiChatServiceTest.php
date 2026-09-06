<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_chat\Unit;

use Drupal\ai_chat\AiChatService;
use Drupal\ai_chat\Event\ObservabilityGenerationEvent;
use Drupal\ai_chat\OpenAiClientFactory;
use Drupal\ai_chat\QdrantClientFactory;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Psr7\Response as HttpResponse;
use OpenAI\Resources\Chat;
use OpenAI\Resources\Embeddings;
use OpenAI\Responses\Chat\CreateResponse as ChatResponse;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingsResponse;
use OpenAI\Testing\ClientFake;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\RequestInterface;
use Qdrant\Http\Transport;
use Qdrant\Qdrant;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Tests the chat service against a fake OpenAI client and Qdrant transport.
 */
#[CoversClass(AiChatService::class)]
#[Group('ai_chat')]
final class AiChatServiceTest extends UnitTestCase {

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
   * The requests the fake Qdrant transport received, in order.
   *
   * @var \Psr\Http\Message\RequestInterface[]
   */
  private array $qdrantRequests = [];

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
   * Builds the service with a fake OpenAI client and a canned Qdrant result.
   *
   * @param \OpenAI\Testing\ClientFake $openai
   *   The fake OpenAI client.
   * @param array<string, mixed> $qdrant_result
   *   The 'result' every Qdrant reply carries.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface|null $dispatcher
   *   The event dispatcher, a silent mock when omitted.
   */
  private function createService(ClientFake $openai, array $qdrant_result = [], ?EventDispatcherInterface $dispatcher = NULL): AiChatService {
    $transport = $this->createMock(Transport::class);
    $transport->method('sendRequest')->willReturnCallback(function (RequestInterface $request) use ($qdrant_result): HttpResponse {
      $this->qdrantRequests[] = $request;
      return new HttpResponse(200, ['content-type' => 'application/json'], self::json([
        'status' => 'ok',
        'result' => $qdrant_result,
      ]));
    });

    $qdrant_factory = $this->createMock(QdrantClientFactory::class);
    $qdrant_factory->method('create')
      ->with('qdrant.example.com', 6333, 'qdrant-key')
      ->willReturn(new Qdrant($transport));

    $openai_factory = $this->createMock(OpenAiClientFactory::class);
    $openai_factory->method('create')
      ->with('openai-key', 'org-1')
      ->willReturn($openai);

    return new AiChatService(
      $this->configFactory(self::SETTINGS),
      $this->createMock(QueueFactory::class),
      $dispatcher ?? $this->createMock(EventDispatcherInterface::class),
      $qdrant_factory,
      $openai_factory,
    );
  }

  /**
   * The recent user turns are embedded and sent to the query endpoint.
   */
  public function testRetrieveContextQueriesQdrantWithTheRecentUserMessages(): void {
    $openai = new ClientFake([
      EmbeddingsResponse::fake(['data' => [['embedding' => [0.1, 0.2, 0.3]]]]),
    ]);
    $service = $this->createService($openai, [
      'points' => [
        ['id' => 'a', 'score' => 0.9, 'payload' => ['text' => 'First chunk']],
        ['id' => 'b', 'score' => 0.8, 'payload' => ['text' => 'Second chunk']],
      ],
    ]);

    $context = $service->retrieveContext([
      ['role' => 'user', 'content' => 'Oldest question'],
      ['role' => 'assistant', 'content' => 'An answer'],
      ['role' => 'user', 'content' => 'Middle question'],
      ['role' => 'user', 'content' => 'Latest question'],
    ]);

    $this->assertSame("First chunk\nSecond chunk", $context);

    // Only the last two user turns are embedded; the assistant turn and the
    // oldest question are left out.
    $openai->assertSent(Embeddings::class, function (string $method, array $parameters): bool {
      return $method === 'create'
        && $parameters['model'] === 'text-embedding-3-small'
        && $parameters['input'] === "Middle question\nLatest question";
    });

    $this->assertCount(1, $this->qdrantRequests);
    $request = $this->qdrantRequests[0];
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame('/collections/site/points/query', $request->getUri()->getPath());
    $this->assertSame([
      'query' => ['nearest' => [0.1, 0.2, 0.3]],
      'using' => 'content',
      'params' => ['hnsw_ef' => 128, 'exact' => FALSE],
      'limit' => 3,
      'with_payload' => TRUE,
    ], json_decode((string) $request->getBody(), TRUE));
  }

  /**
   * No matching points means an empty context, not an error.
   */
  public function testRetrieveContextIsEmptyWhenNothingMatches(): void {
    $openai = new ClientFake([EmbeddingsResponse::fake()]);
    $service = $this->createService($openai, ['points' => []]);

    $this->assertSame('', $service->retrieveContext([
      ['role' => 'user', 'content' => 'Anything'],
    ]));
  }

  /**
   * The system instructions and the context lead the conversation.
   */
  public function testPrepareMessagesPrependsTheSystemMessage(): void {
    $service = $this->createService(new ClientFake());
    $messages = [
      ['role' => 'user', 'content' => 'Hello'],
      ['role' => 'assistant', 'content' => 'Hi'],
    ];

    $prepared = $service->prepareMessages($messages, 'Some context');

    $this->assertSame([
      ['role' => 'system', 'content' => "You are the site assistant. Context:\n\nSome context"],
      ...$messages,
    ], $prepared);
  }

  /**
   * The chat completion is returned and reported to observability.
   */
  public function testGetOpenAiResponseReturnsTheCompletionAndDispatchesTheEvent(): void {
    $openai = new ClientFake([
      ChatResponse::fake([
        'choices' => [['message' => ['content' => 'Hi there']]],
        'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 5, 'total_tokens' => 45],
      ]),
    ]);
    $messages = [
      ['role' => 'system', 'content' => 'Instructions'],
      ['role' => 'user', 'content' => 'Hello'],
    ];

    $dispatcher = $this->createMock(EventDispatcherInterface::class);
    $dispatcher->expects($this->once())
      ->method('dispatch')
      ->with(
        $this->callback(function (ObservabilityGenerationEvent $event) use ($messages): bool {
          return $event->getTraceId() === 'trace-1'
            && $event->getMessages() === $messages
            && $event->getResponseData() === ['role' => 'assistant', 'content' => 'Hi there']
            && $event->getUsage() === ['prompt_tokens' => 40, 'completion_tokens' => 5, 'total_tokens' => 45]
            && $event->getModel() === 'gpt-4o-mini';
        }),
        ObservabilityGenerationEvent::OBSERVABILITY_GENERATION,
      )
      ->willReturnArgument(0);

    $service = $this->createService($openai, [], $dispatcher);
    $response = $service->getOpenAiResponse($messages, 'trace-1');

    $this->assertSame(['role' => 'assistant', 'content' => 'Hi there'], json_decode((string) $response->getContent(), TRUE));

    $openai->assertSent(Chat::class, function (string $method, array $parameters) use ($messages): bool {
      return $method === 'create'
        && $parameters['model'] === 'gpt-4o-mini'
        && $parameters['messages'] === $messages
        && $parameters['max_tokens'] === 256
        && $parameters['temperature'] === 0.2;
    });
  }

}
