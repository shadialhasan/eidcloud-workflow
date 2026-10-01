<?php

declare(strict_types=1);

namespace EidCloud\Workflow\Nodes;

use EidCloud\Workflow\State\ExecutionContext;
use InvalidArgumentException;

/**
 * Executes a branch in parallel or iterates over a list of items with aggregation.
 */
class LoopNode extends AbstractNode
{
    public function execute(ExecutionContext $context): mixed
    {
        $items = $context->interpolate($this->config['items'] ?? []);
        if (!is_array($items)) {
            $items = [];
        }

        $itemKey = $this->config['item_variable'] ?? 'item';
        $indexKey = $this->config['index_variable'] ?? 'index';
        $steps = $this->config['steps'] ?? [];
        $concurrency = (int) ($this->config['concurrency'] ?? 1); // 1 = sequential, >1 = parallel simulation

        $results = [];

        foreach ($items as $idx => $item) {
            $iterationState = [];
            $loopContext = new ExecutionContext(
                array_merge($context->getInput(), [
                    $itemKey => $item,
                    $indexKey => $idx,
                ]),
                array_merge($context->getSteps(), [
                    'current_loop' => [
                        'item' => $item,
                        'index' => $idx,
                    ]
                ]),
                $context->getVariables(),
                $context->getEvaluator()
            );

            // Execute sub-steps for this item
            foreach ($steps as $subStepConfig) {
                $subNode = NodeFactory::create($subStepConfig);
                $stepOutput = $subNode->execute($loopContext);
                $loopContext->setStepOutput($subNode->getId(), $stepOutput);
                $iterationState[$subNode->getId()] = $stepOutput;
            }

            $results[] = [
                'index' => $idx,
                'item' => $item,
                'output' => $iterationState,
            ];
        }

        return [
            'total' => count($items),
            'results' => $results,
        ];
    }
}
