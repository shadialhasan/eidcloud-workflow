<?php

declare(strict_types=1);

namespace EidCloud\Workflow\Nodes;

use EidCloud\Workflow\State\ExecutionContext;
use InvalidArgumentException;

/**
 * Handles Conditional Branching (If/Else, Switch-case, Multi-condition comparisons).
 * Evaluates rules and determines the next step ID to branch to.
 */
class ConditionNode extends AbstractNode
{
    public function execute(ExecutionContext $context): mixed
    {
        // Check if switch mode is enabled
        if (isset($this->config['switch'])) {
            return $this->evaluateSwitch($context);
        }

        // Standard if/else condition mode
        return $this->evaluateIfElse($context);
    }

    protected function evaluateIfElse(ExecutionContext $context): array
    {
        $condition = $this->config['condition'] ?? $this->config['if'] ?? null;
        $thenStep = $this->config['then'] ?? null;
        $elseStep = $this->config['else'] ?? null;

        $isMet = $this->checkCondition($condition, $context);

        $nextStep = $isMet ? $thenStep : $elseStep;

        return [
            'conditionMet' => $isMet,
            'selectedBranch' => $isMet ? 'then' : 'else',
            'nextStep' => $nextStep,
        ];
    }

    protected function evaluateSwitch(ExecutionContext $context): array
    {
        $value = $context->interpolate($this->config['switch']['value'] ?? null);
        $cases = $this->config['switch']['cases'] ?? [];
        $default = $this->config['switch']['default'] ?? null;

        foreach ($cases as $caseKey => $caseTarget) {
            $interpolatedKey = $context->interpolate($caseKey);
            if ($value == $interpolatedKey) {
                return [
                    'conditionMet' => true,
                    'matchedCase' => $caseKey,
                    'nextStep' => $caseTarget,
                ];
            }
        }

        return [
            'conditionMet' => false,
            'matchedCase' => 'default',
            'nextStep' => $default,
        ];
    }

    protected function checkCondition(mixed $condition, ExecutionContext $context): bool
    {
        if (is_bool($condition)) {
            return $condition;
        }

        if (is_string($condition)) {
            $interpolated = $context->interpolate($condition);
            if (is_bool($interpolated)) {
                return $interpolated;
            }
            if (is_string($interpolated)) {
                // If expression string like "val == 'approved'"
                return $this->evaluateExpressionString($interpolated);
            }
            return (bool) $interpolated;
        }

        if (is_array($condition)) {
            // Rule structure: ['field' => '...', 'operator' => '==', 'value' => '...']
            if (isset($condition['operator'])) {
                return $this->compareRule($condition, $context);
            }

            // Logical AND/OR grouping: ['and' => [...]] or ['or' => [...]]
            if (isset($condition['and']) && is_array($condition['and'])) {
                foreach ($condition['and'] as $subCondition) {
                    if (!$this->checkCondition($subCondition, $context)) {
                        return false;
                    }
                }
                return true;
            }

            if (isset($condition['or']) && is_array($condition['or'])) {
                foreach ($condition['or'] as $subCondition) {
                    if ($this->checkCondition($subCondition, $context)) {
                        return true;
                    }
                }
                return false;
            }
        }

        return (bool) $condition;
    }

    protected function compareRule(array $rule, ExecutionContext $context): bool
    {
        $left = $context->interpolate($rule['field'] ?? $rule['left'] ?? null);
        $operator = $rule['operator'] ?? '==';
        $right = $context->interpolate($rule['value'] ?? $rule['right'] ?? null);

        return match ($operator) {
            '==', 'eq' => $left == $right,
            '===', 'identical' => $left === $right,
            '!=', '<>', 'neq' => $left != $right,
            '!==', 'not_identical' => $left !== $right,
            '>', 'gt' => $left > $right,
            '>=', 'gte' => $left >= $right,
            '<', 'lt' => $left < $right,
            '<=', 'lte' => $left <= $right,
            'contains' => is_string($left) && is_string($right) && str_contains($left, $right),
            'not_contains' => is_string($left) && is_string($right) && !str_contains($left, $right),
            'in' => is_array($right) && in_array($left, $right, false),
            'not_in' => is_array($right) && !in_array($left, $right, false),
            'empty' => empty($left),
            'not_empty' => !empty($left),
            'starts_with' => is_string($left) && is_string($right) && str_starts_with($left, $right),
            'ends_with' => is_string($left) && is_string($right) && str_ends_with($left, $right),
            default => throw new InvalidArgumentException("Unsupported condition operator '{$operator}'"),
        };
    }

    protected function evaluateExpressionString(string $expr): bool
    {
        $expr = trim($expr);
        if (strtolower($expr) === 'true' || $expr === '1') {
            return true;
        }
        if (strtolower($expr) === 'false' || $expr === '0' || $expr === '') {
            return false;
        }

        // Match comparison operators: ==, !=, >=, <=, >, <
        if (preg_match('/^(.+?)\s*(===|==|!==|!=|>=|<=|>|<)\s*(.+)$/', $expr, $matches)) {
            $left = trim($matches[1], " '\"");
            $op = $matches[2];
            $right = trim($matches[3], " '\"");

            return match ($op) {
                '==', '===' => $left == $right,
                '!=', '!==' => $left != $right,
                '>' => (float)$left > (float)$right,
                '>=' => (float)$left >= (float)$right,
                '<' => (float)$left < (float)$right,
                '<=' => (float)$left <= (float)$right,
                default => false,
            };
        }

        return (bool) $expr;
    }
}
