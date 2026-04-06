<?php

declare(strict_types=1);

function xapps_backend_kit_register_host_api_core(array &$routes, array $app, array $allowedOrigins = [], array $bootstrap = []): void
{
    $routes[] = [
        'method' => 'GET',
        'path' => '/api/host-config',
        'handler' => static function (array $request) use ($app, $allowedOrigins): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            $config = method_exists($app['hostProxyService'], 'getHostConfigForRequest')
                ? $app['hostProxyService']->getHostConfigForRequest($request)
                : $app['hostProxyService']->getHostConfig();
            xapps_backend_kit_send_json(
                $config,
                200,
                xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
            );
        },
    ];

    $routes[] = [
        'method' => 'POST',
        'path' => '/api/host-bootstrap',
        'handler' => static function (array $request) use ($app, $bootstrap): void {
            try {
                $body = xapps_backend_kit_read_record($request['body']);
                $bootstrapRequest = xapps_backend_kit_require_host_bootstrap_request($request, $bootstrap);
                $resolved = $app['hostProxyService']->resolveSubject([
                    'email' => $body['email'] ?? null,
                    'name' => $body['name'] ?? null,
                ]);
                xapps_backend_kit_send_json(
                    xapps_backend_kit_build_host_bootstrap_result([
                        'subjectId' => $resolved['subjectId'] ?? null,
                        'email' => $body['email'] ?? null,
                        'name' => $body['name'] ?? null,
                        'origin' => $body['origin'] ?? null,
                        'signingSecret' => $bootstrapRequest['signingSecret'],
                        'ttlSeconds' => $bootstrapRequest['ttlSeconds'],
                    ]),
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_send_service_error($error, 'host bootstrap failed');
            }
        },
    ];

    $routes[] = [
        'method' => 'POST',
        'path' => '/api/resolve-subject',
        'handler' => static function (array $request) use ($app, $allowedOrigins): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $body = xapps_backend_kit_read_record($request['body']);
                $result = $app['hostProxyService']->resolveSubject([
                    'email' => $body['email'] ?? null,
                    'name' => $body['name'] ?? null,
                ]);
                xapps_backend_kit_send_json(
                    xapps_backend_kit_subject_result($result, $body),
                    200,
                    xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_send_service_error($error, 'resolve-subject failed');
            }
        },
    ];

    $routes[] = [
        'method' => 'POST',
        'path' => '/api/create-catalog-session',
        'handler' => static function (array $request) use ($app, $allowedOrigins, $bootstrap): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $body = xapps_backend_kit_read_record($request['body']);
                $bootstrapContext = xapps_backend_kit_read_host_bootstrap_context($request, $bootstrap);
                xapps_backend_kit_send_json(
                    $app['hostProxyService']->createCatalogSession([
                        'origin' => $body['origin'] ?? null,
                        'subjectId' => $bootstrapContext['subjectId'] ?? ($body['subjectId'] ?? null),
                        'xappId' => $body['xappId'] ?? null,
                        'publishers' => is_array($body['publishers'] ?? null) ? $body['publishers'] : null,
                        'tags' => is_array($body['tags'] ?? null) ? $body['tags'] : null,
                    ]),
                    201,
                    xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_send_service_error($error, 'create-catalog-session failed');
            }
        },
    ];

    $routes[] = [
        'method' => 'POST',
        'path' => '/api/create-widget-session',
        'handler' => static function (array $request) use ($app, $allowedOrigins, $bootstrap): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $input = xapps_backend_kit_widget_session_input(xapps_backend_kit_read_record($request['body']), $request);
                $bootstrapContext = xapps_backend_kit_read_host_bootstrap_context($request, $bootstrap);
                if (($bootstrapContext['subjectId'] ?? null) !== null) {
                    $input['subjectId'] = $bootstrapContext['subjectId'];
                }
                xapps_backend_kit_send_json(
                    $app['hostProxyService']->createWidgetSession($input),
                    200,
                    xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_send_service_error($error, 'create-widget-session failed');
            }
        },
    ];
}
