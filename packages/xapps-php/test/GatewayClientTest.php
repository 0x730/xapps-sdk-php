<?php

declare(strict_types=1);

use Xapps\GatewayClient;
use Xapps\TestCurlShim;

return [
    [
        'name' => 'GatewayClient covers payment-session and hosted gateway helpers',
        'run' => static function (): void {
            $client = new GatewayClient(xappsPhpTestBaseUrl(), 'gateway-key');

            $created = $client->createPaymentSession([
                'paymentSessionId' => 'pay_fixture_1',
                'xappId' => 'xapp_demo',
                'toolName' => 'submit_demo',
                'amount' => '5.00',
                'currency' => 'USD',
                'returnUrl' => 'https://tenant.example.test/return',
            ]);
            xappsPhpAssertSame('pay_fixture_1', (string) ($created['session']['payment_session_id'] ?? ''));
            xappsPhpAssertContains(
                'https://pay.example.test/session/pay_fixture_1',
                (string) ($created['paymentPageUrl'] ?? ''),
                'payment page URL should be returned',
            );

            $fetched = $client->getPaymentSession('pay_fixture_1');
            xappsPhpAssertSame('pay_fixture_1', (string) ($fetched['session']['payment_session_id'] ?? ''));

            $hosted = $client->getGatewayPaymentSession([
                'paymentSessionId' => 'pay_fixture_1',
                'returnUrl' => 'https://tenant.example.test/return',
                'xappsResume' => 'resume_1',
            ]);
            xappsPhpAssertSame('pay_fixture_1', (string) ($hosted['session']['payment_session_id'] ?? ''));
            xappsPhpAssertSame('resume_1', (string) ($hosted['session']['xapps_resume'] ?? ''));

            $complete = $client->completeGatewayPayment([
                'paymentSessionId' => 'pay_fixture_1',
            ]);
            xappsPhpAssertSame('hosted_redirect', (string) ($complete['flow'] ?? ''));
            xappsPhpAssertContains(
                'https://checkout.example.test/gateway/pay_fixture_1',
                (string) ($complete['redirectUrl'] ?? ''),
                'gateway hosted complete should return a redirect URL',
            );

            $settled = $client->clientSettleGatewayPayment([
                'paymentSessionId' => 'pay_fixture_1',
                'status' => 'paid',
                'clientToken' => 'client_token_1',
            ]);
            xappsPhpAssertSame('immediate', (string) ($settled['flow'] ?? ''));
            xappsPhpAssertSame(
                'client_token_1',
                (string) ($settled['metadata']['client_token'] ?? ''),
                'client token should be preserved in metadata',
            );

            $resolved = $client->resolveSubject([
                'type' => 'user',
                'identifier' => [
                    'idType' => 'email',
                    'value' => 'demo@example.com',
                    'hint' => 'demo@example.com',
                ],
                'email' => 'demo@example.com',
            ]);
            xappsPhpAssertContains('subj_', (string) ($resolved['subjectId'] ?? ''), 'subject id should be returned');

            TestCurlShim::reset();
            $catalog = $client->createCatalogSession([
                'origin' => 'http://localhost:3312',
                'subjectId' => (string) ($resolved['subjectId'] ?? ''),
                'publishers' => ['', 'xplace', ' xplace ', '   '],
                'tags' => ['featured', '', ' featured '],
            ]);
            xappsPhpAssertContains('catalog_token_', (string) ($catalog['token'] ?? ''), 'catalog token should be returned');
            $catalogRequest = TestCurlShim::$requests[count(TestCurlShim::$requests) - 1] ?? null;
            xappsPhpAssertSame(['xplace'], $catalogRequest['payload']['publishers'] ?? null, 'catalog publishers should be normalized');
            xappsPhpAssertSame(['featured'], $catalogRequest['payload']['tags'] ?? null, 'catalog tags should be normalized');

            TestCurlShim::reset();
            $widget = $client->createWidgetSession([
                'installationId' => 'inst_fixture_1',
                'widgetId' => 'widget_fixture_1',
                'origin' => 'http://localhost:3312',
                'subjectId' => (string) ($resolved['subjectId'] ?? ''),
            ]);
            xappsPhpAssertContains('widget_token_', (string) ($widget['token'] ?? ''), 'widget token should be returned');
            $widgetRequest = TestCurlShim::$requests[count(TestCurlShim::$requests) - 1] ?? null;
            xappsPhpAssertTrue(
                !array_key_exists('resultPresentation', is_array($widgetRequest['payload'] ?? null) ? $widgetRequest['payload'] : []),
                'widget payload should omit null resultPresentation',
            );

            TestCurlShim::reset();
            $verified = $client->verifyBrowserWidgetContext([
                'hostOrigin' => 'http://localhost:3312',
                'installationId' => 'inst_fixture_1',
                'bindToolName' => 'submit_certificate_request_async',
                'subjectId' => (string) ($resolved['subjectId'] ?? ''),
            ]);
            xappsPhpAssertTrue(($verified['verified'] ?? false) === true, 'widget bootstrap verify should pass');
            xappsPhpAssertContains('req_latest_', (string) ($verified['latestRequestId'] ?? ''), 'latest request id should be returned');
            $verifyRequest = TestCurlShim::$requests[count(TestCurlShim::$requests) - 1] ?? null;
            xappsPhpAssertSame('/v1/requests/latest', $verifyRequest['path'] ?? null, 'verify path mismatch');
            xappsPhpAssertSame('http://localhost:3312', $verifyRequest['headers']['origin'] ?? null, 'verify origin mismatch');
            xappsPhpAssertSame('inst_fixture_1', $verifyRequest['query']['installationId'] ?? null, 'verify installation mismatch');
            xappsPhpAssertSame('submit_certificate_request_async', $verifyRequest['query']['toolName'] ?? null, 'verify tool mismatch');

            $installations = $client->listInstallations([
                'subjectId' => (string) ($resolved['subjectId'] ?? ''),
            ]);
            xappsPhpAssertSame('inst_fixture_1', (string) ($installations['items'][0]['installationId'] ?? ''));

            $installed = $client->installXapp([
                'xappId' => 'xapp_demo',
                'subjectId' => (string) ($resolved['subjectId'] ?? ''),
                'termsAccepted' => true,
            ]);
            xappsPhpAssertSame('inst_xapp_demo', (string) ($installed['installation']['installationId'] ?? ''));

            $updated = $client->updateInstallation([
                'installationId' => 'inst_fixture_1',
                'subjectId' => (string) ($resolved['subjectId'] ?? ''),
                'termsAccepted' => true,
            ]);
            xappsPhpAssertSame('inst_fixture_1', (string) ($updated['installation']['installationId'] ?? ''));

            $uninstalled = $client->uninstallInstallation([
                'installationId' => 'inst_fixture_1',
                'subjectId' => (string) ($resolved['subjectId'] ?? ''),
            ]);
            xappsPhpAssertTrue(($uninstalled['ok'] ?? false) === true, 'uninstall should return ok');
        },
    ],
];
