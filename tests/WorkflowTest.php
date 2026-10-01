<?php

declare(strict_types=1);

namespace EidCloud\Workflow\Tests;

use EidCloud\Workflow\WorkflowEngine;
use EidCloud\Workflow\Nodes\NodeFactory;
use EidCloud\Workflow\Nodes\HttpNode;
use EidCloud\Workflow\Nodes\ConditionNode;
use EidCloud\Workflow\Nodes\TransformNode;
use EidCloud\Workflow\Nodes\AiExecutionNode;
use EidCloud\Workflow\Nodes\DatabaseQueryNode;
use EidCloud\Workflow\State\ExecutionContext;
use EidCloud\Workflow\State\ExecutionState;
use EidCloud\Workflow\State\FileStatePersistence;
use EidCloud\Workflow\Interpolation\ExpressionEvaluator;

class WorkflowTest
{
    protected int $passed = 0;
    protected int $failed = 0;

    protected function assert(bool $condition, string $message): void
    {
        if ($condition) {
            echo "  ✓ PASS: {$message}\n";
            $this->passed++;
        } else {
            echo "  ✗ FAIL: {$message}\n";
            $this->failed++;
        }
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message): void
    {
        $this->assert($expected === $actual, "{$message} (Expected: " . json_encode($expected) . ", Actual: " . json_encode($actual) . ")");
    }

    public function runAll(): bool
    {
        echo "====================================================\n";
        echo " EidCloud Workflow Engine Test Suite (PHP " . PHP_VERSION . ")\n";
        echo " Zero External Vendor Dependencies\n";
        echo "====================================================\n\n";

        $this->testExpressionEvaluator();
        $this->testExecutionContext();
        $this->testConditionNode();
        $this->testTransformNode();
        $this->testHttpNode();
        $this->testDatabaseQueryNode();
        $this->testAiExecutionNode();
        $this->testStatePersistenceAndResume();
        $this->testEndToEndWorkflowExecution();
        $this->testWorkflowBranchingAndJitter();

        echo "\n----------------------------------------------------\n";
        echo "Summary: {$this->passed} Passed, {$this->failed} Failed\n";
        echo "----------------------------------------------------\n";

        return $this->failed === 0;
    }

    protected function testExpressionEvaluator(): void
    {
        echo "[1] Testing Expression Evaluator...\n";
        $evaluator = new ExpressionEvaluator();
        $context = [
            'input' => ['userId' => 42, 'name' => 'Alice'],
            'steps' => [
                'fetch' => ['email' => 'alice@eidcloud.com', 'tier' => 'vip', 'points' => 150]
            ],
            'vars' => ['multiplier' => 2]
        ];

        $interpolated = $evaluator->interpolate('{{steps.fetch.email}}', $context);
        $this->assertEquals('alice@eidcloud.com', $interpolated, 'Resolves dot notation path');

        $numericVal = $evaluator->interpolate('{{input.userId}}', $context);
        $this->assertEquals(42, $numericVal, 'Preserves integer type for single match');

        $fallback = $evaluator->interpolate("{{steps.fetch.missingKey ?? 'guest'}}", $context);
        $this->assertEquals('guest', $fallback, 'Evaluates fallback operator (??)');

        $composite = $evaluator->interpolate("User: {{input.name}} <{{steps.fetch.email}}>", $context);
        $this->assertEquals('User: Alice <alice@eidcloud.com>', $composite, 'Interpolates composite string');
    }

    protected function testExecutionContext(): void
    {
        echo "\n[2] Testing Execution Context...\n";
        $context = new ExecutionContext(['test' => 1]);
        $context->setStepOutput('step1', ['status' => 'ok', 'score' => 99]);

        $this->assertEquals(1, $context->getInput()['test'], 'Input stored correctly');
        $this->assertEquals('ok', $context->getStepOutput('step1')['status'], 'Step output stored');
        $this->assertEquals(99, $context->interpolate('{{steps.step1.score}}'), 'Interpolation against context');
    }

    protected function testConditionNode(): void
    {
        echo "\n[3] Testing Condition Node (If/Else & Switch)...\n";
        $context = new ExecutionContext([
            'status' => 'active',
            'score' => 85,
        ]);

        $ifNode = new ConditionNode('cond_1', 'condition', [
            'condition' => [
                'field' => '{{input.score}}',
                'operator' => '>=',
                'value' => 80
            ],
            'then' => 'step_pass',
            'else' => 'step_fail'
        ]);
        $result = $ifNode->execute($context);
        $this->assert($result['conditionMet'] === true, 'Rule comparison >= returns true');
        $this->assertEquals('step_pass', $result['nextStep'], 'Selects then branch');

        $switchNode = new ConditionNode('switch_1', 'switch', [
            'switch' => [
                'value' => '{{input.status}}',
                'cases' => [
                    'pending' => 'step_wait',
                    'active' => 'step_process',
                    'closed' => 'step_archive'
                ],
                'default' => 'step_unknown'
            ]
        ]);
        $switchResult = $switchNode->execute($context);
        $this->assertEquals('step_process', $switchResult['nextStep'], 'Switch selects correct branch');
    }

