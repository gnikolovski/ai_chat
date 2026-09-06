<?php

declare(strict_types=1);

namespace Drupal\ai_chat;

use Qdrant\Config;
use Qdrant\Http\Builder;
use Qdrant\Qdrant;

/**
 * Builds Qdrant clients.
 *
 * Kept as a service so the chat service can be handed a client with a fake
 * transport in tests.
 */
class QdrantClientFactory {

  /**
   * Creates a client for the given Qdrant server.
   *
   * @param string $host
   *   The Qdrant host.
   * @param int $port
   *   The Qdrant port.
   * @param string $api_key
   *   The Qdrant API key.
   *
   * @return \Qdrant\Qdrant
   *   The Qdrant client.
   */
  public function create(string $host, int $port, string $api_key): Qdrant {
    $config = new Config($host, $port);
    $config->setApiKey($api_key);

    return new Qdrant((new Builder())->build($config));
  }

}
