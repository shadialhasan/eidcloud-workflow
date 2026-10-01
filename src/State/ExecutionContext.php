<?php

declare(strict_types=1);

namespace EidCloud\Workflow\State;

use EidCloud\Workflow\Interpolation\ExpressionEvaluator;

/**
 * Execution Context passed to nodes containing workflow input,
 * previously executed step outputs, engine metadata, and evaluator.
 */
class ExecutionContext
{
    protected array $input;
    protected array $steps;
    protected array $variables;
    protected ExpressionEvaluator $evaluator;

    public function __construct(
        array $input = [],
        array $steps = [],
        array $variables = [],
        ?ExpressionEvaluator $evaluator = null
    ) {
        $this->input = $input;
        $this->steps = $steps;
        $this->variables = $variables;
        $this->evaluator = $evaluator ?? new ExpressionEvaluator();
    }

    public function getInput(): array
    {
        return $this->input;
    }

    public function getSteps(): array
    {
        return $this->steps;
    }

    public function getStepOutput(string $stepId): mixed
    {
        return $this->steps[$stepId]['output'] ?? null;
    }

    public function setStepOutput(string $stepId, mixed $output, array $metadata = []): void
    {
        $this->steps[$stepId] = array_merge([
            'id' => $stepId,
            'output' => $output,
            'timestamp' => microtime(true),
        ], $metadata);
    }

    public function getVariables(): array
    {
        return $this->variables;
    }

    public function setVariable(string $key, mixed $value): void
    {
        $this->variables[$key] = $value;
    }

    public function getEvaluator(): ExpressionEvaluator
    {
        return $this->evaluator;
    }

    /**
     * Interpolate any variable or nested structure against current state.
     */
    public function interpolate(mixed $value): mixed
    {
        return $this->evaluator->interpolate($value, $this->toArray());
    }

    /**
     * Export state dictionary for expressions.
     */
    public function toArray(): array
    {
        // Support both {{steps.stepId.field}} and {{steps.stepId.output.field}}
        $flattenedSteps = [];
        foreach ($this->steps as $stepId => $stepData) {
            $output = $stepData['output'] ?? null;
            if (is_array($output)) {
                $flattenedSteps[$stepId] = array_merge($output, [
                    'output' => $output,
                    'status' => $stepData['status'] ?? 'completed',
                ]);
            } else {
                $flattenedSteps[$stepId] = [
                    'output' => $output,
                    'data' => $output,
                    'status' => $stepData['status'] ?? 'completed',
                ];
            }
        }

        return [
            'input' => $this->input,
            'steps' => $flattenedSteps,
            'vars' => $this->variables,
            'env' => $_ENV,
        ];
    }
}
