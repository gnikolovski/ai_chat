<?php

declare(strict_types=1);

namespace Drupal\ai_chat;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Defines the ChatRateLimiter class.
 */
final class ChatRateLimiter {

  const int REQUEST_COUNT_LIMIT = 10;

  const int REQUEST_SECONDS_LIMIT = 60;

  /**
   * Constructor for ChatRateLimiter.
   */
  public function __construct(
    #[Autowire(service: 'cache.default')]
    protected CacheBackendInterface $cache,
    #[Autowire(service: 'request_stack')]
    protected RequestStack $requestStack,
    #[Autowire(service: 'logger.factory')]
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {
  }

  /**
   * Checks if the current request is within rate limits.
   *
   * Implements a rolling window rate limit of n requests per n seconds per IP.
   * Uses Drupal cache to track request counts.
   *
   * @return bool
   *   TRUE if request is allowed, FALSE if rate limit exceeded.
   */
  public function isAllowed(): bool {
    $ip = $this->requestStack->getCurrentRequest()->getClientIp();
    $cid = "ai_chat_rate_limit:$ip";

    if ($cache = $this->cache->get($cid)) {
      $data = $cache->data;
      $data['count']++;

      if ($data['count'] > self::REQUEST_COUNT_LIMIT) {
        $this->loggerFactory->get('ai_chat:ChatRateLimiter')->error('Rate limit for user with IP Address: @ip_address exceeded.', [
          '@ip_address' => $this->requestStack->getCurrentRequest()->getClientIp(),
        ]);
        return FALSE;
      }

      $this->cache->set($cid, $data, time() + self::REQUEST_SECONDS_LIMIT);
      return TRUE;
    }

    $this->cache->set($cid, ['count' => 1], time() + self::REQUEST_SECONDS_LIMIT);
    return TRUE;
  }

}
