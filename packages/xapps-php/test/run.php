<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$files = [
    __DIR__ . '/SignatureTest.php',
    __DIR__ . '/PaymentReturnTest.php',
    __DIR__ . '/CallbackClientTest.php',
    __DIR__ . '/GatewayClientTest.php',
    __DIR__ . '/EmbedHostProxyServiceTest.php',
    __DIR__ . '/HostedGatewayPaymentSessionTest.php',
    __DIR__ . '/PaymentPolicySupportTest.php',
    __DIR__ . '/XmsEventsTest.php',
    __DIR__ . '/PublisherApiClientTest.php',
];

$passed = 0;
$failed = 0;

putenv('XAPPS_PHP_TEST_BASE_URL=http://fixture.local');
\Xapps\TestCurlShim::reset();

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
            echo "PASS  {$name}\n";
        } catch (Throwable $error) {
            $failed += 1;
            fwrite(STDERR, "FAIL  {$name}\n");
            fwrite(STDERR, $error->getMessage() . "\n");
            fwrite(STDERR, $error->getTraceAsString() . "\n");
        }
    }
}

echo "\n";
echo 'Tests: ' . ($failed > 0 ? 'FAILED' : 'PASSED') . ' (' . (string) $passed . ' passed';
if ($failed > 0) {
    echo ', ' . (string) $failed . ' failed';
}
echo ")\n";

exit($failed > 0 ? 1 : 0);
