<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/functions.php';

function xappsBackendKitPhpAssertTrue(bool $condition, string $message = 'Assertion failed'): void
{
    if ($condition) {
        return;
    }
    throw new RuntimeException($message);
}

function xappsBackendKitPhpAssertSame(mixed $expected, mixed $actual, string $message = 'Assertion failed'): void
{
    if ($expected === $actual) {
        return;
    }
    throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
}

function xappsBackendKitPhpInvokeRoute(callable $handler, array $request): array
{
    http_response_code(200);
    ob_start();
    $handler($request);
    $raw = (string) ob_get_clean();
    $status = http_response_code();
    $decoded = null;
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = null;
        }
    }
    return [
        'status' => is_int($status) ? $status : 200,
        'body' => $decoded,
        'raw' => $raw,
    ];
}

function xappsBackendKitPhpFindRoute(array $routes, string $method, string $path): array
{
    foreach ($routes as $route) {
        if (($route['method'] ?? null) === $method && ($route['path'] ?? null) === $path) {
            return $route;
        }
    }
    throw new RuntimeException('Route not found: ' . $method . ' ' . $path);
}
