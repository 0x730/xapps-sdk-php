<?php

declare(strict_types=1);

namespace Xapps\BackendKit;

final class BackendRedisHostStore
{
    private const TOUCH_SESSION_STATE_LUA = <<<'LUA'
if redis.call("GET", KEYS[2]) then
  redis.call("DEL", KEYS[1])
  return 0
end
local raw = redis.call("GET", KEYS[1])
if (not raw) or raw == "" then
  return 0
end
local ok, state = pcall(cjson.decode, raw)
if (not ok) or type(state) ~= "table" then
  redis.call("DEL", KEYS[1])
  return 0
end
local now = tonumber(ARGV[1]) or 0
local idle_ttl = tonumber(ARGV[2]) or 0
local grace = tonumber(ARGV[3]) or 0
local absolute_expires_at = tonumber(state.absoluteExpiresAt or 0) or 0
local idle_expires_at = tonumber(state.idleExpiresAt or 0) or 0
if absolute_expires_at <= now or idle_expires_at <= now then
  redis.call("DEL", KEYS[1])
  return 0
end
state.idleExpiresAt = math.min(absolute_expires_at, now + idle_ttl)
local ttl = math.max(1, (absolute_expires_at - now) + grace)
redis.call("SET", KEYS[1], cjson.encode(state), "EX", ttl)
if redis.call("GET", KEYS[2]) then
  redis.call("DEL", KEYS[1])
  return 0
end
return 1
LUA;

    private const REVOKE_SESSION_STATE_LUA = <<<'LUA'
local exp = tonumber(ARGV[1]) or 0
local now = tonumber(ARGV[2]) or 0
local grace = tonumber(ARGV[3]) or 0
local fallback_ttl = tonumber(ARGV[4]) or 1800
local ttl = math.max(1, ((exp > now) and (exp - now) or fallback_ttl) + grace)
redis.call("SET", KEYS[1], tostring(exp), "EX", ttl)
redis.call("DEL", KEYS[2])
return 1
LUA;

    private static function normalizePrefix(mixed $value): string
    {
        $normalized = rtrim(trim((string) $value), ':');
        return $normalized !== '' ? $normalized : 'xapps:host';
    }

    private static function normalizePositiveInteger(mixed $value, int $fallback): int
    {
        $numeric = is_numeric($value) ? (int) $value : $fallback;
        return $numeric > 0 ? $numeric : $fallback;
    }

    private static function nowSeconds(): int
    {
        return time();
    }

    private static function bootstrapReplayKey(string $prefix, string $jti): string
    {
        return $prefix . ':bootstrap:jti:' . $jti;
    }

    private static function sessionStateKey(string $prefix, string $jti): string
    {
        return $prefix . ':session:state:' . $jti;
    }

    private static function sessionRevokedKey(string $prefix, string $jti): string
    {
        return $prefix . ':session:revoked:' . $jti;
    }

    private static function isRedisWriteOk(mixed $value): bool
    {
        if ($value === true || $value === 1) {
            return true;
        }
        $normalized = strtoupper(trim((string) $value));
        return $normalized === 'OK';
    }

    private static function redisSetEx(object $client, string $key, string $value, int $ttlSeconds): bool
    {
        if (!method_exists($client, 'set')) {
            throw new \InvalidArgumentException('Redis host store client must implement set');
        }
        try {
            $result = $client->set($key, $value, ['ex' => $ttlSeconds]);
            if ($result === null) {
                return true;
            }
            return self::isRedisWriteOk($result);
        } catch (\TypeError|\ArgumentCountError) {
        }
        $result = $client->set($key, $value, 'EX', $ttlSeconds);
        if ($result === null) {
            return true;
        }
        return self::isRedisWriteOk($result);
    }

    private static function redisSetNxEx(object $client, string $key, string $value, int $ttlSeconds): bool
    {
        if (!method_exists($client, 'set')) {
            throw new \InvalidArgumentException('Redis host store client must implement set');
        }
        try {
            $result = $client->set($key, $value, ['nx', 'ex' => $ttlSeconds]);
            return self::isRedisWriteOk($result);
        } catch (\TypeError|\ArgumentCountError) {
        }
        $result = $client->set($key, $value, 'EX', $ttlSeconds, 'NX');
        return self::isRedisWriteOk($result);
    }

