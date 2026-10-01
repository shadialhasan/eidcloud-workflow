<?php

declare(strict_types=1);

namespace EidCloud\Workflow\Nodes;

use EidCloud\Workflow\State\ExecutionContext;
use InvalidArgumentException;

/**
 * Executes multiple branches concurrently or sequentially and merges their outputs.
 */
class ParallelNode extends AbstractNode
{
    public function execute(ExecutionContext $context): mixed
    {
        $branches = $this->config['branches'] ?? [];
        if (!is_array($branches)) {
            throw new InvalidArgumentException("ParallelNode '{$this->id}' requires a 'branches' array.");
        }

        $branchOutputs = [];

        foreach ($branches as $branchKey => $branchSteps) {
            $branchContext = new ExecutionContext(
                $context->getInput(),
                $context->getSteps(),
                $context->getVariables(),
                $context->getEvaluator()
            );

            $branchStepOutputs = [];
            foreach ($branchSteps as $stepConfig) {
                $subNode = NodeFactory::create($stepConfig);
                $stepResult = $subNode->execute($branchContext);
                $branchContext->setStepOutput($subNode->getId(), $stepResult);
                $branchStepOutputs[$subNode->getId()] = $stepResult;
            }

            $branchOutputs[$branchKey] = $branchStepOutputs;
        }

        return [
            'branches' => $branchOutputs,
            'completed' => true,
        ];
    }
}
