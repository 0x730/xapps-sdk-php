<?php

declare(strict_types=1);

return [
    [
        'name' => 'backend options fail closed when hosted bootstrap api keys are configured without a signing secret',
        'run' => static function (): void {
            try {
                \Xapps\BackendKit\BackendOptions::normalizeOptions([
                    'host' => [
                        'bootstrap' => [
                            'apiKeys' => ['bootstrap_key_123'],
                            'signingSecret' => '',
                        ],
                    ],
                ], [
                    'normalizeEnabledModes' => static fn (): array => [],
                ]);
                throw new RuntimeException('Expected missing signing secret rejection');
            } catch (Throwable $error) {
                xappsBackendKitPhpAssertTrue(
                    str_contains($error->getMessage(), 'host.bootstrap.signingSecret is required'),
                    'missing signing secret message mismatch',
                );
            }
        },
    ],
    [
        'name' => 'backend options fail closed when hosted bootstrap api keys are configured without a session signing secret',
        'run' => static function (): void {
            try {
                \Xapps\BackendKit\BackendOptions::normalizeOptions([
                    'host' => [
                        'bootstrap' => [
                            'apiKeys' => ['bootstrap_key_123'],
                            'signingSecret' => 'bootstrap_secret_123',
                        ],
                        'session' => [
                            'signingSecret' => '',
                        ],
                    ],
                ], [
                    'normalizeEnabledModes' => static fn (): array => [],
                ]);
                throw new RuntimeException('Expected missing session signing secret rejection');
            } catch (Throwable $error) {
                xappsBackendKitPhpAssertTrue(
                    str_contains($error->getMessage(), 'host.session.signingSecret is required'),
                    'missing session signing secret message mismatch',
                );
            }
        },
    ],
    [
        'name' => 'backend options fail closed when hosted bootstrap api keys are configured without revocation hooks',
        'run' => static function (): void {
            try {
                \Xapps\BackendKit\BackendOptions::normalizeOptions([
                    'host' => [
                        'bootstrap' => [
                            'apiKeys' => ['bootstrap_key_123'],
                            'signingSecret' => 'bootstrap_secret_123',
                        ],
                        'session' => [
                            'signingSecret' => 'session_secret_123',
                        ],
                    ],
                ], [
                    'normalizeEnabledModes' => static fn (): array => [],
                ]);
                throw new RuntimeException('Expected missing revocation hook rejection');
            } catch (Throwable $error) {
                xappsBackendKitPhpAssertTrue(
                    str_contains($error->getMessage(), 'host.session.store.isRevoked is required'),
                    'missing revocation hook message mismatch',
                );
            }
        },
    ],
    [
        'name' => 'backend options accept host.session.store as the preferred host session adapter surface',
        'run' => static function (): void {
            $activate = static fn (): bool => true;
            $touch = static fn (): array => ['active' => true];
            $isRevoked = static fn (): bool => false;
            $revoke = static fn (): bool => true;

            $normalized = \Xapps\BackendKit\BackendOptions::normalizeOptions([
                'host' => [
                    'bootstrap' => [
                        'apiKeys' => ['bootstrap_key_123'],
                        'signingSecret' => 'bootstrap_secret_123',
                        'consumeJti' => static fn (): bool => true,
                    ],
                    'session' => [
                        'signingSecret' => 'session_secret_123',
                        'store' => [
                            'activate' => $activate,
                            'touch' => $touch,
                            'isRevoked' => $isRevoked,
                            'revoke' => $revoke,
                        ],
                    ],
                ],
            ], [
                'normalizeEnabledModes' => static fn (): array => [],
            ]);

            xappsBackendKitPhpAssertSame($activate, $normalized['host']['session']['store']['activate'] ?? null, 'store activate mismatch');
            xappsBackendKitPhpAssertSame($touch, $normalized['host']['session']['store']['touch'] ?? null, 'store touch mismatch');
            xappsBackendKitPhpAssertSame($isRevoked, $normalized['host']['session']['store']['isRevoked'] ?? null, 'store isRevoked mismatch');
            xappsBackendKitPhpAssertSame($revoke, $normalized['host']['session']['store']['revoke'] ?? null, 'store revoke mismatch');
        },
    ],
    [
        'name' => 'backend kit provides a generic file bootstrap replay consumer helper',
        'run' => static function (): void {
            $tempDir = sys_get_temp_dir() . '/xapps-host-bootstrap-' . bin2hex(random_bytes(4));
            mkdir($tempDir, 0777, true);
            $replayFile = $tempDir . '/host-bootstrap-replay.json';
            try {
                $consume = \Xapps\BackendKit\BackendKit::createFileHostBootstrapReplayConsumer([
                    'replayFile' => $replayFile,
                ]);
                xappsBackendKitPhpAssertTrue($consume([
                    'jti' => 'replay_1',
                    'exp' => time() + 300,
                ]), 'first replay consume should succeed');
                xappsBackendKitPhpAssertSame(false, $consume([
                    'jti' => 'replay_1',
                    'exp' => time() + 300,
                ]), 'second replay consume should fail');
            } finally {
                @unlink($replayFile);
                @rmdir($tempDir);
            }
        },
    ],
    [
        'name' => 'backend kit fails closed when bootstrap replay state JSON is invalid',
        'run' => static function (): void {
            $tempDir = sys_get_temp_dir() . '/xapps-host-bootstrap-invalid-' . bin2hex(random_bytes(4));
            mkdir($tempDir, 0777, true);
            $replayFile = $tempDir . '/host-bootstrap-replay.json';
            file_put_contents($replayFile, '{invalid_json');
            try {
                $consume = \Xapps\BackendKit\BackendKit::createFileHostBootstrapReplayConsumer([
                    'replayFile' => $replayFile,
                ]);
                try {
                    $consume([
                        'jti' => 'replay_bad_1',
                        'exp' => time() + 300,
                    ]);
                    throw new RuntimeException('Expected invalid replay JSON rejection');
                } catch (Throwable $error) {
                    xappsBackendKitPhpAssertTrue(
                        str_contains($error->getMessage(), 'Invalid host state file JSON'),
                        'invalid replay JSON message mismatch',
                    );
                }
            } finally {
                @unlink($replayFile);
                @rmdir($tempDir);
            }
        },
    ],
    [
        'name' => 'backend kit provides a generic file host session store helper',
        'run' => static function (): void {
            $tempDir = sys_get_temp_dir() . '/xapps-host-session-' . bin2hex(random_bytes(4));
            mkdir($tempDir, 0777, true);
            $stateFile = $tempDir . '/host-session-state.json';
            $revocationsFile = $tempDir . '/host-session-revocations.json';
            try {
                $store = \Xapps\BackendKit\BackendKit::createFileHostSessionStore([
                    'stateFile' => $stateFile,
                    'revocationsFile' => $revocationsFile,
                ]);
                $exp = time() + 300;
                xappsBackendKitPhpAssertTrue(($store['activate'])(
                    [
                        'jti' => 'sess_1',
                        'subjectId' => 'sub_123',
                        'exp' => $exp,
                        'idleTtlSeconds' => 120,
                    ]
                ), 'session activate should succeed');
                xappsBackendKitPhpAssertTrue(($store['touch'])(
                    [
                        'jti' => 'sess_1',
                        'idleTtlSeconds' => 120,
                    ]
                ), 'session touch should succeed');
                xappsBackendKitPhpAssertSame(false, ($store['isRevoked'])(['jti' => 'sess_1']), 'session should not start revoked');
                xappsBackendKitPhpAssertTrue(($store['revoke'])(['jti' => 'sess_1', 'exp' => $exp]), 'session revoke should succeed');
                xappsBackendKitPhpAssertTrue(($store['isRevoked'])(['jti' => 'sess_1']), 'session should read as revoked');
            } finally {
                @unlink($stateFile);
                @unlink($revocationsFile);
                @rmdir($tempDir);
            }
        },
    ],
    [
        'name' => 'backend kit prevents fallback touch from resurrecting revoked redis host sessions',
        'run' => static function (): void {
            $client = new class () {
                public bool $injectRevocationOnNextStateSet = false;
                public string $raceStateKey = '';
                public string $raceRevokedKey = '';
                /** @var array<string, array{value: string, expiresAt: int}> */
                private array $entries = [];

                private function nowSeconds(): int
                {
                    return time();
                }

                private function prune(): void
                {
                    $now = $this->nowSeconds();
                    foreach ($this->entries as $key => $entry) {
                        if (($entry['expiresAt'] ?? 0) > 0 && (int) $entry['expiresAt'] <= $now) {
                            unset($this->entries[$key]);
                        }
                    }
                }

                public function set(mixed $key, mixed $value, mixed ...$args): string
                {
                    $this->prune();
                    $normalizedKey = trim((string) $key);
                    if ($normalizedKey === '') {
                        return '';
                    }
                    $ttlSeconds = 0;
                    if (count($args) === 1 && is_array($args[0])) {
                        $ttlSeconds = is_numeric($args[0]['ex'] ?? null) ? (int) $args[0]['ex'] : 0;
                    } else {
                        for ($index = 0; $index < count($args); $index += 1) {
                            $token = strtoupper(trim((string) ($args[$index] ?? '')));
                            if ($token === 'EX') {
                                $ttlSeconds = is_numeric($args[$index + 1] ?? null) ? (int) $args[$index + 1] : 0;
                                $index += 1;
                            }
                        }
                    }
                    if ($this->injectRevocationOnNextStateSet && $normalizedKey === $this->raceStateKey) {
                        $this->injectRevocationOnNextStateSet = false;
                        $this->entries[$this->raceRevokedKey] = [
                            'value' => (string) ($this->nowSeconds() + 120),
                            'expiresAt' => $this->nowSeconds() + 120,
                        ];
                    }
                    $this->entries[$normalizedKey] = [
                        'value' => (string) $value,
                        'expiresAt' => $ttlSeconds > 0 ? $this->nowSeconds() + $ttlSeconds : 0,
                    ];
                    return 'OK';
                }

                public function get(string $key): ?string
                {
                    $this->prune();
                    $normalizedKey = trim($key);
                    if ($normalizedKey === '') {
                        return null;
                    }
                    return $this->entries[$normalizedKey]['value'] ?? null;
                }

                public function del(string ...$keys): int
                {
                    $deleted = 0;
                    foreach ($keys as $key) {
                        $normalizedKey = trim($key);
                        if ($normalizedKey !== '' && array_key_exists($normalizedKey, $this->entries)) {
                            unset($this->entries[$normalizedKey]);
                            $deleted += 1;
                        }
                    }
                    return $deleted;
                }

                public function eval(mixed ...$args): mixed
                {
                    throw new RuntimeException('eval unavailable in test redis client');
                }
            };

            $keyPrefix = 'xapps:host:race';
            $store = \Xapps\BackendKit\BackendKit::createRedisHostSessionStore([
                'client' => $client,
                'keyPrefix' => $keyPrefix,
            ]);

            $jti = 'session_race_jti_123';
            $exp = time() + 600;
            $stateKey = $keyPrefix . ':session:state:' . $jti;
            $revokedKey = $keyPrefix . ':session:revoked:' . $jti;

            xappsBackendKitPhpAssertTrue(($store['activate'])(
                [
                    'jti' => $jti,
                    'subjectId' => 'sub_123',
                    'exp' => $exp,
                    'idleTtlSeconds' => 120,
                ]
            ), 'redis session activate should succeed');

            $client->raceStateKey = $stateKey;
            $client->raceRevokedKey = $revokedKey;
            $client->injectRevocationOnNextStateSet = true;

            xappsBackendKitPhpAssertSame(false, ($store['touch'])(
                [
                    'jti' => $jti,
                    'idleTtlSeconds' => 120,
                ]
            ), 'fallback redis touch should fail when revocation races');

            xappsBackendKitPhpAssertTrue(($store['isRevoked'])(['jti' => $jti]), 'session should read as revoked');
            xappsBackendKitPhpAssertSame(null, $client->get($stateKey), 'state key should be deleted after raced revocation');
        },
    ],
    [
        'name' => 'backend kit fails closed when host session revocation state JSON is invalid',
        'run' => static function (): void {
            $tempDir = sys_get_temp_dir() . '/xapps-host-session-invalid-' . bin2hex(random_bytes(4));
            mkdir($tempDir, 0777, true);
            $stateFile = $tempDir . '/host-session-state.json';
            $revocationsFile = $tempDir . '/host-session-revocations.json';
            file_put_contents($revocationsFile, '{invalid_json');
            try {
                $store = \Xapps\BackendKit\BackendKit::createFileHostSessionStore([
                    'stateFile' => $stateFile,
                    'revocationsFile' => $revocationsFile,
                ]);
                try {
                    ($store['isRevoked'])(['jti' => 'sess_bad_1']);
                    throw new RuntimeException('Expected invalid revocation JSON rejection');
                } catch (Throwable $error) {
                    xappsBackendKitPhpAssertTrue(
                        str_contains($error->getMessage(), 'Invalid host state file JSON'),
                        'invalid revocation JSON message mismatch',
                    );
                }
            } finally {
                @unlink($stateFile);
                @unlink($revocationsFile);
                @rmdir($tempDir);
            }
        },
    ],
    [
        'name' => 'backend options fail closed when idle host sessions are configured without activation hooks',
        'run' => static function (): void {
            try {
                \Xapps\BackendKit\BackendOptions::normalizeOptions([
                    'host' => [
                        'bootstrap' => [
                            'apiKeys' => ['bootstrap_key_123'],
                            'signingSecret' => 'bootstrap_secret_123',
                        ],
                        'session' => [
                            'signingSecret' => 'session_secret_123',
                            'idleTtlSeconds' => 300,
                            'store' => [
                                'isRevoked' => static fn (): bool => false,
                                'revoke' => static fn (): bool => true,
                            ],
                        ],
                    ],
                ], [
                    'normalizeEnabledModes' => static fn (): array => [],
                ]);
                throw new RuntimeException('Expected missing activation hook rejection');
            } catch (Throwable $error) {
                xappsBackendKitPhpAssertTrue(
                    str_contains($error->getMessage(), 'host.session.store.activate is required'),
                    'missing activation hook message mismatch',
                );
            }
        },
    ],
    [
        'name' => 'backend options fail closed when idle host sessions are configured without touch hooks',
        'run' => static function (): void {
            try {
                \Xapps\BackendKit\BackendOptions::normalizeOptions([
                    'host' => [
                        'bootstrap' => [
                            'apiKeys' => ['bootstrap_key_123'],
                            'signingSecret' => 'bootstrap_secret_123',
                        ],
                        'session' => [
                            'signingSecret' => 'session_secret_123',
                            'idleTtlSeconds' => 300,
                            'store' => [
                                'activate' => static fn (): bool => true,
                                'isRevoked' => static fn (): bool => false,
                                'revoke' => static fn (): bool => true,
                            ],
                        ],
                    ],
                ], [
                    'normalizeEnabledModes' => static fn (): array => [],
                ]);
                throw new RuntimeException('Expected missing touch hook rejection');
            } catch (Throwable $error) {
                xappsBackendKitPhpAssertTrue(
                    str_contains($error->getMessage(), 'host.session.store.touch is required'),
                    'missing touch hook message mismatch',
                );
            }
        },
    ],
    [
        'name' => 'host bootstrap security requires origin allowlist and exact token origin match',
        'run' => static function (): void {
            xappsBackendKitPhpAssertSame(
                'https://host.example.test',
                xapps_backend_kit_require_requested_host_bootstrap_origin(
                    'https://host.example.test/',
                    ['https://host.example.test']
                ),
                'normalized bootstrap origin mismatch',
            );

            try {
                xapps_backend_kit_require_requested_host_bootstrap_origin('', ['https://host.example.test']);
                throw new RuntimeException('Expected missing origin rejection');
            } catch (Throwable $error) {
                xappsBackendKitPhpAssertTrue(
                    str_contains($error->getMessage(), 'host bootstrap origin is required'),
                    'missing origin message mismatch',
                );
            }

            try {
                xapps_backend_kit_require_requested_host_bootstrap_origin(
                    'https://other.example.test',
                    ['https://host.example.test']
                );
                throw new RuntimeException('Expected disallowed origin rejection');
            } catch (Throwable $error) {
                xappsBackendKitPhpAssertTrue(
                    str_contains($error->getMessage(), 'host bootstrap origin is not allowed'),
                    'disallowed origin message mismatch',
                );
            }

            $result = xapps_backend_kit_build_host_bootstrap_result([
                'subjectId' => 'sub_123',
                'origin' => 'https://host.example.test',
                'signingSecret' => 'secret_123',
                'ttlSeconds' => 300,
            ]);

            try {
                xapps_backend_kit_read_host_bootstrap_context([
                    'headers' => [
                        'x-xapps-host-bootstrap' => $result['bootstrapToken'],
                    ],
                ], [
                    'signingSecret' => 'secret_123',
                ]);
                throw new RuntimeException('Expected missing request origin rejection');
            } catch (Throwable $error) {
                xappsBackendKitPhpAssertTrue(
                    str_contains($error->getMessage(), 'host bootstrap request origin is required'),
                    'missing request origin message mismatch',
                );
            }

            try {
                xapps_backend_kit_read_host_bootstrap_context([
                    'headers' => [
                        'origin' => 'https://other.example.test',
                        'x-xapps-host-bootstrap' => $result['bootstrapToken'],
                    ],
                ], [
                    'signingSecret' => 'secret_123',
                ]);
                throw new RuntimeException('Expected origin mismatch rejection');
            } catch (Throwable $error) {
                xappsBackendKitPhpAssertTrue(
                    str_contains($error->getMessage(), 'host bootstrap token origin mismatch'),
                    'origin mismatch message mismatch',
                );
            }
        },
    ],
    [
        'name' => 'host bootstrap security rejects invalid token type and version',
        'run' => static function (): void {
            $now = time();
            $wrongType = xapps_backend_kit_issue_host_bootstrap_token([
                'v' => 2,
                'type' => 'wrong_type',
                'iss' => 'xapps_host_bootstrap',
                'aud' => 'xapps_host_api',
                'jti' => 'jti_wrong_type',
                'subjectId' => 'sub_123',
                'origin' => 'https://host.example.test',
                'iat' => $now,
                'exp' => $now + 300,
            ], 'secret_123');

            try {
                xapps_backend_kit_read_host_bootstrap_context([
                    'headers' => [
                        'origin' => 'https://host.example.test',
                        'x-xapps-host-bootstrap' => $wrongType,
                    ],
                ], [
                    'signingSecret' => 'secret_123',
                ]);
                throw new RuntimeException('Expected token type rejection');
            } catch (Throwable $error) {
                xappsBackendKitPhpAssertTrue(
                    str_contains($error->getMessage(), 'host bootstrap token type is invalid'),
                    'token type message mismatch',
                );
            }

            $wrongVersion = xapps_backend_kit_issue_host_bootstrap_token([
                'v' => 999,
                'type' => 'host_bootstrap',
                'iss' => 'xapps_host_bootstrap',
                'aud' => 'xapps_host_api',
                'jti' => 'jti_wrong_version',
                'subjectId' => 'sub_123',
                'origin' => 'https://host.example.test',
                'iat' => $now,
                'exp' => $now + 300,
            ], 'secret_123');

            try {
                xapps_backend_kit_read_host_bootstrap_context([
                    'headers' => [
                        'origin' => 'https://host.example.test',
                        'x-xapps-host-bootstrap' => $wrongVersion,
                    ],
                ], [
                    'signingSecret' => 'secret_123',
                ]);
                throw new RuntimeException('Expected token version rejection');
            } catch (Throwable $error) {
                xappsBackendKitPhpAssertTrue(
                    str_contains($error->getMessage(), 'host bootstrap token version is invalid'),
                    'token version message mismatch',
                );
            }

            $wrongIssuer = xapps_backend_kit_issue_host_bootstrap_token([
                'v' => 2,
                'type' => 'host_bootstrap',
                'iss' => 'wrong_issuer',
                'aud' => 'xapps_host_api',
                'jti' => 'jti_wrong_issuer',
                'subjectId' => 'sub_123',
                'origin' => 'https://host.example.test',
                'iat' => $now,
                'exp' => $now + 300,
            ], 'secret_123');

            try {
                xapps_backend_kit_read_host_bootstrap_context([
                    'headers' => [
                        'origin' => 'https://host.example.test',
                        'x-xapps-host-bootstrap' => $wrongIssuer,
                    ],
                ], [
                    'signingSecret' => 'secret_123',
                ]);
                throw new RuntimeException('Expected token issuer rejection');
            } catch (Throwable $error) {
                xappsBackendKitPhpAssertTrue(
                    str_contains($error->getMessage(), 'host bootstrap token issuer is invalid'),
                    'token issuer message mismatch',
                );
            }

            $wrongAudience = xapps_backend_kit_issue_host_bootstrap_token([
                'v' => 2,
                'type' => 'host_bootstrap',
                'iss' => 'xapps_host_bootstrap',
                'aud' => 'wrong_audience',
                'jti' => 'jti_wrong_audience',
                'subjectId' => 'sub_123',
                'origin' => 'https://host.example.test',
                'iat' => $now,
                'exp' => $now + 300,
            ], 'secret_123');

            try {
                xapps_backend_kit_read_host_bootstrap_context([
                    'headers' => [
                        'origin' => 'https://host.example.test',
                        'x-xapps-host-bootstrap' => $wrongAudience,
                    ],
                ], [
                    'signingSecret' => 'secret_123',
                ]);
                throw new RuntimeException('Expected token audience rejection');
            } catch (Throwable $error) {
                xappsBackendKitPhpAssertTrue(
                    str_contains($error->getMessage(), 'host bootstrap token audience is invalid'),
                    'token audience message mismatch',
                );
            }

            $missingJti = xapps_backend_kit_issue_host_bootstrap_token([
                'v' => 2,
                'type' => 'host_bootstrap',
                'iss' => 'xapps_host_bootstrap',
                'aud' => 'xapps_host_api',
                'subjectId' => 'sub_123',
                'origin' => 'https://host.example.test',
                'iat' => $now,
                'exp' => $now + 300,
            ], 'secret_123');

            try {
                xapps_backend_kit_read_host_bootstrap_context([
                    'headers' => [
                        'origin' => 'https://host.example.test',
                        'x-xapps-host-bootstrap' => $missingJti,
                    ],
                ], [
                    'signingSecret' => 'secret_123',
                ]);
                throw new RuntimeException('Expected token jti rejection');
            } catch (Throwable $error) {
                xappsBackendKitPhpAssertTrue(
                    str_contains($error->getMessage(), 'host bootstrap token jti is invalid'),
                    'token jti message mismatch',
                );
            }

            $encodedMalformedPayload = rtrim(strtr(base64_encode('not-json'), '+/', '-_'), '=');
            $malformedPayload = $encodedMalformedPayload . '.'
                . rtrim(strtr(base64_encode(hash_hmac('sha256', $encodedMalformedPayload, 'secret_123', true)), '+/', '-_'), '=');

            try {
                xapps_backend_kit_read_host_bootstrap_context([
                    'headers' => [
                        'origin' => 'https://host.example.test',
                        'x-xapps-host-bootstrap' => $malformedPayload,
                    ],
                ], [
                    'signingSecret' => 'secret_123',
                ]);
                throw new RuntimeException('Expected malformed payload rejection');
            } catch (Throwable $error) {
                xappsBackendKitPhpAssertTrue(
                    str_contains($error->getMessage(), 'host bootstrap token payload is invalid'),
                    'malformed payload message mismatch',
                );
            }
        },
    ],
    [
        'name' => 'host bootstrap security verifies rotated bootstrap tokens by kid and rejects unknown kids',
        'run' => static function (): void {
            $now = time();
            $rotated = xapps_backend_kit_issue_host_bootstrap_token([
                'v' => 2,
                'type' => 'host_bootstrap',
                'iss' => 'xapps_host_bootstrap',
                'aud' => 'xapps_host_api',
                'kid' => 'bootstrap-previous',
                'jti' => 'jti_rotated_bootstrap',
                'subjectId' => 'sub_123',
                'origin' => 'https://host.example.test',
                'iat' => $now,
                'exp' => $now + 300,
            ], 'bootstrap_secret_prev_123');

            $context = xapps_backend_kit_read_host_bootstrap_context([
                'headers' => [
                    'origin' => 'https://host.example.test',
                    'x-xapps-host-bootstrap' => $rotated,
                ],
            ], [
                'signingSecret' => 'bootstrap_secret_123',
                'signingKeyId' => 'bootstrap-current',
                'verifierKeys' => [
                    'bootstrap-current' => 'bootstrap_secret_123',
                    'bootstrap-previous' => 'bootstrap_secret_prev_123',
                ],
            ]);
            xappsBackendKitPhpAssertSame('sub_123', $context['subjectId'] ?? null, 'rotated bootstrap subject mismatch');

            $unknownKid = xapps_backend_kit_issue_host_bootstrap_token([
                'v' => 2,
                'type' => 'host_bootstrap',
                'iss' => 'xapps_host_bootstrap',
                'aud' => 'xapps_host_api',
                'kid' => 'bootstrap-unknown',
                'jti' => 'jti_unknown_bootstrap',
                'subjectId' => 'sub_123',
                'origin' => 'https://host.example.test',
                'iat' => $now,
                'exp' => $now + 300,
            ], 'bootstrap_secret_prev_123');

            try {
                xapps_backend_kit_read_host_bootstrap_context([
                    'headers' => [
                        'origin' => 'https://host.example.test',
                        'x-xapps-host-bootstrap' => $unknownKid,
                    ],
                ], [
                    'signingSecret' => 'bootstrap_secret_123',
                    'signingKeyId' => 'bootstrap-current',
                    'verifierKeys' => [
                        'bootstrap-current' => 'bootstrap_secret_123',
                        'bootstrap-previous' => 'bootstrap_secret_prev_123',
                    ],
                ]);
                throw new RuntimeException('Expected unknown bootstrap kid rejection');
            } catch (Throwable $error) {
                xappsBackendKitPhpAssertTrue(
                    str_contains($error->getMessage(), 'host bootstrap token kid is invalid'),
                    'unknown bootstrap kid message mismatch',
                );
            }
        },
    ],
    [
        'name' => 'host session exchange signs cookie-backed host auth context',
        'run' => static function (): void {
            $result = xapps_backend_kit_build_host_session_exchange_result([
                'subjectId' => 'sub_session_123',
                'email' => 'alex@example.com',
                'name' => 'Alex Example',
                'signingSecret' => 'session_secret_123',
            ], [
                'protocol' => 'https',
                'headers' => [
                    'host' => 'tenant.example.test',
                    'origin' => 'https://host.example.test',
                ],
            ]);

            xappsBackendKitPhpAssertTrue(
                str_contains((string) ($result['setCookie'] ?? ''), 'xapps_host_session='),
                'missing host-session cookie header',
            );

            preg_match('/xapps_host_session=([^;]+)/', (string) ($result['setCookie'] ?? ''), $matches);
            $token = trim((string) ($matches[1] ?? ''));
            xappsBackendKitPhpAssertTrue($token !== '', 'missing host-session token');

            $context = xapps_backend_kit_read_host_auth_context([
                'headers' => [
                    'cookie' => 'xapps_host_session=' . $token,
                ],
            ], [
                'signingSecret' => 'session_secret_123',
                'store' => [
                    'isRevoked' => static fn (): bool => false,
                ],
            ]);

            xappsBackendKitPhpAssertSame('sub_session_123', $context['subjectId'] ?? null, 'host session subject mismatch');
            xappsBackendKitPhpAssertSame('host_session', $context['sessionMode'] ?? null, 'host session mode mismatch');
        },
    ],
    [
        'name' => 'host session auth verifies rotated session tokens by kid and rejects unknown kids',
        'run' => static function (): void {
            $now = time();
            $rotated = xapps_backend_kit_issue_host_session_token([
                'v' => 2,
                'type' => 'host_session',
                'iss' => 'xapps_host_session',
                'aud' => 'xapps_host_api',
                'kid' => 'session-previous',
                'jti' => 'jti_rotated_session',
                'subjectId' => 'sub_session_123',
                'email' => 'alex@example.com',
                'iat' => $now,
                'exp' => $now + 300,
            ], 'session_secret_prev_123');

            $context = xapps_backend_kit_read_host_auth_context([
                'headers' => [
                    'cookie' => 'xapps_host_session=' . rawurlencode($rotated),
                ],
            ], [
                'signingSecret' => 'session_secret_123',
                'signingKeyId' => 'session-current',
                'verifierKeys' => [
                    'session-current' => 'session_secret_123',
                    'session-previous' => 'session_secret_prev_123',
                ],
                'store' => [
                    'isRevoked' => static fn (): bool => false,
                ],
            ]);
            xappsBackendKitPhpAssertSame('sub_session_123', $context['subjectId'] ?? null, 'rotated host session subject mismatch');

            $unknownKid = xapps_backend_kit_issue_host_session_token([
                'v' => 1,
                'type' => 'host_session',
                'iss' => 'xapps_host_session',
                'aud' => 'xapps_host_api',
                'kid' => 'session-unknown',
                'jti' => 'jti_unknown_session',
                'subjectId' => 'sub_session_123',
                'email' => 'alex@example.com',
                'iat' => $now,
                'exp' => $now + 300,
            ], 'session_secret_prev_123');

            try {
                xapps_backend_kit_read_host_auth_context([
                    'headers' => [
                        'cookie' => 'xapps_host_session=' . rawurlencode($unknownKid),
                    ],
                ], [
                    'signingSecret' => 'session_secret_123',
                    'signingKeyId' => 'session-current',
                    'verifierKeys' => [
                        'session-current' => 'session_secret_123',
                        'session-previous' => 'session_secret_prev_123',
                    ],
                    'store' => [
                        'isRevoked' => static fn (): bool => false,
                    ],
                ]);
                throw new RuntimeException('Expected unknown session kid rejection');
            } catch (Throwable $error) {
                xappsBackendKitPhpAssertTrue(
                    str_contains($error->getMessage(), 'host session token kid is invalid'),
                    'unknown session kid message mismatch',
                );
            }
        },
    ],
    [
        'name' => 'revoked host-session cookies are rejected',
        'run' => static function (): void {
            $result = xapps_backend_kit_build_host_session_exchange_result([
                'subjectId' => 'sub_session_123',
                'email' => 'alex@example.com',
                'name' => 'Alex Example',
                'signingSecret' => 'session_secret_123',
            ], [
                'protocol' => 'https',
                'headers' => [
                    'host' => 'tenant.example.test',
                    'origin' => 'https://host.example.test',
                ],
            ]);

            preg_match('/xapps_host_session=([^;]+)/', (string) ($result['setCookie'] ?? ''), $matches);
            $token = trim((string) ($matches[1] ?? ''));
            xappsBackendKitPhpAssertTrue($token !== '', 'missing host-session token');
            [$encodedPayload] = explode('.', $token, 2);
            $paddedPayload = strtr($encodedPayload, '-_', '+/');
            $padding = strlen($paddedPayload) % 4;
            if ($padding !== 0) {
                $paddedPayload .= str_repeat('=', 4 - $padding);
            }
            $payload = json_decode(base64_decode($paddedPayload), true);
            $revoked = [trim((string) ($payload['jti'] ?? '')) => true];

            try {
                xapps_backend_kit_read_host_auth_context([
                    'headers' => [
                        'cookie' => 'xapps_host_session=' . $token,
                    ],
                ], [
                    'signingSecret' => 'session_secret_123',
                    'store' => [
                        'isRevoked' => static function (array $input) use ($revoked): bool {
                            $jti = trim((string) ($input['jti'] ?? ''));
                            return $jti !== '' && (($revoked[$jti] ?? false) === true);
                        },
                    ],
                ]);
                throw new RuntimeException('Expected revoked host session rejection');
            } catch (Throwable $error) {
                xappsBackendKitPhpAssertTrue(
                    str_contains($error->getMessage(), 'host session revoked'),
                    'revoked session message mismatch',
                );
            }
        },
    ],
    [
        'name' => 'host session cookies fail closed without a revocation checker',
        'run' => static function (): void {
            $result = xapps_backend_kit_build_host_session_exchange_result([
                'subjectId' => 'sub_session_123',
                'email' => 'alex@example.com',
                'name' => 'Alex Example',
                'signingSecret' => 'session_secret_123',
            ], [
                'protocol' => 'https',
                'headers' => [
                    'host' => 'tenant.example.test',
                    'origin' => 'https://host.example.test',
                ],
            ]);

            preg_match('/xapps_host_session=([^;]+)/', (string) ($result['setCookie'] ?? ''), $matches);
            $token = trim((string) ($matches[1] ?? ''));
            xappsBackendKitPhpAssertTrue($token !== '', 'missing host-session token');

            try {
                xapps_backend_kit_read_host_auth_context([
                    'headers' => [
                        'cookie' => 'xapps_host_session=' . $token,
                    ],
                ], [
                    'signingSecret' => 'session_secret_123',
                ]);
                throw new RuntimeException('Expected missing revocation checker rejection');
            } catch (Throwable $error) {
                xappsBackendKitPhpAssertTrue(
                    str_contains($error->getMessage(), 'Host session revocation is not configured'),
                    'missing revocation checker message mismatch',
                );
            }
        },
    ],
    [
        'name' => 'host session exchange applies explicit cookie policy and absolute ttl',
        'run' => static function (): void {
            $result = xapps_backend_kit_build_host_session_exchange_result([
                'subjectId' => 'sub_session_123',
                'email' => 'alex@example.com',
                'name' => 'Alex Example',
                'signingSecret' => 'secret_123',
                'session' => [
                    'cookieName' => 'xapps_host_session_v2',
                    'absoluteTtlSeconds' => 900,
                    'cookiePath' => '/embed',
                    'cookieDomain' => 'host.example.test',
                    'cookieSameSite' => 'Strict',
                    'cookieSecure' => false,
                ],
            ], [
                'protocol' => 'http',
                'headers' => [
                    'host' => 'tenant.example.test',
                    'origin' => 'https://host.example.test',
                ],
            ]);

            xappsBackendKitPhpAssertSame(900, $result['payload']['expiresIn'] ?? null, 'host session expiresIn mismatch');
            xappsBackendKitPhpAssertTrue(
                str_contains((string) ($result['setCookie'] ?? ''), 'xapps_host_session_v2='),
                'cookie name mismatch',
            );
            xappsBackendKitPhpAssertTrue(
                str_contains((string) ($result['setCookie'] ?? ''), 'Path=/embed'),
                'cookie path mismatch',
            );
            xappsBackendKitPhpAssertTrue(
                str_contains((string) ($result['setCookie'] ?? ''), 'Domain=host.example.test'),
                'cookie domain mismatch',
            );
            xappsBackendKitPhpAssertTrue(
                str_contains((string) ($result['setCookie'] ?? ''), 'SameSite=Strict'),
                'cookie same-site mismatch',
            );
            xappsBackendKitPhpAssertTrue(
                str_contains((string) ($result['setCookie'] ?? ''), 'Max-Age=900'),
                'cookie max-age mismatch',
            );
            xappsBackendKitPhpAssertTrue(
                !str_contains((string) ($result['setCookie'] ?? ''), 'Secure'),
                'cookie secure flag mismatch',
            );
        },
    ],
    [
        'name' => 'host session exchange prefers forwarded public proto and host',
        'run' => static function (): void {
            $result = xapps_backend_kit_build_host_session_exchange_result([
                'subjectId' => 'sub_session_123',
                'email' => 'alex@example.com',
                'name' => 'Alex Example',
                'signingSecret' => 'session_secret_123',
            ], [
                'protocol' => 'http',
                'headers' => [
                    'host' => 'internal-host:8080',
                    'origin' => 'https://tenant.example.test',
                    'x-forwarded-proto' => 'https',
                    'x-forwarded-host' => 'tenant.example.test',
                ],
            ]);

            xappsBackendKitPhpAssertTrue(
                str_contains((string) ($result['setCookie'] ?? ''), 'Secure'),
                'expected secure cookie behind forwarded https',
            );
            xappsBackendKitPhpAssertTrue(
                str_contains((string) ($result['setCookie'] ?? ''), 'SameSite=Lax'),
                'expected same-origin lax cookie behind forwarded public host',
            );

            preg_match('/xapps_host_session=([^;]+)/', (string) ($result['setCookie'] ?? ''), $matches);
            $token = trim((string) ($matches[1] ?? ''));
            xappsBackendKitPhpAssertTrue($token !== '', 'missing host-session token');

            $context = xapps_backend_kit_read_host_auth_context([
                'protocol' => 'http',
                'headers' => [
                    'host' => 'internal-host:8080',
                    'origin' => 'https://tenant.example.test',
                    'x-forwarded-proto' => 'https',
                    'x-forwarded-host' => 'tenant.example.test',
                    'cookie' => 'xapps_host_session=' . $token,
                ],
            ], [
                'signingSecret' => 'session_secret_123',
                'store' => [
                    'isRevoked' => static fn (): bool => false,
                ],
            ]);

            xappsBackendKitPhpAssertSame('sub_session_123', $context['subjectId'] ?? null, 'forwarded host session subject mismatch');
        },
    ],
    [
        'name' => 'host session exchange rejects missing subject id',
        'run' => static function (): void {
            try {
                xapps_backend_kit_build_host_session_exchange_result([
                    'subjectId' => '',
                    'signingSecret' => 'secret_123',
                ], [
                    'protocol' => 'https',
                    'headers' => [
                        'host' => 'tenant.example.test',
                        'origin' => 'https://host.example.test',
                    ],
                ]);
                throw new RuntimeException('Expected missing subject id rejection');
            } catch (Throwable $error) {
                xappsBackendKitPhpAssertTrue(
                    str_contains($error->getMessage(), 'host session subjectId is required'),
                    'missing subject id message mismatch',
                );
            }
        },
    ],
    [
        'name' => 'host session exchange supports stateful idle timeout and rejects expired idle state',
        'run' => static function (): void {
            $state = [];
            $result = xapps_backend_kit_build_host_session_exchange_result([
                'subjectId' => 'sub_session_123',
                'email' => 'alex@example.com',
                'name' => 'Alex Example',
                'signingSecret' => 'session_secret_123',
                'session' => [
                    'idleTtlSeconds' => 60,
                ],
            ], [
                'protocol' => 'https',
                'headers' => [
                    'host' => 'tenant.example.test',
                    'origin' => 'https://host.example.test',
                ],
            ]);

            preg_match('/xapps_host_session=([^;]+)/', (string) ($result['setCookie'] ?? ''), $matches);
            $token = trim((string) ($matches[1] ?? ''));
            xappsBackendKitPhpAssertTrue($token !== '', 'missing host-session token');

            xapps_backend_kit_activate_host_session(
                (array) ($result['sessionContext'] ?? []),
                [
                    'idleTtlSeconds' => 60,
                    'store' => [
                        'activate' => static function (array $input) use (&$state): bool {
                            $jti = trim((string) ($input['jti'] ?? ''));
                            if ($jti === '') {
                                return false;
                            }
                            $exp = (int) ($input['exp'] ?? 0);
                            $idleTtlSeconds = (int) ($input['idleTtlSeconds'] ?? 0);
                            $state[$jti] = [
                                'absoluteExpiresAt' => $exp,
                                'idleExpiresAt' => min($exp, 1700000000 + $idleTtlSeconds),
                            ];
                            return true;
                        },
                    ],
                ],
            );

            $context = xapps_backend_kit_read_host_auth_context([
                'headers' => [
                    'cookie' => 'xapps_host_session=' . $token,
                ],
                ], [
                    'signingSecret' => 'session_secret_123',
                    'idleTtlSeconds' => 60,
                    'store' => [
                        'isRevoked' => static fn (): bool => false,
                        'touch' => static function (array $input) use (&$state): bool {
                            $jti = trim((string) ($input['jti'] ?? ''));
                            if ($jti === '' || !isset($state[$jti]) || !is_array($state[$jti])) {
                                return false;
                            }
                            $exp = (int) ($input['exp'] ?? 0);
                            $idleTtlSeconds = (int) ($input['idleTtlSeconds'] ?? 0);
                            $state[$jti]['idleExpiresAt'] = min($exp, 1700000010 + $idleTtlSeconds);
                            return true;
                        },
                    ],
            ]);
            xappsBackendKitPhpAssertSame('sub_session_123', $context['subjectId'] ?? null, 'idle timeout context mismatch');

            try {
                xapps_backend_kit_read_host_auth_context([
                    'headers' => [
                        'cookie' => 'xapps_host_session=' . $token,
                    ],
                ], [
                    'signingSecret' => 'session_secret_123',
                    'idleTtlSeconds' => 60,
                    'store' => [
                        'isRevoked' => static fn (): bool => false,
                        'touch' => static fn (): bool => false,
                    ],
                ]);
                throw new RuntimeException('Expected idle timeout rejection');
            } catch (Throwable $error) {
                xappsBackendKitPhpAssertTrue(
                    str_contains($error->getMessage(), 'host session idle expired'),
                    'idle timeout message mismatch',
                );
            }
        },
    ],
    [
        'name' => 'cross-origin host auth requires host session and does not accept bootstrap proof',
        'run' => static function (): void {
            $bootstrap = xapps_backend_kit_build_host_bootstrap_result([
                'subjectId' => 'sub_123',
                'email' => 'alex@example.com',
                'name' => 'Alex Example',
                'origin' => 'https://host.example.test',
                'signingSecret' => 'secret_123',
                'ttlSeconds' => 300,
            ]);

            try {
                xapps_backend_kit_read_host_auth_context([
                    'protocol' => 'https',
                    'headers' => [
                        'host' => 'tenant.example.test',
                        'origin' => 'https://host.example.test',
                        'x-xapps-host-bootstrap' => $bootstrap['bootstrapToken'],
                    ],
                ], []);
                throw new RuntimeException('Expected host-session requirement rejection');
            } catch (Throwable $error) {
                xappsBackendKitPhpAssertTrue(
                    str_contains($error->getMessage(), 'host session is required'),
                    'host session requirement message mismatch',
                );
            }
        },
    ],
    [
        'name' => 'host session exchange requires replay protection and rejects reused bootstrap jtis',
        'run' => static function (): void {
            $result = xapps_backend_kit_build_host_bootstrap_result([
                'subjectId' => 'sub_123',
                'origin' => 'https://host.example.test',
                'signingSecret' => 'secret_123',
                'ttlSeconds' => 300,
            ]);

            $context = xapps_backend_kit_read_host_bootstrap_context([
                'headers' => [
                    'origin' => 'https://host.example.test',
                    'x-xapps-host-bootstrap' => $result['bootstrapToken'],
                ],
            ], [
                'signingSecret' => 'secret_123',
            ]);

            try {
                xapps_backend_kit_consume_host_bootstrap_replay($context, [
                    'signingSecret' => 'secret_123',
                ]);
                throw new RuntimeException('Expected missing replay protection rejection');
            } catch (Throwable $error) {
                xappsBackendKitPhpAssertTrue(
                    str_contains($error->getMessage(), 'Host session exchange replay protection is not configured'),
                    'missing replay protection message mismatch',
                );
            }

            $seen = [];
            $bootstrap = [
                'signingSecret' => 'secret_123',
                'consumeJti' => static function (array $input) use (&$seen): bool {
                    $jti = trim((string) ($input['jti'] ?? ''));
                    if ($jti === '' || in_array($jti, $seen, true)) {
                        return false;
                    }
                    $seen[] = $jti;
                    return true;
                },
            ];

            xapps_backend_kit_consume_host_bootstrap_replay($context, $bootstrap);

            try {
                xapps_backend_kit_consume_host_bootstrap_replay($context, $bootstrap);
                throw new RuntimeException('Expected replay rejection');
            } catch (Throwable $error) {
                xappsBackendKitPhpAssertTrue(
                    str_contains($error->getMessage(), 'host bootstrap token replay detected'),
                    'replay rejection message mismatch',
                );
            }
        },
    ],
    [
        'name' => 'host session exchange applies rate limiting before replay consumption',
        'run' => static function (): void {
            $routes = [];
            xapps_backend_kit_register_host_api_core($routes, [
                'hostProxyService' => new class {
                    public function getHostConfig(): array
                    {
                        return [];
                    }
                },
            ], ['https://host.example.test'], [
                'signingSecret' => 'bootstrap_secret_123',
                'consumeJti' => static fn (): bool => true,
            ], [
                'signingSecret' => 'session_secret_123',
                'store' => [
                    'isRevoked' => static fn (): bool => false,
                    'revoke' => static fn (): bool => true,
                ],
                'rateLimitExchange' => static fn (): bool => false,
            ]);

            $route = xappsBackendKitPhpFindRoute($routes, 'POST', '/api/host-session/exchange');
            $bootstrap = xapps_backend_kit_build_host_bootstrap_result([
                'subjectId' => 'sub_123',
                'origin' => 'https://host.example.test',
                'signingSecret' => 'bootstrap_secret_123',
                'ttlSeconds' => 300,
            ]);

            $response = xappsBackendKitPhpInvokeRoute($route['handler'], [
                'protocol' => 'https',
                'headers' => [
                    'host' => 'tenant.example.test',
                    'origin' => 'https://host.example.test',
                    'x-xapps-host-bootstrap' => $bootstrap['bootstrapToken'],
                ],
            ]);

            xappsBackendKitPhpAssertSame(500, $response['status'], 'rate limit exchange status mismatch');
            xappsBackendKitPhpAssertTrue(
                str_contains((string) (($response['body']['message'] ?? '') ?: ''), 'host session exchange failed'),
                'rate limit exchange message mismatch',
            );
        },
    ],
    [
        'name' => 'host session exchange audits success and failure',
        'run' => static function (): void {
            $events = [];
            $routes = [];
            xapps_backend_kit_register_host_api_core($routes, [
                'hostProxyService' => new class {
                    public function getHostConfig(): array
                    {
                        return [];
                    }
                },
            ], ['https://host.example.test'], [
                'signingSecret' => 'bootstrap_secret_123',
                'consumeJti' => static fn (): bool => true,
            ], [
                'signingSecret' => 'session_secret_123',
                'store' => [
                    'isRevoked' => static fn (): bool => false,
                    'revoke' => static fn (): bool => true,
                ],
                'auditExchange' => static function (array $input) use (&$events): void {
                    $events[] = $input;
                },
            ]);

            $route = xappsBackendKitPhpFindRoute($routes, 'POST', '/api/host-session/exchange');
            $bootstrap = xapps_backend_kit_build_host_bootstrap_result([
                'subjectId' => 'sub_success',
                'origin' => 'https://host.example.test',
                'signingSecret' => 'bootstrap_secret_123',
                'ttlSeconds' => 300,
            ]);
            $success = xappsBackendKitPhpInvokeRoute($route['handler'], [
                'protocol' => 'https',
                'headers' => [
                    'host' => 'tenant.example.test',
                    'origin' => 'https://host.example.test',
                    'x-xapps-host-bootstrap' => $bootstrap['bootstrapToken'],
                ],
            ]);
            xappsBackendKitPhpAssertSame(200, $success['status'], 'successful exchange status mismatch');
            xappsBackendKitPhpAssertSame(true, (bool) ($events[count($events) - 1]['ok'] ?? false), 'successful exchange audit mismatch');
            xappsBackendKitPhpAssertSame('sub_success', $events[count($events) - 1]['subjectId'] ?? null, 'successful exchange audited subject mismatch');
            xappsBackendKitPhpAssertTrue(
                trim((string) ($events[count($events) - 1]['sessionJti'] ?? '')) !== '',
                'successful exchange missing session jti audit',
            );

            $failureEvents = [];
            $failureRoutes = [];
            xapps_backend_kit_register_host_api_core($failureRoutes, [
                'hostProxyService' => new class {
                    public function getHostConfig(): array
                    {
                        return [];
                    }
                },
            ], ['https://host.example.test'], [
                'signingSecret' => 'bootstrap_secret_123',
                'consumeJti' => static fn (): bool => true,
            ], [
                'signingSecret' => 'session_secret_123',
                'store' => [
                    'isRevoked' => static fn (): bool => false,
                    'revoke' => static fn (): bool => true,
                ],
                'rateLimitExchange' => static fn (): bool => false,
                'auditExchange' => static function (array $input) use (&$failureEvents): void {
                    $failureEvents[] = $input;
                },
            ]);

            $failureRoute = xappsBackendKitPhpFindRoute($failureRoutes, 'POST', '/api/host-session/exchange');
            $failedBootstrap = xapps_backend_kit_build_host_bootstrap_result([
                'subjectId' => 'sub_failure',
                'origin' => 'https://host.example.test',
                'signingSecret' => 'bootstrap_secret_123',
                'ttlSeconds' => 300,
            ]);
            $failed = xappsBackendKitPhpInvokeRoute($failureRoute['handler'], [
                'protocol' => 'https',
                'headers' => [
                    'host' => 'tenant.example.test',
                    'origin' => 'https://host.example.test',
                    'x-xapps-host-bootstrap' => $failedBootstrap['bootstrapToken'],
                ],
            ]);
            xappsBackendKitPhpAssertSame(500, $failed['status'], 'failed exchange status mismatch');
            xappsBackendKitPhpAssertSame(false, (bool) ($failureEvents[count($failureEvents) - 1]['ok'] ?? true), 'failed exchange audit mismatch');
            xappsBackendKitPhpAssertSame('sub_failure', $failureEvents[count($failureEvents) - 1]['subjectId'] ?? null, 'failed exchange audited subject mismatch');
            xappsBackendKitPhpAssertTrue(
                str_contains((string) ($failureEvents[count($failureEvents) - 1]['reason'] ?? ''), 'Host session exchange rate limit exceeded'),
                'failed exchange reason audit mismatch',
            );
        },
    ],
    [
        'name' => 'host bootstrap applies rate limiting and audits bootstrap attempts',
        'run' => static function (): void {
            $events = [];
            $routes = [];
            xapps_backend_kit_register_host_api_core($routes, [
                'hostProxyService' => new class {
                    public function getHostConfig(): array
                    {
                        return [];
                    }

                    public function resolveSubject(array $input): array
                    {
                        return [
                            'subjectId' => 'sub_123',
                            'email' => trim((string) ($input['email'] ?? '')) ?: null,
                        ];
                    }
                },
            ], ['https://host.example.test'], [
                'apiKeys' => ['bootstrap_key_123'],
                'signingSecret' => 'bootstrap_secret_123',
                'consumeJti' => static fn (): bool => true,
                'rateLimitBootstrap' => static fn (): bool => true,
                'auditBootstrap' => static function (array $input) use (&$events): void {
                    $events[] = $input;
                },
            ], [
                'signingSecret' => 'session_secret_123',
                'store' => [
                    'isRevoked' => static fn (): bool => false,
                    'revoke' => static fn (): bool => true,
                ],
            ]);

            $route = xappsBackendKitPhpFindRoute($routes, 'POST', '/api/host-bootstrap');
            $success = xappsBackendKitPhpInvokeRoute($route['handler'], [
                'headers' => [
                    'host' => 'tenant.example.test',
                    'x-api-key' => 'bootstrap_key_123',
                ],
                'body' => [
                    'origin' => 'https://host.example.test',
                    'email' => 'u@example.test',
                ],
            ]);
            xappsBackendKitPhpAssertSame(200, $success['status'], 'bootstrap success status mismatch');
            xappsBackendKitPhpAssertSame(true, (bool) ($events[count($events) - 1]['ok'] ?? false), 'bootstrap success audit mismatch');

            $failureEvents = [];
            $failureRoutes = [];
            xapps_backend_kit_register_host_api_core($failureRoutes, [
                'hostProxyService' => new class {
                    public function getHostConfig(): array
                    {
                        return [];
                    }

                    public function resolveSubject(array $input): array
                    {
                        return [
                            'subjectId' => 'sub_123',
                            'email' => trim((string) ($input['email'] ?? '')) ?: null,
                        ];
                    }
                },
            ], ['https://host.example.test'], [
                'apiKeys' => ['bootstrap_key_123'],
                'signingSecret' => 'bootstrap_secret_123',
                'consumeJti' => static fn (): bool => true,
                'rateLimitBootstrap' => static fn (): bool => false,
                'auditBootstrap' => static function (array $input) use (&$failureEvents): void {
                    $failureEvents[] = $input;
                },
            ], [
                'signingSecret' => 'session_secret_123',
                'store' => [
                    'isRevoked' => static fn (): bool => false,
                    'revoke' => static fn (): bool => true,
                ],
            ]);

            $failureRoute = xappsBackendKitPhpFindRoute($failureRoutes, 'POST', '/api/host-bootstrap');
            $failed = xappsBackendKitPhpInvokeRoute($failureRoute['handler'], [
                'headers' => [
                    'host' => 'tenant.example.test',
                    'x-api-key' => 'bootstrap_key_123',
                ],
                'body' => [
                    'origin' => 'https://host.example.test',
                    'email' => 'u@example.test',
                ],
            ]);
            xappsBackendKitPhpAssertSame(500, $failed['status'], 'bootstrap rate limit status mismatch');
            xappsBackendKitPhpAssertTrue(
                str_contains((string) ($failureEvents[count($failureEvents) - 1]['reason'] ?? ''), 'Host bootstrap rate limit exceeded'),
                'bootstrap rate limit reason mismatch',
            );
        },
    ],
    [
        'name' => 'host session logout applies rate limiting and audits logout/revocation phases',
        'run' => static function (): void {
            $logoutEvents = [];
            $revocationEvents = [];
            $routes = [];
            xapps_backend_kit_register_host_api_core($routes, [
                'hostProxyService' => new class {
                    public function getHostConfig(): array
                    {
                        return [];
                    }

                    public function reportHostSessionRevocation(array $input): array
                    {
                        throw new RuntimeException('gateway report failed');
                    }
                },
            ], ['https://host.example.test'], [
                'signingSecret' => 'bootstrap_secret_123',
                'consumeJti' => static fn (): bool => true,
            ], [
                'signingSecret' => 'session_secret_123',
                'rateLimitLogout' => static fn (): bool => true,
                'auditLogout' => static function (array $input) use (&$logoutEvents): void {
                    $logoutEvents[] = $input;
                },
                'auditRevocation' => static function (array $input) use (&$revocationEvents): void {
                    $revocationEvents[] = $input;
                },
                'store' => [
                    'isRevoked' => static fn (): bool => false,
                    'revoke' => static fn (): bool => true,
                ],
            ]);

            $route = xappsBackendKitPhpFindRoute($routes, 'POST', '/api/host-session/logout');
            $exchange = xapps_backend_kit_build_host_session_exchange_result([
                'subjectId' => 'sub_logout_123',
                'signingSecret' => 'session_secret_123',
            ], [
                'protocol' => 'https',
                'headers' => [
                    'host' => 'tenant.example.test',
                    'origin' => 'https://host.example.test',
                ],
            ]);
            preg_match('/xapps_host_session=([^;]+)/', (string) ($exchange['setCookie'] ?? ''), $cookieMatch);
            $token = isset($cookieMatch[1]) ? trim((string) $cookieMatch[1]) : '';
            xappsBackendKitPhpAssertTrue($token !== '', 'missing host-session token');

            $response = xappsBackendKitPhpInvokeRoute($route['handler'], [
                'headers' => [
                    'host' => 'tenant.example.test',
                    'origin' => 'https://host.example.test',
                    'cookie' => 'xapps_host_session=' . $token,
                ],
            ]);
            xappsBackendKitPhpAssertSame(200, $response['status'], 'logout success status mismatch');
            xappsBackendKitPhpAssertSame(true, (bool) ($logoutEvents[count($logoutEvents) - 1]['ok'] ?? false), 'logout success audit mismatch');
            $hasLocalRevoke = false;
            $hasGatewayReportFailure = false;
            foreach ($revocationEvents as $entry) {
                if (($entry['phase'] ?? null) === 'local_revoke' && ($entry['ok'] ?? null) === true) {
                    $hasLocalRevoke = true;
                }
                if (($entry['phase'] ?? null) === 'gateway_report' && ($entry['ok'] ?? null) === false) {
                    $hasGatewayReportFailure = true;
                }
            }
            xappsBackendKitPhpAssertSame(true, $hasLocalRevoke, 'missing local revoke audit');
            xappsBackendKitPhpAssertSame(true, $hasGatewayReportFailure, 'missing gateway report failure audit');

            $rateLimitedEvents = [];
            $rateLimitedRoutes = [];
            xapps_backend_kit_register_host_api_core($rateLimitedRoutes, [
                'hostProxyService' => new class {
                    public function getHostConfig(): array
                    {
                        return [];
                    }
                },
            ], ['https://host.example.test'], [
                'signingSecret' => 'bootstrap_secret_123',
                'consumeJti' => static fn (): bool => true,
            ], [
                'signingSecret' => 'session_secret_123',
                'rateLimitLogout' => static fn (): bool => false,
                'auditLogout' => static function (array $input) use (&$rateLimitedEvents): void {
                    $rateLimitedEvents[] = $input;
                },
                'store' => [
                    'isRevoked' => static fn (): bool => false,
                    'revoke' => static fn (): bool => true,
                ],
            ]);

            $rateLimitedRoute = xappsBackendKitPhpFindRoute($rateLimitedRoutes, 'POST', '/api/host-session/logout');
            $rateLimited = xappsBackendKitPhpInvokeRoute($rateLimitedRoute['handler'], [
                'headers' => [
                    'host' => 'tenant.example.test',
                    'origin' => 'https://host.example.test',
                    'cookie' => 'xapps_host_session=' . $token,
                ],
            ]);
            xappsBackendKitPhpAssertSame(500, $rateLimited['status'], 'logout rate limit status mismatch');
            xappsBackendKitPhpAssertTrue(
                str_contains((string) ($rateLimitedEvents[count($rateLimitedEvents) - 1]['reason'] ?? ''), 'Host session logout rate limit exceeded'),
                'logout rate limit reason mismatch',
            );
        },
    ],
    [
        'name' => 'deprecated host bootstrap header warning hook fires on non-exchange host routes',
        'run' => static function (): void {
            $warnings = [];
            $routes = [];
            xapps_backend_kit_register_host_api_core($routes, [
                'hostProxyService' => new class {
                    public function getHostConfig(): array
                    {
                        return [];
                    }

                    public function createCatalogSession(array $input): array
                    {
                        return ['token' => 'catalog_token', 'embedUrl' => '/embed/catalog?token=catalog_token'];
                    }
                },
            ], ['https://host.example.test'], [
                'signingSecret' => 'bootstrap_secret_123',
                'consumeJti' => static fn (): bool => true,
                'deprecatedWarn' => static function (array $input) use (&$warnings): void {
                    $warnings[] = $input;
                },
            ], [
                'signingSecret' => 'session_secret_123',
                'store' => [
                    'isRevoked' => static fn (): bool => false,
                    'revoke' => static fn (): bool => true,
                ],
            ]);

            $route = xappsBackendKitPhpFindRoute($routes, 'POST', '/api/create-catalog-session');
            $exchange = xapps_backend_kit_build_host_session_exchange_result([
                'subjectId' => 'sub_warn_123',
                'signingSecret' => 'session_secret_123',
            ], [
                'protocol' => 'https',
                'headers' => [
                    'host' => 'tenant.example.test',
                    'origin' => 'https://host.example.test',
                ],
            ]);
            preg_match('/xapps_host_session=([^;]+)/', (string) ($exchange['setCookie'] ?? ''), $cookieMatch);
            $token = isset($cookieMatch[1]) ? trim((string) $cookieMatch[1]) : '';
            xappsBackendKitPhpAssertTrue($token !== '', 'missing host-session token');

            $response = xappsBackendKitPhpInvokeRoute($route['handler'], [
                'headers' => [
                    'host' => 'tenant.example.test',
                    'origin' => 'https://host.example.test',
                    'cookie' => 'xapps_host_session=' . $token,
                    'x-xapps-host-bootstrap' => 'deprecated-token-value',
                ],
                'body' => [
                    'origin' => 'https://host.example.test',
                    'xappId' => 'xapp_123',
                ],
            ]);
            xappsBackendKitPhpAssertSame(201, $response['status'], 'deprecated warning catalog status mismatch');
            xappsBackendKitPhpAssertSame(1, count($warnings), 'deprecated warning hook count mismatch');
            xappsBackendKitPhpAssertSame('/api/create-catalog-session', $warnings[0]['route'] ?? null, 'deprecated warning route mismatch');
        },
    ],
    [
        'name' => 'empty host allowlist is same-origin only',
        'run' => static function (): void {
            xappsBackendKitPhpAssertSame(
                'https://host.example.test',
                xapps_backend_kit_require_requested_host_bootstrap_origin(
                    'HTTPS://HOST.EXAMPLE.TEST/',
                    ['https://host.example.test']
                ),
                'normalized bootstrap origin mismatch',
            );
            xappsBackendKitPhpAssertSame(
                false,
                xapps_backend_kit_is_allowed_origin('https://host.example.test', []),
                'empty allowlist should not allow explicit origins',
            );

            $routes = [];
            xapps_backend_kit_register_host_api_core($routes, [
                'hostProxyService' => new class {
                    public function getHostConfig(): array
                    {
                        return [];
                    }

                    public function resolveSubject(array $input): array
                    {
                        return [
                            'subjectId' => 'sub_same_origin',
                            'email' => trim((string) ($input['email'] ?? '')) ?: null,
                            'name' => null,
                        ];
                    }
                },
            ], [], [
                'apiKeys' => ['bootstrap_key_123'],
                'signingSecret' => 'bootstrap_secret_123',
                'consumeJti' => static fn (): bool => true,
            ], [
                'signingSecret' => 'session_secret_123',
                'store' => [
                    'isRevoked' => static fn (): bool => false,
                    'revoke' => static fn (): bool => true,
                ],
            ]);

            $bootstrapRoute = xappsBackendKitPhpFindRoute($routes, 'POST', '/api/host-bootstrap');
            $sameOriginBootstrap = xappsBackendKitPhpInvokeRoute($bootstrapRoute['handler'], [
                'protocol' => 'http',
                'headers' => [
                    'host' => 'tenant.example.test',
                    'x-api-key' => 'bootstrap_key_123',
                ],
                'body' => [
                    'origin' => 'http://tenant.example.test',
                    'email' => 'billing@example.test',
                ],
            ]);
            xappsBackendKitPhpAssertSame(200, $sameOriginBootstrap['status'], 'same-origin bootstrap status mismatch');

            $crossOriginBootstrap = xappsBackendKitPhpInvokeRoute($bootstrapRoute['handler'], [
                'protocol' => 'http',
                'headers' => [
                    'host' => 'tenant.example.test',
                    'x-api-key' => 'bootstrap_key_123',
                ],
                'body' => [
                    'origin' => 'https://host.example.test',
                    'email' => 'billing@example.test',
                ],
            ]);
            xappsBackendKitPhpAssertSame(403, $crossOriginBootstrap['status'], 'cross-origin bootstrap status mismatch');

            $exchangeRoute = xappsBackendKitPhpFindRoute($routes, 'POST', '/api/host-session/exchange');
            $sameOriginExchangeBootstrap = xapps_backend_kit_build_host_bootstrap_result([
                'subjectId' => 'sub_same_origin',
                'origin' => 'http://tenant.example.test',
                'signingSecret' => 'bootstrap_secret_123',
                'ttlSeconds' => 300,
            ]);
            $sameOriginExchange = xappsBackendKitPhpInvokeRoute($exchangeRoute['handler'], [
                'protocol' => 'http',
                'headers' => [
                    'host' => 'tenant.example.test',
                    'origin' => 'http://tenant.example.test',
                    'x-xapps-host-bootstrap' => $sameOriginExchangeBootstrap['bootstrapToken'],
                ],
            ]);
            xappsBackendKitPhpAssertSame(200, $sameOriginExchange['status'], 'same-origin exchange status mismatch');

            $crossOriginExchangeBootstrap = xapps_backend_kit_build_host_bootstrap_result([
                'subjectId' => 'sub_cross_origin',
                'origin' => 'https://host.example.test',
                'signingSecret' => 'bootstrap_secret_123',
                'ttlSeconds' => 300,
            ]);
            $crossOriginExchange = xappsBackendKitPhpInvokeRoute($exchangeRoute['handler'], [
                'protocol' => 'http',
                'headers' => [
                    'host' => 'tenant.example.test',
                    'origin' => 'https://host.example.test',
                    'x-xapps-host-bootstrap' => $crossOriginExchangeBootstrap['bootstrapToken'],
                ],
            ]);
            xappsBackendKitPhpAssertSame(403, $crossOriginExchange['status'], 'cross-origin exchange status mismatch');
        },
    ],
    [
        'name' => 'bridge sign pins subject ids from the host session',
        'run' => static function (): void {
            $holder = new stdClass();
            $holder->input = null;
            $routes = [];
            xapps_backend_kit_register_host_api_bridge($routes, [
                'hostProxyService' => new class($holder) {
                    public function __construct(private stdClass $holder)
                    {
                    }

                    public function bridgeSign(array $input): array
                    {
                        $this->holder->input = $input;
                        return ['ok' => true, 'envelope' => ['signed' => true]];
                    }
                },
            ], ['https://host.example.test'], [], [
                'signingSecret' => 'session_secret_123',
                'store' => [
                    'isRevoked' => static fn (): bool => false,
                    'revoke' => static fn (): bool => true,
                ],
            ]);

            $route = xappsBackendKitPhpFindRoute($routes, 'POST', '/api/bridge/sign');
            $exchange = xapps_backend_kit_build_host_session_exchange_result([
                'subjectId' => 'sub_session_123',
                'signingSecret' => 'session_secret_123',
            ], [
                'protocol' => 'https',
                'headers' => [
                    'host' => 'tenant.example.test',
                    'origin' => 'https://host.example.test',
                ],
            ]);
            preg_match('/xapps_host_session=([^;]+)/', (string) ($exchange['setCookie'] ?? ''), $matches);
            $response = xappsBackendKitPhpInvokeRoute($route['handler'], [
                'protocol' => 'https',
                'headers' => [
                    'host' => 'tenant.example.test',
                    'origin' => 'https://host.example.test',
                    'cookie' => 'xapps_host_session=' . (string) ($matches[1] ?? ''),
                ],
                'body' => [
                    'requestId' => 'req_123',
                    'subjectId' => 'spoofed_subject',
                    'subject_id' => 'spoofed_subject',
                ],
            ]);

            xappsBackendKitPhpAssertSame(200, $response['status'], 'bridge sign status mismatch');
            xappsBackendKitPhpAssertTrue(is_array($holder->input), 'bridge sign input capture missing');
            xappsBackendKitPhpAssertSame('req_123', $holder->input['data']['requestId'] ?? null, 'bridge sign request id mismatch');
            xappsBackendKitPhpAssertSame('sub_session_123', $holder->input['data']['subjectId'] ?? null, 'bridge sign subjectId mismatch');
            xappsBackendKitPhpAssertSame('sub_session_123', $holder->input['data']['subject_id'] ?? null, 'bridge sign subject_id mismatch');
        },
    ],
    [
        'name' => 'bridge sign drops caller envelope when host session subject is present',
        'run' => static function (): void {
            $holder = new stdClass();
            $holder->input = null;
            $routes = [];
            xapps_backend_kit_register_host_api_bridge($routes, [
                'hostProxyService' => new class($holder) {
                    public function __construct(private stdClass $holder)
                    {
                    }

                    public function bridgeSign(array $input): array
                    {
                        $this->holder->input = $input;
                        return ['ok' => true, 'envelope' => ['signed' => true]];
                    }
                },
            ], ['https://host.example.test'], [], [
                'signingSecret' => 'session_secret_123',
                'store' => [
                    'isRevoked' => static fn (): bool => false,
                    'revoke' => static fn (): bool => true,
                ],
            ]);

            $route = xappsBackendKitPhpFindRoute($routes, 'POST', '/api/bridge/sign');
            $exchange = xapps_backend_kit_build_host_session_exchange_result([
                'subjectId' => 'sub_session_123',
                'signingSecret' => 'session_secret_123',
            ], [
                'protocol' => 'https',
                'headers' => [
                    'host' => 'tenant.example.test',
                    'origin' => 'https://host.example.test',
                ],
            ]);
            preg_match('/xapps_host_session=([^;]+)/', (string) ($exchange['setCookie'] ?? ''), $matches);
            $response = xappsBackendKitPhpInvokeRoute($route['handler'], [
                'protocol' => 'https',
                'headers' => [
                    'host' => 'tenant.example.test',
                    'origin' => 'https://host.example.test',
                    'cookie' => 'xapps_host_session=' . (string) ($matches[1] ?? ''),
                ],
                'body' => [
                    'requestId' => 'req_456',
                    'envelope' => [
                        'subjectId' => 'spoofed_subject',
                        'precomputed' => true,
                    ],
                ],
            ]);

            xappsBackendKitPhpAssertSame(200, $response['status'], 'bridge sign envelope-drop status mismatch');
            xappsBackendKitPhpAssertTrue(is_array($holder->input), 'bridge sign envelope-drop capture missing');
            xappsBackendKitPhpAssertTrue(
                array_key_exists('envelope', $holder->input),
                'bridge sign envelope key missing',
            );
            xappsBackendKitPhpAssertSame(null, $holder->input['envelope'], 'bridge sign envelope should be null');
            xappsBackendKitPhpAssertSame('sub_session_123', $holder->input['data']['subjectId'] ?? null, 'bridge sign envelope-drop subjectId mismatch');
        },
    ],
];
