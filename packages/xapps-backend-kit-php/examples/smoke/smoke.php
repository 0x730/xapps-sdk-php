<?php

declare(strict_types=1);

$autoloadPath = __DIR__ . '/../../vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
} else {
    require_once __DIR__ . '/../../../xapps-php/src/index.php';
    require_once __DIR__ . '/../../src/functions.php';
}

use Xapps\BackendKit\BackendKit;

function assertTrue(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

echo "xapps-backend-kit smoke: start\n";

$request = BackendKit::createRequestContext([
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/health?ok=1',
]);

assertTrue(($request['method'] ?? '') === 'GET', 'request method mismatch');
assertTrue(($request['path'] ?? '') === '/health', 'request path mismatch');
assertTrue(($request['query']['ok'] ?? '') === '1', 'request query mismatch');

$normalized = BackendKit::normalizeOptions([
    'host' => [
        'allowedOrigins' => 'http://localhost:8001,http://localhost:8002',
        'bootstrap' => [
            'apiKeys' => 'key_a,key_b',
            'signingSecret' => 'bootstrap_secret',
            'ttlSeconds' => 300,
        ],
    ],
    'payments' => [
        'enabledModes' => ['gateway_managed', 'owner_managed'],
        'paymentUrl' => 'https://tenant.example.test/pay',
        'returnSecret' => 'return_secret',
        'returnUrlAllowlist' => 'https://tenant.example.test,https://host.example.test/',
    ],
    'gateway' => [
        'baseUrl' => 'https://gateway.example.test',
        'apiKey' => 'gateway_key',
    ],
    'reference' => [
        'hostSurfaces' => [
            ['key' => 'single-panel', 'label' => 'Single Panel'],
        ],
    ],
], [
    'defaults' => [
        'host' => [
            'enableReference' => true,
            'enableLifecycle' => true,
            'enableBridge' => true,
        ],
        'payments' => [
            'ownerIssuer' => 'tenant',
        ],
        'gateway' => [],
    ],
    'normalizeEnabledModes' => static fn (mixed $value): array => is_array($value) ? $value : [],
]);

assertTrue(count($normalized['host']['allowedOrigins'] ?? []) === 2, 'allowed origins normalization mismatch');
assertTrue(count($normalized['host']['bootstrap']['apiKeys'] ?? []) === 2, 'bootstrap key normalization mismatch');
assertTrue(($normalized['payments']['paymentUrl'] ?? '') === 'https://tenant.example.test/pay', 'payment url mismatch');

$hostProxy = BackendKit::createHostProxyService([
    'gatewayUrl' => 'https://gateway.example.test',
    'gatewayApiKey' => 'gateway_key',
], $normalized, [
    'createGatewayClient' => static fn (string $baseUrl, string $apiKey): object => (object) [
        'baseUrl' => $baseUrl,
        'apiKey' => $apiKey,
    ],
    'createEmbedHostProxyService' => static fn (object $gatewayClient, array $hostOptions): object => (object) [
        'gatewayClient' => $gatewayClient,
        'hostOptions' => $hostOptions,
    ],
]);

assertTrue(($hostProxy->gatewayClient->baseUrl ?? '') === 'https://gateway.example.test', 'host proxy gateway url mismatch');
assertTrue(count($hostProxy->hostOptions['hostModes'] ?? []) === 1, 'host proxy modes mismatch');

$verified = BackendKit::verifyBrowserWidgetContext(
    new class {
        /** @param array<string,mixed> $input @return array<string,mixed> */
        public function verifyBrowserWidgetContext(array $input): array
        {
            return [
                'verified' => true,
                'latestRequestId' => 'req_latest_123',
                'result' => $input,
            ];
        }
    },
    [
        'hostOrigin' => 'https://tenant.example.test',
        'installationId' => 'inst_123',
        'bindToolName' => 'submit_certificate_request_async',
        'subjectId' => 'sub_123',
    ],
);
assertTrue(($verified['verified'] ?? false) === true, 'widget bootstrap verify mismatch');
assertTrue(($verified['latestRequestId'] ?? '') === 'req_latest_123', 'widget bootstrap request mismatch');

$app = BackendKit::createPlainPhpApp([
    'gatewayUrl' => 'https://gateway.example.test',
    'gatewayApiKey' => 'gateway_key',
], $normalized, [
    'createHostProxyService' => static fn (array $config, array $options = []): object => (object) [
        'gatewayUrl' => $config['gatewayUrl'] ?? null,
        'hasOptions' => count($options) > 0,
    ],
]);

assertTrue(is_array($app['routes'] ?? null), 'plain app routes mismatch');
assertTrue(($app['hostProxyService']->gatewayUrl ?? '') === 'https://gateway.example.test', 'plain app host proxy mismatch');

$appWithOptions = BackendKit::attachBackendOptions($app, $normalized);
assertTrue(is_array($appWithOptions['hostOptions'] ?? null), 'backend options attach failed');

$allowlist = BackendKit::paymentReturnAllowlist([
    'tenantPaymentReturnUrlAllowlist' => 'https://tenant.example.test,https://host.example.test/',
]);
assertTrue(count($allowlist) === 2, 'payment return allowlist mismatch');
assertTrue($allowlist[1] === 'https://host.example.test', 'payment return allowlist trimming mismatch');

echo "xapps-backend-kit smoke: ok\n";
