<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/WorkflowTest.php';

use EidCloud\Workflow\Tests\WorkflowTest;

$tester = new WorkflowTest();
$success = $tester->runAll();

exit($success ? 0 : 1);
