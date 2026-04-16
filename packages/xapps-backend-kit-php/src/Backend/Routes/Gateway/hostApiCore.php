<?php

declare(strict_types=1);

function xapps_backend_kit_register_host_api_core(array &$routes, array $app, array $allowedOrigins = [], array $bootstrap = []): void
{
    $subjectProfiles = xapps_backend_kit_read_record($app['subjectProfileOptions'] ?? []);
    $resolveCatalogCustomerProfile = $subjectProfiles['resolveCatalogCustomerProfile'] ?? null;

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
                    'subjectId' => $body['subjectId'] ?? null,
                    'type' => $body['type'] ?? null,
                    'identifier' => is_array($body['identifier'] ?? null) ? $body['identifier'] : null,
                    'email' => $body['email'] ?? null,
                    'name' => $body['name'] ?? null,
                    'metadata' => is_array($body['metadata'] ?? null) ? $body['metadata'] : null,
                    'linkId' => $body['linkId'] ?? ($body['link_id'] ?? null),
                ]);
                xapps_backend_kit_send_json(
                    xapps_backend_kit_build_host_bootstrap_result([
                        'subjectId' => $resolved['subjectId'] ?? null,
                        'email' => $resolved['email'] ?? ($body['email'] ?? null),
                        'name' => $resolved['name'] ?? ($body['name'] ?? null),
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
                    'subjectId' => $body['subjectId'] ?? null,
                    'type' => $body['type'] ?? null,
                    'identifier' => is_array($body['identifier'] ?? null) ? $body['identifier'] : null,
                    'email' => $body['email'] ?? null,
                    'name' => $body['name'] ?? null,
                    'metadata' => is_array($body['metadata'] ?? null) ? $body['metadata'] : null,
                    'linkId' => $body['linkId'] ?? ($body['link_id'] ?? null),
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
                        'customerProfile' => is_array($body['customerProfile'] ?? null)
                            ? $body['customerProfile']
                            : null,
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
        'path' => '/api/catalog-customer-profile',
        'handler' => static function (array $request) use ($allowedOrigins, $bootstrap, $resolveCatalogCustomerProfile): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $body = xapps_backend_kit_read_record($request['body']);
                $bootstrapContext = xapps_backend_kit_read_host_bootstrap_context($request, $bootstrap);
                $subjectId = xapps_backend_kit_read_string(
                    $bootstrapContext['subjectId'] ?? null,
                    $body['subjectId'] ?? null,
                );
                if ($subjectId === '') {
                    xapps_backend_kit_send_json(
                        ['ok' => true, 'customerProfile' => null],
                        200,
                        xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                    );
                    return;
                }
                if (!is_callable($resolveCatalogCustomerProfile)) {
                    xapps_backend_kit_send_json(
                        ['ok' => true, 'customerProfile' => null],
                        200,
                        xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                    );
                    return;
                }
                $resolved = call_user_func($resolveCatalogCustomerProfile, [
                    'subjectId' => $subjectId,
                    'xappId' => xapps_backend_kit_read_string($body['xappId'] ?? null),
                    'profileFamily' => xapps_backend_kit_read_string(
                        $body['profile_family'] ?? null,
                        $body['profileFamily'] ?? null,
                    ),
                    'xappSlug' => xapps_backend_kit_read_string(
                        $body['xapp_slug'] ?? null,
                        $body['xappSlug'] ?? null,
                    ),
                    'toolName' => xapps_backend_kit_read_string(
                        $body['tool_name'] ?? null,
                        $body['toolName'] ?? null,
                    ),
                ]);
                $customerProfile = xapps_backend_kit_read_record($resolved);
                xapps_backend_kit_send_json(
                    ['ok' => true, 'customerProfile' => count($customerProfile) > 0 ? $customerProfile : null],
                    200,
                    xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_send_service_error($error, 'catalog-customer-profile failed');
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
