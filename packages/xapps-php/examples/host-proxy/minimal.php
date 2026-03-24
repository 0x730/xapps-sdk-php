<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/index.php';

use Xapps\EmbedHostProxyService;
use Xapps\GatewayClient;

function envOrDefault(string $name, string $default): string
{
    $value = getenv($name);
    if ($value === false) {
        return $default;
    }
    $trimmed = trim((string) $value);
    return $trimmed !== '' ? $trimmed : $default;
}

$gatewayUrl = envOrDefault('XAPPS_BASE_URL', 'http://localhost:3000');
$gatewayApiKey = envOrDefault('XAPPS_GATEWAY_API_KEY', '');

if ($gatewayApiKey === '') {
    fwrite(STDERR, "Missing XAPPS_GATEWAY_API_KEY\n");
    exit(1);
}

$gateway = new GatewayClient($gatewayUrl, $gatewayApiKey);
$hostProxy = new EmbedHostProxyService($gateway, [
    'gatewayUrl' => $gatewayUrl,
]);

$subject = $hostProxy->resolveSubject([
    'email' => 'demo@example.com',
    'name' => 'Demo User',
]);

$catalog = $hostProxy->createCatalogSession([
    'origin' => 'http://localhost:3312',
    'subjectId' => (string) ($subject['subjectId'] ?? ''),
]);

echo json_encode([
    'hostConfig' => $hostProxy->getHostConfig(),
    'headers' => $hostProxy->getNoStoreHeaders(),
    'subject' => $subject,
    'catalog' => $catalog,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

/*
Framework mapping sketch:

  $headers = $hostProxy->getNoStoreHeaders();

  GET  /api/host-config
    -> $hostProxy->getHostConfig()

  POST /api/resolve-subject
    -> $hostProxy->resolveSubject($payload)

  POST /api/create-catalog-session
    -> $hostProxy->createCatalogSession($payload)

  POST /api/create-widget-session
    -> $hostProxy->createWidgetSession($payload)

Add installations and bridge routes only when your host profile needs them.
*/
