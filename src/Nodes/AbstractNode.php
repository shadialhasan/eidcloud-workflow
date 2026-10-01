<?php

declare(strict_types=1);

namespace EidCloud\Workflow\Nodes;

/**
 * Base abstract class providing common helpers for workflow nodes.
 */
abstract class AbstractNode implements NodeInterface
{
    /**
     * @param string $id
     * @param string $type
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected string $id,
        protected string $type,
        protected array $config = []
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}
