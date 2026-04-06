<?php

declare(strict_types=1);

use Xapps\XappsSdkError;

function xapps_backend_kit_read_record(mixed $value): array
{
    return is_array($value) ? $value : [];
}

function xapps_backend_kit_read_list(mixed $value): array
{
    return is_array($value) ? $value : [];
}

function xapps_backend_kit_read_string(mixed ...$values): string
{
    foreach ($values as $value) {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized !== '') {
            return $normalized;
        }
    }
    return '';
}

function xapps_backend_kit_optional_string(mixed ...$values): ?string
{
    $resolved = xapps_backend_kit_read_string(...$values);
    return $resolved !== '' ? $resolved : null;
}

function xapps_backend_kit_normalize_origin(mixed $value): string
{
    $resolved = trim((string) ($value ?? ''));
    return $resolved !== '' ? rtrim($resolved, '/') : '';
}

function xapps_backend_kit_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function xapps_backend_kit_base64url_decode(string $value): string
{
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    if ($decoded === false) {
        throw new XappsSdkError(
            XappsSdkError::INVALID_ARGUMENT,
            'host bootstrap token is malformed',
            401,
            false,
        );
    }
    return $decoded;
}

function xapps_backend_kit_request_origin(array $request): string
{
    return xapps_backend_kit_normalize_origin($request['headers']['origin'] ?? null);
}

function xapps_backend_kit_request_host_bootstrap_token(array $request): string
{
    return trim((string) ($request['headers']['x-xapps-host-bootstrap'] ?? ''));
}

function xapps_backend_kit_issue_host_bootstrap_token(array $payload, string $signingSecret): string
{
    $encodedPayload = xapps_backend_kit_base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $signature = xapps_backend_kit_base64url_encode(hash_hmac('sha256', $encodedPayload, $signingSecret, true));
    return $encodedPayload . '.' . $signature;
}

function xapps_backend_kit_verify_host_bootstrap_token(string $token, string $signingSecret): array
{
    $parts = explode('.', trim($token));
    if (count($parts) !== 2) {
        throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'host bootstrap token is malformed', 401, false);
    }
    [$encodedPayload, $receivedSignature] = $parts;
    $expectedSignature = xapps_backend_kit_base64url_encode(hash_hmac('sha256', $encodedPayload, $signingSecret, true));
    if (!hash_equals($expectedSignature, $receivedSignature)) {
        throw new XappsSdkError(XappsSdkError::GATEWAY_API_UNAUTHORIZED, 'host bootstrap token is invalid', 401, false);
    }
    $payload = json_decode(xapps_backend_kit_base64url_decode($encodedPayload), true);
    if (!is_array($payload)) {
        throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'host bootstrap token payload is invalid', 401, false);
    }
    $now = time();
    if ((int) ($payload['exp'] ?? 0) <= $now) {
        throw new XappsSdkError(XappsSdkError::GATEWAY_API_UNAUTHORIZED, 'host bootstrap token expired', 401, false);
    }
    return $payload;
}

function xapps_backend_kit_read_host_bootstrap_context(array $request, array $bootstrap = []): ?array
{
    $signingSecret = trim((string) ($bootstrap['signingSecret'] ?? ''));
    $token = xapps_backend_kit_request_host_bootstrap_token($request);
    if ($signingSecret === '' || $token === '') {
        return null;
    }
    $payload = xapps_backend_kit_verify_host_bootstrap_token($token, $signingSecret);
    $requestOrigin = xapps_backend_kit_request_origin($request);
    $tokenOrigin = xapps_backend_kit_normalize_origin($payload['origin'] ?? null);
    if ($tokenOrigin !== '' && $requestOrigin !== '' && $tokenOrigin !== $requestOrigin) {
        throw new XappsSdkError(XappsSdkError::GATEWAY_API_UNAUTHORIZED, 'host bootstrap token origin mismatch', 401, false);
    }
    return [
        'subjectId' => xapps_backend_kit_optional_string($payload['subjectId'] ?? null),
        'email' => xapps_backend_kit_optional_string($payload['email'] ?? null),
        'name' => xapps_backend_kit_optional_string($payload['name'] ?? null),
        'origin' => $tokenOrigin !== '' ? $tokenOrigin : null,
        'token' => $token,
    ];
}

