<?php

declare(strict_types=1);

function xapps_backend_kit_register_host_api_bridge(array &$routes, array $app, array $allowedOrigins = [], array $bootstrap = [], array $session = []): void
{
    $routes[] = [
        'method' => 'POST',
        'path' => '/api/bridge/token-refresh',
        'handler' => static function (array $request) use ($app, $allowedOrigins, $bootstrap, $session): void {
            if (!xapps_backend_kit_enforce_browser_unsafe_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            xapps_backend_kit_warn_deprecated_host_bootstrap_header($bootstrap, $request, '/api/bridge/token-refresh');
            try {
                $input = xapps_backend_kit_bridge_refresh_input(xapps_backend_kit_read_record($request['body']), $request);
                $bootstrapContext = xapps_backend_kit_read_host_auth_context($request, $session);
                if (($bootstrapContext['subjectId'] ?? null) !== null) {
                    $input['subjectId'] = $bootstrapContext['subjectId'];
                }
                if (($bootstrapContext['jti'] ?? null) !== null) {
                    $input['hostSessionJti'] = xapps_backend_kit_optional_string($bootstrapContext['jti'] ?? null);
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
        'handler' => static function (array $request) use ($app, $allowedOrigins, $bootstrap, $session): void {
            if (!xapps_backend_kit_enforce_browser_unsafe_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            xapps_backend_kit_warn_deprecated_host_bootstrap_header($bootstrap, $request, '/api/bridge/sign');
            try {
                $body = xapps_backend_kit_read_record($request['body']);
                $authContext = xapps_backend_kit_read_host_auth_context($request, $session);
                $data = $body;
                if (($authContext['subjectId'] ?? null) !== null) {
                    $data['subjectId'] = $authContext['subjectId'];
                    $data['subject_id'] = $authContext['subjectId'];
                }
                $envelope = is_array($body['envelope'] ?? null) ? $body['envelope'] : null;
                xapps_backend_kit_send_json(
                    $app['hostProxyService']->bridgeSign([
                        // If a host session subject is present, force server-side signing semantics.
                        // This prevents callers from injecting a precomputed envelope with mismatched subject claims.
                        'envelope' => ($authContext['subjectId'] ?? null) !== null ? null : $envelope,
                        'data' => $data,
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
        'handler' => static function (array $request) use ($app, $allowedOrigins, $bootstrap, $session): void {
            if (!xapps_backend_kit_enforce_browser_unsafe_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            xapps_backend_kit_warn_deprecated_host_bootstrap_header($bootstrap, $request, '/api/bridge/vendor-assertion');
            try {
                $body = xapps_backend_kit_read_record($request['body']);
                $authContext = xapps_backend_kit_read_host_auth_context($request, $session);
                $input = xapps_backend_kit_bridge_vendor_assertion_input($body);
                if (($authContext['subjectId'] ?? null) !== null) {
                    $input['subjectId'] = $authContext['subjectId'];
                    if (is_array($input['data'] ?? null)) {
                        $input['data']['subjectId'] = $authContext['subjectId'];
                        $input['data']['subject_id'] = $authContext['subjectId'];
                    }
                }
                xapps_backend_kit_send_json(
                    $app['hostProxyService']->bridgeVendorAssertion($input),
                    200,
                    xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_send_service_error($error, 'bridge vendor-assertion failed');
            }
        },
    ];
}
