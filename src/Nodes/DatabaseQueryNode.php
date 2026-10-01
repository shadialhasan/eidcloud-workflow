<?php

declare(strict_types=1);

namespace EidCloud\Workflow\Nodes;

use EidCloud\Workflow\State\ExecutionContext;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Executes Database Queries via PDO (SQLite, MySQL, PostgreSQL, etc.).
 * Supports parameterized queries, variable interpolation, and in-memory test databases.
 */
class DatabaseQueryNode extends AbstractNode
{
    public function execute(ExecutionContext $context): mixed
    {
        $dsn = (string) $context->interpolate($this->config['dsn'] ?? 'sqlite::memory:');
        $username = isset($this->config['username']) ? (string) $context->interpolate($this->config['username']) : null;
        $password = isset($this->config['password']) ? (string) $context->interpolate($this->config['password']) : null;
        $options = $this->config['options'] ?? [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        // Support mock handler if provided
        if (isset($this->config['mock_rows'])) {
            return [
                'rows' => $context->interpolate($this->config['mock_rows']),
                'count' => count((array) $this->config['mock_rows']),
                'affected' => 0,
            ];
        }

        $query = (string) $context->interpolate($this->config['query'] ?? '');
        if (trim($query) === '') {
            throw new InvalidArgumentException("DatabaseQueryNode '{$this->id}' requires a 'query' configuration.");
        }

        $params = $this->config['params'] ?? [];
        if (is_array($params)) {
            $params = $context->interpolate($params);
        }

        try {
            $pdo = new PDO($dsn, $username, $password, $options);
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);

            $isSelect = preg_match('/^\s*(SELECT|PRAGMA|SHOW|DESCRIBE|EXPLAIN)\b/i', $query);

            if ($isSelect) {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                return [
                    'rows' => $rows,
                    'count' => count($rows),
                    'affected' => $stmt->rowCount(),
                ];
            }

            return [
                'rows' => [],
                'count' => 0,
                'affected' => $stmt->rowCount(),
                'lastInsertId' => $pdo->lastInsertId(),
            ];
        } catch (\PDOException $e) {
            throw new RuntimeException("DatabaseQueryNode '{$this->id}' failed: " . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
