<?php

declare(strict_types=1);

function xapps_backend_kit_register_host_api_lifecycle(array &$routes, array $app, array $allowedOrigins = [], array $bootstrap = [], array $session = []): void
{
    $routes[] = [
        'method' => 'GET',
        'path' => '/api/installations',
        'handler' => static function (array $request) use ($app, $allowedOrigins, $bootstrap, $session): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $query = xapps_backend_kit_read_record($request['query']);
                $bootstrapContext = xapps_backend_kit_read_host_auth_context($request, $session);
                $subjectId = xapps_backend_kit_resolve_trusted_host_subject_id(
                    $request,
                    $bootstrapContext,
                    $query['subjectId'] ?? null,
                    $session,
                );
                xapps_backend_kit_send_json(
                    $app['hostProxyService']->listInstallations([
                        'subjectId' => $subjectId,
                    ]),
                    200,
                    xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_send_service_error($error, 'list installations failed');
            }
        },
    ];

    $routes[] = [
        'method' => 'POST',
        'path' => '/api/install',
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
                    $app['hostProxyService']->installXapp([
                        'xappId' => $body['xappId'] ?? null,
                        'subjectId' => $subjectId,
                        'termsAccepted' => $body['termsAccepted'] ?? null,
                    ]),
                    200,
                    xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_send_service_error($error, 'install failed');
            }
        },
    ];

    $routes[] = [
        'method' => 'POST',
        'path' => '/api/update',
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
                    $app['hostProxyService']->updateInstallation([
                        'installationId' => $body['installationId'] ?? null,
                        'subjectId' => $subjectId,
                        'termsAccepted' => $body['termsAccepted'] ?? null,
                    ]),
                    200,
                    xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_send_service_error($error, 'update failed');
            }
        },
    ];

    $routes[] = [
        'method' => 'POST',
        'path' => '/api/uninstall',
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
                    $app['hostProxyService']->uninstallInstallation([
                        'installationId' => $body['installationId'] ?? null,
                        'subjectId' => $subjectId,
                    ]),
                    200,
                    xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_send_service_error($error, 'uninstall failed');
            }
        },
    ];

    $routes[] = [
        'method' => 'POST',
        'path' => '/api/widget-tool-request',
        'handler' => static function (array $request) use ($app, $allowedOrigins): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $body = xapps_backend_kit_read_record($request['body']);
                xapps_backend_kit_send_json(
                    $app['hostProxyService']->runWidgetToolRequest([
                        'token' => xapps_backend_kit_read_execution_plane_token($request, $body['token'] ?? null),
                        'installationId' => $body['installationId'] ?? ($body['installation_id'] ?? null),
                        'toolName' => $body['toolName'] ?? ($body['tool_name'] ?? null),
                        'payload' => is_array($body['payload'] ?? null) ? $body['payload'] : [],
                    ]),
                    200,
                    xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_send_service_error($error, 'run widget tool request failed');
            }
        },
    ];

    $routes[] = [
        'method' => 'GET',
        'path' => '/api/my-xapps/:xappId/monetization',
        'handler' => static function (array $request) use ($app, $allowedOrigins): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $query = xapps_backend_kit_read_record($request['query']);
                $params = xapps_backend_kit_read_record($request['params']);
                xapps_backend_kit_send_json(
                    $app['hostProxyService']->getMyXappMonetization([
                        'xappId' => $params['xappId'] ?? null,
                        'token' => xapps_backend_kit_read_execution_plane_token($request, $query['token'] ?? null),
                        'installationId' => $query['installationId'] ?? null,
                        'locale' => $query['locale'] ?? null,
                        'country' => $query['country'] ?? null,
                        'realmRef' => $query['realmRef'] ?? ($query['realm_ref'] ?? null),
                    ]),
                    200,
                    xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_send_service_error($error, 'get my-xapp monetization failed');
            }
        },
    ];

    $routes[] = [
        'method' => 'GET',
        'path' => '/api/my-xapps/:xappId/monetization/history',
        'handler' => static function (array $request) use ($app, $allowedOrigins): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $query = xapps_backend_kit_read_record($request['query']);
                $params = xapps_backend_kit_read_record($request['params']);
                xapps_backend_kit_send_json(
                    $app['hostProxyService']->getMyXappMonetizationHistory([
                        'xappId' => $params['xappId'] ?? null,
                        'token' => xapps_backend_kit_read_execution_plane_token($request, $query['token'] ?? null),
                        'limit' => $query['limit'] ?? null,
                    ]),
                    200,
                    xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_send_service_error($error, 'get my-xapp monetization history failed');
            }
        },
    ];

    $routes[] = [
        'method' => 'POST',
        'path' => '/api/my-xapps/:xappId/monetization/subscription-contracts/:contractId/refresh-state',
        'handler' => static function (array $request) use ($app, $allowedOrigins): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $body = xapps_backend_kit_read_record($request['body']);
                $query = xapps_backend_kit_read_record($request['query']);
                $params = xapps_backend_kit_read_record($request['params']);
                xapps_backend_kit_send_json(
                    $app['hostProxyService']->refreshMyXappSubscriptionContractState([
                        'xappId' => $params['xappId'] ?? null,
                        'contractId' => $params['contractId'] ?? null,
                        'token' => xapps_backend_kit_read_execution_plane_token($request, $query['token'] ?? null, $body['token'] ?? null),
                    ]),
                    200,
                    xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_send_service_error($error, 'refresh my-xapp subscription contract state failed');
            }
        },
    ];

    $routes[] = [
        'method' => 'POST',
        'path' => '/api/my-xapps/:xappId/monetization/subscription-contracts/:contractId/cancel',
        'handler' => static function (array $request) use ($app, $allowedOrigins): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $body = xapps_backend_kit_read_record($request['body']);
                $query = xapps_backend_kit_read_record($request['query']);
                $params = xapps_backend_kit_read_record($request['params']);
                xapps_backend_kit_send_json(
                    $app['hostProxyService']->cancelMyXappSubscriptionContract([
                        'xappId' => $params['xappId'] ?? null,
                        'contractId' => $params['contractId'] ?? null,
                        'token' => xapps_backend_kit_read_execution_plane_token($request, $query['token'] ?? null, $body['token'] ?? null),
                    ]),
                    200,
                    xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_send_service_error($error, 'cancel my-xapp subscription failed');
            }
        },
    ];

    $routes[] = [
        'method' => 'POST',
        'path' => '/api/my-xapps/:xappId/monetization/purchase-intents/prepare',
        'handler' => static function (array $request) use ($app, $allowedOrigins): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $body = xapps_backend_kit_read_record($request['body']);
                $query = xapps_backend_kit_read_record($request['query']);
                $params = xapps_backend_kit_read_record($request['params']);
                xapps_backend_kit_send_json(
                    $app['hostProxyService']->prepareMyXappPurchaseIntent([
                        'xappId' => $params['xappId'] ?? null,
                        'token' => xapps_backend_kit_read_execution_plane_token($request, $query['token'] ?? null, $body['token'] ?? null),
                        'offeringId' => $body['offeringId'] ?? ($body['offering_id'] ?? null),
                        'packageId' => $body['packageId'] ?? ($body['package_id'] ?? null),
                        'priceId' => $body['priceId'] ?? ($body['price_id'] ?? null),
                        'installationId' => $body['installationId'] ?? ($body['installation_id'] ?? null),
                        'locale' => $body['locale'] ?? null,
                        'country' => $body['country'] ?? null,
                    ]),
                    200,
                    xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_send_service_error($error, 'prepare my-xapp purchase intent failed');
            }
        },
    ];

    $routes[] = [
        'method' => 'POST',
        'path' => '/api/my-xapps/:xappId/monetization/purchase-intents/:intentId/payment-session',
        'handler' => static function (array $request) use ($app, $allowedOrigins): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $body = xapps_backend_kit_read_record($request['body']);
                $query = xapps_backend_kit_read_record($request['query']);
                $params = xapps_backend_kit_read_record($request['params']);
                xapps_backend_kit_send_json(
                    $app['hostProxyService']->createMyXappPurchasePaymentSession([
                        'xappId' => $params['xappId'] ?? null,
                        'intentId' => $params['intentId'] ?? null,
                        'token' => xapps_backend_kit_read_execution_plane_token($request, $query['token'] ?? null, $body['token'] ?? null),
                        'returnUrl' => $body['returnUrl'] ?? ($body['return_url'] ?? null),
                        'cancelUrl' => $body['cancelUrl'] ?? ($body['cancel_url'] ?? null),
                        'xappsResume' => $body['xappsResume'] ?? ($body['xapps_resume'] ?? null),
                        'locale' => $body['locale'] ?? null,
                        'installationId' => $body['installationId'] ?? ($body['installation_id'] ?? null),
                        'paymentGuardRef' => $body['paymentGuardRef'] ?? ($body['payment_guard_ref'] ?? null),
                        'issuer' => $body['issuer'] ?? null,
                        'scheme' => $body['scheme'] ?? null,
                        'paymentScheme' => $body['paymentScheme'] ?? ($body['payment_scheme'] ?? null),
                        'metadata' => $body['metadata'] ?? null,
                    ]),
                    200,
                    xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_send_service_error($error, 'create my-xapp purchase payment-session failed');
            }
        },
    ];

    $routes[] = [
        'method' => 'POST',
        'path' => '/api/my-xapps/:xappId/monetization/purchase-intents/:intentId/payment-session/finalize',
        'handler' => static function (array $request) use ($app, $allowedOrigins): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $body = xapps_backend_kit_read_record($request['body']);
                $query = xapps_backend_kit_read_record($request['query']);
                $params = xapps_backend_kit_read_record($request['params']);
                xapps_backend_kit_send_json(
                    $app['hostProxyService']->finalizeMyXappPurchasePaymentSession([
                        'xappId' => $params['xappId'] ?? null,
                        'intentId' => $params['intentId'] ?? null,
                        'token' => xapps_backend_kit_read_execution_plane_token($request, $query['token'] ?? null, $body['token'] ?? null),
                    ]),
                    200,
                    xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_send_service_error($error, 'finalize my-xapp purchase payment-session failed');
            }
        },
    ];
}
