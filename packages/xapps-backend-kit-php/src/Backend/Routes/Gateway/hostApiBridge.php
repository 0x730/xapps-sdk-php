<?php

declare(strict_types=1);

function xapps_backend_kit_register_host_api_bridge(array &$routes, array $app, array $allowedOrigins = [], array $bootstrap = []): void
{
    $routes[] = [
        'method' => 'POST',
        'path' => '/api/bridge/token-refresh',
        'handler' => static function (array $request) use ($app, $allowedOrigins, $bootstrap): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $input = xapps_backend_kit_bridge_refresh_input(xapps_backend_kit_read_record($request['body']), $request);
                $bootstrapContext = xapps_backend_kit_read_host_bootstrap_context($request, $bootstrap);
                if (($bootstrapContext['subjectId'] ?? null) !== null) {
                    $input['subjectId'] = $bootstrapContext['subjectId'];
                }
                xapps_backend_kit_send_json(
                    $app['hostProxyService']->refreshWidgetToken($input),
                    200,
                    xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_send_service_error($error, 'bridge token-refresh failed');
            }
        },
    ];

    $routes[] = [
        'method' => 'POST',
        'path' => '/api/bridge/sign',
        'handler' => static function (array $request) use ($app, $allowedOrigins): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $body = xapps_backend_kit_read_record($request['body']);
                xapps_backend_kit_send_json(
                    $app['hostProxyService']->bridgeSign([
                        'envelope' => is_array($body['envelope'] ?? null) ? $body['envelope'] : null,
                        'data' => $body,
                    ]),
                    200,
                    xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_send_service_error($error, 'bridge sign failed');
            }
        },
    ];

    $routes[] = [
        'method' => 'POST',
        'path' => '/api/bridge/vendor-assertion',
        'handler' => static function (array $request) use ($app, $allowedOrigins): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $body = xapps_backend_kit_read_record($request['body']);
                xapps_backend_kit_send_json(
                    $app['hostProxyService']->bridgeVendorAssertion(
                        xapps_backend_kit_bridge_vendor_assertion_input($body),
                    ),
                    200,
                    xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_send_service_error($error, 'bridge vendor-assertion failed');
            }
        },
    ];
}
