<?php

declare(strict_types=1);

namespace Drupal\ai_chat;

use OpenAI\Contracts\ClientContract;

/**
 * Builds OpenAI clients.
 *
 * Kept as a service so the chat service can be handed the library's
 * ClientFake in tests.
 */
class OpenAiClientFactory {

  /**
   * Creates a client for the given OpenAI account.
   *
   * @param string $api_key
   *   The OpenAI API key.
   * @param string|null $organization_id
   *   The OpenAI organization ID, if any.
   *
   * @return \OpenAI\Contracts\ClientContract
   *   The OpenAI client.
   */
  public function create(string $api_key, ?string $organization_id = NULL): ClientContract {
    return \OpenAI::client($api_key, $organization_id);
  }

}
