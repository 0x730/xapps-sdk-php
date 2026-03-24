<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/index.php';

use Xapps\CallbackClient;
use Xapps\GatewayClient;
use Xapps\XappsSdkError;

function envOrDefault(string $name, string $default): string
{
    $value = getenv($name);
    if ($value === false) {
        return $default;
    }
    $trimmed = trim((string) $value);
    return $trimmed !== '' ? $trimmed : $default;
}

echo "xapps-php live smoke: start\n";

$baseUrl = envOrDefault('XAPPS_SMOKE_BASE_URL', 'http://localhost:3000');
$apiKey = envOrDefault('XAPPS_SMOKE_API_KEY', 'xapps_test_tenant_b_key_123456789');

try {
    $gateway = new GatewayClient($baseUrl, $apiKey, 20, [
        'retry' => [
            'maxAttempts' => 2,
            'baseDelayMs' => 100,
            'maxDelayMs' => 300,
            'methods' => ['GET'],
        ],
    ]);
    $health = $gateway->get('/health');
    echo 'gateway /health status=' . (string) ($health['status'] ?? 0) . "\n";
} catch (XappsSdkError $err) {
    echo 'gateway error code=' . $err->errorCode . ' message=' . $err->getMessage() . "\n";
    exit(1);
}

$callbackToken = trim((string) getenv('XAPPS_SMOKE_CALLBACK_TOKEN'));
$requestId = trim((string) getenv('XAPPS_SMOKE_REQUEST_ID'));

if ($callbackToken === '' || $requestId === '') {
    echo "callback smoke skipped (set XAPPS_SMOKE_CALLBACK_TOKEN and XAPPS_SMOKE_REQUEST_ID)\n";
    echo "xapps-php live smoke: ok\n";
    exit(0);
}

try {
    $callbacks = new CallbackClient($baseUrl, $callbackToken, [
        'retry' => [
            'maxAttempts' => 2,
            'baseDelayMs' => 100,
            'maxDelayMs' => 300,
        ],
    ]);

    $eventRes = $callbacks->sendEvent(
        $requestId,
        ['type' => 'smoke.live.event', 'message' => 'xapps-php live smoke event'],
        null,
        ['retry' => ['maxAttempts' => 2, 'baseDelayMs' => 100, 'maxDelayMs' => 300]],
    );
    echo 'callback /events status=' . (string) ($eventRes['status'] ?? 0) . "\n";

    $completeRes = $callbacks->complete(
        $requestId,
        ['status' => 'PENDING', 'message' => 'xapps-php live smoke complete'],
    );
    echo 'callback /complete status=' . (string) ($completeRes['status'] ?? 0) . "\n";
} catch (XappsSdkError $err) {
    echo 'callback error code=' . $err->errorCode .
        ' status=' . (string) ($err->status ?? 0) .
        ' retryable=' . ($err->retryable ? 'true' : 'false') .
        ' message=' . $err->getMessage() . "\n";
    exit(1);
}

echo "xapps-php live smoke: ok\n";
