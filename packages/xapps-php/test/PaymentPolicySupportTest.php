<?php

declare(strict_types=1);

use Xapps\PaymentPolicySupport;

return [
    [
        'name' => 'PaymentPolicySupport resolves merged guard context',
        'run' => static function (): void {
            $context = PaymentPolicySupport::resolveMergedPaymentGuardContext([
                'context' => [
                    'client_id' => 'client_123',
                ],
                'xapps_resume' => rtrim(strtr(base64_encode(json_encode([
                    'xapp_id' => 'xapp_demo',
                    'tool_name' => 'submit_demo',
                    'subject_id' => 'sub_123',
                    'installation_id' => 'inst_123',
                    'return_url' => 'https://tenant.example.test/return',
                ], JSON_THROW_ON_ERROR)), '+/', '-_'), '='),
            ]);

            xappsPhpAssertSame('xapp_demo', (string) ($context['xapp_id'] ?? ''));
            xappsPhpAssertSame('submit_demo', (string) ($context['tool_name'] ?? ''));
            xappsPhpAssertSame('client_123', (string) ($context['client_id'] ?? ''));
            xappsPhpAssertSame('https://tenant.example.test/return', (string) ($context['return_url'] ?? ''));
        },
    ],
    [
        'name' => 'PaymentPolicySupport normalizes issuers, prices, verification hints, and actions',
        'run' => static function (): void {
            xappsPhpAssertSame(
                ['gateway', 'tenant_delegated'],
                PaymentPolicySupport::normalizePaymentAllowedIssuers([
                    'payment_allowed_issuers' => ['Gateway', 'tenant_delegated', 'bad'],
                ], 'tenant'),
            );
            xappsPhpAssertSame(
                7.5,
                PaymentPolicySupport::resolvePaymentGuardPriceAmount([
                    'pricing' => [
                        'default_amount' => 3,
                        'tool_overrides' => ['xapp_demo:submit_demo' => 7.5],
                    ],
                ], [
                    'xapp_id' => 'xapp_demo',
                    'tool_name' => 'submit_demo',
                ]),
            );
            xappsPhpAssertTrue(PaymentPolicySupport::hasUpstreamPaymentVerified(['receipt_id' => 'rcpt_1']));
            xappsPhpAssertSame([
                'kind' => 'complete_payment',
                'url' => 'https://tenant.example.test/pay',
                'label' => 'Pay now',
                'title' => 'Complete payment',
                'target' => '_blank',
            ], PaymentPolicySupport::buildPaymentGuardAction([
                'url' => 'https://tenant.example.test/pay',
                'label' => 'Pay now',
                'title' => 'Complete payment',
                'target' => '_blank',
            ]));
        },
    ],
];
