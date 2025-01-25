<?php

declare(strict_types=1);

namespace Drupal\ai_chat\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Defines the AiChatSettingsForm class.
 */
final class AiChatSettingsForm extends ConfigFormBase {

  /**
   * {@inheritDoc}
   */
  protected function getEditableConfigNames(): array {
    return [
      'ai_chat.settings',
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function getFormId(): string {
    return 'ai_chat_settings';
  }

  /**
   * {@inheritDoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('ai_chat.settings');

    $form['openai'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Open AI'),
    ];

    $form['openai']['openai_organization_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organization ID'),
      '#default_value' => $config->get('openai_organization_id') ?? '',
      '#required' => TRUE,
    ];

    $form['openai']['openai_api_key'] = [
      '#type' => 'textarea',
      '#title' => $this->t('OpenAI API key'),
      '#default_value' => $config->get('openai_api_key') ?? '',
      '#required' => TRUE,
    ];

    $form['openai']['openai_embedding_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Embedding model'),
      '#description' => $this->t('An embedding model converts input text or data into numerical vectors, capturing semantic meaning to enable tasks like similarity comparison, clustering, and search.'),
      '#options' => [
        'text-embedding-3-small' => $this->t('text-embedding-3-small'),
        'text-embedding-3-large' => $this->t('text-embedding-3-large'),
      ],
      '#default_value' => $config->get('openai_embedding_model') ?? '',
      '#required' => TRUE,
    ];

    $form['openai']['openai_chat_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Chat model'),
      '#description' => $this->t('Select the OpenAI chat model to use for generating responses. GPT-4o is the most capable model for complex tasks, while GPT-4o-mini offers faster performance for simpler tasks at a lower cost.'),
      '#options' => [
        'gpt-4o-mini' => $this->t('GPT-4o-mini'),
        'gpt-4o' => $this->t('GPT-4o'),
      ],
      '#default_value' => $config->get('openai_chat_model') ?? '',
      '#required' => TRUE,
    ];

    $form['openai']['openai_system_instructions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System instructions'),
      '#default_value' => $config->get('openai_system_instructions') ?? '',
      '#required' => TRUE,
    ];

    $form['openai']['openai_temperature'] = [
      '#type' => 'select',
      '#options' => [
        '0' => '0',
        '0.1' => '0.1',
        '0.2' => '0.2',
        '0.3' => '0.3',
        '0.4' => '0.4',
        '0.5' => '0.5',
        '0.6' => '0.6',
        '0.7' => '0.7',
        '0.8' => '0.8',
        '0.9' => '0.9',
        '1' => '1',
      ],
      '#title' => $this->t('Temperature'),
      '#description' => $this->t('Higher values like 0.8 will make the output more random, while lower values like 0.2 will make it more focused and deterministic.'),
      '#default_value' => $config->get('openai_temperature') ?? '0.2',
      '#required' => TRUE,
    ];

    $form['openai']['openai_max_completion_tokens'] = [
      '#type' => 'number',
      '#min' => 100,
      '#max' => 4000,
      '#step' => 100,
      '#title' => $this->t('Max completion tokens'),
      '#description' => $this->t('An upper bound for the number of tokens that can be generated for a completion.'),
      '#default_value' => $config->get('openai_max_completion_tokens'),
    ];

    $form['qdrant'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Qdrant'),
    ];

    $form['qdrant']['qdrant_host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Qdrant host'),
      '#default_value' => $config->get('qdrant_host') ?? '',
      '#required' => TRUE,
    ];

    $form['qdrant']['qdrant_port'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Qdrant port'),
      '#default_value' => $config->get('qdrant_port') ?? '',
      '#required' => TRUE,
    ];

    $form['qdrant']['qdrant_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Qdrant API key'),
      '#default_value' => $config->get('qdrant_api_key') ?? '',
      '#required' => TRUE,
    ];

    $form['qdrant']['qdrant_collection_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Qdrant collection name'),
      '#default_value' => $config->get('qdrant_collection_name') ?? '',
      '#required' => TRUE,
    ];

    $form['langfuse'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Langfuse'),
    ];

    $form['langfuse']['langfuse_host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Host'),
      '#default_value' => $config->get('langfuse_host') ?? '',
      '#required' => TRUE,
    ];

    $form['langfuse']['langfuse_secret_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Secret key'),
      '#default_value' => $config->get('langfuse_secret_key') ?? '',
      '#required' => TRUE,
    ];

    $form['langfuse']['langfuse_public_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Public key'),
      '#default_value' => $config->get('langfuse_public_key') ?? '',
      '#required' => TRUE,
    ];

    $form['raq'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Retrieval augmented generation (RAG)'),
    ];

    $form['raq']['combined_messages_count'] = [
      '#type' => 'number',
      '#step' => 1,
      '#min' => 1,
      '#max' => 10,
      '#title' => $this->t('Number of messages to combine for context'),
      '#default_value' => $config->get('combined_messages_count') ?? '',
      '#required' => TRUE,
    ];

    $form['raq']['top_k'] = [
      '#type' => 'select',
      '#options' => [
        '1' => '1',
        '2' => '2',
        '3' => '3',
        '4' => '4',
        '5' => '5',
        '6' => '6',
        '7' => '7',
        '8' => '8',
        '9' => '9',
        '10' => '10',
      ],
      '#title' => $this->t('Top K'),
      '#description' => $this->t('In the context of Retrieval-Augmented Generation (RAG) applications, top-k refers to selecting the k most relevant documents or passages from a retrieval system (like a search or database) based on their similarity or relevance to a query. These top-k documents are then used as context for generating more accurate and informed responses in tasks such as question answering or summarization.'),
      '#default_value' => $config->get('top_k') ?? '3',
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritDoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('ai_chat.settings');
    $form_state->cleanValues();
    $values = $form_state->getValues();
    $config->setData($values);
    $config->save();

    parent::submitForm($form, $form_state);
  }

}
