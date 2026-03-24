<?php

declare(strict_types=1);

use Xapps\BackendKit\BackendKit;

function xapps_backend_kit_register_payment_routes(array &$routes, array $app, array $options = []): void
{
    $enabledModes = $options['enabledModes'] ?? null;
    xapps_backend_kit_register_backend_mode_routes(
        $routes,
        $app,
        $enabledModes,
        xapps_backend_kit_read_record($options['paymentRuntime'] ?? null),
    );

    if (in_array('owner_managed', xapps_backend_kit_normalize_enabled_backend_modes($enabledModes), true)) {
        $routes[] = [
            'method' => 'GET',
            'path' => '/tenant-payment.html',
            'handler' => static function () use ($app): void {
                $runtime = xapps_backend_kit_read_record($app['paymentRuntimeOptions'] ?? null);
                $paymentPageFile = xapps_backend_kit_read_string(
                    $runtime['paymentPageFile'] ?? null,
                    (string) (($app['config']['hostPages']['tenantPayment'] ?? '')),
                );
                xapps_backend_kit_send_file(
                    $paymentPageFile,
                    'text/html; charset=utf-8',
                    200,
                    $app['hostProxyService']->getNoStoreHeaders(),
                );
            },
        ];
    }

    $routes[] = [
        'method' => 'POST',
        'path' => '/api/payment/return/verify',
        'handler' => static function (array $request) use ($app): void {
            $body = xapps_backend_kit_read_record($request['body']);
            $payload = xapps_backend_kit_read_record($body['payload'] ?? null);
            $expected = xapps_backend_kit_read_record($body['expected'] ?? null);
            try {
                $result = BackendKit::verifyPaymentEvidence(
                    $app,
                    count($payload) > 0 ? $payload : $body,
                    $expected,
                    (int) ($body['maxAgeSeconds'] ?? $body['max_age_seconds'] ?? 900),
                );
                xapps_backend_kit_send_json([
                    'status' => 'success',
                    'result' => $result,
                ], 200);
            } catch (\Throwable $error) {
                xapps_backend_kit_send_service_error($error, 'payment return verification failed');
            }
        },
    ];
}
