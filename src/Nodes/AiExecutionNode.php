<?php

declare(strict_types=1);

namespace EidCloud\Workflow\Nodes;

use EidCloud\Workflow\State\ExecutionContext;
use RuntimeException;

/**
 * Executes AI LLM completions and prompt chains with OpenAI/Anthropic/Gemini/Ollama compatible endpoints,
 * or mock offline completions. Zero vendor dependencies using native HTTP requests.
 */
class AiExecutionNode extends AbstractNode
{
    public function execute(ExecutionContext $context): mixed
    {
        // Support offline mock response for testing or offline environments
        if (isset($this->config['mock_response'])) {
            $mock = $context->interpolate($this->config['mock_response']);
            return is_array($mock) ? $mock : ['text' => (string) $mock, 'tokens' => 42];
        }

        $provider = strtolower((string) ($this->config['provider'] ?? 'openai'));
        $model = (string) $context->interpolate($this->config['model'] ?? 'gpt-4o-mini');
        $prompt = (string) $context->interpolate($this->config['prompt'] ?? '');
        $systemPrompt = (string) $context->interpolate($this->config['system'] ?? 'You are a workflow AI assistant.');
        $apiKey = (string) $context->interpolate($this->config['api_key'] ?? getenv('AI_API_KEY') ?: '');
        $temperature = (float) ($this->config['temperature'] ?? 0.7);
        $maxTokens = (int) ($this->config['max_tokens'] ?? 1024);

        if (empty($prompt)) {
            throw new RuntimeException("AiExecutionNode '{$this->id}' requires a 'prompt' configuration.");
        }

        $endpoint = (string) $context->interpolate($this->config['endpoint'] ?? '');
        if (empty($endpoint)) {
            $endpoint = match ($provider) {
                'openai' => 'https://api.openai.com/v1/chat/completions',
                'anthropic' => 'https://api.anthropic.com/v1/messages',
                'ollama' => 'http://localhost:11434/api/generate',
                default => 'https://api.openai.com/v1/chat/completions',
            };
        }

        // Construct standard OpenAI-compatible payload unless custom provider format specified
        $headers = [
            'Content-Type: application/json',
        ];

        if ($provider === 'anthropic') {
            $headers[] = "x-api-key: {$apiKey}";
            $headers[] = 'anthropic-version: 2023-06-01';
            $payload = [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => $temperature,
            ];
        } elseif ($provider === 'ollama') {
            $payload = [
                'model' => $model,
                'prompt' => $prompt,
                'system' => $systemPrompt,
                'stream' => false,
            ];
        } else {
            // Default OpenAI compatible format
            if (!empty($apiKey)) {
                $headers[] = "Authorization: Bearer {$apiKey}";
            }
            $payload = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ];
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $httpNode = new HttpNode($this->id . '_http', 'http', [
            'url' => $endpoint,
            'method' => 'POST',
            'headers' => $headers,
            'body' => $jsonPayload,
            'timeout' => $this->config['timeout'] ?? 60,
        ]);

        $response = $httpNode->execute($context);
        $data = $response['data'] ?? [];

        // Parse standard LLM responses
        $extractedText = '';
        if (isset($data['choices'][0]['message']['content'])) {
            $extractedText = $data['choices'][0]['message']['content'];
        } elseif (isset($data['content'][0]['text'])) {
            $extractedText = $data['content'][0]['text'];
        } elseif (isset($data['response'])) {
            $extractedText = $data['response'];
        } else {
            $extractedText = is_string($response['raw']) ? $response['raw'] : json_encode($data);
        }

        return [
            'text' => trim($extractedText),
            'model' => $model,
            'provider' => $provider,
            'raw' => $data,
        ];
    }
}
