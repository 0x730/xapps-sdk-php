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

function xapps_backend_kit_run_hook_safely(array $request, mixed $hook, array $input, string $label): void
{
    if (!is_callable($hook)) {
        return;
    }
    try {
        $hook($input);
    } catch (\Throwable $error) {
        error_log($label . ': ' . $error->getMessage());
    }
}

function xapps_backend_kit_warn_deprecated_host_bootstrap_header(array $bootstrap, array $request, string $route): void
{
    $token = trim((string) ($request['headers']['x-xapps-host-bootstrap'] ?? ''));
    if ($token === '') {
        return;
    }
    $message = 'host bootstrap header is deprecated outside /api/host-session/exchange and will be removed';
    $warnHook = $bootstrap['deprecatedWarn'] ?? null;
    if (is_callable($warnHook)) {
        xapps_backend_kit_run_hook_safely($request, $warnHook, [
            'request' => $request,
            'route' => $route,
            'headerName' => 'x-xapps-host-bootstrap',
            'message' => $message,
        ], 'host-bootstrap deprecated warning hook failed');
        return;
    }
    if ($warnHook === true) {
        error_log($message . ' route=' . $route . ' headerName=x-xapps-host-bootstrap');
    }
}

function xapps_backend_kit_normalize_origin(mixed $value): string
{
    $resolved = trim((string) ($value ?? ''));
    return $resolved !== '' ? strtolower(rtrim($resolved, '/')) : '';
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

function xapps_backend_kit_request_bearer_token(array $request): string
{
    $raw = trim((string) ($request['headers']['authorization'] ?? ''));
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^Bearer\s+(.+)$/i', $raw, $matches) === 1) {
        return trim((string) ($matches[1] ?? ''));
    }
    return '';
}

function xapps_backend_kit_read_execution_plane_token(array $request, mixed ...$values): string
{
    $bearer = xapps_backend_kit_request_bearer_token($request);
    if ($bearer !== '') {
        return $bearer;
    }
    return xapps_backend_kit_read_string(...$values);
}

function xapps_backend_kit_parse_cookie_header(array $request): array
{
    $raw = trim((string) ($request['headers']['cookie'] ?? ''));
    if ($raw === '') {
        return [];
    }
    $result = [];
    foreach (explode(';', $raw) as $entry) {
        $parts = explode('=', (string) $entry, 2);
        $name = trim((string) ($parts[0] ?? ''));
        if ($name === '') {
            continue;
        }
        $value = trim((string) ($parts[1] ?? ''));
        $decoded = rawurldecode($value);
        $result[$name] = $decoded !== '' ? $decoded : $value;
    }
    return $result;
}

function xapps_backend_kit_request_host_session_token(array $request, string $cookieName = 'xapps_host_session'): string
{
    $cookies = xapps_backend_kit_parse_cookie_header($request);
    return trim((string) ($cookies[$cookieName] ?? ''));
}

function xapps_backend_kit_resolve_host_session_cookie_name(array $session = []): string
{
    $cookieName = trim((string) ($session['cookieName'] ?? ''));
    return $cookieName !== '' ? $cookieName : 'xapps_host_session';
}

function xapps_backend_kit_resolve_host_session_signing_secret(array $session = []): string
{
    return trim((string) ($session['signingSecret'] ?? ''));
}

function xapps_backend_kit_resolve_host_session_signing_key_id(array $session = []): string
{
    return trim((string) ($session['signingKeyId'] ?? ''));
}

function xapps_backend_kit_resolve_signing_verifier_keys(array $config = []): array
{
    $verifierKeys = is_array($config['verifierKeys'] ?? null) ? $config['verifierKeys'] : [];
    $normalized = [];
    foreach ($verifierKeys as $key => $value) {
        $normalizedKey = trim((string) $key);
        $normalizedValue = trim((string) ($value ?? ''));
        if ($normalizedKey === '' || $normalizedValue === '') {
            continue;
        }
        $normalized[$normalizedKey] = $normalizedValue;
    }
    return $normalized;
}

