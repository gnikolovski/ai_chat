<?php

declare(strict_types=1);

namespace Drupal\ai_chat_langfuse\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_chat_langfuse\LangfuseService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines 'ai_chat_langfuse_queue' queue worker.
 */
#[QueueWorker(
  id: 'ai_chat_langfuse_queue',
  title: new TranslatableMarkup('Langfuse ingestion'),
  cron: ['time' => 60],
)]
final class LangfuseQueue extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a new LangfuseQueue instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly LangfuseService $aiChatLangfuseService,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai_chat_langfuse.langfuse_service'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $this->aiChatLangfuseService->sendEvent([$data]);
  }

}
