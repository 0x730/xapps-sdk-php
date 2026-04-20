<?php

declare(strict_types=1);

function xapps_backend_kit_register_guard_routes(array &$routes, array $app, array $options = []): void
{
    $routes[] = [
        'method' => 'POST',
        'path' => '/xapps/requests',
        'handler' => static function (array $request) use ($app, $options): void {
            $headers = $request['headers'];
            $key = trim((string) ($headers['x-api-key'] ?? ''));
            if ($key === '' || $key !== (string) $app['config']['guardIngestApiKey']) {
                xapps_backend_kit_send_json([
                    'status' => 'error',
                    'result' => ['message' => 'Invalid API key'],
                ], 401);
                return;
            }

            $body = xapps_backend_kit_read_record($request['body']);
            $requestId = xapps_backend_kit_read_string($body['requestId'] ?? null, $body['request_id'] ?? null);
            $toolName = xapps_backend_kit_read_string($body['toolName'] ?? null, $body['tool_name'] ?? null);
            $payload = xapps_backend_kit_read_record($body['payload'] ?? null);
            if ($requestId === '' || $toolName === '') {
                xapps_backend_kit_send_json([
                    'status' => 'error',
                    'result' => ['message' => 'requestId and toolName are required'],
                ], 400);
                return;
            }

            if ($toolName !== 'evaluate_tenant_payment_policy') {
                xapps_backend_kit_send_json([
                    'status' => 'error',
                    'result' => ['message' => 'Unsupported tool: ' . $toolName],
                ], 400);
                return;
            }

            try {
                $paymentRuntime = xapps_backend_kit_read_record($options['paymentRuntime'] ?? null);
                $resolver = $paymentRuntime['resolvePolicyRequest'] ?? null;
                $result = is_callable($resolver)
                    ? $resolver($payload, $app, $options['enabledModes'] ?? null)
                    : xapps_backend_kit_resolve_backend_payment_policy($payload, $app, $options['enabledModes'] ?? null);
                xapps_backend_kit_send_json([
                    'status' => 'success',
                    'result' => $result,
                ], 200);
            } catch (\Throwable $error) {
                error_log('[xconectb] guard evaluation failed for ' . $requestId . ': ' . $error->getMessage());
                xapps_backend_kit_send_json([
                    'status' => 'success',
                    'result' => xapps_backend_kit_payment_guard_fail_closed_result(
                        (int) (($error instanceof \Xapps\XappsSdkError ? $error->status : 500) ?: 500),
                    ),
                ], 200);
            }
        },
    ];
}