function xapps_backend_kit_require_host_bootstrap_request(array $request, array $bootstrap = []): array
{
    $apiKeys = array_values(array_filter(array_map(
        static fn (mixed $entry): string => trim((string) $entry),
        is_array($bootstrap['apiKeys'] ?? null) ? $bootstrap['apiKeys'] : [],
    ), static fn (string $entry): bool => $entry !== ''));
    $signingSecret = trim((string) ($bootstrap['signingSecret'] ?? ''));
    if ($apiKeys === [] || $signingSecret === '') {
        throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'Host bootstrap is not configured', 501, false);
    }
    $apiKey = trim((string) ($request['headers']['x-api-key'] ?? ''));
    if ($apiKey === '' || !in_array($apiKey, $apiKeys, true)) {
        throw new XappsSdkError(XappsSdkError::GATEWAY_API_UNAUTHORIZED, 'Invalid host bootstrap api key', 401, false);
    }
    $ttlSeconds = (int) ($bootstrap['ttlSeconds'] ?? 300);
    return [
        'apiKey' => $apiKey,
        'signingSecret' => $signingSecret,
        'ttlSeconds' => $ttlSeconds > 0 ? $ttlSeconds : 300,
    ];
}

function xapps_backend_kit_build_host_bootstrap_result(array $input): array
{
    $subjectId = xapps_backend_kit_read_string($input['subjectId'] ?? null);
    $email = xapps_backend_kit_optional_string($input['email'] ?? null);
    $name = xapps_backend_kit_optional_string($input['name'] ?? null);
    $origin = xapps_backend_kit_normalize_origin($input['origin'] ?? null);
    $ttlSeconds = (int) ($input['ttlSeconds'] ?? 300);
    $ttlSeconds = $ttlSeconds > 0 ? $ttlSeconds : 300;
    $now = time();
    $bootstrapToken = xapps_backend_kit_issue_host_bootstrap_token([
        'v' => 1,
        'type' => 'host_bootstrap',
        'subjectId' => $subjectId,
        'email' => $email,
        'name' => $name,
        'origin' => $origin,
        'iat' => $now,
        'exp' => $now + $ttlSeconds,
    ], xapps_backend_kit_read_string($input['signingSecret'] ?? null));
    return [
        'subjectId' => $subjectId,
        'email' => $email,
        'name' => $name,
        'bootstrapToken' => $bootstrapToken,
        'expiresIn' => $ttlSeconds,
    ];
}

function xapps_backend_kit_is_allowed_origin(string $origin, array $allowedOrigins = []): bool
{
    $normalizedOrigin = xapps_backend_kit_normalize_origin($origin);
    if ($normalizedOrigin === '') {
        return true;
    }
    $normalizedAllowedOrigins = array_values(array_filter(array_map(
        static fn (mixed $entry): string => xapps_backend_kit_normalize_origin($entry),
        $allowedOrigins,
    )));
    if ($normalizedAllowedOrigins === []) {
        return true;
    }
    return in_array($normalizedOrigin, $normalizedAllowedOrigins, true);
}

function xapps_backend_kit_host_api_cors_headers(array $request, array $allowedOrigins = []): array
{
    $origin = xapps_backend_kit_request_origin($request);
    $normalizedAllowedOrigins = array_values(array_filter(array_map(
        static fn (mixed $entry): string => xapps_backend_kit_normalize_origin($entry),
        $allowedOrigins,
    )));
    if (
        $origin === ''
        || $normalizedAllowedOrigins === []
        || !in_array($origin, $normalizedAllowedOrigins, true)
    ) {
        return [];
    }
    return [
        'Access-Control-Allow-Origin' => $origin,
        'Vary' => 'Origin',
    ];
}

function xapps_backend_kit_enforce_host_api_origin(array $request, array $allowedOrigins = []): bool
{
    $origin = xapps_backend_kit_request_origin($request);
    if ($origin === '' || xapps_backend_kit_is_allowed_origin($origin, $allowedOrigins)) {
        return true;
    }
    xapps_backend_kit_send_json(['message' => 'Origin is not allowed'], 403);
    return false;
}

function xapps_backend_kit_send_host_api_preflight(array $request, array $allowedOrigins = []): void
{
    $origin = xapps_backend_kit_request_origin($request);
    if ($origin !== '' && !xapps_backend_kit_is_allowed_origin($origin, $allowedOrigins)) {
        xapps_backend_kit_send_json(['message' => 'Origin is not allowed'], 403);
        return;
    }
    $requestedHeaders = trim((string) ($request['headers']['access-control-request-headers'] ?? ''));
    xapps_backend_kit_send_text('', 'text/plain; charset=utf-8', 204, array_merge(
        xapps_backend_kit_host_api_cors_headers($request, $allowedOrigins),
        [
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => $requestedHeaders !== '' ? $requestedHeaders : 'Content-Type, Authorization, X-Xapps-Host-Bootstrap',
            'Access-Control-Max-Age' => '600',
        ],
    ));
}

