<?php

declare(strict_types=1);

namespace EidCloud\Workflow\State;

/**
 * Interface for saving and retrieving workflow checkpoint states.
 */
interface StatePersistenceInterface
{
    /**
     * Save an execution state.
     */
    public function save(ExecutionState $state): void;

    /**
     * Load an execution state by execution ID.
     */
    public function load(string $executionId): ?ExecutionState;

    /**
     * List execution states, optionally filtered by workflow name/ID.
     *
     * @return array<ExecutionState>
     */
    public function list(?string $workflowId = null): array;

    /**
     * Delete an execution state.
     */
    public function delete(string $executionId): bool;
}