function xapps_backend_kit_resolve_signing_context(array $config = []): array
{
    $signingSecret = trim((string) ($config['signingSecret'] ?? ''));
    $signingKeyId = trim((string) ($config['signingKeyId'] ?? ''));
    $verifierKeys = xapps_backend_kit_resolve_signing_verifier_keys($config);
    if ($signingKeyId !== '' && $signingSecret !== '' && !array_key_exists($signingKeyId, $verifierKeys)) {
        $verifierKeys[$signingKeyId] = $signingSecret;
    }
    return [
        'signingSecret' => $signingSecret,
        'signingKeyId' => $signingKeyId,
        'verifierKeys' => $verifierKeys,
    ];
}

function xapps_backend_kit_resolve_host_session_absolute_ttl_seconds(array $session = []): int
{
    $ttlSeconds = (int) ($session['absoluteTtlSeconds'] ?? 1800);
    return $ttlSeconds > 0 ? $ttlSeconds : 1800;
}

function xapps_backend_kit_resolve_host_session_idle_ttl_seconds(array $session = []): int
{
    $ttlSeconds = (int) ($session['idleTtlSeconds'] ?? 0);
    return $ttlSeconds > 0 ? $ttlSeconds : 0;
}

function xapps_backend_kit_resolve_host_session_cookie_path(array $session = []): string
{
    $cookiePath = trim((string) ($session['cookiePath'] ?? '/api'));
    return $cookiePath !== '' ? $cookiePath : '/api';
}

function xapps_backend_kit_resolve_host_session_cookie_domain(array $session = []): string
{
    return trim((string) ($session['cookieDomain'] ?? ''));
}

function xapps_backend_kit_require_requested_host_bootstrap_origin(mixed $origin, array $allowedOrigins = []): string
{
    $normalizedOrigin = xapps_backend_kit_normalize_origin($origin);
    if ($normalizedOrigin === '') {
        throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'host bootstrap origin is required', 400, false);
    }
    if (!xapps_backend_kit_is_allowed_origin($normalizedOrigin, $allowedOrigins)) {
        throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'host bootstrap origin is not allowed', 403, false);
    }
    return $normalizedOrigin;
}

function xapps_backend_kit_issue_host_bootstrap_token(array $payload, string $signingSecret): string
{
    $encodedPayload = xapps_backend_kit_base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $signature = xapps_backend_kit_base64url_encode(hash_hmac('sha256', $encodedPayload, $signingSecret, true));
    return $encodedPayload . '.' . $signature;
}

function xapps_backend_kit_issue_host_session_token(array $payload, string $signingSecret): string
{
    $encodedPayload = xapps_backend_kit_base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $signature = xapps_backend_kit_base64url_encode(hash_hmac('sha256', $encodedPayload, $signingSecret, true));
    return $encodedPayload . '.' . $signature;
}

function xapps_backend_kit_resolve_verification_secret(array $payload, array $config, string $tokenLabel): string
{
    $signing = xapps_backend_kit_resolve_signing_context($config);
    $payloadKid = trim((string) ($payload['kid'] ?? ''));
    if ($payloadKid !== '') {
        $resolved = trim((string) ($signing['verifierKeys'][$payloadKid] ?? ''));
        if ($resolved === '') {
            throw new XappsSdkError(XappsSdkError::GATEWAY_API_UNAUTHORIZED, $tokenLabel . ' kid is invalid', 401, false);
        }
        return $resolved;
    }
    if (($signing['signingSecret'] ?? '') === '') {
        throw new XappsSdkError(XappsSdkError::GATEWAY_API_UNAUTHORIZED, $tokenLabel . ' is invalid', 401, false);
    }
    if (($signing['signingKeyId'] ?? '') !== '' && !array_key_exists($signing['signingKeyId'], $signing['verifierKeys'])) {
        throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, $tokenLabel . ' verification is not configured', 501, false);
    }
    return (string) $signing['signingSecret'];
}

