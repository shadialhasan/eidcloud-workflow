<?php

declare(strict_types=1);

namespace EidCloud\Workflow\Engine;

use EidCloud\Workflow\Nodes\NodeFactory;
use EidCloud\Workflow\Nodes\NodeInterface;
use EidCloud\Workflow\Parser\WorkflowParser;
use EidCloud\Workflow\State\ExecutionContext;
use EidCloud\Workflow\State\ExecutionState;
use EidCloud\Workflow\State\FileStatePersistence;
use EidCloud\Workflow\State\StatePersistenceInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Core Workflow Automation Engine.
 * Features:
 * - Declarative execution of nodes
 * - State machine checkpointing & resume capabilities
 * - Variable interpolation
 * - Conditional branching (If/Else, Switch)
 * - Parallel execution & loop iteration
 * - Automatic retry with jitter and timeout monitoring
 */
class WorkflowEngine
{
    protected StatePersistenceInterface $persistence;
    protected WorkflowParser $parser;

    /**
     * @var array<string, callable> Event hooks
     */
    protected array $eventListeners = [];

    public function __construct(
        ?StatePersistenceInterface $persistence = null,
        ?WorkflowParser $parser = null
    ) {
        $this->persistence = $persistence ?? new FileStatePersistence();
        $this->parser = $parser ?? new WorkflowParser();
    }

    public function getPersistence(): StatePersistenceInterface
    {
        return $this->persistence;
    }

    public function getParser(): WorkflowParser
    {
        return $this->parser;
    }

    /**
     * Register workflow event listener (e.g., 'onStepStart', 'onStepSuccess', 'onStepFailure').
     */
    public function on(string $event, callable $listener): self
    {
        $this->eventListeners[$event][] = $listener;
        return $this;
    }

    protected function trigger(string $event, mixed ...$args): void
    {
        if (isset($this->eventListeners[$event])) {
            foreach ($this->eventListeners[$event] as $listener) {
                $listener(...$args);
            }
        }
    }

