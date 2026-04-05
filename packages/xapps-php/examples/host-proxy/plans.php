<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/index.php';

use Xapps\EmbedHostProxyService;
use Xapps\GatewayClient;

function envOrDefault(string $name, string $default = ''): string
{
    $value = getenv($name);
    if ($value === false) {
        return $default;
    }
    $trimmed = trim((string) $value);
    return $trimmed !== '' ? $trimmed : $default;
}

$gatewayUrl = envOrDefault('XAPPS_BASE_URL', 'http://localhost:3000');
$gatewayApiKey = envOrDefault('XAPPS_GATEWAY_API_KEY');
$xappId = envOrDefault('XAPPS_XAPP_ID');
$token = envOrDefault('XAPPS_HOST_TOKEN');

if ($gatewayApiKey === '') {
    fwrite(STDERR, "Missing XAPPS_GATEWAY_API_KEY\n");
    exit(1);
}

if ($xappId === '' || $token === '') {
    fwrite(STDERR, "Missing XAPPS_XAPP_ID or XAPPS_HOST_TOKEN\n");
    exit(1);
}

$gateway = new GatewayClient($gatewayUrl, $gatewayApiKey);
$hostProxy = new EmbedHostProxyService($gateway, [
    'gatewayUrl' => $gatewayUrl,
]);

$monetization = $hostProxy->getMyXappMonetization([
    'xappId' => $xappId,
    'token' => $token,
    'installationId' => envOrDefault('XAPPS_INSTALLATION_ID'),
    'locale' => envOrDefault('XAPPS_LOCALE', 'en'),
    'country' => envOrDefault('XAPPS_COUNTRY'),
    'realmRef' => envOrDefault('XAPPS_REALM_REF'),
]);

echo json_encode([
    'xappId' => $xappId,
    'access' => $monetization['access'] ?? null,
    'currentSubscription' => $monetization['currentSubscription'] ?? null,
    'entitlements' => $monetization['entitlements'] ?? [],
    'paywalls' => $monetization['paywalls'] ?? [],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

/*
Framework mapping sketch:

  GET /api/my-xapps/:xappId/monetization
    -> $hostProxy->getMyXappMonetization([
         'xappId' => $params['xappId'],
         'token' => $query['token'],
         'installationId' => $query['installationId'] ?? null,
         'locale' => $query['locale'] ?? null,
         'country' => $query['country'] ?? null,
         'realmRef' => $query['realmRef'] ?? null,
       ])

  POST /api/my-xapps/:xappId/monetization/purchase-intents/prepare
    -> $hostProxy->prepareMyXappPurchaseIntent([
         'xappId' => $params['xappId'],
         'token' => $body['token'],
         'offeringId' => $body['offeringId'] ?? null,
         'packageId' => $body['packageId'] ?? null,
         'priceId' => $body['priceId'] ?? null,
         'installationId' => $body['installationId'] ?? null,
         'locale' => $body['locale'] ?? null,
         'country' => $body['country'] ?? null,
       ])
*/