function xapps_backend_kit_verify_host_bootstrap_token(string $token, array $bootstrap = []): array
{
    $parts = explode('.', trim($token));
    if (count($parts) !== 2) {
        throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'host bootstrap token is malformed', 401, false);
    }
    [$encodedPayload, $receivedSignature] = $parts;
    $payload = json_decode(xapps_backend_kit_base64url_decode($encodedPayload), true);
    if (!is_array($payload)) {
        throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'host bootstrap token payload is invalid', 401, false);
    }
    $signingSecret = xapps_backend_kit_resolve_verification_secret($payload, $bootstrap, 'host bootstrap token');
    $expectedSignature = xapps_backend_kit_base64url_encode(hash_hmac('sha256', $encodedPayload, $signingSecret, true));
    if (!hash_equals($expectedSignature, $receivedSignature)) {
        throw new XappsSdkError(XappsSdkError::GATEWAY_API_UNAUTHORIZED, 'host bootstrap token is invalid', 401, false);
    }
    if (trim((string) ($payload['type'] ?? '')) !== 'host_bootstrap') {
        throw new XappsSdkError(XappsSdkError::GATEWAY_API_UNAUTHORIZED, 'host bootstrap token type is invalid', 401, false);
    }
    if ((int) ($payload['v'] ?? 0) !== 2) {
        throw new XappsSdkError(XappsSdkError::GATEWAY_API_UNAUTHORIZED, 'host bootstrap token version is invalid', 401, false);
    }
    if (trim((string) ($payload['iss'] ?? '')) !== 'xapps_host_bootstrap') {
        throw new XappsSdkError(XappsSdkError::GATEWAY_API_UNAUTHORIZED, 'host bootstrap token issuer is invalid', 401, false);
    }
    if (trim((string) ($payload['aud'] ?? '')) !== 'xapps_host_api') {
        throw new XappsSdkError(XappsSdkError::GATEWAY_API_UNAUTHORIZED, 'host bootstrap token audience is invalid', 401, false);
    }
    if (trim((string) ($payload['jti'] ?? '')) === '') {
        throw new XappsSdkError(XappsSdkError::GATEWAY_API_UNAUTHORIZED, 'host bootstrap token jti is invalid', 401, false);
    }
    $now = time();
    if ((int) ($payload['exp'] ?? 0) <= $now) {
        throw new XappsSdkError(XappsSdkError::GATEWAY_API_UNAUTHORIZED, 'host bootstrap token expired', 401, false);
    }
    return $payload;
}

function xapps_backend_kit_verify_host_session_token(string $token, array $session = []): array
{
    $parts = explode('.', trim($token));
    if (count($parts) !== 2) {
        throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'host session token is malformed', 401, false);
    }
    [$encodedPayload, $receivedSignature] = $parts;
    $payload = json_decode(xapps_backend_kit_base64url_decode($encodedPayload), true);
    if (!is_array($payload)) {
        throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'host session token payload is invalid', 401, false);
    }
    $signingSecret = xapps_backend_kit_resolve_verification_secret($payload, $session, 'host session token');
    $expectedSignature = xapps_backend_kit_base64url_encode(hash_hmac('sha256', $encodedPayload, $signingSecret, true));
    if (!hash_equals($expectedSignature, $receivedSignature)) {
        throw new XappsSdkError(XappsSdkError::GATEWAY_API_UNAUTHORIZED, 'host session token is invalid', 401, false);
    }
    if (trim((string) ($payload['type'] ?? '')) !== 'host_session') {
        throw new XappsSdkError(XappsSdkError::GATEWAY_API_UNAUTHORIZED, 'host session token type is invalid', 401, false);
    }
    if ((int) ($payload['v'] ?? 0) !== 2) {
        throw new XappsSdkError(XappsSdkError::GATEWAY_API_UNAUTHORIZED, 'host session token version is invalid', 401, false);
    }
    if (trim((string) ($payload['iss'] ?? '')) !== 'xapps_host_session') {
        throw new XappsSdkError(XappsSdkError::GATEWAY_API_UNAUTHORIZED, 'host session token issuer is invalid', 401, false);
    }
    if (trim((string) ($payload['aud'] ?? '')) !== 'xapps_host_api') {
        throw new XappsSdkError(XappsSdkError::GATEWAY_API_UNAUTHORIZED, 'host session token audience is invalid', 401, false);
    }
    if (trim((string) ($payload['jti'] ?? '')) === '') {
        throw new XappsSdkError(XappsSdkError::GATEWAY_API_UNAUTHORIZED, 'host session token jti is invalid', 401, false);
    }
    $now = time();
    if ((int) ($payload['exp'] ?? 0) <= $now) {
        throw new XappsSdkError(XappsSdkError::GATEWAY_API_UNAUTHORIZED, 'host session token expired', 401, false);
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
    $payload = xapps_backend_kit_verify_host_bootstrap_token($token, $bootstrap);
    $requestOrigin = xapps_backend_kit_request_origin($request);
    $tokenOrigin = xapps_backend_kit_normalize_origin($payload['origin'] ?? null);
    if ($tokenOrigin === '') {
        throw new XappsSdkError(XappsSdkError::GATEWAY_API_UNAUTHORIZED, 'host bootstrap token origin is missing', 401, false);
    }
    if ($requestOrigin === '') {
        throw new XappsSdkError(XappsSdkError::GATEWAY_API_UNAUTHORIZED, 'host bootstrap request origin is required', 401, false);
    }
    if ($tokenOrigin !== $requestOrigin) {
        throw new XappsSdkError(XappsSdkError::GATEWAY_API_UNAUTHORIZED, 'host bootstrap token origin mismatch', 401, false);
    }
    return [
        'subjectId' => xapps_backend_kit_optional_string($payload['subjectId'] ?? null),
        'origin' => $tokenOrigin !== '' ? $tokenOrigin : null,
        'jti' => xapps_backend_kit_optional_string($payload['jti'] ?? null),
        'iat' => is_numeric($payload['iat'] ?? null) ? (int) $payload['iat'] : null,
        'exp' => is_numeric($payload['exp'] ?? null) ? (int) $payload['exp'] : null,
        'token' => $token,
    ];
}

