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

$widgetBootstrapPolicy = BackendKit::evaluateWidgetBootstrapOriginPolicy([
    'hostOrigin' => 'https://tenant.example.test/embed',
    'allowedOrigins' => 'https://tenant.example.test,https://tenant-b.example.test',
]);
assertTrue(($widgetBootstrapPolicy['ok'] ?? false) === true, 'widget bootstrap policy should allow normalized host');
assertTrue(($widgetBootstrapPolicy['hostOrigin'] ?? '') === 'https://tenant.example.test', 'widget bootstrap policy normalized host mismatch');

$widgetBootstrapRejected = BackendKit::evaluateWidgetBootstrapOriginPolicy([
    'hostOrigin' => 'https://tenant-c.example.test',
    'allowedOrigins' => ['https://tenant.example.test'],
]);
assertTrue(($widgetBootstrapRejected['ok'] ?? true) === false, 'widget bootstrap policy should reject unknown host origin');
assertTrue(($widgetBootstrapRejected['code'] ?? '') === 'HOST_ORIGIN_NOT_ALLOWED', 'widget bootstrap policy rejection mismatch');
assertTrue(BackendKit::normalizeXappMonetizationScopeKind('realm') === 'realm', 'normalizeXappMonetizationScopeKind mismatch');
$resolvedScope = BackendKit::resolveXappMonetizationScope([
    'scopeKind' => 'subject',
    'context' => ['subject_id' => 'sub_123'],
]);
assertTrue(($resolvedScope['scope_fields']['subject_id'] ?? '') === 'sub_123', 'resolveXappMonetizationScope mismatch');
$resolvedPaymentDefinition = BackendKit::resolveXappHostedPaymentDefinition([
    'manifest' => [
        'payment_guard_definitions' => [
            [
                'name' => 'publisher_checkout_default',
                'payment_issuer_mode' => 'publisher_delegated',
                'payment_scheme' => 'stripe',
                'accepts' => [['scheme' => 'mock_manual']],
            ],
        ],
    ],
    'paymentGuardRef' => 'publisher_checkout_default',
    'paymentScheme' => 'mock_manual',
    'env' => [
        'PUBLISHER_DELEGATED_PAYMENT_RETURN_SECRET_REF' => 'platform://publisher:return:secret',
    ],
]);
assertTrue(($resolvedPaymentDefinition['issuerMode'] ?? '') === 'publisher_delegated', 'resolveXappHostedPaymentDefinition issuer mismatch');
assertTrue((($resolvedPaymentDefinition['metadata']['payment_return_signing']['secret_ref'] ?? '') === 'platform://publisher:return:secret'), 'resolveXappHostedPaymentDefinition signing mismatch');
assertTrue((($resolvedPaymentDefinition['scheme'] ?? '') === 'mock_manual'), 'resolveXappHostedPaymentDefinition scheme mismatch');
$hostedPaymentPresets = BackendKit::listXappHostedPaymentPresets([
    'manifest' => [
        'payment_guard_definitions' => [
            [
                'name' => 'gateway_checkout_default',
                'payment_issuer_mode' => 'gateway_managed',
                'payment_scheme' => 'stripe',
                'accepts' => [['scheme' => 'mock_manual']],
            ],
        ],
    ],
]);
assertTrue(count($hostedPaymentPresets) === 2, 'listXappHostedPaymentPresets mismatch');
assertTrue(count(array_filter($hostedPaymentPresets, static fn (array $item): bool => ($item['label'] ?? '') === 'Gateway managed (stripe)')) === 1, 'hosted payment stripe preset label mismatch');
assertTrue(count(array_filter($hostedPaymentPresets, static fn (array $item): bool => ($item['label'] ?? '') === 'Gateway managed (mock manual)')) === 1, 'hosted payment mock preset label mismatch');
$foundHostedPaymentPreset = BackendKit::findXappHostedPaymentPreset([
    'manifest' => [
        'payment_guard_definitions' => [
            [
                'name' => 'gateway_checkout_default',
                'payment_issuer_mode' => 'gateway_managed',
                'payment_scheme' => 'stripe',
                'accepts' => [['scheme' => 'mock_manual']],
            ],
        ],
    ],
    'paymentGuardRef' => 'gateway_checkout_default',
    'paymentScheme' => 'mock_manual',
]);
assertTrue(($foundHostedPaymentPreset['paymentScheme'] ?? '') === 'mock_manual', 'findXappHostedPaymentPreset mismatch');

