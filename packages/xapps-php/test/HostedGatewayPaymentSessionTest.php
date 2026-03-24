<?php

declare(strict_types=1);

use Xapps\GatewayClient;
use Xapps\HostedGatewayPaymentSession;
use Xapps\PaymentHandler;

return [
    [
        'name' => 'HostedGatewayPaymentSession returns provider payment page URL when gateway supplies it',
        'run' => static function (): void {
            $gateway = new GatewayClient(xappsPhpTestBaseUrl(), 'gateway-key');
            $handler = new PaymentHandler([
                'secret' => 'tenant-secret',
                'issuer' => 'tenant',
                'returnUrlAllowlist' => ['https://tenant.example.test'],
            ]);

            $result = HostedGatewayPaymentSession::buildHostedGatewayPaymentUrl([
                'gatewayClient' => $gateway,
                'paymentHandler' => $handler,
                'payload' => [
                    'xapps_resume' => rtrim(strtr(base64_encode(json_encode([
                        'xapp_id' => 'xapp_demo',
                        'tool_name' => 'submit_demo',
                        'subject_id' => 'sub_123',
                        'installation_id' => 'inst_123',
                        'client_id' => 'client_123',
                        'return_url' => 'https://tenant.example.test/return',
                    ], JSON_THROW_ON_ERROR)), '+/', '-_'), '='),
                ],
                'guard' => ['slug' => 'tenant-payment-policy'],
                'guardConfig' => [
                    'payment_scheme' => 'stripe',
                    'payment_allowed_issuers' => ['gateway'],
                ],
                'amount' => '4.20',
                'currency' => 'USD',
                'defaultPaymentUrl' => 'https://tenant.example.test/tenant-payment.html',
                'fallbackIssuer' => 'gateway',
                'storedIssuer' => 'gateway',
            ]);

            xappsPhpAssertSame('pay_fixture', (string) ($result['paymentSessionId'] ?? ''));
            xappsPhpAssertContains(
                'https://pay.example.test/session/pay_fixture',
                (string) ($result['paymentUrl'] ?? ''),
                'payment page URL should come directly from gateway when available',
            );
        },
    ],
    [
        'name' => 'HostedGatewayPaymentSession falls back to tenant page URL and persists the session',
        'run' => static function (): void {
            $gateway = new class {
                /** @param array<string,mixed> $input @return array<string,mixed> */
                public function createPaymentSession(array $input): array
                {
                    xappsPhpAssertSame('xapp_demo', (string) ($input['xappId'] ?? ''));
                    xappsPhpAssertSame('submit_demo', (string) ($input['toolName'] ?? ''));
                    xappsPhpAssertSame('stripe', (string) ($input['paymentScheme'] ?? ''));
                    xappsPhpAssertSame('tenant', (string) ($input['issuer'] ?? ''));
                    return [
                        'session' => [
                            'payment_session_id' => 'pay_guard_456',
                            'status' => 'pending',
                        ],
                    ];
                }
            };
            $handler = new class {
                /** @var array<string,mixed>|null */
                public ?array $upserted = null;

                /** @param array<string,mixed> $input @return array<string,mixed> */
                public function upsertSession(array $input): array
                {
                    $this->upserted = $input;
                    return $input;
                }
            };

            $result = HostedGatewayPaymentSession::buildHostedGatewayPaymentUrl([
                'gatewayClient' => $gateway,
                'paymentHandler' => $handler,
                'payload' => [
                    'xapp_id' => 'xapp_demo',
                    'tool_name' => 'submit_demo',
                    'subject_id' => 'sub_123',
                    'installation_id' => 'inst_123',
                    'client_id' => 'client_123',
                    'return_url' => 'https://tenant.example.test/return',
                ],
                'guard' => ['slug' => 'tenant-payment-policy'],
                'guardConfig' => [
                    'payment_scheme' => 'stripe',
                ],
                'amount' => '6.50',
                'currency' => 'USD',
                'defaultPaymentUrl' => 'https://tenant.example.test/tenant-payment.html',
                'fallbackIssuer' => 'tenant',
                'storedIssuer' => 'tenant',
                'allowDefaultSecretFallback' => true,
                'defaultSecret' => 'tenant-secret',
            ]);

            xappsPhpAssertSame('pay_guard_456', (string) ($result['paymentSessionId'] ?? ''));
            xappsPhpAssertSame(
                'pay_guard_456',
                (string) HostedGatewayPaymentSession::extractHostedPaymentSessionId((string) ($result['paymentUrl'] ?? '')),
            );
            xappsPhpAssertContains(
                'https://tenant.example.test/tenant-payment.html',
                (string) ($result['paymentUrl'] ?? ''),
                'tenant payment page URL should be used as fallback',
            );
            xappsPhpAssertSame('pay_guard_456', (string) (($handler->upserted['payment_session_id'] ?? '')));
            xappsPhpAssertSame('tenant', (string) (($handler->upserted['issuer'] ?? '')));
        },
    ],
];
