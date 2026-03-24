<?php

declare(strict_types=1);

use Xapps\CallbackClient;

return [
    [
        'name' => 'CallbackClient sends event payloads with generated idempotency keys',
        'run' => static function (): void {
            $client = new CallbackClient(
                xappsPhpTestBaseUrl(),
                'callback-token',
                [
                    'idempotencyKeyFactory' => static function (array $input): string {
                        return 'xapps:' . $input['operation'] . ':' . $input['requestId'];
                    },
                ],
            );

            $response = $client->sendEvent('req_1', [
                'type' => 'request.updated',
                'data' => ['state' => 'ok'],
            ]);

            xappsPhpAssertSame(200, $response['status'], 'event callback should succeed');
            xappsPhpAssertSame('xapps:event:req_1', (string) ($response['body']['idempotency_key'] ?? ''));
            xappsPhpAssertSame('request.updated', (string) ($response['body']['payload']['type'] ?? ''));
        },
    ],
    [
        'name' => 'CallbackClient completes requests and preserves explicit idempotency keys',
        'run' => static function (): void {
            $client = new CallbackClient(xappsPhpTestBaseUrl(), 'callback-token');

            $response = $client->complete(
                'req_2',
                [
                    'status' => 'completed',
                    'output' => ['ok' => true],
                ],
                'complete-key',
            );

            xappsPhpAssertSame(200, $response['status'], 'complete callback should succeed');
            xappsPhpAssertSame('complete-key', (string) ($response['body']['idempotency_key'] ?? ''));
            xappsPhpAssertSame('completed', (string) ($response['body']['payload']['status'] ?? ''));
        },
    ],
];
