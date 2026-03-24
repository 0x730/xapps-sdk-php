<?php

declare(strict_types=1);

namespace Xapps\BackendKit;

final class BackendRuntime
{
    public static function createRequestContext(?array $server = null): array
    {
        $server = is_array($server) ? $server : $_SERVER;
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = (string) parse_url($uri, PHP_URL_PATH);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return [
            'method' => $method,
            'path' => $path !== '' ? $path : '/',
            'query' => is_array($query) ? $query : [],
            'body' => xapps_backend_kit_request_body(),
            'headers' => xapps_backend_kit_request_headers(),
            'server' => $server,
            'params' => [],
        ];
    }

    public static function dispatch(array $app, array $request, array $deps = []): void
    {
        $sendJson = $deps['sendJson'] ?? 'xapps_backend_kit_send_json';
        if (!is_callable($sendJson)) {
            throw new \InvalidArgumentException('dispatch sendJson dependency is invalid');
        }

        foreach (BackendSupport::readList($app['routes'] ?? null) as $route) {
            if (!is_array($route)) {
                continue;
            }
            if (($route['method'] ?? '') !== ($request['method'] ?? '')) {
                continue;
            }
            if (isset($route['path']) && $route['path'] === ($request['path'] ?? null)) {
                $handler = $route['handler'] ?? null;
                if (is_callable($handler)) {
                    $handler($request);
                    return;
                }
                continue;
            }
            if (isset($route['pattern']) && preg_match((string) $route['pattern'], (string) ($request['path'] ?? ''), $matches)) {
                $params = [];
                foreach ($matches as $key => $value) {
                    if (!is_int($key)) {
                        $params[$key] = $value;
                    }
                }
                $request['params'] = $params;
                $handler = $route['handler'] ?? null;
                if (is_callable($handler)) {
                    $handler($request);
                    return;
                }
            }
        }

        $sendJson(['message' => 'Not found'], 404);
    }

    public static function bootstrap(array $config, array $options = [], array $deps = []): array
    {
        $normalizeOptions = $deps['normalizeOptions'] ?? null;
        $applyGatewayOverrides = $deps['applyGatewayOverrides'] ?? null;
        $applyPaymentOverrides = $deps['applyPaymentOverrides'] ?? null;
        $createApp = $deps['createApp'] ?? null;
        $attachOptions = $deps['attachOptions'] ?? null;
        $registerModules = BackendSupport::readList($deps['registerModules'] ?? null);
        if (!is_callable($normalizeOptions) || !is_callable($applyGatewayOverrides) || !is_callable($applyPaymentOverrides) || !is_callable($createApp)) {
            throw new \InvalidArgumentException('backend kit dependencies are incomplete');
        }

        $normalizedOptions = $normalizeOptions($options);
        $resolvedConfig = $applyPaymentOverrides(
            $applyGatewayOverrides($config, BackendSupport::readRecord($normalizedOptions['gateway'] ?? null)),
            BackendSupport::readRecord($normalizedOptions['payments'] ?? null),
        );
        $app = $createApp($resolvedConfig, $normalizedOptions);

        if (is_callable($attachOptions)) {
            $app = $attachOptions($app, $normalizedOptions);
        }

        foreach ($registerModules as $registerModule) {
            if (!is_callable($registerModule)) {
                continue;
            }
            $app = $registerModule($app, $normalizedOptions);
        }

        return $app;
    }
}