$referenceSummary = BackendKit::buildXappMonetizationReferenceSummary([
    'snapshot' => [
        'current_subscription' => [
            'status' => 'active',
            'product_slug' => 'creator-pro',
            'package_slug' => 'creator-pro-monthly',
            'renews_at' => '2026-05-05T00:00:00.000Z',
        ],
        'entitlements' => [
            ['status' => 'active', 'product_slug' => 'creator-starter-unlock'],
        ],
        'access_projection' => [
            'has_current_access' => true,
        ],
    ],
    'paywallPackages' => [
        [
            'package_slug' => 'creator-pro-monthly',
            'product_family' => 'subscription_plan',
            'purchase_policy' => [
                'can_purchase' => false,
                'status' => 'current_recurring_plan',
                'transition_kind' => 'none',
            ],
        ],
        [
            'package_slug' => 'creator-starter-unlock',
            'product_family' => 'one_time_unlock',
            'purchase_policy' => [
                'can_purchase' => false,
                'status' => 'owned_additive_unlock',
                'transition_kind' => 'none',
            ],
        ],
        [
            'package_slug' => 'creator-boost-credits',
            'product_family' => 'credit_pack',
            'purchase_policy' => [
                'can_purchase' => true,
                'status' => 'available',
                'transition_kind' => 'buy_credit_pack',
            ],
        ],
    ],
]);
assertTrue(($referenceSummary['current_recurring_plan']['status'] ?? '') === 'active', 'buildXappMonetizationReferenceSummary recurring mismatch');
assertTrue((($referenceSummary['owned_additive_unlocks'][0]['package_slug'] ?? '') === 'creator-starter-unlock'), 'buildXappMonetizationReferenceSummary unlock mismatch');
assertTrue((($referenceSummary['credit_topups'][0]['package_slug'] ?? '') === 'creator-boost-credits'), 'buildXappMonetizationReferenceSummary credit mismatch');

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

