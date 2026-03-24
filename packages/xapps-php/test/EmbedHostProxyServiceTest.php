<?php

declare(strict_types=1);

use Xapps\EmbedHostProxyService;
use Xapps\GatewayClient;
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