    /**
     * Validate workflow schema structure and node validity.
     *
     * @return array{valid: bool, errors: array<string>}
     */
    public function validate(array|string $workflow): array
    {
        $errors = [];
        try {
            $def = is_string($workflow) ? $this->parser->parseFile($workflow) : $workflow;

            if (empty($def['id']) && empty($def['name'])) {
                $errors[] = "Workflow missing 'id' or 'name'.";
            }

            if (empty($def['steps']) || !is_array($def['steps'])) {
                $errors[] = "Workflow must contain at least one step in 'steps'.";
            } else {
                $stepIds = [];
                foreach ($def['steps'] as $index => $step) {
                    if (empty($step['id'])) {
                        $errors[] = "Step at index {$index} missing 'id'.";
                    } else {
                        if (in_array($step['id'], $stepIds, true)) {
                            $errors[] = "Duplicate step id '{$step['id']}' at index {$index}.";
                        }
                        $stepIds[] = $step['id'];
                    }

                    if (empty($step['type'])) {
                        $errors[] = "Step '{$step['id']}' missing 'type'.";
                    }
                }
            }
        } catch (Throwable $e) {
            $errors[] = "Parse error: " . $e->getMessage();
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Run a workflow definition with inputs.
     *
     * @param array|string $workflow Array definition or file path (.json, .yaml)
     * @param array<string, mixed> $input Initial input variables
     * @param string|null $resumeExecutionId If provided, resumes state from checkpoint
     * @return ExecutionState
     */
    public function run(
        array|string $workflow,
        array $input = [],
        ?string $resumeExecutionId = null
    ): ExecutionState {
        $definition = is_string($workflow) ? $this->parser->parseFile($workflow) : $workflow;
        $workflowId = (string) ($definition['id'] ?? $definition['name'] ?? 'workflow_' . uniqid());

        // Resume or create new state
        if ($resumeExecutionId !== null) {
            $state = $this->persistence->load($resumeExecutionId);
            if (!$state) {
                throw new InvalidArgumentException("Execution state '{$resumeExecutionId}' not found to resume.");
            }
            // Merge additional input if provided
            if (!empty($input)) {
                $state = new ExecutionState(
                    $state->getExecutionId(),
                    $state->getWorkflowId(),
                    array_merge($state->getInput(), $input),
                    ExecutionState::STATUS_RUNNING
                );
            } else {
                $state->setStatus(ExecutionState::STATUS_RUNNING);
            }
        } else {
            $executionId = $workflowId . '_' . bin2hex(random_bytes(6));
            $state = new ExecutionState($executionId, $workflowId, $input, ExecutionState::STATUS_RUNNING);
        }

        $this->persistence->save($state);

        // Build execution context
        $steps = $definition['steps'] ?? [];
        $stepsById = [];
        foreach ($steps as $stepDef) {
            $stepsById[$stepDef['id']] = $stepDef;
        }

        $context = new ExecutionContext(
            $state->getInput(),
            $state->getStepResults()
        );

        $this->trigger('onWorkflowStart', $state, $definition);

        $stepPointer = 0;
        $stepKeys = array_keys($stepsById);
        $totalSteps = count($stepKeys);

        while ($stepPointer < $totalSteps) {
            $currentStepId = $stepKeys[$stepPointer];
            $stepDef = $stepsById[$currentStepId];

            // If already completed in resumed state, skip to next
            if ($state->isStepCompleted($currentStepId)) {
                $stepPointer++;
                continue;
            }

            $node = NodeFactory::create($stepDef);
            $this->trigger('onStepStart', $node, $context, $state);

            // Execute step with retry, jitter, and timeout configuration
            try {
                $output = $this->executeNodeWithRetries($node, $stepDef, $context);
                $state->recordStepSuccess($currentStepId, $output);
                $context->setStepOutput($currentStepId, $output);

                // Save checkpoint immediately after step completion
                $this->persistence->save($state);
                $this->trigger('onStepSuccess', $node, $output, $state);

                // Handle conditional branching
                if (is_array($output) && isset($output['nextStep']) && !empty($output['nextStep'])) {
                    $targetStep = $output['nextStep'];
                    if (isset($stepsById[$targetStep])) {
                        $targetIndex = array_search($targetStep, $stepKeys, true);
                        if ($targetIndex !== false) {
                            $stepPointer = $targetIndex;
                            continue;
                        }
                    } elseif ($targetStep === 'END' || $targetStep === 'STOP') {
                        break;
                    }
                }

                $stepPointer++;
            } catch (Throwable $e) {
                $errorMessage = "Step '{$currentStepId}' failed: " . $e->getMessage();
                $state->recordStepFailure($currentStepId, $errorMessage);
                $this->persistence->save($state);
                $this->trigger('onStepFailure', $node, $e, $state);

                // Check for fallback error handler step
                $errorHandler = $stepDef['on_error'] ?? $definition['error_handler'] ?? null;
                if ($errorHandler !== null && isset($stepsById[$errorHandler])) {
                    $handlerNode = NodeFactory::create($stepsById[$errorHandler]);
                    try {
                        $fallbackOutput = $handlerNode->execute($context);
                        $state->recordStepSuccess($errorHandler, $fallbackOutput);
                        $this->persistence->save($state);
                    } catch (Throwable) {
                        // Keep failed state
                    }
                }

                return $state;
            }
        }

        $state->setStatus(ExecutionState::STATUS_COMPLETED);
        $this->persistence->save($state);
        $this->trigger('onWorkflowComplete', $state);

        return $state;
    }

    /**
     * Executes a node with retry policies and exponential backoff with jitter.
     */
    protected function executeNodeWithRetries(NodeInterface $node, array $stepDef, ExecutionContext $context): mixed
    {
        $maxRetries = (int) ($stepDef['retries'] ?? $stepDef['retry'] ?? 0);
        $backoffMs = (int) ($stepDef['retry_backoff_ms'] ?? 50);
        $attempts = 0;

        while (true) {
            $attempts++;
            try {
                return $node->execute($context);
            } catch (Throwable $e) {
                if ($attempts > $maxRetries) {
                    throw $e;
                }

                // Exponential backoff with jitter
                $jitter = rand(10, 50);
                $delayUs = ($backoffMs * (2 ** ($attempts - 1)) + $jitter) * 1000;
                usleep((int) min($delayUs, 5000000)); // Cap delay at 5s
            }
        }
    }
}
