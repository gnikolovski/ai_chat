<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_chat_langfuse\Unit;

use Drupal\ai_chat\Event\ObservabilityGenerationEvent;
use Drupal\ai_chat\Event\ObservabilityTraceEvent;
use Drupal\ai_chat_langfuse\EventSubscriber\ObservabilitySubscriber;
use Drupal\ai_chat_langfuse\LangfuseService;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that chat observability events end up in the Langfuse queue.
 *
 * The Langfuse service is final, so the subscriber gets a real instance on
 * top of mocks rather than a mock.
 */
#[CoversClass(ObservabilitySubscriber::class)]
#[Group('ai_chat_langfuse')]
final class ObservabilitySubscriberTest extends UnitTestCase {

  /**
   * The items queued for Langfuse, in order.
   */
  private array $queued = [];

  /**
   * Builds the subscriber with a recording queue.
   */
  private function createSubscriber(): ObservabilitySubscriber {
    $uuid = $this->createMock(UuidInterface::class);
    $uuid->method('generate')->willReturn('gen-1');
    $tempstore = $this->createMock(PrivateTempStore::class);
    $tempstore->method('get')->with('session_id')->willReturn('sess-1');
    $tempstore_factory = $this->createMock(PrivateTempStoreFactory::class);
    $tempstore_factory->method('get')->willReturn($tempstore);

    $langfuse = new LangfuseService(
      $this->getConfigFactoryStub(['ai_chat.settings' => []]),
      $this->createMock(ClientInterface::class),
      $uuid,
      $this->createMock(AccountProxyInterface::class),
      $this->createMock(LoggerChannelFactoryInterface::class),
      $tempstore_factory,
    );

    $queue = $this->createMock(QueueInterface::class);
    $queue->method('createItem')->willReturnCallback(function (array $item): int {
      $this->queued[] = $item;
      return count($this->queued);
    });
    $queue_factory = $this->createMock(QueueFactory::class);
    $queue_factory->method('get')->with('ai_chat_langfuse_queue')->willReturn($queue);

    return new ObservabilitySubscriber($langfuse, $queue_factory);
  }

  /**
   * Both chat events are subscribed to.
   */
  public function testSubscribesToTheTraceAndGenerationEvents(): void {
    $this->assertSame([
      ObservabilityTraceEvent::OBSERVABILITY_TRACE => 'onTraceObservation',
      ObservabilityGenerationEvent::OBSERVABILITY_GENERATION => 'onGenerationObservation',
    ], ObservabilitySubscriber::getSubscribedEvents());
  }

  /**
   * A trace event is queued as a Langfuse trace with the request details.
   */
  public function testTraceEventsAreQueuedAsTraces(): void {
    $subscriber = $this->createSubscriber();

    $subscriber->onTraceObservation(new ObservabilityTraceEvent('chat_request', 'trace-1', '42', '203.0.113.7', 'TestAgent/1.0'));

    $this->assertCount(1, $this->queued);
    $item = $this->queued[0];
    $this->assertSame('trace-create', $item['type']);
    $this->assertSame('trace-1', $item['body']['id']);
    $this->assertSame('chat_request', $item['body']['name']);
    $this->assertSame('sess-1', $item['body']['sessionId']);
    $this->assertSame('42', $item['body']['userId']);
    $this->assertSame(['request_ip' => '203.0.113.7', 'user_agent' => 'TestAgent/1.0'], $item['body']['metadata']);
  }

  /**
   * A generation event is queued as a Langfuse generation under its trace.
   */
  public function testGenerationEventsAreQueuedAsGenerations(): void {
    $subscriber = $this->createSubscriber();
    $messages = [['role' => 'user', 'content' => 'Hello']];

    $subscriber->onGenerationObservation(new ObservabilityGenerationEvent(
      'chat_completion',
      'trace-1',
      $messages,
      ['role' => 'assistant', 'content' => 'Hi'],
      ['prompt_tokens' => 9, 'completion_tokens' => 12, 'total_tokens' => 21],
      'start',
      'end',
      'gpt-4o-mini',
      ['temperature' => '0.2'],
    ));

    $this->assertCount(1, $this->queued);
    $item = $this->queued[0];
    $this->assertSame('generation-create', $item['type']);
    $this->assertSame('gen-1', $item['id']);
    $this->assertSame('trace-1', $item['body']['traceId']);
    $this->assertSame('gpt-4o-mini', $item['body']['model']);
    $this->assertSame(['messages' => $messages], $item['body']['input']);
    $this->assertSame(['promptTokens' => 9, 'completionTokens' => 12, 'totalTokens' => 21], $item['body']['usage']);
  }

}
