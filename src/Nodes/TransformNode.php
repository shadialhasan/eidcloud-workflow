<?php

declare(strict_types=1);

namespace EidCloud\Workflow\Nodes;

use EidCloud\Workflow\State\ExecutionContext;
use InvalidArgumentException;

/**
 * Transforms data: JSON encoding/decoding, mapping, filtering, math operations,
 * string formatting, and aggregation.
 */
class TransformNode extends AbstractNode
{
    public function execute(ExecutionContext $context): mixed
    {
        $operation = $this->config['operation'] ?? 'map';

        return match ($operation) {
            'map' => $this->executeMap($context),
            'filter' => $this->executeFilter($context),
            'aggregate', 'reduce' => $this->executeAggregate($context),
            'json_encode' => $this->executeJsonEncode($context),
            'json_decode' => $this->executeJsonDecode($context),
            'math' => $this->executeMath($context),
            'custom' => $this->executeCustom($context),
            default => throw new InvalidArgumentException("Unsupported TransformNode operation: '{$operation}'"),
        };
    }

    protected function executeMap(ExecutionContext $context): mixed
    {
        $mapping = $this->config['mapping'] ?? [];
        $data = $this->config['data'] ?? null;

        if ($data !== null) {
            $data = $context->interpolate($data);
        }

        if (is_array($data) && array_is_list($data) && !empty($mapping)) {
            // Apply mapping to each item in the array
            $result = [];
            foreach ($data as $index => $item) {
                $subContext = new ExecutionContext(
                    array_merge($context->getInput(), ['item' => $item, 'index' => $index]),
                    $context->getSteps(),
                    $context->getVariables(),
                    $context->getEvaluator()
                );
                $result[] = $subContext->interpolate($mapping);
            }
            return $result;
        }

        // Direct object/dictionary transformation
        return $context->interpolate($mapping);
    }

    protected function executeFilter(ExecutionContext $context): array
    {
        $items = $context->interpolate($this->config['items'] ?? $this->config['data'] ?? []);
        if (!is_array($items)) {
            return [];
        }

        $field = $this->config['field'] ?? null;
        $operator = $this->config['operator'] ?? '==';
        $targetValue = $context->interpolate($this->config['value'] ?? null);

        $filtered = array_filter($items, function ($item) use ($field, $operator, $targetValue) {
            $val = is_array($item) ? ($item[$field] ?? null) : $item;
            return match ($operator) {
                '==', 'eq' => $val == $targetValue,
                '!=', 'neq' => $val != $targetValue,
                '>', 'gt' => $val > $targetValue,
                '>=', 'gte' => $val >= $targetValue,
                '<', 'lt' => $val < $targetValue,
                '<=', 'lte' => $val <= $targetValue,
                'contains' => is_string($val) && str_contains($val, (string) $targetValue),
                default => (bool) $val,
            };
        });

        return array_values($filtered);
    }

    protected function executeAggregate(ExecutionContext $context): mixed
    {
        $items = $context->interpolate($this->config['items'] ?? $this->config['data'] ?? []);
        if (!is_array($items) || empty($items)) {
            return 0;
        }

        $type = $this->config['aggregate_type'] ?? 'sum'; // sum, avg, count, min, max
        $field = $this->config['field'] ?? null;

        $values = [];
        foreach ($items as $item) {
            if ($field !== null && is_array($item)) {
                $values[] = (float) ($item[$field] ?? 0);
            } else {
                $values[] = (float) $item;
            }
        }

        return match ($type) {
            'count' => count($items),
            'sum' => array_sum($values),
            'avg' => count($values) > 0 ? array_sum($values) / count($values) : 0,
            'min' => count($values) > 0 ? min($values) : 0,
            'max' => count($values) > 0 ? max($values) : 0,
            default => array_sum($values),
        };
    }

    protected function executeJsonEncode(ExecutionContext $context): string
    {
        $data = $context->interpolate($this->config['data'] ?? []);
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function executeJsonDecode(ExecutionContext $context): mixed
    {
        $json = (string) $context->interpolate($this->config['json'] ?? $this->config['data'] ?? '');
        return json_decode($json, true);
    }

    protected function executeMath(ExecutionContext $context): float|int
    {
        $expr = (string) $context->interpolate($this->config['expression'] ?? '0');
        $sanitized = preg_replace('/[^0-9\.\+\-\*\/\%\(\)\s]/', '', $expr);
        if (trim($sanitized) === '') {
            return 0;
        }

        try {
            $tokens = preg_split('/([+\\-*\\/()%])|\\s+/', $sanitized, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
            $pos = 0;

            $parseFactor = function() use (&$parseExpr, &$parseFactor, &$tokens, &$pos): float {
                if ($pos >= count($tokens)) return 0.0;
                $t = $tokens[$pos++];
                if ($t === '+') return +$parseFactor();
                if ($t === '-') return -$parseFactor();
                if ($t === '(') {
                    $val = $parseExpr();
                    if ($pos < count($tokens) && $tokens[$pos] === ')') $pos++;
                    return $val;
                }
                return is_numeric($t) ? (float)$t : 0.0;
            };

            $parseTerm = function() use (&$parseTerm, &$parseFactor, &$tokens, &$pos): float {
                $val = $parseFactor();
                while ($pos < count($tokens) && in_array($tokens[$pos], ['*', '/', '%'], true)) {
                    $op = $tokens[$pos++];
                    $rhs = $parseFactor();
                    if ($op === '*') $val *= $rhs;
                    elseif ($op === '/') {
                        if ($rhs != 0.0) $val /= $rhs;
                    } elseif ($op === '%') {
                        if ($rhs != 0.0) $val = fmod($val, $rhs);
                    }
                }
                return $val;
            };

            $parseExpr = function() use (&$parseExpr, &$parseTerm, &$tokens, &$pos): float {
                $val = $parseTerm();
                while ($pos < count($tokens) && in_array($tokens[$pos], ['+', '-'], true)) {
                    $op = $tokens[$pos++];
                    $rhs = $parseTerm();
                    $val = $op === '+' ? $val + $rhs : $val - $rhs;
                }
                return $val;
            };

            $val = $parseExpr();
            return (floor($val) === $val) ? (int)$val : $val;
        } catch (\Throwable) {
            return 0;
        }
    }

    protected function executeCustom(ExecutionContext $context): mixed
    {
        $template = $this->config['template'] ?? null;
        if ($template !== null) {
            return $context->interpolate($template);
        }
        return null;
    }
}