function xapps_backend_kit_consume_host_bootstrap_replay(array $context, array $bootstrap = []): void
{
    $consumeJti = $bootstrap['consumeJti'] ?? null;
    if (!is_callable($consumeJti)) {
        throw new XappsSdkError(
            XappsSdkError::INVALID_ARGUMENT,
            'Host session exchange replay protection is not configured',
            501,
            false,
        );
    }
    $accepted = (bool) call_user_func($consumeJti, [
        'jti' => xapps_backend_kit_read_string($context['jti'] ?? null),
        'subjectId' => xapps_backend_kit_optional_string($context['subjectId'] ?? null),
        'origin' => xapps_backend_kit_optional_string($context['origin'] ?? null),
        'iat' => is_numeric($context['iat'] ?? null) ? (int) $context['iat'] : null,
        'exp' => is_numeric($context['exp'] ?? null) ? (int) $context['exp'] : null,
        'token' => xapps_backend_kit_read_string($context['token'] ?? null),
        'type' => 'host_bootstrap',
    ]);
    if (!$accepted) {
        throw new XappsSdkError(
            XappsSdkError::INVALID_ARGUMENT,
            'host bootstrap token replay detected',
            409,
            false,
        );
    }
}

function xapps_backend_kit_request_base_url(array $request): string
{
    $forwardedProto = strtolower(trim((string) explode(',', (string) ($request['headers']['x-forwarded-proto'] ?? ''))[0]));
    $forwardedHost = trim((string) explode(',', (string) ($request['headers']['x-forwarded-host'] ?? ''))[0]);
    $protocol = strtolower(trim((string) ($forwardedProto !== '' ? $forwardedProto : ($request['protocol'] ?? 'http'))));
    $host = trim((string) ($forwardedHost !== '' ? $forwardedHost : ($request['headers']['host'] ?? '')));
    return $host !== '' ? $protocol . '://' . $host : '';
}

function xapps_backend_kit_is_cross_origin_request(array $request): bool
{
    $origin = xapps_backend_kit_request_origin($request);
    $baseUrl = xapps_backend_kit_request_base_url($request);
    return $origin !== '' && $baseUrl !== '' && $origin !== $baseUrl;
}

function xapps_backend_kit_should_use_secure_cookie(array $request): bool
{
    $baseUrl = strtolower(xapps_backend_kit_request_base_url($request));
    return str_starts_with($baseUrl, 'https://')
        || str_starts_with($baseUrl, 'http://localhost')
        || str_starts_with($baseUrl, 'http://127.0.0.1')
        || str_starts_with($baseUrl, 'http://[::1]');
}

function xapps_backend_kit_resolve_session_cookie_secure(array $request, array $session = []): bool
{
    if (is_bool($session['cookieSecure'] ?? null)) {
        return (bool) $session['cookieSecure'];
    }
    return xapps_backend_kit_should_use_secure_cookie($request);
}

