<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$files = [
    __DIR__ . '/HostProxyTest.php',
    __DIR__ . '/HostApiLifecycleTest.php',
    __DIR__ . '/HostBootstrapSecurityTest.php',
];

$passed = 0;
$failed = 0;
$messages = [];
$errors = [];

foreach ($files as $file) {
    $definitions = require $file;
    foreach ($definitions as $definition) {
        $name = (string) ($definition['name'] ?? basename($file));
        $callback = $definition['run'] ?? null;
        if (!is_callable($callback)) {
            throw new RuntimeException('Invalid test definition in ' . $file);
        }
        try {
            $callback();
            $passed += 1;
            $messages[] = "PASS  {$name}";
        } catch (Throwable $error) {
            $failed += 1;
            $errors[] = "FAIL  {$name}";
            $errors[] = $error->getMessage();
            $errors[] = $error->getTraceAsString();
        }
    }
}

foreach ($messages as $message) {
    echo $message . "\n";
}
if ($failed > 0) {
    foreach ($errors as $line) {
        fwrite(STDERR, $line . "\n");
    }
}

echo "\n";
echo 'Tests: ' . ($failed > 0 ? 'FAILED' : 'PASSED') . ' (' . (string) $passed . ' passed';
if ($failed > 0) {
    echo ', ' . (string) $failed . ' failed';
}
echo ")\n";

exit($failed > 0 ? 1 : 0);
