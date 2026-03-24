<?php

declare(strict_types=1);

use Xapps\PaymentReturn;

return [
    [
        'name' => 'PaymentReturn signs, builds query params, parses, and verifies evidence',
        'run' => static function (): void {
            $secret = 'payment-secret';
            $evidence = [
                'contract' => PaymentReturn::CONTRACT_V1,
                'payment_session_id' => 'pay_test_1',
                'status' => 'paid',
                'receipt_id' => 'rcpt_test_1',
                'amount' => '7.25',
                'currency' => 'usd',
                'ts' => gmdate('c', 1710000000),
                'issuer' => 'gateway',
                'xapp_id' => 'xapp_demo',
                'tool_name' => 'submit_demo',
                'subject_id' => 'sub_1',
                'installation_id' => 'inst_1',
                'client_id' => 'client_1',
            ];
            $signed = $evidence;
            $signed['sig'] = PaymentReturn::signPaymentReturnEvidence($evidence, $secret);

            $params = PaymentReturn::buildPaymentReturnQueryParams($signed, 'resume_1');
            xappsPhpAssertSame('xapp_demo', $params['xapp_id'], 'xapp_id should stay plain in query params');
            xappsPhpAssertSame('submit_demo', $params['tool_name'], 'tool_name should stay plain in query params');

            $parsed = PaymentReturn::parsePaymentReturnEvidence($params);
            xappsPhpAssertTrue(is_array($parsed), 'parsed payment return should be an array');
            xappsPhpAssertSame('7.25', $parsed['amount'], 'amount should be canonicalized');

            $verified = PaymentReturn::verifyPaymentReturnEvidence([
                'evidence' => $parsed,
                'secret' => $secret,
                'expected' => [
                    'issuer' => 'gateway',
                    'xapp_id' => 'xapp_demo',
                    'tool_name' => 'submit_demo',
                    'amount' => '7.25',
                    'currency' => 'USD',
                ],
                'nowMs' => 1710000000 * 1000,
            ]);
            xappsPhpAssertTrue($verified['ok'] === true, 'payment return should verify');
        },
    ],
    [
        'name' => 'PaymentReturn resolves env and file secret refs',
        'run' => static function (): void {
            putenv('XAPPS_PHP_TEST_SECRET=env-secret');
            $filePath = sys_get_temp_dir() . '/xapps-php-secret-' . bin2hex(random_bytes(4)) . '.txt';
            file_put_contents($filePath, 'file-secret');

            try {
                xappsPhpAssertSame(
                    'env-secret',
                    PaymentReturn::resolveSecretFromRef('env:XAPPS_PHP_TEST_SECRET'),
                    'env secret ref should resolve',
                );
                xappsPhpAssertSame(
                    'file-secret',
                    PaymentReturn::resolveSecretFromRef('file:' . $filePath),
                    'file secret ref should resolve',
                );
            } finally {
                @unlink($filePath);
            }
        },
    ],
];
