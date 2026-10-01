<?php

declare(strict_types=1);

namespace EidCloud\Workflow\Parser;

use InvalidArgumentException;
use RuntimeException;

/**
 * Parses JSON and YAML workflow definitions into normalized workflow arrays.
 * Zero external vendor dependencies: includes a built-in pure PHP YAML parser for workflow definitions.
 */
class WorkflowParser
{
    /**
     * Parse workflow definition from a file (.json, .yaml, .yml).
     */
    public function parseFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("Workflow file not found: '{$filePath}'");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException("Unable to read workflow file: '{$filePath}'");
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension === 'json') {
            return $this->parseJson($content);
        }

        if (in_array($extension, ['yaml', 'yml'], true)) {
            return $this->parseYaml($content);
        }

        // Try JSON first, then YAML
        try {
            return $this->parseJson($content);
        } catch (\Throwable) {
            return $this->parseYaml($content);
        }
    }

    /**
     * Parse workflow definition from JSON string.
     */
    public function parseJson(string $jsonString): array
    {
        $data = json_decode($jsonString, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException("Invalid JSON: " . json_last_error_msg());
        }

        return $this->normalizeWorkflow($data);
    }

    /**
     * Parse workflow definition from YAML string (Pure PHP zero-dependency parser).
     */
    public function parseYaml(string $yamlString): array
    {
        // If ext-yaml is available, use it; otherwise use pure-PHP parser
        if (function_exists('yaml_parse')) {
            $parsed = @yaml_parse($yamlString);
            if (is_array($parsed)) {
                return $this->normalizeWorkflow($parsed);
            }
        }

        $data = $this->parseBasicYaml($yamlString);
        return $this->normalizeWorkflow($data);
    }

    /**
     * Lightweight recursive pure PHP YAML parser supporting mappings, lists, strings, numbers, booleans, and nested structures.
     */
    protected function parseBasicYaml(string $yaml): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $yaml);
        $cleanLines = [];

        foreach ($lines as $line) {
            // Remove full line comments and preserve indentation
            if (preg_match('/^\s*#/', $line)) {
                continue;
            }
            if (trim($line) === '') {
                continue;
            }
            $cleanLines[] = rtrim($line);
        }

        $index = 0;
        return $this->parseYamlBlock($cleanLines, $index, 0);
    }

    protected function parseYamlBlock(array $lines, int &$index, int $currentIndent): array
    {
        $result = [];
        $isList = false;

        while ($index < count($lines)) {
            $line = $lines[$index];
            $indent = strlen($line) - strlen(ltrim($line));

            if ($indent < $currentIndent) {
                break;
            }

            $trimmed = trim($line);

            // Handle list item "- value" or "- key: value"
            if (str_starts_with($trimmed, '-')) {
                $isList = true;
                $rest = trim(substr($trimmed, 1));

                if ($rest === '') {
                    // Nested list item or dictionary
                    $index++;
                    $result[] = $this->parseYamlBlock($lines, $index, $indent + 2);
                    continue;
                }

                if (str_contains($rest, ':')) {
                    // List item starting a dictionary: "- id: step1"
                    $fakeLines = [str_repeat(' ', $indent) . $rest];
                    $subIndex = $index + 1;
                    while ($subIndex < count($lines)) {
                        $subLine = $lines[$subIndex];
                        $subIndent = strlen($subLine) - strlen(ltrim($subLine));
                        if ($subIndent > $indent) {
                            $fakeLines[] = $subLine;
                            $subIndex++;
                        } else {
                            break;
                        }
                    }
                    $index = $subIndex;
                    $itemParserIdx = 0;
                    $result[] = $this->parseYamlBlock($fakeLines, $itemParserIdx, $indent);
                    continue;
                }

                $result[] = $this->parseScalar($rest);
                $index++;
                continue;
            }

            // Handle key: value
            if (str_contains($trimmed, ':')) {
                $colonPos = strpos($trimmed, ':');
                $key = trim(substr($trimmed, 0, $colonPos));
                $valuePart = trim(substr($trimmed, $colonPos + 1));

                if ($valuePart === '' || $valuePart === '|' || $valuePart === '>') {
                    // Nested mapping or list
                    $index++;
                    $sub = $this->parseYamlBlock($lines, $index, $indent + 2);
                    $result[$key] = $sub;
                    continue;
                }

                $result[$key] = $this->parseScalar($valuePart);
                $index++;
                continue;
            }

            $index++;
        }

        return $result;
    }

    protected function parseScalar(string $val): mixed
    {
        $val = trim($val);

        if (($val[0] === '"' && str_ends_with($val, '"')) || ($val[0] === "'" && str_ends_with($val, "'"))) {
            return substr($val, 1, -1);
        }

        $lower = strtolower($val);
        if ($lower === 'true' || $lower === 'yes' || $lower === 'on') {
            return true;
        }
        if ($lower === 'false' || $lower === 'no' || $lower === 'off') {
            return false;
        }
        if ($lower === 'null' || $val === '~') {
            return null;
        }
        if (is_numeric($val)) {
            return str_contains($val, '.') ? (float) $val : (int) $val;
        }

        // Inline JSON array or object
        if ((str_starts_with($val, '[') && str_ends_with($val, ']')) || (str_starts_with($val, '{') && str_ends_with($val, '}'))) {
            $decoded = json_decode($val, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $val;
    }

    /**
     * Normalize workflow schema.
     */
    protected function normalizeWorkflow(array $data): array
    {
        $name = $data['name'] ?? $data['id'] ?? 'unnamed_workflow';
        $steps = $data['steps'] ?? [];

        // If steps are dictionary with stepId as key, convert to ordered list
        if (!array_is_list($steps)) {
            $normalizedSteps = [];
            foreach ($steps as $id => $stepDef) {
                $stepDef['id'] = $stepDef['id'] ?? $id;
                $normalizedSteps[] = $stepDef;
            }
            $steps = $normalizedSteps;
        }

        return [
            'id' => (string) ($data['id'] ?? $name),
            'name' => (string) $name,
            'description' => (string) ($data['description'] ?? ''),
            'version' => (string) ($data['version'] ?? '1.0.0'),
            'inputs' => $data['inputs'] ?? $data['input'] ?? [],
            'steps' => $steps,
            'retries' => (int) ($data['retries'] ?? 0),
            'timeout' => (int) ($data['timeout'] ?? 300),
            'error_handler' => $data['error_handler'] ?? null,
        ];
    }
}
