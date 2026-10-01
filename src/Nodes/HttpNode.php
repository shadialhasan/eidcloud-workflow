<?php

declare(strict_types=1);

namespace EidCloud\Workflow\Nodes;

use EidCloud\Workflow\State\ExecutionContext;
use RuntimeException;

/**
 * Executes HTTP Requests (GET, POST, PUT, DELETE, PATCH) with timeout, headers, and payload interpolation.
 * Uses native PHP curl extension or stream context if curl is unavailable (pure PHP, zero vendor dependencies).
 */
class HttpNode extends AbstractNode
{
    public function execute(ExecutionContext $context): mixed
    {
        $rawUrl = $this->config['url'] ?? '';
        if (empty($rawUrl)) {
            throw new RuntimeException("HttpNode '{$this->id}' requires a 'url' configuration.");
        }

        $url = (string) $context->interpolate($rawUrl);
        $method = strtoupper((string) ($this->config['method'] ?? 'GET'));
        $headers = $this->config['headers'] ?? [];
        $body = $this->config['body'] ?? null;
        $timeout = (int) ($this->config['timeout'] ?? 30);

        if (is_array($headers)) {
            $headers = $context->interpolate($headers);
        }

        if ($body !== null) {
            $body = $context->interpolate($body);
        }

        // Support mock handler for unit testing or offline simulation
        if (isset($this->config['mock_response'])) {
            return $context->interpolate($this->config['mock_response']);
        }

        if (function_exists('curl_init')) {
            return $this->executeCurl($url, $method, $headers, $body, $timeout);
        }

        return $this->executeStream($url, $method, $headers, $body, $timeout);
    }

    protected function executeCurl(string $url, string $method, array $headers, mixed $body, int $timeout): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $formattedHeaders = [];
        foreach ($headers as $key => $value) {
            if (is_int($key)) {
                $formattedHeaders[] = (string) $value;
            } else {
                $formattedHeaders[] = "{$key}: {$value}";
            }
        }

        if ($body !== null) {
            if (is_array($body)) {
                $bodyString = json_encode($body, JSON_UNESCAPED_SLASHES);
                $hasContentType = false;
                foreach ($formattedHeaders as $h) {
                    if (stripos($h, 'Content-Type:') === 0) {
                        $hasContentType = true;
                        break;
                    }
                }
                if (!$hasContentType) {
                    $formattedHeaders[] = 'Content-Type: application/json';
                }
            } else {
                $bodyString = (string) $body;
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyString);
        }

        if (!empty($formattedHeaders)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
        }

        $rawResponse = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false) {
            throw new RuntimeException("HttpNode curl error: {$error}");
        }

        $parsedData = json_decode((string) $rawResponse, true);
        $isJson = (json_last_error() === JSON_ERROR_NONE);

        return [
            'statusCode' => $statusCode,
            'data' => $isJson ? $parsedData : $rawResponse,
            'isJson' => $isJson,
            'raw' => (string) $rawResponse,
        ];
    }

    protected function executeStream(string $url, string $method, array $headers, mixed $body, int $timeout): array
    {
        $headerLines = [];
        foreach ($headers as $key => $value) {
            if (is_int($key)) {
                $headerLines[] = (string) $value;
            } else {
                $headerLines[] = "{$key}: {$value}";
            }
        }

        $content = null;
        if ($body !== null) {
            if (is_array($body)) {
                $content = json_encode($body, JSON_UNESCAPED_SLASHES);
                $headerLines[] = 'Content-Type: application/json';
            } else {
                $content = (string) $body;
            }
        }

        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $content,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ];

        $ctx = stream_context_create($opts);
        $rawResponse = @file_get_contents($url, false, $ctx);
        if ($rawResponse === false) {
            throw new RuntimeException("HttpNode failed to fetch URL: {$url}");
        }

        $statusCode = 200;
        if (isset($http_response_header) && is_array($http_response_header)) {
            if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $http_response_header[0], $matches)) {
                $statusCode = (int) $matches[1];
            }
        }

        $parsedData = json_decode((string) $rawResponse, true);
        $isJson = (json_last_error() === JSON_ERROR_NONE);

        return [
            'statusCode' => $statusCode,
            'data' => $isJson ? $parsedData : $rawResponse,
            'isJson' => $isJson,
            'raw' => (string) $rawResponse,
        ];
    }
}
