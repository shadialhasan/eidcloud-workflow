<?php

declare(strict_types=1);

namespace EidCloud\Workflow\Interpolation;

/**
 * Evaluates template expressions like {{steps.step_id.key.subkey}} or {{input.userId}}
 * with support for fallback values, formatting, and nested arrays/objects.
 */
class ExpressionEvaluator
{
    /**
     * Interpolate all {{ ... }} occurrences in a string, array, or scalar value.
     */
    public function interpolate(mixed $value, array $context): mixed
    {
        if (is_string($value)) {
            return $this->interpolateString($value, $context);
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = $this->interpolate($v, $context);
            }
            return $result;
        }

        return $value;
    }

    /**
     * Interpolate placeholders in a string.
     * If the whole string is exactly `{{ expression }}`, preserve native types (int, bool, array, object).
     */
    public function interpolateString(string $template, array $context): mixed
    {
        $trimmed = trim($template);
        if (preg_match('/^\{\{\s*([^{}]+?)\s*\}\}$/', $trimmed, $singleMatch)) {
            return $this->resolvePath($singleMatch[1], $context);
        }

        return preg_replace_callback('/\{\{\s*([^{}]+?)\s*\}\}/', function ($matches) use ($context) {
            $resolved = $this->resolvePath($matches[1], $context);
            if (is_scalar($resolved) || $resolved === null) {
                return (string) $resolved;
            }
            return json_encode($resolved, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }, $template);
    }

    /**
     * Resolve a dot-notated path like `steps.fetch_user.output.data.email ?? 'guest@example.com'`.
     */
    public function resolvePath(string $expression, array $context): mixed
    {
        $expression = trim($expression);
        $defaultValue = null;
        $hasDefault = false;

        // Check for fallback operator `??`
        if (str_contains($expression, '??')) {
            $parts = explode('??', $expression, 2);
            $expression = trim($parts[0]);
            $rawDefault = trim($parts[1]);
            $hasDefault = true;
            $defaultValue = $this->parseLiteral($rawDefault);
        }

        $tokens = explode('.', $expression);
        $current = $context;

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            if (is_array($current)) {
                if (array_key_exists($token, $current)) {
                    $current = $current[$token];
                } else {
                    return $hasDefault ? $defaultValue : null;
                }
            } elseif (is_object($current)) {
                if (isset($current->{$token})) {
                    $current = $current->{$token};
                } elseif (method_exists($current, $token)) {
                    $current = $current->{$token}();
                } else {
                    return $hasDefault ? $defaultValue : null;
                }
            } else {
                return $hasDefault ? $defaultValue : null;
            }
        }

        return ($current === null && $hasDefault) ? $defaultValue : $current;
    }

    /**
     * Parse literal values for fallback values: 'hello', 42, true, false, null.
     */
    protected function parseLiteral(string $literal): mixed
    {
        $literal = trim($literal);
        if (($literal[0] === "'" && str_ends_with($literal, "'")) || ($literal[0] === '"' && str_ends_with($literal, '"'))) {
            return substr($literal, 1, -1);
        }
        if (strtolower($literal) === 'true') {
            return true;
        }
        if (strtolower($literal) === 'false') {
            return false;
        }
        if (strtolower($literal) === 'null') {
            return null;
        }
        if (is_numeric($literal)) {
            return str_contains($literal, '.') ? (float) $literal : (int) $literal;
        }

        return $literal;
    }
}