function xapps_backend_kit_resolve_session_cookie_same_site(array $request, array $session = []): string
{
    $configured = trim((string) ($session['cookieSameSite'] ?? ''));
    if (in_array($configured, ['Lax', 'Strict', 'None'], true)) {
        return $configured;
    }
    $origin = xapps_backend_kit_request_origin($request);
    $baseUrl = xapps_backend_kit_request_base_url($request);
    return $origin !== '' && $baseUrl !== '' && $origin !== $baseUrl ? 'None' : 'Lax';
}

function xapps_backend_kit_build_set_cookie_header(
    string $name,
    string $value,
    array $request,
    int $maxAgeSeconds,
    array $session = [],
): string {
    $parts = [
        trim($name) !== '' ? trim($name) : 'xapps_host_session',
        '=',
        rawurlencode(trim($value)),
        '; Path=',
        xapps_backend_kit_resolve_host_session_cookie_path($session),
        '; HttpOnly; Max-Age=',
        (string) max(0, $maxAgeSeconds),
        '; SameSite=',
        xapps_backend_kit_resolve_session_cookie_same_site($request, $session),
    ];
    $cookieDomain = xapps_backend_kit_resolve_host_session_cookie_domain($session);
    if ($cookieDomain !== '') {
        $parts[] = '; Domain=';
        $parts[] = $cookieDomain;
    }
    $header = implode('', $parts);
    if (xapps_backend_kit_resolve_session_cookie_secure($request, $session)) {
        $header .= '; Secure';
    }
    return $header;
}

function xapps_backend_kit_build_cleared_host_session_cookie_header(array $request, array $session = []): string
{
    return xapps_backend_kit_build_set_cookie_header(
        xapps_backend_kit_resolve_host_session_cookie_name($session),
        '',
        $request,
        0,
        $session,
    );
}

function xapps_backend_kit_read_host_session_context(
    array $request,
    array $session = [],
): ?array {
    $signingSecret = xapps_backend_kit_resolve_host_session_signing_secret($session);
    $token = xapps_backend_kit_request_host_session_token(
        $request,
        xapps_backend_kit_resolve_host_session_cookie_name($session),
    );
    if ($signingSecret === '' || $token === '') {
        return null;
    }
    $payload = xapps_backend_kit_verify_host_session_token($token, $session);
    $sessionStore = xapps_backend_kit_read_record($session['store'] ?? null);
    $isRevoked = $sessionStore['isRevoked'] ?? null;
    if (!is_callable($isRevoked)) {
        throw new XappsSdkError(
            XappsSdkError::INVALID_ARGUMENT,
            'Host session revocation is not configured',
            501,
            false,
        );
    }
    $revoked = (bool) call_user_func($isRevoked, [
        'jti' => xapps_backend_kit_read_string($payload['jti'] ?? null),
        'subjectId' => xapps_backend_kit_optional_string($payload['subjectId'] ?? null),
        'iat' => is_numeric($payload['iat'] ?? null) ? (int) $payload['iat'] : null,
        'exp' => is_numeric($payload['exp'] ?? null) ? (int) $payload['exp'] : null,
        'token' => $token,
        'type' => 'host_session',
    ]);
    if ($revoked) {
        throw new XappsSdkError(
            XappsSdkError::GATEWAY_API_UNAUTHORIZED,
            'host session revoked',
            401,
            false,
        );
    }
    $idleTtlSeconds = xapps_backend_kit_resolve_host_session_idle_ttl_seconds($session);
    if ($idleTtlSeconds > 0) {
        $touch = $sessionStore['touch'] ?? null;
        if (!is_callable($touch)) {
            throw new XappsSdkError(
                XappsSdkError::INVALID_ARGUMENT,
                'Host session idle timeout is not configured',
                501,
                false,
            );
        }
        $touched = $touch([
            'jti' => xapps_backend_kit_read_string($payload['jti'] ?? null),
            'subjectId' => xapps_backend_kit_optional_string($payload['subjectId'] ?? null),
            'iat' => is_numeric($payload['iat'] ?? null) ? (int) $payload['iat'] : null,
            'exp' => is_numeric($payload['exp'] ?? null) ? (int) $payload['exp'] : null,
            'idleTtlSeconds' => $idleTtlSeconds,
            'token' => $token,
            'type' => 'host_session',
        ]);
        $active = $touched === true
            || (is_array($touched) && (($touched['active'] ?? true) !== false));
        if (!$active) {
            throw new XappsSdkError(
                XappsSdkError::GATEWAY_API_UNAUTHORIZED,
                'host session idle expired',
                401,
                false,
            );
        }
    }
    return [
        'subjectId' => xapps_backend_kit_optional_string($payload['subjectId'] ?? null),
        'sessionMode' => 'host_session',
        'jti' => xapps_backend_kit_optional_string($payload['jti'] ?? null),
        'iat' => is_numeric($payload['iat'] ?? null) ? (int) $payload['iat'] : null,
        'exp' => is_numeric($payload['exp'] ?? null) ? (int) $payload['exp'] : null,
        'token' => $token,
    ];
}