$fakeGatewayClient = new class {
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function prepareXappPurchaseIntent(array $input): array
    {
        return [
            'prepared_intent' => [
                'purchase_intent_id' => 'pi_123',
                'price' => ['amount' => '29', 'currency' => 'RON'],
            ] + $input,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createXappPurchasePaymentSession(array $input): array
    {
        return [
            'payment_session' => ['payment_session_id' => 'pay_123'] + $input,
            'payment_page_url' => 'https://gateway.example.test/v1/gateway-payment.html?payment_session_id=pay_123',
        ];
    }

    /** @return array<string,mixed> */
    public function finalizeXappPurchasePaymentSession(array $input): array
    {
        return [
            'prepared_intent' => ['purchase_intent_id' => (string) ($input['intentId'] ?? ''), 'status' => 'paid'],
            'payment_session' => ['payment_session_id' => 'pay_123', 'status' => 'completed'],
            'transaction' => ['id' => 'txn_123', 'status' => 'verified'],
            'access_projection' => [
                'has_current_access' => true,
                'source_intent_id' => $input['intentId'] ?? null,
            ],
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createXappPurchaseTransaction(array $input): array
    {
        return [
            'transaction' => [
                'id' => 'txn_ref_123',
                'status' => $input['status'] ?? null,
                'amount' => $input['amount'] ?? null,
                'currency' => $input['currency'] ?? null,
            ],
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function issueXappPurchaseAccess(array $input): array
    {
        return [
            'prepared_intent' => [
                'purchase_intent_id' => (string) ($input['intentId'] ?? ''),
                'status' => 'paid',
            ],
            'access_projection' => [
                'has_current_access' => true,
                'source_intent_id' => $input['intentId'] ?? null,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function getXappMonetizationAccess(array $input): array
    {
        return [
            'access_projection' => [
                'entitlement_state' => 'active',
                'has_current_access' => true,
            ] + $input,
        ];
    }

    /** @return array<string,mixed> */
    public function getXappCurrentSubscription(array $input): array
    {
        return [
            'current_subscription' => [
                'id' => 'sub_contract_123',
                'status' => 'active',
            ] + $input,
        ];
    }

    /** @return array<string,mixed> */
    public function listXappEntitlements(array $input): array
    {
        return [
            'items' => [
                ['id' => 'entitlement_123', 'status' => 'active', 'product_slug' => 'creator-starter-unlock'] + $input,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function listXappWalletAccounts(array $input): array
    {
        return [
            'items' => [
                ['id' => 'wallet_123', 'credits_remaining' => 500] + $input,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function consumeXappWalletCredits(array $input): array
    {
        return [
            'wallet_account' => [
                'id' => (string) ($input['walletAccountId'] ?? ''),
                'balance_remaining' => '475',
            ],
            'wallet_ledger' => [
                'id' => 'ledger_123',
                'wallet_account_id' => (string) ($input['walletAccountId'] ?? ''),
                'event_kind' => 'consume',
                'amount' => (string) ($input['amount'] ?? ''),
                'source_ref' => (string) ($input['source_ref'] ?? ''),
            ],
            'access_projection' => [
                'has_current_access' => true,
                'credits_remaining' => 475,
            ],
            'snapshot_id' => 'snapshot_123',
        ];
    }
};

$snapshot = BackendKit::readXappMonetizationSnapshot($fakeGatewayClient, [
    'xappId' => 'xapp_123',
    'subjectId' => 'sub_123',
]);
assertTrue(($snapshot['access_projection']['has_current_access'] ?? false) === true, 'readXappMonetizationSnapshot access mismatch');
assertTrue(($snapshot['current_subscription']['id'] ?? '') === 'sub_contract_123', 'readXappMonetizationSnapshot subscription mismatch');
assertTrue(($snapshot['entitlements'][0]['id'] ?? '') === 'entitlement_123', 'readXappMonetizationSnapshot entitlements mismatch');
assertTrue(($snapshot['wallet_accounts'][0]['id'] ?? '') === 'wallet_123', 'readXappMonetizationSnapshot wallet mismatch');

$consumedCredits = BackendKit::consumeXappWalletCredits($fakeGatewayClient, [
    'xappId' => 'xapp_123',
    'walletAccountId' => 'wallet_123',
    'amount' => '25',
    'sourceRef' => 'feature:priority_support_call',
    'metadata' => [
        'feature_key' => 'priority_support_call',
    ],
]);
assertTrue(($consumedCredits['wallet_account']['balance_remaining'] ?? '') === '475', 'consumeXappWalletCredits wallet mismatch');
assertTrue(($consumedCredits['wallet_ledger']['event_kind'] ?? '') === 'consume', 'consumeXappWalletCredits ledger mismatch');
assertTrue(($consumedCredits['access_projection']['credits_remaining'] ?? null) === 475, 'consumeXappWalletCredits access mismatch');

$hostedPurchase = BackendKit::startXappHostedPurchase($fakeGatewayClient, [
    'xappId' => 'xapp_123',
    'offeringId' => 'offer_123',
    'packageId' => 'package_123',
    'priceId' => 'price_123',
    'subjectId' => 'sub_123',
    'paymentGuardRef' => 'gateway_checkout_default',
    'paymentScheme' => 'mock_manual',
    'returnUrl' => 'https://tenant.example.test/return',
    'pageUrl' => 'https://gateway.example.test/v1/gateway-payment.html',
]);
assertTrue(($hostedPurchase['prepared_intent']['purchase_intent_id'] ?? '') === 'pi_123', 'startXappHostedPurchase intent mismatch');
assertTrue(($hostedPurchase['payment_session']['payment_session_id'] ?? '') === 'pay_123', 'startXappHostedPurchase session mismatch');

$finalizedPurchase = BackendKit::finalizeXappHostedPurchase($fakeGatewayClient, [
    'xappId' => 'xapp_123',
    'intentId' => 'pi_123',
]);
assertTrue(($finalizedPurchase['transaction']['id'] ?? '') === 'txn_123', 'finalizeXappHostedPurchase transaction mismatch');
assertTrue(($finalizedPurchase['issued']['access_projection']['has_current_access'] ?? false) === true, 'finalizeXappHostedPurchase issued access mismatch');

$referenceActivation = BackendKit::activateXappPurchaseReference($fakeGatewayClient, [
    'xappId' => 'xapp_123',
    'offeringId' => 'offer_123',
    'packageId' => 'package_123',
    'priceId' => 'price_123',
    'subjectId' => 'sub_123',
]);
assertTrue(($referenceActivation['transaction']['id'] ?? '') === 'txn_ref_123', 'activateXappPurchaseReference transaction mismatch');
assertTrue(($referenceActivation['issued']['access_projection']['has_current_access'] ?? false) === true, 'activateXappPurchaseReference issued access mismatch');

echo "xapps-backend-kit smoke: ok\n";
