<?php

declare(strict_types=1);

namespace Drupal\ai_chat_langfuse\EventSubscriber;

use Drupal\ai_chat\Event\ObservabilityGenerationEvent;
use Drupal\ai_chat\Event\ObservabilityTraceEvent;
use Drupal\ai_chat_langfuse\LangfuseService;
use Drupal\Core\Queue\QueueFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Defines the ObservabilitySubscriber class.
 */
final class ObservabilitySubscriber implements EventSubscriberInterface {

  /**
   * Constructs a ObservabilitySubscriber object.
   */
  public function __construct(
    #[Autowire(service: 'ai_chat_langfuse.langfuse_service')]
    protected LangfuseService $langfuseService,
    #[Autowire(service: 'queue')]
    protected QueueFactory $queue,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ObservabilityTraceEvent::OBSERVABILITY_TRACE => 'onTraceObservation',
      ObservabilityGenerationEvent::OBSERVABILITY_GENERATION => 'onGenerationObservation',
    ];
  }

  /**
   * Handles on trace observation event.
   *
   * @param \Drupal\ai_chat\Event\ObservabilityTraceEvent $event
   *   The trace event.
   */
  public function onTraceObservation(ObservabilityTraceEvent $event): void {
    $trace_data = $this->langfuseService->buildTraceData(
      $event->getTraceName(),
      $event->getTraceId(),
      [
        'request_ip' => $event->getRequestIp(),
        'user_agent' => $event->getUserAgent(),
      ],
      $event->getUserId(),
    );
    $this->queue->get('ai_chat_langfuse_queue')->createItem($trace_data);
  }

  /**
   * Handles on generation observation event.
   *
   * @param \Drupal\ai_chat\Event\ObservabilityGenerationEvent $event
   *   The generation event.
   */
  public function onGenerationObservation(ObservabilityGenerationEvent $event): void {
    $trace_data = $this->langfuseService->buildGenerationData(
      $event->getTraceId(),
      $event->getGenerationName(),
      $event->getMessages(),
      $event->getResponseData(),
      $event->getUsage(),
      $event->getStartTime(),
      $event->getEndTime(),
      $event->getModel(),
      $event->getModelParams(),
    );
    $this->queue->get('ai_chat_langfuse_queue')->createItem($trace_data);
  }

}