function xapps_backend_kit_activate_host_session(array $context, array $session = []): void
{
    $idleTtlSeconds = xapps_backend_kit_resolve_host_session_idle_ttl_seconds($session);
    if ($idleTtlSeconds <= 0) {
        return;
    }
    $sessionStore = xapps_backend_kit_read_record($session['store'] ?? null);
    $activate = $sessionStore['activate'] ?? null;
    if (!is_callable($activate)) {
        throw new XappsSdkError(
            XappsSdkError::INVALID_ARGUMENT,
            'Host session idle timeout is not configured',
            501,
            false,
        );
    }
    $activated = (bool) $activate([
        'jti' => xapps_backend_kit_read_string($context['jti'] ?? null),
        'subjectId' => xapps_backend_kit_optional_string($context['subjectId'] ?? null),
        'iat' => is_numeric($context['iat'] ?? null) ? (int) $context['iat'] : null,
        'exp' => is_numeric($context['exp'] ?? null) ? (int) $context['exp'] : null,
        'idleTtlSeconds' => $idleTtlSeconds,
        'token' => xapps_backend_kit_read_string($context['token'] ?? null),
        'type' => 'host_session',
    ]);
    if (!$activated) {
        throw new XappsSdkError(
            XappsSdkError::INVALID_ARGUMENT,
            'host session activation failed',
            409,
            false,
        );
    }
}

function xapps_backend_kit_revoke_host_session(array $context, array $session = []): void
{
    $sessionStore = xapps_backend_kit_read_record($session['store'] ?? null);
    $revoke = $sessionStore['revoke'] ?? null;
    if (!is_callable($revoke)) {
        throw new XappsSdkError(
            XappsSdkError::INVALID_ARGUMENT,
            'Host session revocation is not configured',
            501,
            false,
        );
    }
    $revoked = (bool) call_user_func($revoke, [
        'jti' => xapps_backend_kit_read_string($context['jti'] ?? null),
        'subjectId' => xapps_backend_kit_optional_string($context['subjectId'] ?? null),
        'iat' => is_numeric($context['iat'] ?? null) ? (int) $context['iat'] : null,
        'exp' => is_numeric($context['exp'] ?? null) ? (int) $context['exp'] : null,
        'token' => xapps_backend_kit_read_string($context['token'] ?? null),
        'type' => 'host_session',
    ]);
    if (!$revoked) {
        throw new XappsSdkError(
            XappsSdkError::INVALID_ARGUMENT,
            'host session revocation failed',
            409,
            false,
        );
    }
}

function xapps_backend_kit_read_host_auth_context(array $request, array $session = []): ?array
{
    $sessionContext = xapps_backend_kit_read_host_session_context($request, $session);
    if (is_array($sessionContext)) {
        return $sessionContext;
    }
    if (xapps_backend_kit_is_cross_origin_request($request)) {
        throw new XappsSdkError(
            XappsSdkError::GATEWAY_API_UNAUTHORIZED,
            'host session is required',
            401,
            false,
        );
    }
    return null;
}

