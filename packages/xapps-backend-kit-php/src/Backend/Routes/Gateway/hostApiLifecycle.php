<?php

declare(strict_types=1);

function xapps_backend_kit_register_host_api_lifecycle(array &$routes, array $app, array $allowedOrigins = [], array $bootstrap = []): void
{
    $routes[] = [
        'method' => 'GET',
        'path' => '/api/installations',
        'handler' => static function (array $request) use ($app, $allowedOrigins, $bootstrap): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $query = xapps_backend_kit_read_record($request['query']);
                $bootstrapContext = xapps_backend_kit_read_host_bootstrap_context($request, $bootstrap);
                xapps_backend_kit_send_json(
                    $app['hostProxyService']->listInstallations([
                        'subjectId' => $bootstrapContext['subjectId'] ?? ($query['subjectId'] ?? null),
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
        'handler' => static function (array $request) use ($app, $allowedOrigins, $bootstrap): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $body = xapps_backend_kit_read_record($request['body']);
                $bootstrapContext = xapps_backend_kit_read_host_bootstrap_context($request, $bootstrap);
                xapps_backend_kit_send_json(
                    $app['hostProxyService']->installXapp([
                        'xappId' => $body['xappId'] ?? null,
                        'subjectId' => $bootstrapContext['subjectId'] ?? ($body['subjectId'] ?? null),
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
        'handler' => static function (array $request) use ($app, $allowedOrigins, $bootstrap): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $body = xapps_backend_kit_read_record($request['body']);
                $bootstrapContext = xapps_backend_kit_read_host_bootstrap_context($request, $bootstrap);
                xapps_backend_kit_send_json(
                    $app['hostProxyService']->updateInstallation([
                        'installationId' => $body['installationId'] ?? null,
                        'subjectId' => $bootstrapContext['subjectId'] ?? ($body['subjectId'] ?? null),
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
        'handler' => static function (array $request) use ($app, $allowedOrigins, $bootstrap): void {
            if (!xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins)) {
                return;
            }
            try {
                $body = xapps_backend_kit_read_record($request['body']);
                $bootstrapContext = xapps_backend_kit_read_host_bootstrap_context($request, $bootstrap);
                xapps_backend_kit_send_json(
                    $app['hostProxyService']->uninstallInstallation([
                        'installationId' => $body['installationId'] ?? null,
                        'subjectId' => $bootstrapContext['subjectId'] ?? ($body['subjectId'] ?? null),
                    ]),
                    200,
                    xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
                );
            } catch (\Throwable $error) {
                xapps_backend_kit_send_service_error($error, 'uninstall failed');
            }
        },
    ];
}
