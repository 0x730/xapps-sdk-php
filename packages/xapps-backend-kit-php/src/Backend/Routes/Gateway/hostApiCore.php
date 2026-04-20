<?php

declare(strict_types=1);

function xapps_backend_kit_register_host_api_core(array &$routes, array $app, array $allowedOrigins = [], array $bootstrap = [], array $session = []): void
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
        'handler' => static function (array $request) use ($app, $bootstrap, $allowedOrigins): void {
            try {
                $body = xapps_backend_kit_read_record($request['body']);
                $bootstrapRequest = xapps_backend_kit_require_host_bootstrap_request($request, $bootstrap);
                $origin = xapps_backend_kit_require_requested_host_bootstrap_origin(
                    $body['origin'] ?? null,
                    xapps_backend_kit_effective_host_api_allowed_origins($request, $allowedOrigins),
                );
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
                        'origin' => $origin,
                        'signingSecret' => $bootstrapRequest['signingSecret'],
                        'signingKeyId' => $bootstrapRequest['signingKeyId'] ?? null,
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
        'path' => '/api/host-session/exchange',
        'handler' => static function (array $request) use ($bootstrap, $session, $allowedOrigins): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $bootstrapContext = xapps_backend_kit_read_host_bootstrap_context($request, $bootstrap);
                if (($bootstrapContext['subjectId'] ?? null) === null) {
                    throw new RuntimeException('Missing host bootstrap token');
                }
                $rateLimitExchange = $session['rateLimitExchange'] ?? null;
                if (is_callable($rateLimitExchange)) {
                    $allowed = $rateLimitExchange([
                        'request' => $request,
                        'subjectId' => $bootstrapContext['subjectId'] ?? null,
                        'origin' => $bootstrapContext['origin'] ?? null,
                        'jti' => $bootstrapContext['jti'] ?? null,
                        'iat' => $bootstrapContext['iat'] ?? null,
                        'exp' => $bootstrapContext['exp'] ?? null,
                        'token' => $bootstrapContext['token'] ?? null,
                        'type' => 'host_bootstrap',
                    ]);
                    $accepted = $allowed === true
                        || (is_array($allowed) && (($allowed['allowed'] ?? true) !== false));
                    if (!$accepted) {
                        throw new RuntimeException('Host session exchange rate limit exceeded');
                    }
                }
                xapps_backend_kit_consume_host_bootstrap_replay($bootstrapContext, $bootstrap);
                $signingSecret = trim((string) ($session['signingSecret'] ?? ''));
                $signingKeyId = trim((string) ($session['signingKeyId'] ?? ''));
                if ($signingSecret === '') {
                    throw new RuntimeException('Host session exchange is not configured');
                }
                $result = xapps_backend_kit_build_host_session_exchange_result([
                    'subjectId' => $bootstrapContext['subjectId'] ?? null,
                    'email' => $bootstrapContext['email'] ?? null,
                    'name' => $bootstrapContext['name'] ?? null,
                    'signingSecret' => $signingSecret,
                    'signingKeyId' => $signingKeyId !== '' ? $signingKeyId : null,
                    'ttlSeconds' => (int) ($session['absoluteTtlSeconds'] ?? 1800),
                    'session' => $session,
                ], $request);
                xapps_backend_kit_activate_host_session(
                    xapps_backend_kit_read_record($result['sessionContext'] ?? null),
                    $session,
                );
                $auditExchange = $session['auditExchange'] ?? null;
                if (is_callable($auditExchange)) {
                    $auditExchange([
                        'request' => $request,
                        'ok' => true,
                        'subjectId' => $bootstrapContext['subjectId'] ?? null,
                        'origin' => $bootstrapContext['origin'] ?? null,
                        'jti' => $bootstrapContext['jti'] ?? null,
                        'iat' => $bootstrapContext['iat'] ?? null,
                        'exp' => $bootstrapContext['exp'] ?? null,
                        'token' => $bootstrapContext['token'] ?? null,
                        'type' => 'host_bootstrap',
                        'sessionJti' => is_array($result['sessionContext'] ?? null) ? ($result['sessionContext']['jti'] ?? null) : null,
                        'sessionExp' => is_array($result['sessionContext'] ?? null) ? ($result['sessionContext']['exp'] ?? null) : null,
                    ]);
                }
                xapps_backend_kit_send_json(
                    $result['payload'],
                    200,
                    array_merge(
                        xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                        ['Set-Cookie' => (string) ($result['setCookie'] ?? '')],
                    ),
                );
            } catch (\Throwable $error) {
                try {
                    $context = xapps_backend_kit_read_host_bootstrap_context($request, $bootstrap);
                    $auditExchange = $session['auditExchange'] ?? null;
                    if (is_array($context) && is_callable($auditExchange)) {
                        $auditExchange([
                            'request' => $request,
                            'ok' => false,
                            'subjectId' => $context['subjectId'] ?? null,
                            'origin' => $context['origin'] ?? null,
                            'jti' => $context['jti'] ?? null,
                            'iat' => $context['iat'] ?? null,
                            'exp' => $context['exp'] ?? null,
                            'token' => $context['token'] ?? null,
                            'type' => 'host_bootstrap',
                            'reason' => $error->getMessage(),
                        ]);
                    }
                } catch (\Throwable) {
                }
                xapps_backend_kit_send_service_error($error, 'host session exchange failed');
            }
        },
    ];

    $routes[] = [
        'method' => 'POST',
        'path' => '/api/host-session/logout',
        'handler' => static function (array $request) use ($bootstrap, $session, $allowedOrigins): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $sessionContext = xapps_backend_kit_read_host_session_context($request, $session);
                if (is_array($sessionContext)) {
                    xapps_backend_kit_revoke_host_session($sessionContext, $session);
                }
                xapps_backend_kit_send_json(
                    [
                        'ok' => true,
                        'status' => 'revoked',
                        'sessionMode' => 'host_session',
                    ],
                    200,
                    array_merge(
                        xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                        ['Set-Cookie' => xapps_backend_kit_build_cleared_host_session_cookie_header($request, $session)],
                    ),
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_send_service_error($error, 'host session logout failed');
            }
        },
    ];

    $routes[] = [
        'method' => 'POST',
        'path' => '/api/resolve-subject',
        'handler' => static function (array $request) use ($app, $allowedOrigins, $bootstrap, $session): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $body = xapps_backend_kit_read_record($request['body']);
                xapps_backend_kit_read_host_auth_context($request, $session);
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
        'handler' => static function (array $request) use ($app, $allowedOrigins, $bootstrap, $session): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $body = xapps_backend_kit_read_record($request['body']);
                $bootstrapContext = xapps_backend_kit_read_host_auth_context($request, $session);
                $subjectId = xapps_backend_kit_resolve_trusted_host_subject_id(
                    $request,
                    $bootstrapContext,
                    $body['subjectId'] ?? null,
                    $session,
                );
                xapps_backend_kit_send_json(
                    $app['hostProxyService']->createCatalogSession([
                        'origin' => $body['origin'] ?? null,
                        'subjectId' => $subjectId,
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
        'handler' => static function (array $request) use ($allowedOrigins, $bootstrap, $session, $resolveCatalogCustomerProfile): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $body = xapps_backend_kit_read_record($request['body']);
                $bootstrapContext = xapps_backend_kit_read_host_auth_context($request, $session);
                $requestedSubjectId = xapps_backend_kit_read_string($body['subjectId'] ?? null);
                $subjectId = (($bootstrapContext['subjectId'] ?? null) !== null || $requestedSubjectId !== '')
                    ? xapps_backend_kit_resolve_trusted_host_subject_id(
                        $request,
                        $bootstrapContext,
                        $requestedSubjectId,
                        $session,
                    )
                    : '';
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
        'handler' => static function (array $request) use ($app, $allowedOrigins, $bootstrap, $session): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $input = xapps_backend_kit_widget_session_input(xapps_backend_kit_read_record($request['body']), $request);
                $bootstrapContext = xapps_backend_kit_read_host_auth_context($request, $session);
                $input['subjectId'] = xapps_backend_kit_resolve_trusted_host_subject_id(
                    $request,
                    $bootstrapContext,
                    $input['subjectId'] ?? null,
                    $session,
                );
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