    private static function redisGet(object $client, string $key): string
    {
        if (!method_exists($client, 'get')) {
            throw new \InvalidArgumentException('Redis host store client must implement get');
        }
        $value = $client->get($key);
        return is_string($value) ? $value : '';
    }

    private static function redisDel(object $client, string ...$keys): void
    {
        if ($keys === []) {
            return;
        }
        if (!method_exists($client, 'del')) {
            throw new \InvalidArgumentException('Redis host store client must implement del');
        }
        $client->del(...$keys);
    }

    private static function redisEval(object $client, string $script, array $keys, array $args): mixed
    {
        if (!method_exists($client, 'eval')) {
            throw new \InvalidArgumentException('Redis host store client must implement eval');
        }
        $numKeys = count($keys);
        $argv = array_map(
            static fn (mixed $value): string => (string) $value,
            array_merge($keys, $args),
        );
        try {
            return $client->eval($script, $argv, $numKeys);
        } catch (\TypeError|\ArgumentCountError) {
        }
        try {
            return $client->eval($script, $numKeys, ...$argv);
        } catch (\TypeError|\ArgumentCountError) {
        }
        return $client->eval($script, $argv);
    }

    private static function isRedisEvalSuccess(mixed $value): bool
    {
        if ($value === true) {
            return true;
        }
        if (is_numeric($value) && (int) $value === 1) {
            return true;
        }
        return strtoupper(trim((string) $value)) === 'OK';
    }

    public static function createRedisHostBootstrapReplayConsumer(array $config = []): callable
    {
        $client = $config['client'] ?? null;
        $prefix = self::normalizePrefix($config['keyPrefix'] ?? null);
        $fallbackTtlSeconds = self::normalizePositiveInteger($config['fallbackTtlSeconds'] ?? null, 300);
        $graceSeconds = self::normalizePositiveInteger($config['graceSeconds'] ?? null, 60);

        return static function (array $input = []) use ($client, $prefix, $fallbackTtlSeconds, $graceSeconds): bool {
            $jti = trim((string) ($input['jti'] ?? ''));
            if (!is_object($client) || $jti === '') {
                return false;
            }
            $now = self::nowSeconds();
            $exp = is_numeric($input['exp'] ?? null) ? (int) $input['exp'] : 0;
            $ttlSeconds = max(1, ($exp > $now ? ($exp - $now) : $fallbackTtlSeconds) + $graceSeconds);
            return self::redisSetNxEx(
                $client,
                self::bootstrapReplayKey($prefix, $jti),
                (string) $exp,
                $ttlSeconds,
            );
        };
    }

