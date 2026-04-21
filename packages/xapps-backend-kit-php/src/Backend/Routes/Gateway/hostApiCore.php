<?php

declare(strict_types=1);

function xapps_backend_kit_host_rate_limit_accepted(mixed $allowed): bool
{
    return $allowed === true || (is_array($allowed) && (($allowed['allowed'] ?? true) !== false));
}

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
            $body = xapps_backend_kit_read_record($request['body'] ?? null);
            $requestedOrigin = xapps_backend_kit_optional_string($body['origin'] ?? null);
            $requestedSubjectId = xapps_backend_kit_optional_string($body['subjectId'] ?? null);
            $requestedType = xapps_backend_kit_optional_string($body['type'] ?? null);
            $requestedEmail = xapps_backend_kit_optional_string($body['email'] ?? null);
            $requestedName = xapps_backend_kit_optional_string($body['name'] ?? null);
            $requestedLinkId = xapps_backend_kit_optional_string($body['linkId'] ?? ($body['link_id'] ?? null));
            $validatedApiKey = null;
            try {
                $bootstrapRequest = xapps_backend_kit_require_host_bootstrap_request($request, $bootstrap);
                $validatedApiKey = $bootstrapRequest['apiKey'] ?? null;
                $rateLimitBootstrap = $bootstrap['rateLimitBootstrap'] ?? null;
                if (is_callable($rateLimitBootstrap)) {
                    $allowed = $rateLimitBootstrap([
                        'request' => $request,
                        'apiKey' => $bootstrapRequest['apiKey'] ?? null,
                        'origin' => $requestedOrigin,
                        'subjectId' => $requestedSubjectId,
                        'type' => $requestedType,
                        'identifier' => is_array($body['identifier'] ?? null) ? $body['identifier'] : null,
                        'email' => $requestedEmail,
                        'name' => $requestedName,
                        'linkId' => $requestedLinkId,
                    ]);
                    if (!xapps_backend_kit_host_rate_limit_accepted($allowed)) {
                        throw new RuntimeException('Host bootstrap rate limit exceeded');
                    }
                }
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
                $result = xapps_backend_kit_build_host_bootstrap_result([
                    'subjectId' => $resolved['subjectId'] ?? null,
                    'email' => $resolved['email'] ?? ($body['email'] ?? null),
                    'name' => $resolved['name'] ?? ($body['name'] ?? null),
                    'origin' => $origin,
                    'signingSecret' => $bootstrapRequest['signingSecret'],
                    'signingKeyId' => $bootstrapRequest['signingKeyId'] ?? null,
                    'ttlSeconds' => $bootstrapRequest['ttlSeconds'],
                ]);
                xapps_backend_kit_run_hook_safely($request, $bootstrap['auditBootstrap'] ?? null, [
                    'request' => $request,
                    'ok' => true,
                    'apiKey' => $bootstrapRequest['apiKey'] ?? null,
                    'origin' => $origin,
                    'subjectId' => xapps_backend_kit_optional_string($resolved['subjectId'] ?? null) ?? $requestedSubjectId,
                    'type' => $requestedType,
                    'identifier' => is_array($body['identifier'] ?? null) ? $body['identifier'] : null,
                    'email' => xapps_backend_kit_optional_string($resolved['email'] ?? ($body['email'] ?? null)),
                    'name' => xapps_backend_kit_optional_string($resolved['name'] ?? ($body['name'] ?? null)),
                    'linkId' => $requestedLinkId,
                    'token' => xapps_backend_kit_optional_string($result['bootstrapToken'] ?? null),
                ], 'host-bootstrap audit hook failed');
                xapps_backend_kit_send_json(
                    $result,
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_run_hook_safely($request, $bootstrap['auditBootstrap'] ?? null, [
                    'request' => $request,
                    'ok' => false,
                    'apiKey' => $validatedApiKey,
                    'origin' => $requestedOrigin,
                    'subjectId' => $requestedSubjectId,
                    'type' => $requestedType,
                    'identifier' => is_array($body['identifier'] ?? null) ? $body['identifier'] : null,
                    'email' => $requestedEmail,
                    'name' => $requestedName,
                    'linkId' => $requestedLinkId,
                    'reason' => $error->getMessage(),
                ], 'host-bootstrap audit hook failed');
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
                    if (!xapps_backend_kit_host_rate_limit_accepted($allowed)) {
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
                xapps_backend_kit_run_hook_safely($request, $session['auditExchange'] ?? null, [
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
                ], 'host-session exchange audit hook failed');
                xapps_backend_kit_send_json(
                    $result['payload'],
                    200,
                    array_merge(
                        xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                        ['Set-Cookie' => [(string) ($result['setCookie'] ?? '')]],
                    ),
                );
            } catch (\Throwable $error) {
                try {
                    $context = xapps_backend_kit_read_host_bootstrap_context($request, $bootstrap);
                    if (is_array($context)) {
                        xapps_backend_kit_run_hook_safely($request, $session['auditExchange'] ?? null, [
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
                        ], 'host-session exchange audit hook failed');
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
        'handler' => static function (array $request) use ($app, $bootstrap, $session, $allowedOrigins): void {
            if (!xapps_backend_kit_enforce_browser_unsafe_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            xapps_backend_kit_warn_deprecated_host_bootstrap_header($bootstrap, $request, '/api/host-session/logout');
            $sessionContext = null;
            try {
                $sessionContext = xapps_backend_kit_read_host_session_context($request, $session);
                $rateLimitLogout = $session['rateLimitLogout'] ?? null;
                if (is_callable($rateLimitLogout)) {
                    $allowed = $rateLimitLogout([
                        'request' => $request,
                        'subjectId' => xapps_backend_kit_optional_string($sessionContext['subjectId'] ?? null),
                        'jti' => xapps_backend_kit_optional_string($sessionContext['jti'] ?? null),
                        'iat' => is_numeric($sessionContext['iat'] ?? null) ? (int) $sessionContext['iat'] : null,
                        'exp' => is_numeric($sessionContext['exp'] ?? null) ? (int) $sessionContext['exp'] : null,
                        'token' => xapps_backend_kit_optional_string($sessionContext['token'] ?? null),
                        'type' => 'host_session',
                    ]);
                    if (!xapps_backend_kit_host_rate_limit_accepted($allowed)) {
                        throw new RuntimeException('Host session logout rate limit exceeded');
                    }
                }
                if (is_array($sessionContext)) {
                    xapps_backend_kit_revoke_host_session($sessionContext, $session);
                    xapps_backend_kit_run_hook_safely($request, $session['auditRevocation'] ?? null, [
                        'request' => $request,
                        'ok' => true,
                        'phase' => 'local_revoke',
                        'subjectId' => xapps_backend_kit_optional_string($sessionContext['subjectId'] ?? null),
                        'jti' => xapps_backend_kit_optional_string($sessionContext['jti'] ?? null),
                        'iat' => is_numeric($sessionContext['iat'] ?? null) ? (int) $sessionContext['iat'] : null,
                        'exp' => is_numeric($sessionContext['exp'] ?? null) ? (int) $sessionContext['exp'] : null,
                        'token' => xapps_backend_kit_optional_string($sessionContext['token'] ?? null),
                        'source' => 'tenant_host_logout',
                    ], 'host-session revocation audit hook failed');
                    $reporter = is_object($app['hostProxyService'] ?? null)
                        && method_exists($app['hostProxyService'], 'reportHostSessionRevocation')
                        ? [$app['hostProxyService'], 'reportHostSessionRevocation']
                        : null;
                    if (is_callable($reporter)) {
                        $hostSessionJti = trim((string) ($sessionContext['jti'] ?? ''));
                        $exp = isset($sessionContext['exp']) ? (int) $sessionContext['exp'] : 0;
                        if ($hostSessionJti !== '' && $exp > 0) {
                            try {
                                call_user_func($reporter, [
                                    'hostSessionJti' => $hostSessionJti,
                                    'exp' => $exp,
                                    'revokedAt' => time(),
                                    'source' => 'tenant_host_logout',
                                ]);
                                xapps_backend_kit_run_hook_safely($request, $session['auditRevocation'] ?? null, [
                                    'request' => $request,
                                    'ok' => true,
                                    'phase' => 'gateway_report',
                                    'subjectId' => xapps_backend_kit_optional_string($sessionContext['subjectId'] ?? null),
                                    'jti' => xapps_backend_kit_optional_string($sessionContext['jti'] ?? null),
                                    'iat' => is_numeric($sessionContext['iat'] ?? null) ? (int) $sessionContext['iat'] : null,
                                    'exp' => is_numeric($sessionContext['exp'] ?? null) ? (int) $sessionContext['exp'] : null,
                                    'token' => xapps_backend_kit_optional_string($sessionContext['token'] ?? null),
                                    'source' => 'tenant_host_logout',
                                ], 'host-session revocation audit hook failed');
                            } catch (\Throwable $error) {
                                xapps_backend_kit_run_hook_safely($request, $session['auditRevocation'] ?? null, [
                                    'request' => $request,
                                    'ok' => false,
                                    'phase' => 'gateway_report',
                                    'subjectId' => xapps_backend_kit_optional_string($sessionContext['subjectId'] ?? null),
                                    'jti' => xapps_backend_kit_optional_string($sessionContext['jti'] ?? null),
                                    'iat' => is_numeric($sessionContext['iat'] ?? null) ? (int) $sessionContext['iat'] : null,
                                    'exp' => is_numeric($sessionContext['exp'] ?? null) ? (int) $sessionContext['exp'] : null,
                                    'token' => xapps_backend_kit_optional_string($sessionContext['token'] ?? null),
                                    'source' => 'tenant_host_logout',
                                    'reason' => $error->getMessage(),
                                ], 'host-session revocation audit hook failed');
                                error_log('host-session revocation propagation failed: ' . $error->getMessage());
                            }
                        }
                    }
                }
                xapps_backend_kit_run_hook_safely($request, $session['auditLogout'] ?? null, [
                    'request' => $request,
                    'ok' => true,
                    'subjectId' => xapps_backend_kit_optional_string($sessionContext['subjectId'] ?? null),
                    'jti' => xapps_backend_kit_optional_string($sessionContext['jti'] ?? null),
                    'iat' => is_numeric($sessionContext['iat'] ?? null) ? (int) $sessionContext['iat'] : null,
                    'exp' => is_numeric($sessionContext['exp'] ?? null) ? (int) $sessionContext['exp'] : null,
                    'token' => xapps_backend_kit_optional_string($sessionContext['token'] ?? null),
                ], 'host-session logout audit hook failed');
                xapps_backend_kit_send_json(
                    [
                        'ok' => true,
                        'status' => 'revoked',
                        'sessionMode' => 'host_session',
                    ],
                    200,
                    array_merge(
                        xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                        ['Set-Cookie' => [xapps_backend_kit_build_cleared_host_session_cookie_header($request, $session)]],
                    ),
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_run_hook_safely($request, $session['auditLogout'] ?? null, [
                    'request' => $request,
                    'ok' => false,
                    'subjectId' => xapps_backend_kit_optional_string($sessionContext['subjectId'] ?? null),
                    'jti' => xapps_backend_kit_optional_string($sessionContext['jti'] ?? null),
                    'iat' => is_numeric($sessionContext['iat'] ?? null) ? (int) $sessionContext['iat'] : null,
                    'exp' => is_numeric($sessionContext['exp'] ?? null) ? (int) $sessionContext['exp'] : null,
                    'token' => xapps_backend_kit_optional_string($sessionContext['token'] ?? null),
                    'reason' => $error->getMessage(),
                ], 'host-session logout audit hook failed');
                xapps_backend_kit_send_service_error($error, 'host session logout failed');
            }
        },
    ];

    $routes[] = [
        'method' => 'POST',
        'path' => '/api/resolve-subject',
        'handler' => static function (array $request) use ($app, $allowedOrigins, $bootstrap, $session): void {
            if (!xapps_backend_kit_enforce_browser_unsafe_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            xapps_backend_kit_warn_deprecated_host_bootstrap_header($bootstrap, $request, '/api/resolve-subject');
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
            if (!xapps_backend_kit_enforce_browser_unsafe_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            xapps_backend_kit_warn_deprecated_host_bootstrap_header($bootstrap, $request, '/api/create-catalog-session');
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
                        'hostSessionJti' => xapps_backend_kit_optional_string($bootstrapContext['jti'] ?? null),
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
            if (!xapps_backend_kit_enforce_browser_unsafe_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            xapps_backend_kit_warn_deprecated_host_bootstrap_header($bootstrap, $request, '/api/catalog-customer-profile');
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
            if (!xapps_backend_kit_enforce_browser_unsafe_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            xapps_backend_kit_warn_deprecated_host_bootstrap_header($bootstrap, $request, '/api/create-widget-session');
            try {
                $input = xapps_backend_kit_widget_session_input(xapps_backend_kit_read_record($request['body']), $request);
                $bootstrapContext = xapps_backend_kit_read_host_auth_context($request, $session);
                $input['subjectId'] = xapps_backend_kit_resolve_trusted_host_subject_id(
                    $request,
                    $bootstrapContext,
                    $input['subjectId'] ?? null,
                    $session,
                );
                if (($bootstrapContext['jti'] ?? null) !== null) {
                    $input['hostSessionJti'] = xapps_backend_kit_optional_string($bootstrapContext['jti'] ?? null);
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
