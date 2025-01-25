<?php

declare(strict_types=1);

namespace Drupal\ai_chat\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event class for observability trace event.
 */
final class ObservabilityTraceEvent extends Event {

  /**
   * The event name.
   */
  const string OBSERVABILITY_TRACE = 'ai_chat.observability_trace';

  /**
   * Constructs a ObservabilityTraceEvent object.
   *
   * @param string $traceName
   *   The name of the trace.
   * @param string $traceId
   *   The ID of the trace.
   * @param string $userId
   *   The user ID.
   * @param string $requestIp
   *   The IP address of the request.
   * @param string $userAgent
   *   The user agent of the request.
   */
  public function __construct(
    protected string $traceName,
    protected string $traceId,
    protected string $userId,
    protected string $requestIp,
    protected string $userAgent,
  ) {}

  /**
   * Gets the trace name.
   *
   * @return string
   *   The trace name.
   */
  public function getTraceName(): string {
    return $this->traceName;
  }

  /**
   * Gets the trace ID.
   *
   * @return string
   *   The trace ID.
   */
  public function getTraceId(): string {
    return $this->traceId;
  }

  /**
   * Gets the user ID.
   *
   * @return string
   *   The user ID.
   */
  public function getUserId(): string {
    return $this->userId;
  }

  /**
   * Gets the request IP address.
   *
   * @return string
   *   The request IP address.
   */
  public function getRequestIp(): string {
    return $this->requestIp;
  }

  /**
   * Gets the user agent.
   *
   * @return string
   *   The user agent.
   */
  public function getUserAgent(): string {
    return $this->userAgent;
  }

}