    public static function createRedisHostSessionStore(array $config = []): array
    {
        $client = $config['client'] ?? null;
        $prefix = self::normalizePrefix($config['keyPrefix'] ?? null);
        $graceSeconds = self::normalizePositiveInteger($config['graceSeconds'] ?? null, 60);

        return [
            'activate' => static function (array $input = []) use ($client, $prefix, $graceSeconds): bool {
                $jti = trim((string) ($input['jti'] ?? ''));
                $idleTtlSeconds = is_numeric($input['idleTtlSeconds'] ?? null) ? (int) $input['idleTtlSeconds'] : 0;
                $exp = is_numeric($input['exp'] ?? null) ? (int) $input['exp'] : 0;
                if (!is_object($client) || $jti === '' || $idleTtlSeconds <= 0 || $exp <= 0) {
                    return false;
                }
                $now = self::nowSeconds();
                $idleExpiresAt = min($exp, $now + $idleTtlSeconds);
                $ttlSeconds = max(1, ($exp - $now) + $graceSeconds);
                $payload = json_encode([
                    'subjectId' => trim((string) ($input['subjectId'] ?? '')) ?: null,
                    'absoluteExpiresAt' => $exp,
                    'idleExpiresAt' => $idleExpiresAt,
                ], JSON_UNESCAPED_SLASHES);
                return self::redisSetEx(
                    $client,
                    self::sessionStateKey($prefix, $jti),
                    (string) $payload,
                    $ttlSeconds,
                );
            },
            'touch' => static function (array $input = []) use ($client, $prefix, $graceSeconds): bool {
                $jti = trim((string) ($input['jti'] ?? ''));
                $idleTtlSeconds = is_numeric($input['idleTtlSeconds'] ?? null) ? (int) $input['idleTtlSeconds'] : 0;
                if (!is_object($client) || $jti === '' || $idleTtlSeconds <= 0) {
                    return false;
                }
                $stateKey = self::sessionStateKey($prefix, $jti);
                $revokedKey = self::sessionRevokedKey($prefix, $jti);
                try {
                    $result = self::redisEval(
                        $client,
                        self::TOUCH_SESSION_STATE_LUA,
                        [$stateKey, $revokedKey],
                        [self::nowSeconds(), $idleTtlSeconds, $graceSeconds],
                    );
                    return self::isRedisEvalSuccess($result);
                } catch (\Throwable) {
                    if (trim(self::redisGet($client, $revokedKey)) !== '') {
                        self::redisDel($client, $stateKey);
                        return false;
                    }
                    $raw = trim(self::redisGet($client, $stateKey));
                    if ($raw === '') {
                        return false;
                    }
                    $parsed = json_decode($raw, true);
                    if (!is_array($parsed)) {
                        self::redisDel($client, $stateKey);
                        return false;
                    }
                    $now = self::nowSeconds();
                    $absoluteExpiresAt = is_numeric($parsed['absoluteExpiresAt'] ?? null)
                        ? (int) $parsed['absoluteExpiresAt']
                        : 0;
                    $currentIdleExpiresAt = is_numeric($parsed['idleExpiresAt'] ?? null)
                        ? (int) $parsed['idleExpiresAt']
                        : 0;
                    if ($absoluteExpiresAt <= $now || $currentIdleExpiresAt <= $now) {
                        self::redisDel($client, $stateKey);
                        return false;
                    }
                    $idleExpiresAt = min($absoluteExpiresAt, $now + $idleTtlSeconds);
                    $ttlSeconds = max(1, ($absoluteExpiresAt - $now) + $graceSeconds);
                    $payload = json_encode([
                        'subjectId' => trim((string) ($parsed['subjectId'] ?? '')) ?: null,
                        'absoluteExpiresAt' => $absoluteExpiresAt,
                        'idleExpiresAt' => $idleExpiresAt,
                    ], JSON_UNESCAPED_SLASHES);
                    if (trim(self::redisGet($client, $revokedKey)) !== '') {
                        self::redisDel($client, $stateKey);
                        return false;
                    }
                    $touched = self::redisSetEx($client, $stateKey, (string) $payload, $ttlSeconds);
                    if (!$touched) {
                        return false;
                    }
                    if (trim(self::redisGet($client, $revokedKey)) !== '') {
                        self::redisDel($client, $stateKey);
                        return false;
                    }
                    return true;
                }
            },
            'isRevoked' => static function (array $input = []) use ($client, $prefix): bool {
                $jti = trim((string) ($input['jti'] ?? ''));
                if (!is_object($client) || $jti === '') {
                    return false;
                }
                return trim(self::redisGet($client, self::sessionRevokedKey($prefix, $jti))) !== '';
            },
            'revoke' => static function (array $input = []) use ($client, $prefix, $graceSeconds): bool {
                $jti = trim((string) ($input['jti'] ?? ''));
                if (!is_object($client) || $jti === '') {
                    return false;
                }
                $now = self::nowSeconds();
                $exp = is_numeric($input['exp'] ?? null) ? (int) $input['exp'] : 0;
                $revokedKey = self::sessionRevokedKey($prefix, $jti);
                $stateKey = self::sessionStateKey($prefix, $jti);
                try {
                    $result = self::redisEval(
                        $client,
                        self::REVOKE_SESSION_STATE_LUA,
                        [$revokedKey, $stateKey],
                        [$exp, $now, $graceSeconds, 1800],
                    );
                    return self::isRedisEvalSuccess($result);
                } catch (\Throwable) {
                    $ttlSeconds = max(1, ($exp > $now ? ($exp - $now) : 1800) + $graceSeconds);
                    $revoked = self::redisSetEx(
                        $client,
                        $revokedKey,
                        (string) $exp,
                        $ttlSeconds,
                    );
                    self::redisDel($client, $stateKey);
                    return $revoked;
                }
            },
        ];
    }
}
