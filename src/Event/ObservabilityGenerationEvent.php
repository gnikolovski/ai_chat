<?php

declare(strict_types=1);

namespace Drupal\ai_chat\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event class for observability generation event.
 */
final class ObservabilityGenerationEvent extends Event {

  /**
   * The event name.
   */
  const string OBSERVABILITY_GENERATION = 'ai_chat.observability_generation';

  /**
   * Constructs a ObservabilityGenerationEvent object.
   *
   * @param string $generationName
   *   The name of the generation.
   * @param string $traceId
   *   The trace ID for the event.
   * @param array $messages
   *   The messages associated with the event.
   * @param array $responseData
   *   The response data from the generation.
   * @param array $usage
   *   The usage for the event.
   * @param string $startTime
   *   The start time of the generation.
   * @param string $endTime
   *   The end time of the generation.
   * @param string $model
   *   The model used for the generation.
   * @param array $modelParams
   *   The parameters used for the model.
   */
  public function __construct(
    protected string $generationName,
    protected string $traceId,
    protected array $messages,
    protected array $responseData,
    protected array $usage,
    protected string $startTime,
    protected string $endTime,
    protected string $model,
    protected array $modelParams,
  ) {}

  /**
   * Gets the generation name.
   *
   * @return string
   *   The generation name.
   */
  public function getGenerationName(): string {
    return $this->generationName;
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
   * Gets the messages.
   *
   * @return array
   *   The messages.
   */
  public function getMessages(): array {
    return $this->messages;
  }

  /**
   * Gets the response data.
   *
   * @return array
   *   The response data.
   */
  public function getResponseData(): array {
    return $this->responseData;
  }

  /**
   * Gets the usage.
   *
   * @return array
   *   The usage.
   */
  public function getUsage(): array {
    return $this->usage;
  }

  /**
   * Gets the start time.
   *
   * @return string
   *   The start time.
   */
  public function getStartTime(): string {
    return $this->startTime;
  }

  /**
   * Gets the end time.
   *
   * @return string
   *   The end time.
   */
  public function getEndTime(): string {
    return $this->endTime;
  }

  /**
   * Gets the model.
   *
   * @return string
   *   The model.
   */
  public function getModel(): string {
    return $this->model;
  }

  /**
   * Gets the model parameters.
   *
   * @return array
   *   The model parameters.
   */
  public function getModelParams(): array {
    return $this->modelParams;
  }

}