function xapps_backend_kit_resolve_trusted_host_subject_id(
    array $request,
    ?array $authContext,
    mixed $subjectId,
    array $session = [],
): string {
    $trustedSubjectId = trim((string) ($authContext['subjectId'] ?? ''));
    if ($trustedSubjectId !== '') {
        return $trustedSubjectId;
    }
    $candidateSubjectId = xapps_backend_kit_optional_string($subjectId);
    $resolver = $session['resolveSameOriginSubjectId'] ?? null;
    if (!is_callable($resolver)) {
        throw new XappsSdkError(
            XappsSdkError::GATEWAY_API_UNAUTHORIZED,
            'same-origin subject resolution is required',
            401,
            false,
        );
    }
    $resolvedSubjectId = trim((string) $resolver([
        'request' => $request,
        'subjectId' => $candidateSubjectId,
    ]));
    if ($resolvedSubjectId === '') {
        throw new XappsSdkError(
            XappsSdkError::GATEWAY_API_UNAUTHORIZED,
            'same-origin subject resolution failed',
            401,
            false,
        );
    }
    return $resolvedSubjectId;
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
        'signingKeyId' => trim((string) ($bootstrap['signingKeyId'] ?? '')) ?: null,
        'ttlSeconds' => $ttlSeconds > 0 ? $ttlSeconds : 300,
    ];
}

function xapps_backend_kit_build_host_bootstrap_result(array $input): array
{
    $subjectId = xapps_backend_kit_read_string($input['subjectId'] ?? null);
    $email = xapps_backend_kit_optional_string($input['email'] ?? null);
    $name = xapps_backend_kit_optional_string($input['name'] ?? null);
    $signingKeyId = xapps_backend_kit_optional_string($input['signingKeyId'] ?? null);
    $origin = xapps_backend_kit_normalize_origin($input['origin'] ?? null);
    $ttlSeconds = (int) ($input['ttlSeconds'] ?? 300);
    $ttlSeconds = $ttlSeconds > 0 ? $ttlSeconds : 300;
    $now = time();
    $bootstrapPayload = [
        'v' => 2,
        'type' => 'host_bootstrap',
        'iss' => 'xapps_host_bootstrap',
        'aud' => 'xapps_host_api',
        'jti' => function_exists('random_bytes')
            ? bin2hex(random_bytes(16))
            : sha1((string) microtime(true) . ':' . (string) mt_rand()),
        'subjectId' => $subjectId,
        'origin' => $origin,
        'iat' => $now,
        'exp' => $now + $ttlSeconds,
    ];
    if (is_string($signingKeyId) && trim($signingKeyId) !== '') {
        $bootstrapPayload['kid'] = trim($signingKeyId);
    }
    $bootstrapToken = xapps_backend_kit_issue_host_bootstrap_token(
        $bootstrapPayload,
        xapps_backend_kit_read_string($input['signingSecret'] ?? null),
    );
    return [
        'subjectId' => $subjectId,
        'email' => $email,
        'name' => $name,
        'bootstrapToken' => $bootstrapToken,
        'expiresIn' => $ttlSeconds,
    ];
}

function xapps_backend_kit_build_host_session_exchange_result(array $input, array $request): array
{
    $subjectId = xapps_backend_kit_read_string($input['subjectId'] ?? null);
    if ($subjectId === '') {
        throw new XappsSdkError(
            XappsSdkError::INVALID_ARGUMENT,
            'host session subjectId is required',
            400,
            false,
        );
    }
    $signingKeyId = xapps_backend_kit_optional_string($input['signingKeyId'] ?? null);
    $session = xapps_backend_kit_read_record($input['session'] ?? null);
    $ttlSeconds = (int) ($input['ttlSeconds'] ?? xapps_backend_kit_resolve_host_session_absolute_ttl_seconds($session));
    $ttlSeconds = $ttlSeconds > 0 ? $ttlSeconds : xapps_backend_kit_resolve_host_session_absolute_ttl_seconds($session);
    $cookieName = xapps_backend_kit_resolve_host_session_cookie_name($session);
    $now = time();
    $sessionPayload = [
        'v' => 2,
        'type' => 'host_session',
        'iss' => 'xapps_host_session',
        'aud' => 'xapps_host_api',
        'jti' => function_exists('random_bytes')
            ? bin2hex(random_bytes(16))
            : sha1((string) microtime(true) . ':' . (string) mt_rand()),
        'subjectId' => $subjectId,
        'iat' => $now,
        'exp' => $now + $ttlSeconds,
    ];
    if (is_string($signingKeyId) && trim($signingKeyId) !== '') {
        $sessionPayload['kid'] = trim($signingKeyId);
    }
    $sessionToken = xapps_backend_kit_issue_host_session_token(
        $sessionPayload,
        xapps_backend_kit_read_string($input['signingSecret'] ?? null),
    );
    return [
        'payload' => [
            'ok' => true,
            'subjectId' => $subjectId,
            'expiresIn' => $ttlSeconds,
            'sessionMode' => 'host_session',
        ],
        'setCookie' => xapps_backend_kit_build_set_cookie_header($cookieName, $sessionToken, $request, $ttlSeconds, $session),
        'sessionContext' => [
            'jti' => xapps_backend_kit_optional_string($sessionPayload['jti'] ?? null),
            'subjectId' => xapps_backend_kit_optional_string($sessionPayload['subjectId'] ?? null),
            'iat' => is_numeric($sessionPayload['iat'] ?? null) ? (int) $sessionPayload['iat'] : null,
            'exp' => is_numeric($sessionPayload['exp'] ?? null) ? (int) $sessionPayload['exp'] : null,
            'token' => $sessionToken,
        ],
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
        return false;
    }
    return in_array($normalizedOrigin, $normalizedAllowedOrigins, true);
}

