<?php

declare(strict_types=1);

namespace EidCloud\Workflow\Nodes;

use InvalidArgumentException;

/**
 * Factory class for instantiating workflow nodes by type.
 */
class NodeFactory
{
    /**
     * @var array<string, class-string<NodeInterface>>
     */
    protected static array $registry = [
        'http' => HttpNode::class,
        'http_request' => HttpNode::class,
        'database' => DatabaseQueryNode::class,
        'db' => DatabaseQueryNode::class,
        'sql' => DatabaseQueryNode::class,
        'condition' => ConditionNode::class,
        'if' => ConditionNode::class,
        'switch' => ConditionNode::class,
        'transform' => TransformNode::class,
        'ai' => AiExecutionNode::class,
        'llm' => AiExecutionNode::class,
        'loop' => LoopNode::class,
        'foreach' => LoopNode::class,
        'parallel' => ParallelNode::class,
    ];

    /**
     * Register a custom node type.
     *
     * @param string $type
     * @param class-string<NodeInterface> $nodeClass
     */
    public static function register(string $type, string $nodeClass): void
    {
        self::$registry[strtolower($type)] = $nodeClass;
    }

    /**
     * Create a node instance from definition array.
     *
     * @param array<string, mixed> $definition
     * @return NodeInterface
     */
    public static function create(array $definition): NodeInterface
    {
        $id = $definition['id'] ?? $definition['name'] ?? uniqid('node_');
        $type = strtolower((string) ($definition['type'] ?? ''));

        if (empty($type)) {
            throw new InvalidArgumentException("Node '{$id}' must have a 'type' specified.");
        }

        if (!isset(self::$registry[$type])) {
            throw new InvalidArgumentException("Unknown node type '{$type}' for step '{$id}'. Registered types: " . implode(', ', array_keys(self::$registry)));
        }

        $className = self::$registry[$type];
        $config = $definition['config'] ?? $definition['params'] ?? $definition;

        return new $className($id, $type, $config);
    }
}