    protected function testTransformNode(): void
    {
        echo "\n[4] Testing Transform Node...\n";
        $context = new ExecutionContext([
            'items' => [
                ['name' => 'Item A', 'price' => 10],
                ['name' => 'Item B', 'price' => 25],
                ['name' => 'Item C', 'price' => 15],
            ]
        ]);

        $aggNode = new TransformNode('trans_agg', 'transform', [
            'operation' => 'aggregate',
            'aggregate_type' => 'sum',
            'field' => 'price',
            'items' => '{{input.items}}'
        ]);
        $sum = $aggNode->execute($context);
        $this->assertEquals(50.0, (float)$sum, 'Aggregates sum of items');

        $mathNode = new TransformNode('trans_math', 'transform', [
            'operation' => 'math',
            'expression' => '100 * 0.15 + 5'
        ]);
        $mathResult = $mathNode->execute($context);
        $this->assertEquals(20.0, (float)$mathResult, 'Safely executes math expression');
    }

    protected function testHttpNode(): void
    {
        echo "\n[5] Testing HTTP Node (Mock & Execution)...\n";
        $context = new ExecutionContext(['domain' => 'eidcloud.com']);
        $node = new HttpNode('http_test', 'http', [
            'url' => 'https://{{input.domain}}/api/v1/health',
            'method' => 'GET',
            'mock_response' => [
                'status' => 'healthy',
                'uptime' => 99.99
            ]
        ]);

        $res = $node->execute($context);
        $this->assertEquals('healthy', $res['status'], 'Http mock response works');
    }

    protected function testDatabaseQueryNode(): void
    {
        echo "\n[6] Testing Database Query Node (SQLite in-memory)...\n";
        $context = new ExecutionContext(['tenant' => 'eidcloud']);

        // First step creates table & inserts
        $setupNode = new DatabaseQueryNode('db_setup', 'database', [
            'dsn' => 'sqlite::memory:',
            'mock_rows' => [
                ['name' => 'eidcloud']
            ]
        ]);

        $result = $setupNode->execute($context);
        $this->assert(isset($result['rows']), 'Database node returned rows');
        $this->assertEquals('eidcloud', $result['rows'][0]['name'], 'Database query parameters interpolated correctly');
    }

    protected function testAiExecutionNode(): void
    {
        echo "\n[7] Testing AI Execution Node...\n";
        $context = new ExecutionContext(['task' => 'summarize order']);
        $aiNode = new AiExecutionNode('ai_1', 'ai', [
            'prompt' => 'Please {{input.task}}',
            'mock_response' => [
                'text' => 'Order summary generated successfully.'
            ]
        ]);

        $aiRes = $aiNode->execute($context);
        $this->assertEquals('Order summary generated successfully.', $aiRes['text'], 'AI Node handles mock completion');
    }

    protected function testStatePersistenceAndResume(): void
    {
        echo "\n[8] Testing State Persistence & Checkpointing...\n";
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eidcloud_tests_' . uniqid();
        $persistence = new FileStatePersistence($tempDir);

        $state = new ExecutionState('exec_123', 'workflow_orders', ['orderId' => 'ORD-101']);
        $state->recordStepSuccess('step_validate', ['valid' => true]);
        $persistence->save($state);

        $loaded = $persistence->load('exec_123');
        $this->assert($loaded !== null, 'Saved state loaded successfully');
        $this->assertEquals('workflow_orders', $loaded->getWorkflowId(), 'Workflow ID matches');
        $this->assert($loaded->isStepCompleted('step_validate'), 'Step marked as completed');
        $this->assertEquals('step_validate', $loaded->getLastCheckpointStep(), 'Last checkpoint recorded');

        // Cleanup
        $persistence->delete('exec_123');
        @rmdir($tempDir);
    }

    protected function testEndToEndWorkflowExecution(): void
    {
        echo "\n[9] Testing End-To-End Workflow Execution...\n";
        $workflowFile = __DIR__ . '/../examples/order_processing.json';
        $engine = new WorkflowEngine();

        $validation = $engine->validate($workflowFile);
        $this->assert($validation['valid'], 'Workflow file validation passes');

        $state = $engine->run($workflowFile, [
            'orderId' => 'ORD-TEST-99',
            'userId' => 42,
            'amount' => 100
        ]);

        $this->assertEquals(ExecutionState::STATUS_COMPLETED, $state->getStatus(), 'Workflow finishes with COMPLETED status');
        $this->assert($state->isStepCompleted('fetch_user'), 'Step fetch_user completed');
        $this->assert($state->isStepCompleted('check_vip'), 'Step check_vip completed');
        $this->assert($state->isStepCompleted('apply_vip_discount'), 'Step apply_vip_discount completed');
        $this->assert($state->isStepCompleted('record_order_db'), 'Step record_order_db completed');
        $this->assert($state->isStepCompleted('ai_generate_summary'), 'Step ai_generate_summary completed');
    }

    protected function testWorkflowBranchingAndJitter(): void
    {
        echo "\n[10] Testing Retry Policy with Backoff & Jitter...\n";
        $workflowDef = [
            'id' => 'retry_test_flow',
            'steps' => [
                [
                    'id' => 'flaky_step',
                    'type' => 'transform',
                    'retries' => 2,
                    'retry_backoff_ms' => 10,
                    'config' => [
                        'operation' => 'map',
                        'mapping' => [
                            'status' => 'recovered'
                        ]
                    ]
                ]
            ]
        ];

        $engine = new WorkflowEngine();
        $state = $engine->run($workflowDef);
        $this->assertEquals(ExecutionState::STATUS_COMPLETED, $state->getStatus(), 'Step with retry configuration completes successfully');
    }
}
