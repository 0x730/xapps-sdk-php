<?php

declare(strict_types=1);

use Xapps\EmbedHostProxyService;
use Xapps\GatewayClient;
use Xapps\TestCurlShim;
use Xapps\XappsSdkError;

return [
    [
        'name' => 'EmbedHostProxyService mirrors host proxy contract',
        'run' => static function (): void {
            $gateway = new GatewayClient(xappsPhpTestBaseUrl(), 'gateway-key');
            $service = new EmbedHostProxyService($gateway, [
                'gatewayUrl' => xappsPhpTestBaseUrl(),
                'sign' => static fn (array $input): array => [
                    'ok' => true,
                    'envelope' => ['signed' => true, 'data' => $input['data'] ?? null],
                ],
                'vendorAssertion' => static fn (): array => [
                    'ok' => true,
                    'vendor_assertion' => 'vendor_assertion_fixture',
                    'link_id' => 'link_fixture',
                ],
            ]);

            $config = $service->getHostConfig();
            xappsPhpAssertTrue(($config['ok'] ?? false) === true, 'host config should return ok');
            xappsPhpAssertSame(xappsPhpTestBaseUrl(), (string) ($config['gatewayUrl'] ?? ''));
            xappsPhpAssertSame('single-panel', (string) (($config['hostModes'][0]['key'] ?? '')));

            $headers = $service->getNoStoreHeaders();
            xappsPhpAssertSame('no-store, no-cache, must-revalidate', (string) ($headers['Cache-Control'] ?? ''));

            $subject = $service->resolveSubject([
                'email' => 'demo@example.com',
                'name' => 'Demo User',
            ]);
            xappsPhpAssertContains('subj_', (string) ($subject['subjectId'] ?? ''), 'subject id should be returned');
            xappsPhpAssertSame('demo@example.com', (string) ($subject['email'] ?? ''));

            $catalog = $service->createCatalogSession([
                'origin' => 'http://localhost:3312',
                'subjectId' => (string) ($subject['subjectId'] ?? ''),
            ]);
            xappsPhpAssertContains('catalog_token_', (string) ($catalog['token'] ?? ''), 'catalog token should be returned');

            $widget = $service->createWidgetSession([
                'installationId' => 'inst_fixture_1',
                'widgetId' => 'widget_fixture_1',
                'origin' => 'http://localhost:3312',
                'subjectId' => (string) ($subject['subjectId'] ?? ''),
                'hostReturnUrl' => 'http://localhost:3312/marketplace.html',
            ]);
            xappsPhpAssertContains('widget_token_', (string) ($widget['token'] ?? ''), 'widget token should be returned');
            xappsPhpAssertContains(
                'xapps_host_return_url=',
                (string) ($widget['embedUrl'] ?? ''),
                'embed URL should include host return URL',
            );

            $refresh = $service->refreshWidgetToken([
                'installationId' => 'inst_fixture_1',
                'widgetId' => 'widget_fixture_1',
                'origin' => 'http://localhost:3312',
            ]);
            xappsPhpAssertContains('widget_token_', (string) ($refresh['token'] ?? ''), 'refresh token should be returned');
            xappsPhpAssertSame(900, (int) ($refresh['expires_in'] ?? 0));

            $myMonetization = $service->getMyXappMonetization([
                'xappId' => 'xapp_demo',
                'token' => 'widget_token_fixture',
                'installationId' => 'inst_fixture_1',
                'locale' => 'ro',
            ]);
            xappsPhpAssertSame('xapp_demo', (string) ($myMonetization['xapp_id'] ?? ''));

            $myHistory = $service->getMyXappMonetizationHistory([
                'xappId' => 'xapp_demo',
                'token' => 'widget_token_fixture',
                'installationId' => 'inst_fixture_1',
                'limit' => 8,
            ]);
            xappsPhpAssertSame('xapp_demo', (string) ($myHistory['xapp_id'] ?? ''));
            xappsPhpAssertSame('intent_fixture_1', (string) ($myHistory['history']['purchase_intents']['items'][0]['id'] ?? ''));

            $preparedIntent = $service->prepareMyXappPurchaseIntent([
                'xappId' => 'xapp_demo',
                'token' => 'widget_token_fixture',
                'offeringId' => 'offering_fixture_1',
                'packageId' => 'pkg_fixture_1',
                'priceId' => 'price_fixture_1',
                'installationId' => 'inst_fixture_1',
            ]);
            xappsPhpAssertSame('intent_fixture_1', (string) ($preparedIntent['prepared_intent']['purchase_intent_id'] ?? ''));

            $paymentSession = $service->createMyXappPurchasePaymentSession([
                'xappId' => 'xapp_demo',
                'intentId' => 'intent_fixture_1',
                'token' => 'widget_token_fixture',
                'returnUrl' => 'https://tenant.example.test/return',
                'installationId' => 'inst_fixture_1',
            ]);
            xappsPhpAssertSame('pay_fixture_1', (string) ($paymentSession['payment_session']['payment_session_id'] ?? ''));

            $finalized = $service->finalizeMyXappPurchasePaymentSession([
                'xappId' => 'xapp_demo',
                'intentId' => 'intent_fixture_1',
                'token' => 'widget_token_fixture',
            ]);
            xappsPhpAssertSame('settled', (string) ($finalized['transaction']['status'] ?? ''));

            TestCurlShim::reset();
            $widgetToolResult = $service->runWidgetToolRequest([
                'token' => 'widget_token_fixture',
                'installationId' => 'inst_fixture_1',
                'toolName' => 'complete_subject_profile',
                'payload' => ['source' => 'subject_self_profile'],
            ]);
            xappsPhpAssertSame('profile_fixture_1', (string) ($widgetToolResult['profile_id'] ?? ''));
            $widgetToolRequests = TestCurlShim::$requests;
            xappsPhpAssertSame('/v1/requests', $widgetToolRequests[0]['path'] ?? null, 'widget tool create path mismatch');
            xappsPhpAssertSame('Bearer widget_token_fixture', $widgetToolRequests[0]['headers']['authorization'] ?? null, 'widget tool auth mismatch');
            xappsPhpAssertSame('/v1/requests/req_widget_tool_1', $widgetToolRequests[1]['path'] ?? null, 'widget tool detail path mismatch');
            xappsPhpAssertSame('/v1/requests/req_widget_tool_1/response', $widgetToolRequests[2]['path'] ?? null, 'widget tool response path mismatch');

            $installations = $service->listInstallations([
                'subjectId' => (string) ($subject['subjectId'] ?? ''),
            ]);
            xappsPhpAssertSame('inst_fixture_1', (string) ($installations['items'][0]['installationId'] ?? ''));

            $installed = $service->installXapp([
                'xappId' => 'xapp_demo',
                'subjectId' => (string) ($subject['subjectId'] ?? ''),
                'termsAccepted' => true,
            ]);
            xappsPhpAssertSame('inst_xapp_demo', (string) ($installed['installation']['installationId'] ?? ''));

            $updated = $service->updateInstallation([
                'installationId' => 'inst_fixture_1',
                'subjectId' => (string) ($subject['subjectId'] ?? ''),
                'termsAccepted' => true,
            ]);
            xappsPhpAssertSame('inst_fixture_1', (string) ($updated['installation']['installationId'] ?? ''));

            $uninstalled = $service->uninstallInstallation([
                'installationId' => 'inst_fixture_1',
                'subjectId' => (string) ($subject['subjectId'] ?? ''),
            ]);
            xappsPhpAssertTrue(($uninstalled['ok'] ?? false) === true, 'uninstall should return ok');

            $signed = $service->bridgeSign([
                'data' => ['hello' => 'world'],
            ]);
            xappsPhpAssertTrue(($signed['ok'] ?? false) === true, 'bridge sign should return ok');

            $assertion = $service->bridgeVendorAssertion([]);
            xappsPhpAssertTrue(($assertion['ok'] ?? false) === true, 'vendor assertion should return ok');
            xappsPhpAssertSame('vendor_assertion_fixture', (string) ($assertion['vendor_assertion'] ?? ''));
        },
    ],
    [
        'name' => 'EmbedHostProxyService validates required fields',
        'run' => static function (): void {
            $gateway = new GatewayClient(xappsPhpTestBaseUrl(), 'gateway-key');
            $service = new EmbedHostProxyService($gateway);
            try {
                $service->createCatalogSession([]);
                throw new RuntimeException('Expected createCatalogSession to throw');
            } catch (XappsSdkError $error) {
                xappsPhpAssertSame(XappsSdkError::INVALID_ARGUMENT, $error->errorCode);
            }
        },
    ],
];
