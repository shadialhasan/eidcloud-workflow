<?php

declare(strict_types=1);

/**
 * PSR-4 Autoloader for EidCloud\Workflow (Zero vendor dependency setup).
 */
spl_autoload_register(function ($class) {
    $prefix = 'EidCloud\\Workflow\\';
    $baseDir = __DIR__ . '/../src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
