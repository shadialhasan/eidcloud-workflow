<?php

declare(strict_types=1);

namespace EidCloud\Workflow\State;

/**
 * File-based state persistence storing JSON checkpoint files.
 */
class FileStatePersistence implements StatePersistenceInterface
{
    protected string $storageDirectory;

    public function __construct(?string $storageDirectory = null)
    {
        $this->storageDirectory = $storageDirectory ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eidcloud_workflow_states';
        if (!is_dir($this->storageDirectory)) {
            @mkdir($this->storageDirectory, 0777, true);
        }
    }

    public function getStorageDirectory(): string
    {
        return $this->storageDirectory;
    }

    protected function getFilePath(string $executionId): string
    {
        $safeId = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $executionId);
        return $this->storageDirectory . DIRECTORY_SEPARATOR . $safeId . '.json';
    }

    public function save(ExecutionState $state): void
    {
        $filePath = $this->getFilePath($state->getExecutionId());
        $payload = json_encode($state->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($filePath, $payload, LOCK_EX);
    }

    public function load(string $executionId): ?ExecutionState
    {
        $filePath = $this->getFilePath($executionId);
        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if (!$content) {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return null;
        }

        return ExecutionState::fromArray($data);
    }

    public function list(?string $workflowId = null): array
    {
        $results = [];
        $files = glob($this->storageDirectory . DIRECTORY_SEPARATOR . '*.json');
        if (!$files) {
            return [];
        }

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (!$content) {
                continue;
            }
            $data = json_decode($content, true);
            if (!is_array($data)) {
                continue;
            }
            $state = ExecutionState::fromArray($data);
            if ($workflowId !== null && $state->getWorkflowId() !== $workflowId) {
                continue;
            }
            $results[] = $state;
        }

        usort($results, fn(ExecutionState $a, ExecutionState $b) => $b->getUpdatedAt() <=> $a->getUpdatedAt());

        return $results;
    }

    public function delete(string $executionId): bool
    {
        $filePath = $this->getFilePath($executionId);
        if (file_exists($filePath)) {
            return unlink($filePath);
        }
        return false;
    }
}
