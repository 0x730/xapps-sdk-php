<?php

declare(strict_types=1);

namespace Xapps;

final class Signature
{
    /**
     * @return array{ok:bool,reason:string|null,mode:string|null}
     */
    public static function verifyXappsSignature(array $input): array
    {
        $method = strtoupper(trim((string) ($input['method'] ?? '')));
        $pathWithQuery = (string) ($input['pathWithQuery'] ?? '');
        $body = (string) ($input['body'] ?? '');
        $timestamp = trim((string) ($input['timestamp'] ?? ''));
        $signature = trim((string) ($input['signature'] ?? ''));
        $secret = (string) ($input['secret'] ?? '');
        $algorithm = strtolower(trim((string) ($input['algorithm'] ?? 'hmac-sha256')));
        $source = trim((string) ($input['source'] ?? ''));
        $requireSource = (bool) ($input['requireSourceInSignature'] ?? false);
        $allowLegacy = (bool) ($input['allowLegacyWithoutSource'] ?? true);
        $maxSkewSeconds = (int) ($input['maxSkewSeconds'] ?? 300);
        $nowSeconds = (int) ($input['nowSeconds'] ?? time());

        if (!in_array($algorithm, ['hmac-sha256', 'hmac-sha512', 'ed25519'], true)) {
            return ['ok' => false, 'reason' => 'unsupported_algorithm', 'mode' => null];
        }
        if ($algorithm === 'ed25519' && !function_exists('sodium_crypto_sign_verify_detached')) {
            return ['ok' => false, 'reason' => 'unsupported_algorithm', 'mode' => null];
        }

        if ($method === '' || $pathWithQuery === '' || $timestamp === '' || $signature === '' || $secret === '') {
            return ['ok' => false, 'reason' => 'bad_signature', 'mode' => null];
        }

        if (!ctype_digit($timestamp)) {
            return ['ok' => false, 'reason' => 'invalid_timestamp', 'mode' => null];
        }

        $ts = (int) $timestamp;
        if (abs($nowSeconds - $ts) > $maxSkewSeconds) {
            return ['ok' => false, 'reason' => 'timestamp_skew', 'mode' => null];
        }

        $bodySha256Hex = hash('sha256', $body);

        if ($requireSource && $source === '') {
            return ['ok' => false, 'reason' => 'missing_source', 'mode' => null];
        }

        $strictCanonical = implode("\n", [$method, $pathWithQuery, $timestamp, $bodySha256Hex, $source]);
        if (self::verifyMac($strictCanonical, $signature, $secret, $algorithm)) {
            return ['ok' => true, 'reason' => null, 'mode' => 'strict'];
        }

        if ($allowLegacy) {
            $legacyCanonical = implode("\n", [$method, $pathWithQuery, $timestamp, $bodySha256Hex]);
            if (self::verifyMac($legacyCanonical, $signature, $secret, $algorithm)) {
                return ['ok' => true, 'reason' => null, 'mode' => 'legacy'];
            }
        }

        return ['ok' => false, 'reason' => 'bad_signature', 'mode' => null];
    }

    private static function verifyMac(string $canonical, string $signature, string $secret, string $algorithm): bool
    {
        if ($algorithm === 'ed25519') {
            return self::verifyEd25519($canonical, $signature, $secret);
        }
        $algo = $algorithm === 'hmac-sha512' ? 'sha512' : 'sha256';
        $computed = hash_hmac($algo, $canonical, $secret, true);
        $provided = self::decodeFlexibleBytes($signature);
        if (!is_string($provided)) {
            return false;
        }

        return hash_equals($computed, $provided);
    }

    private static function verifyEd25519(string $canonical, string $signature, string $secret): bool
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }
        $publicKey = self::parseEd25519PublicKey($secret);
        $sigBytes = self::decodeFlexibleBytes($signature);
        if (!is_string($publicKey) || !is_string($sigBytes) || strlen($publicKey) !== 32 || strlen($sigBytes) !== 64) {
            return false;
        }
        return sodium_crypto_sign_verify_detached($sigBytes, $canonical, $publicKey);
    }

    private static function parseEd25519PublicKey(string $secret): ?string
    {
        $raw = trim($secret);
        $prefix = 'ed25519:pk:';
        if (str_starts_with($raw, $prefix)) {
            $raw = substr($raw, strlen($prefix));
        }
        $decoded = self::decodeFlexibleBytes($raw);
        if (!is_string($decoded) || strlen($decoded) !== 32) {
            return null;
        }
        return $decoded;
    }

    private static function decodeFlexibleBytes(string $input): ?string
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            return null;
        }
        if (ctype_xdigit($trimmed) && strlen($trimmed) % 2 === 0) {
            $hex = hex2bin($trimmed);
            if (is_string($hex)) {
                return $hex;
            }
        }

        $b64 = strtr($trimmed, '-_', '+/');
        $padLen = (4 - (strlen($b64) % 4)) % 4;
        if ($padLen > 0) {
            $b64 .= str_repeat('=', $padLen);
        }
        $decoded = base64_decode($b64, true);
        return is_string($decoded) ? $decoded : null;
    }
}
