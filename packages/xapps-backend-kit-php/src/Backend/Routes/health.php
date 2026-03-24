<?php

declare(strict_types=1);

function xapps_backend_kit_register_health_routes(array &$routes, array $branding = [], array $reference = [], array $options = []): void
{
    $serviceName = xapps_backend_kit_read_string($branding['serviceName'] ?? null, 'tenant-backend');
    $stackLabel = xapps_backend_kit_read_string($branding['stackLabel'] ?? null);
    $mode = xapps_backend_kit_read_string($reference['mode'] ?? null, 'reference-minimum');
    $tools = array_values(array_filter(
        is_array($options['tools'] ?? null) ? $options['tools'] : [],
        static fn($value): bool => is_string($value) && trim($value) !== '',
    ));

    $routes[] = [
        'method' => 'GET',
        'path' => '/health',
        'handler' => static function () use ($serviceName, $stackLabel, $mode, $tools): void {
            $payload = [
                'ok' => true,
                'service' => $serviceName,
                'mode' => $mode,
                'time' => gmdate('c'),
            ];
            if ($stackLabel !== '') {
                $payload['stack'] = $stackLabel;
            }
            if (count($tools) > 0) {
                $payload['tools'] = $tools;
            }
            xapps_backend_kit_send_json($payload);
        },
    ];
}
