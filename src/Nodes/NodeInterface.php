<?php

declare(strict_types=1);

namespace EidCloud\Workflow\Nodes;

use EidCloud\Workflow\State\ExecutionContext;

/**
 * Interface that all workflow nodes must implement.
 */
interface NodeInterface
{
    /**
     * Get the unique step ID / node key.
     */
    public function getId(): string;

    /**
     * Get the node type identifier.
     */
    public function getType(): string;

    /**
     * Get the node configuration options.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array;

    /**
     * Execute the node logic within the given context.
     *
     * @param ExecutionContext $context
     * @return mixed Result data to store in execution state for this step
     * @throws \Throwable If node execution fails
     */
    public function execute(ExecutionContext $context): mixed;
}
