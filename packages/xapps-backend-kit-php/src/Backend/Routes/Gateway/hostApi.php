<?php

declare(strict_types=1);

function xapps_backend_kit_register_host_api_routes(array &$routes, array $app, array $options = []): void
{
    $allowedOrigins = xapps_backend_kit_read_list($options['allowedOrigins'] ?? []);
    $bootstrap = xapps_backend_kit_read_record($options['bootstrap'] ?? []);
    $preflightPaths = [
        '/api/host-config',
        '/api/resolve-subject',
        '/api/create-catalog-session',
        '/api/create-widget-session',
    ];
    if (($options['enableLifecycle'] ?? true) !== false) {
        $preflightPaths = array_merge($preflightPaths, [
            '/api/installations',
            '/api/install',
            '/api/update',
            '/api/uninstall',
            '/api/widget-tool-request',
            '/api/my-xapps/:xappId/monetization',
            '/api/my-xapps/:xappId/monetization/history',
            '/api/my-xapps/:xappId/monetization/purchase-intents/prepare',
            '/api/my-xapps/:xappId/monetization/purchase-intents/:intentId/payment-session',
            '/api/my-xapps/:xappId/monetization/purchase-intents/:intentId/payment-session/finalize',
        ]);
    }
    if (($options['enableBridge'] ?? true) !== false) {
        $preflightPaths = array_merge($preflightPaths, [
            '/api/bridge/token-refresh',
            '/api/bridge/sign',
            '/api/bridge/vendor-assertion',
        ]);
    }

    foreach ($preflightPaths as $path) {
        $routes[] = [
            'method' => 'OPTIONS',
            'path' => $path,
            'handler' => static function (array $request) use ($allowedOrigins): void {
                xapps_backend_kit_send_host_api_preflight($request, $allowedOrigins);
            },
        ];
    }

    xapps_backend_kit_register_host_api_core($routes, $app, $allowedOrigins, $bootstrap);
    if (($options['enableLifecycle'] ?? true) !== false) {
        xapps_backend_kit_register_host_api_lifecycle($routes, $app, $allowedOrigins, $bootstrap);
    }
    if (($options['enableBridge'] ?? true) !== false) {
        xapps_backend_kit_register_host_api_bridge($routes, $app, $allowedOrigins, $bootstrap);
    }
}