function xapps_backend_kit_widget_session_input(array $body, array $request): array
{
    return [
        'installationId' => xapps_backend_kit_read_string($body['installationId'] ?? null),
        'widgetId' => xapps_backend_kit_read_string($body['widgetId'] ?? null),
        'xappId' => xapps_backend_kit_optional_string($body['xappId'] ?? null),
        'subjectId' => xapps_backend_kit_optional_string($body['subjectId'] ?? null),
        'requestId' => xapps_backend_kit_optional_string($body['requestId'] ?? null),
        'origin' => xapps_backend_kit_read_string($body['origin'] ?? null, $request['headers']['origin'] ?? null),
        'hostReturnUrl' => xapps_backend_kit_optional_string(
            $body['hostReturnUrl'] ?? null,
            $body['host_return_url'] ?? null,
            $request['headers']['referer'] ?? null,
        ),
        'resultPresentation' => xapps_backend_kit_optional_string(
            $body['resultPresentation'] ?? null,
            $body['result_presentation'] ?? null,
        ),
        'guardUi' => is_array($body['guardUi'] ?? null)
            ? $body['guardUi']
            : (is_array($body['guard_ui'] ?? null) ? $body['guard_ui'] : null),
    ];
}

function xapps_backend_kit_subject_result(array $payload, array $input = []): array
{
    $subjectId = xapps_backend_kit_read_string(
        $payload['subjectId'] ?? null,
        $payload['subject_id'] ?? null,
        is_array($payload['result'] ?? null) ? ($payload['result']['subjectId'] ?? null) : null,
        is_array($payload['result'] ?? null) ? ($payload['result']['subject_id'] ?? null) : null,
    );
    if ($subjectId === '') {
        throw new XappsSdkError(
            XappsSdkError::GATEWAY_API_INVALID_RESPONSE,
            'resolve-subject response missing subjectId',
            502,
            false,
            ['payload' => $payload],
        );
    }
    return [
        'subjectId' => $subjectId,
        'email' => xapps_backend_kit_optional_string($payload['email'] ?? null, $input['email'] ?? null),
        'name' => xapps_backend_kit_optional_string($payload['name'] ?? null, $input['name'] ?? null),
    ];
}

function xapps_backend_kit_bridge_refresh_input(array $body, array $request): array
{
    return [
        'installationId' => xapps_backend_kit_read_string($body['installationId'] ?? null),
        'widgetId' => xapps_backend_kit_read_string($body['widgetId'] ?? null),
        'subjectId' => xapps_backend_kit_optional_string($body['subjectId'] ?? null),
        'origin' => xapps_backend_kit_read_string($body['origin'] ?? null, $request['headers']['origin'] ?? null),
        'hostReturnUrl' => xapps_backend_kit_optional_string(
            $body['hostReturnUrl'] ?? null,
            $body['host_return_url'] ?? null,
            $request['headers']['referer'] ?? null,
        ),
    ];
}

function xapps_backend_kit_bridge_vendor_assertion_input(array $body): array
{
    return [
        'vendorId' => xapps_backend_kit_optional_string($body['vendorId'] ?? null, $body['vendor_id'] ?? null),
        'subjectId' => xapps_backend_kit_optional_string($body['subjectId'] ?? null, $body['subject_id'] ?? null),
        'installationId' => xapps_backend_kit_optional_string(
            $body['installationId'] ?? null,
            $body['installation_id'] ?? null,
        ),
        'data' => $body,
    ];
}

function xapps_backend_kit_send_service_error(\Throwable $error, string $fallbackMessage): void
{
    if ($error instanceof XappsSdkError) {
        $status = (int) ($error->status ?? 500);
        $payload = count($error->details) > 0 ? $error->details : ['message' => $error->getMessage()];
        if (!isset($payload['message'])) {
            $payload['message'] = $error->getMessage();
        }
        xapps_backend_kit_send_json($payload, $status);
        return;
    }
    error_log('[backend-kit] ' . $fallbackMessage . ': ' . $error->getMessage());
    xapps_backend_kit_send_json(['message' => $fallbackMessage], 500);
}
