<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_chat\Unit;

use Drupal\ai_chat\ChatRateLimiter;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the per-IP request window of the chat rate limiter.
 */
#[CoversClass(ChatRateLimiter::class)]
#[Group('ai_chat')]
final class ChatRateLimiterTest extends UnitTestCase {

  private const string IP = '203.0.113.7';
  private const string CID = 'ai_chat_rate_limit:203.0.113.7';

  /**
   * The cache backend that holds the per-IP window.
   */
  private CacheBackendInterface&MockObject $cache;

  /**
   * The logger channel the limiter writes to.
   */
  private LoggerChannelInterface&MockObject $logger;

  /**
   * Builds the limiter for a request from the fixed IP.
   */
  private function createLimiter(): ChatRateLimiter {
    $this->cache = $this->createMock(CacheBackendInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->logger);

    $request_stack = new RequestStack();
    $request_stack->push(Request::create('/api/v1/ai-chat', 'POST', [], [], [], ['REMOTE_ADDR' => self::IP]));

    return new ChatRateLimiter($this->cache, $request_stack, $logger_factory);
  }

  /**
   * Checks that a cache write starts or extends the one-minute window.
   *
   * @param array{count: int} $data
   *   The window data the write has to carry.
   */
  private function expectWindowWrite(array $data): void {
    $before = time();
    $this->cache->expects($this->once())
      ->method('set')
      ->with(
        self::CID,
        $data,
        $this->callback(fn (int $expire): bool => $expire >= $before + ChatRateLimiter::REQUEST_SECONDS_LIMIT && $expire <= time() + ChatRateLimiter::REQUEST_SECONDS_LIMIT),
      );
  }

  /**
   * The first request from an IP opens its window with a count of one.
   */
  public function testFirstRequestIsAllowedAndOpensTheWindow(): void {
    $limiter = $this->createLimiter();
    $this->cache->method('get')->with(self::CID)->willReturn(FALSE);
    $this->expectWindowWrite(['count' => 1]);
    $this->logger->expects($this->never())->method('error');

    $this->assertTrue($limiter->isAllowed());
  }

  /**
   * Requests up to the limit are allowed and counted.
   */
  public function testRequestsUpToTheLimitAreAllowed(): void {
    $limiter = $this->createLimiter();
    $this->cache->method('get')->with(self::CID)->willReturn((object) ['data' => ['count' => ChatRateLimiter::REQUEST_COUNT_LIMIT - 1]]);
    $this->expectWindowWrite(['count' => ChatRateLimiter::REQUEST_COUNT_LIMIT]);
    $this->logger->expects($this->never())->method('error');

    $this->assertTrue($limiter->isAllowed());
  }

  /**
   * One request past the limit is refused, logged, and not counted.
   */
  public function testRequestPastTheLimitIsRefusedAndLogged(): void {
    $limiter = $this->createLimiter();
    $this->cache->method('get')->with(self::CID)->willReturn((object) ['data' => ['count' => ChatRateLimiter::REQUEST_COUNT_LIMIT]]);
    $this->cache->expects($this->never())->method('set');
    $this->logger->expects($this->once())
      ->method('error')
      ->with($this->stringContains('Rate limit'), ['@ip_address' => self::IP]);

    $this->assertFalse($limiter->isAllowed());
  }

}
