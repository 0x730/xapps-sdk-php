<?php

declare(strict_types=1);

function xapps_backend_kit_apply_headers(array $headers): void
{
    foreach ($headers as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $entry) {
                header($key . ': ' . $entry, strtolower((string) $key) !== 'set-cookie');
            }
            continue;
        }
        header($key . ': ' . $value, strtolower((string) $key) !== 'set-cookie');
    }
}

function xapps_backend_kit_send_json(array $payload, int $status = 200, array $headers = []): void
{
    http_response_code($status);
    xapps_backend_kit_apply_headers(array_merge(['Content-Type' => 'application/json; charset=utf-8'], $headers));
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
}

function xapps_backend_kit_send_text(string $body, string $contentType, int $status = 200, array $headers = []): void
{
    http_response_code($status);
    xapps_backend_kit_apply_headers(array_merge(['Content-Type' => $contentType], $headers));
    echo $body;
}

function xapps_backend_kit_send_file(string $filePath, string $contentType, int $status = 200, array $headers = []): void
{
    if (!is_file($filePath)) {
        xapps_backend_kit_send_json(['message' => 'file not found'], 404);
        return;
    }
    xapps_backend_kit_send_text((string) file_get_contents($filePath), $contentType, $status, $headers);
}

function xapps_backend_kit_request_headers(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (!is_array($headers)) {
            return [];
        }
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtolower((string) $key)] = (string) $value;
        }
        return $normalized;
    }
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (!str_starts_with((string) $key, 'HTTP_')) {
            continue;
        }
        $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
        $headers[$name] = (string) $value;
    }
    return $headers;
}

function xapps_backend_kit_request_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}
