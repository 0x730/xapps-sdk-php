<?php

declare(strict_types=1);

function xapps_backend_kit_register_embed_sdk_route(array &$routes, array $app): void
{
    $routes[] = [
        'method' => 'GET',
        'path' => '/embed/sdk/xapps-embed-sdk.esm.js',
        'handler' => static function () use ($app): void {
            $candidateFiles = $app['config']['embedSdkCandidateFiles'] ?? [];
            if (is_array($candidateFiles)) {
                foreach ($candidateFiles as $filePath) {
                    if (is_string($filePath) && is_file($filePath)) {
                        xapps_backend_kit_send_file(
                            $filePath,
                            'application/javascript; charset=utf-8',
                            200,
                            $app['hostProxyService']->getNoStoreHeaders(),
                        );
                        return;
                    }
                }
            }

            $gatewayUrl = rtrim((string) ($app['config']['gatewayUrl'] ?? ''), '/');
            if (!preg_match('/^https?:\/\//i', $gatewayUrl)) {
                xapps_backend_kit_send_json(['message' => 'embed sdk not built'], 404);
                return;
            }

            $upstreamUrl = $gatewayUrl . '/embed/sdk/xapps-embed-sdk.esm.js';
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 10,
                    'ignore_errors' => true,
                ],
            ]);
            $content = @file_get_contents($upstreamUrl, false, $context);
            $statusLine = is_array($http_response_header ?? null) ? (string) ($http_response_header[0] ?? '') : '';
            if ($content === false || !preg_match('/\s200\s/', $statusLine)) {
                xapps_backend_kit_send_json(['message' => 'embed sdk not available'], 502);
                return;
            }

            xapps_backend_kit_send_text(
                $content,
                'application/javascript; charset=utf-8',
                200,
                $app['hostProxyService']->getNoStoreHeaders(),
            );
        },
    ];
}
