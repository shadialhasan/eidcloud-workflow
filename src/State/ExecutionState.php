<?php

declare(strict_types=1);

namespace EidCloud\Workflow\State;

/**
 * Represents the persistent snapshot of a workflow execution.
 */
class ExecutionState
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PAUSED = 'paused';

    protected string $executionId;
    protected string $workflowId;
    protected string $status;
    protected array $input;
    protected array $completedSteps = [];
    protected array $stepResults = [];
    protected ?string $failedStep = null;
    protected ?string $errorMessage = null;
    protected float $createdAt;
    protected float $updatedAt;
    protected ?string $lastCheckpointStep = null;

    public function __construct(
        string $executionId,
        string $workflowId,
        array $input = [],
        string $status = self::STATUS_PENDING
    ) {
        $this->executionId = $executionId;
        $this->workflowId = $workflowId;
        $this->input = $input;
        $this->status = $status;
        $this->createdAt = microtime(true);
        $this->updatedAt = $this->createdAt;
    }

    public function getExecutionId(): string
    {
        return $this->executionId;
    }

    public function getWorkflowId(): string
    {
        return $this->workflowId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->updatedAt = microtime(true);
    }

    public function getInput(): array
    {
        return $this->input;
    }

    public function getCompletedSteps(): array
    {
        return $this->completedSteps;
    }

    public function isStepCompleted(string $stepId): bool
    {
        return in_array($stepId, $this->completedSteps, true);
    }

    public function recordStepSuccess(string $stepId, mixed $result): void
    {
        if (!in_array($stepId, $this->completedSteps, true)) {
            $this->completedSteps[] = $stepId;
        }
        $this->stepResults[$stepId] = [
            'output' => $result,
            'status' => 'completed',
            'timestamp' => microtime(true),
        ];
        $this->lastCheckpointStep = $stepId;
        $this->updatedAt = microtime(true);
    }

    public function recordStepFailure(string $stepId, string $errorMessage): void
    {
        $this->failedStep = $stepId;
        $this->errorMessage = $errorMessage;
        $this->status = self::STATUS_FAILED;
        $this->stepResults[$stepId] = [
            'error' => $errorMessage,
            'status' => 'failed',
            'timestamp' => microtime(true),
        ];
        $this->updatedAt = microtime(true);
    }

    public function getStepResults(): array
    {
        return $this->stepResults;
    }

    public function getFailedStep(): ?string
    {
        return $this->failedStep;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getLastCheckpointStep(): ?string
    {
        return $this->lastCheckpointStep;
    }

    public function getCreatedAt(): float
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): float
    {
        return $this->updatedAt;
    }

    public function toArray(): array
    {
        return [
            'executionId' => $this->executionId,
            'workflowId' => $this->workflowId,
            'status' => $this->status,
            'input' => $this->input,
            'completedSteps' => $this->completedSteps,
            'stepResults' => $this->stepResults,
            'failedStep' => $this->failedStep,
            'errorMessage' => $this->errorMessage,
            'lastCheckpointStep' => $this->lastCheckpointStep,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public static function fromArray(array $data): self
    {
        $state = new self(
            $data['executionId'] ?? '',
            $data['workflowId'] ?? '',
            $data['input'] ?? [],
            $data['status'] ?? self::STATUS_PENDING
        );
        $state->completedSteps = $data['completedSteps'] ?? [];
        $state->stepResults = $data['stepResults'] ?? [];
        $state->failedStep = $data['failedStep'] ?? null;
        $state->errorMessage = $data['errorMessage'] ?? null;
        $state->lastCheckpointStep = $data['lastCheckpointStep'] ?? null;
        $state->createdAt = $data['createdAt'] ?? microtime(true);
        $state->updatedAt = $data['updatedAt'] ?? microtime(true);

        return $state;
    }
}