function xapps_backend_kit_effective_host_api_allowed_origins(array $request, array $allowedOrigins = []): array
{
    $normalizedAllowedOrigins = array_values(array_filter(array_map(
        static fn (mixed $entry): string => xapps_backend_kit_normalize_origin($entry),
        $allowedOrigins,
    )));
    if ($normalizedAllowedOrigins !== []) {
        return $normalizedAllowedOrigins;
    }
    $baseUrl = xapps_backend_kit_normalize_origin(xapps_backend_kit_request_base_url($request));
    return $baseUrl !== '' ? [$baseUrl] : [];
}

function xapps_backend_kit_host_api_cors_headers(array $request, array $allowedOrigins = []): array
{
    $origin = xapps_backend_kit_request_origin($request);
    $normalizedAllowedOrigins = xapps_backend_kit_effective_host_api_allowed_origins($request, $allowedOrigins);
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
        'Access-Control-Allow-Credentials' => 'true',
    ];
}

function xapps_backend_kit_enforce_host_api_origin(array $request, array $allowedOrigins = []): bool
{
    $origin = xapps_backend_kit_request_origin($request);
    $effectiveAllowedOrigins = xapps_backend_kit_effective_host_api_allowed_origins($request, $allowedOrigins);
    if ($origin === '' || xapps_backend_kit_is_allowed_origin($origin, $effectiveAllowedOrigins)) {
        return true;
    }
    xapps_backend_kit_send_json(['message' => 'Origin is not allowed'], 403);
    return false;
}

function xapps_backend_kit_enforce_browser_unsafe_host_api_origin(array $request, array $allowedOrigins = []): bool
{
    $origin = xapps_backend_kit_request_origin($request);
    if ($origin === '') {
        xapps_backend_kit_send_json(['message' => 'Origin is required'], 403);
        return false;
    }
    return xapps_backend_kit_enforce_host_api_origin($request, $allowedOrigins);
}

function xapps_backend_kit_enforce_cookie_unsafe_host_api_origin(array $request, array $allowedOrigins = []): bool
{
    return xapps_backend_kit_enforce_browser_unsafe_host_api_origin($request, $allowedOrigins);
}

function xapps_backend_kit_send_host_api_preflight(array $request, array $allowedOrigins = []): void
{
    $origin = xapps_backend_kit_request_origin($request);
    $effectiveAllowedOrigins = xapps_backend_kit_effective_host_api_allowed_origins($request, $allowedOrigins);
    if ($origin !== '' && !xapps_backend_kit_is_allowed_origin($origin, $effectiveAllowedOrigins)) {
        xapps_backend_kit_send_json(['message' => 'Origin is not allowed'], 403);
        return;
    }
    $requestedHeaders = trim((string) ($request['headers']['access-control-request-headers'] ?? ''));
    xapps_backend_kit_send_text('', 'text/plain; charset=utf-8', 204, array_merge(
        xapps_backend_kit_host_api_cors_headers($request, $effectiveAllowedOrigins),
        [
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => $requestedHeaders !== '' ? $requestedHeaders : 'Content-Type, Authorization, X-Xapps-Host-Bootstrap',
            'Access-Control-Allow-Credentials' => 'true',
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
        'hostSessionJti' => xapps_backend_kit_optional_string(
            $body['host_session_jti'] ?? null,
        ),
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
        'hostSessionJti' => xapps_backend_kit_optional_string(
            $body['host_session_jti'] ?? null,
        ),
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
