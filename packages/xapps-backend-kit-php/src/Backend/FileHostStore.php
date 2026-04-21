<?php

declare(strict_types=1);

namespace Xapps\BackendKit;

final class BackendFileHostStore
{
    public static function createFileHostBootstrapReplayConsumer(array $config = []): callable
    {
        $replayFile = trim((string) ($config['replayFile'] ?? $config['filePath'] ?? ''));
        $fallbackTtlSeconds = is_numeric($config['fallbackTtlSeconds'] ?? null)
            ? max(1, (int) $config['fallbackTtlSeconds'])
            : 300;

        return static function (array $input = []) use ($replayFile, $fallbackTtlSeconds): bool {
            $jti = trim((string) ($input['jti'] ?? ''));
            if ($replayFile === '' || $jti === '') {
                return false;
            }

            return self::withLockedJsonState($replayFile, static function (array $current, callable $writeState) use ($jti, $input, $fallbackTtlSeconds): bool {
                $now = time();
                $next = self::pruneJtiExpirations($current, $now);
                if ((int) ($next[$jti] ?? 0) > $now) {
                    return false;
                }
                $exp = is_numeric($input['exp'] ?? null) ? (int) $input['exp'] : 0;
                $next[$jti] = $exp > $now ? $exp : ($now + $fallbackTtlSeconds);
                $writeState($next);
                return true;
            });
        };
    }

    public static function createFileHostSessionStore(array $config = []): array
    {
        $stateFile = trim((string) ($config['stateFile'] ?? ''));
        $revocationsFile = trim((string) ($config['revocationsFile'] ?? ''));

        return [
            'activate' => static function (array $input = []) use ($stateFile): bool {
                $jti = trim((string) ($input['jti'] ?? ''));
                $idleTtlSeconds = is_numeric($input['idleTtlSeconds'] ?? null) ? (int) $input['idleTtlSeconds'] : 0;
                $exp = is_numeric($input['exp'] ?? null) ? (int) $input['exp'] : 0;
                if ($stateFile === '' || $jti === '' || $idleTtlSeconds <= 0 || $exp <= 0) {
                    return false;
                }

                return self::withLockedJsonState($stateFile, static function (array $current, callable $writeState) use ($jti, $input, $idleTtlSeconds, $exp): bool {
                    $now = time();
                    $next = self::pruneSessionAbsoluteExpirations($current, $now);
                    $next[$jti] = [
                        'subjectId' => trim((string) ($input['subjectId'] ?? '')) ?: null,
                        'absoluteExpiresAt' => $exp,
                        'idleExpiresAt' => min($exp, $now + $idleTtlSeconds),
                    ];
                    $writeState($next);
                    return true;
                });
            },
            'touch' => static function (array $input = []) use ($stateFile): bool {
                $jti = trim((string) ($input['jti'] ?? ''));
                $idleTtlSeconds = is_numeric($input['idleTtlSeconds'] ?? null) ? (int) $input['idleTtlSeconds'] : 0;
                if ($stateFile === '' || $jti === '' || $idleTtlSeconds <= 0) {
                    return false;
                }

                return self::withLockedJsonState($stateFile, static function (array $current, callable $writeState) use ($jti, $idleTtlSeconds): bool {
                    $now = time();
                    $next = [];
                    foreach ($current as $entryJti => $value) {
                        $absoluteExpiresAt = is_array($value) ? (int) ($value['absoluteExpiresAt'] ?? 0) : 0;
                        $idleExpiresAt = is_array($value) ? (int) ($value['idleExpiresAt'] ?? 0) : 0;
                        if ($absoluteExpiresAt > $now && $idleExpiresAt > $now) {
                            $next[(string) $entryJti] = $value;
                        }
                    }
                    if (!array_key_exists($jti, $next) || !is_array($next[$jti])) {
                        if (count($next) !== count($current)) {
                            $writeState($next);
                        }
                        return false;
                    }
                    $entry = $next[$jti];
                    $next[$jti] = [
                        'subjectId' => trim((string) ($entry['subjectId'] ?? '')) ?: null,
                        'absoluteExpiresAt' => (int) ($entry['absoluteExpiresAt'] ?? 0),
                        'idleExpiresAt' => min((int) ($entry['absoluteExpiresAt'] ?? 0), $now + $idleTtlSeconds),
                    ];
                    $writeState($next);
                    return true;
                });
            },
            'isRevoked' => static function (array $input = []) use ($revocationsFile): bool {
                $jti = trim((string) ($input['jti'] ?? ''));
                if ($revocationsFile === '' || $jti === '') {
                    return false;
                }

                return self::withLockedJsonState($revocationsFile, static function (array $current, callable $writeState) use ($jti): bool {
                    $now = time();
                    $next = self::pruneJtiExpirations($current, $now);
                    if (count($next) !== count($current)) {
                        $writeState($next);
                    }
                    return (int) ($next[$jti] ?? 0) > $now;
                });
            },
            'revoke' => static function (array $input = []) use ($revocationsFile, $stateFile): bool {
                $jti = trim((string) ($input['jti'] ?? ''));
                if ($revocationsFile === '' || $jti === '') {
                    return false;
                }

                $revoked = self::withLockedJsonState($revocationsFile, static function (array $current, callable $writeState) use ($jti, $input): bool {
                    $now = time();
                    $next = self::pruneJtiExpirations($current, $now);
                    $exp = is_numeric($input['exp'] ?? null) ? (int) $input['exp'] : 0;
                    $next[$jti] = $exp > $now ? $exp : ($now + 1800);
                    $writeState($next);
                    return true;
                });
                if ($revoked && $stateFile !== '') {
                    self::withLockedJsonState($stateFile, static function (array $current, callable $writeState) use ($jti): bool {
                        $now = time();
                        $next = self::pruneSessionAbsoluteExpirations($current, $now);
                        if (array_key_exists($jti, $next)) {
                            unset($next[$jti]);
                            $writeState($next);
                            return true;
                        }
                        if (count($next) !== count($current)) {
                            $writeState($next);
                        }
                        return true;
                    });
                }
                return $revoked;
            },
        ];
    }

    private static function withLockedJsonState(string $filePath, callable $callback): mixed
    {
        $resolvedFilePath = trim($filePath);
        if ($resolvedFilePath === '') {
            return $callback([], static function (): void {
            });
        }
        $directory = dirname($resolvedFilePath);
        $previousUmask = umask(0077);
        try {
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new \RuntimeException('Unable to create host state directory');
            }
        } finally {
            umask($previousUmask);
        }
        $previousUmask = umask(0077);
        $handle = fopen($resolvedFilePath, 'c+');
        umask($previousUmask);
        if ($handle === false) {
            throw new \RuntimeException('Unable to open host state file');
        }
        @chmod($resolvedFilePath, 0600);
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock host state file');
            }
            rewind($handle);
            $raw = stream_get_contents($handle);
            $rawJson = is_string($raw) ? trim($raw) : '';
            if ($rawJson === '') {
                $parsed = [];
            } else {
                $parsed = json_decode($rawJson, true);
                if (!is_array($parsed)) {
                    throw new \RuntimeException('Invalid host state file JSON: ' . $resolvedFilePath);
                }
            }
            $current = is_array($parsed) ? $parsed : [];
            $persist = false;
            $writeState = static function (array $state) use (&$current, &$persist): void {
                $current = $state;
                $persist = true;
            };
            $result = $callback($current, $writeState);
            if ($persist) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                fflush($handle);
            }
            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function pruneJtiExpirations(array $current, int $now): array
    {
        $next = [];
        foreach ($current as $entryJti => $expiresAt) {
            if ((int) $expiresAt > $now) {
                $next[(string) $entryJti] = (int) $expiresAt;
            }
        }
        return $next;
    }

    private static function pruneSessionAbsoluteExpirations(array $current, int $now): array
    {
        $next = [];
        foreach ($current as $entryJti => $value) {
            $absoluteExpiresAt = is_array($value) ? (int) ($value['absoluteExpiresAt'] ?? 0) : 0;
            if ($absoluteExpiresAt > $now) {
                $next[(string) $entryJti] = $value;
            }
        }
        return $next;
    }
}
