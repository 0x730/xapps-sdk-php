<?php

declare(strict_types=1);

namespace Xapps\BackendKit;

final class BackendOptions
{
    private static function readFirstNonEmptyString(mixed ...$values): string
    {
        foreach ($values as $value) {
            $normalized = trim(BackendSupport::readString($value));
            if ($normalized !== '') {
                return $normalized;
            }
        }
        return '';
    }

    private static function resolveSessionAbsoluteTtlSeconds(array $sessionInput): int
    {
        $rawTtl = $sessionInput['absoluteTtlSeconds'] ?? null;
        return (float) $rawTtl > 0 ? (int) $rawTtl : 1800;
    }

    private static function pickCallable(mixed ...$values): mixed
    {
        foreach ($values as $value) {
            if (is_callable($value)) {
                return $value;
            }
        }

        return null;
    }

    public static function applyGatewayOverrides(array $config, array $gateway = []): array
    {
        $baseUrl = BackendSupport::readString($gateway['baseUrl'] ?? null);
        $apiKey = BackendSupport::readString($gateway['apiKey'] ?? null);
        if ($baseUrl !== '') {
            $config['gatewayUrl'] = $baseUrl;
        }
        if ($apiKey !== '') {
            $config['gatewayApiKey'] = $apiKey;
        }
        return $config;
    }

    public static function applyPaymentOverrides(array $config, array $payments = []): array
    {
        $paymentUrl = BackendSupport::readString($payments['paymentUrl'] ?? null);
        $returnSecret = BackendSupport::readString($payments['returnSecret'] ?? null);
        $returnSecretRef = BackendSupport::readString($payments['returnSecretRef'] ?? null);
        $returnUrlAllowlist = BackendSupport::readString($payments['returnUrlAllowlist'] ?? null);
        $ownerIssuer = self::normalizeOwnerIssuer($payments['ownerIssuer'] ?? null, '');

        if ($paymentUrl !== '') {
            $config['tenantPaymentUrl'] = rtrim($paymentUrl, '/');
        }
        if ($returnSecret !== '') {
            $config['tenantPaymentReturnSecret'] = $returnSecret;
        }
        if ($returnSecretRef !== '') {
            $config['tenantPaymentReturnSecretRef'] = $returnSecretRef;
        }
        if ($returnUrlAllowlist !== '') {
            $config['tenantPaymentReturnUrlAllowlist'] = $returnUrlAllowlist;
        }
        if ($ownerIssuer !== '') {
            $config['paymentOwnerIssuer'] = $ownerIssuer;
        }

        return $config;
    }

    public static function normalizeOwnerIssuer(mixed $value, string $fallback = 'tenant'): string
    {
        $normalized = strtolower(trim(BackendSupport::readString($value, $fallback)));
        return $normalized === 'publisher' ? 'publisher' : 'tenant';
    }

    public static function normalizeAllowedOrigins(mixed $value): array
    {
        if (is_array($value)) {
            $entries = array_map(
                static fn (mixed $entry): string => rtrim(BackendSupport::readString($entry), '/'),
                $value,
            );
            return array_values(array_filter($entries, static fn (string $entry): bool => $entry !== ''));
        }
        $raw = trim(BackendSupport::readString($value));
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[,\n]/', $raw) ?: [];
        $entries = array_map(
            static fn (mixed $entry): string => rtrim(trim((string) $entry), '/'),
            $parts,
        );
        return array_values(array_filter($entries, static fn (string $entry): bool => $entry !== ''));
    }

    public static function normalizeApiKeys(mixed $value): array
    {
        if (is_array($value)) {
            $entries = array_map(
                static fn (mixed $entry): string => trim(BackendSupport::readString($entry)),
                $value,
            );
            return array_values(array_filter($entries, static fn (string $entry): bool => $entry !== ''));
        }
        $raw = trim(BackendSupport::readString($value));
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[,\n]/', $raw) ?: [];
        $entries = array_map(
            static fn (mixed $entry): string => trim((string) $entry),
            $parts,
        );
        return array_values(array_filter($entries, static fn (string $entry): bool => $entry !== ''));
    }

    public static function normalizeSigningVerifierKeys(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $normalized = [];
        foreach ($value as $key => $raw) {
            $normalizedKey = trim((string) $key);
            $normalizedValue = trim(BackendSupport::readString($raw));
            if ($normalizedKey === '' || $normalizedValue === '') {
                continue;
            }
            $normalized[$normalizedKey] = $normalizedValue;
        }
        return $normalized;
    }

    public static function normalizeCookieSameSite(
        mixed $value,
        string $fallback = 'auto',
    ): string {
        $normalized = strtolower(trim(BackendSupport::readString($value, $fallback)));
        return match ($normalized) {
            'lax' => 'Lax',
            'strict' => 'Strict',
            'none' => 'None',
            default => 'auto',
        };
    }

    public static function normalizeCookieSecure(
        mixed $value,
        string|bool $fallback = 'auto',
    ): string|bool {
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower(trim(BackendSupport::readString($value, (string) $fallback)));
        return match ($normalized) {
            'true' => true,
            'false' => false,
            default => 'auto',
        };
    }

    private static function validateHostedSecurityOptions(array $host): void
    {
        $bootstrap = BackendSupport::readRecord($host['bootstrap'] ?? null);
        $session = BackendSupport::readRecord($host['session'] ?? null);
        $apiKeys = self::normalizeApiKeys($bootstrap['apiKeys'] ?? null);
        $signingSecret = trim(BackendSupport::readString($bootstrap['signingSecret'] ?? null));
        if ($apiKeys !== [] && $signingSecret === '') {
            throw new \InvalidArgumentException(
                'host.bootstrap.signingSecret is required when host.bootstrap.apiKeys is configured'
            );
        }
        $sessionSigningSecret = trim(BackendSupport::readString($session['signingSecret'] ?? null));
        if ($apiKeys !== [] && $sessionSigningSecret === '') {
            throw new \InvalidArgumentException(
                'host.session.signingSecret is required when host.bootstrap.apiKeys is configured'
            );
        }
        $sessionStore = BackendSupport::readRecord($session['store'] ?? null);
        if ($apiKeys !== [] && !is_callable($sessionStore['isRevoked'] ?? null)) {
            throw new \InvalidArgumentException(
                'host.session.store.isRevoked is required when host.bootstrap.apiKeys is configured'
            );
        }
        if ($apiKeys !== [] && !is_callable($sessionStore['revoke'] ?? null)) {
            throw new \InvalidArgumentException(
                'host.session.store.revoke is required when host.bootstrap.apiKeys is configured'
            );
        }
        $idleTtlSeconds = (int) (
            (float) ($session['idleTtlSeconds'] ?? 0) > 0
                ? ($session['idleTtlSeconds'] ?? 0)
                : 0
        );
        if ($apiKeys !== [] && $idleTtlSeconds > 0 && !is_callable($sessionStore['activate'] ?? null)) {
            throw new \InvalidArgumentException(
                'host.session.store.activate is required when host.session.idleTtlSeconds is configured'
            );
        }
        if ($apiKeys !== [] && $idleTtlSeconds > 0 && !is_callable($sessionStore['touch'] ?? null)) {
            throw new \InvalidArgumentException(
                'host.session.store.touch is required when host.session.idleTtlSeconds is configured'
            );
        }
        if ($apiKeys !== [] && !is_callable($bootstrap['consumeJti'] ?? null)) {
            throw new \InvalidArgumentException(
                'host.bootstrap.consumeJti is required when host.bootstrap.apiKeys is configured'
            );
        }
    }

    public static function normalizeOptions(array $options = [], array $deps = []): array
    {
        $normalizeEnabledModes = $deps['normalizeEnabledModes'] ?? null;
        if (!is_callable($normalizeEnabledModes)) {
            throw new \InvalidArgumentException('normalizeEnabledModes is required');
        }

        $host = BackendSupport::readRecord($options['host'] ?? null);
        $bootstrap = BackendSupport::readRecord($host['bootstrap'] ?? null);
        $session = BackendSupport::readRecord($host['session'] ?? null);
        $store = BackendSupport::readRecord($session['store'] ?? null);
        $payments = BackendSupport::readRecord($options['payments'] ?? null);
        $gateway = BackendSupport::readRecord($options['gateway'] ?? null);
        $assets = BackendSupport::readRecord($options['assets'] ?? null);
        $seedLogo = BackendSupport::readRecord($assets['seedLogo'] ?? null);
        $paymentPage = BackendSupport::readRecord($assets['paymentPage'] ?? null);
        $branding = BackendSupport::readRecord($options['branding'] ?? null);
        $reference = BackendSupport::readRecord($options['reference'] ?? null);
        $subjectProfiles = BackendSupport::readRecord($options['subjectProfiles'] ?? null);
        $overrides = BackendSupport::readRecord($options['overrides'] ?? null);

        $subjectResolver = $subjectProfiles['resolveCandidates'] ?? null;
        $catalogCustomerProfileResolver = $subjectProfiles['resolveCatalogCustomerProfile'] ?? null;
        $policyResolver = $overrides['resolvePolicyRequest'] ?? null;
        $hostProxyService = $overrides['hostProxyService'] ?? null;
        $gatewayClient = $overrides['gatewayClient'] ?? null;
        $paymentHandler = $overrides['paymentHandler'] ?? null;
        $sessionAbsoluteTtlSeconds = self::resolveSessionAbsoluteTtlSeconds($session);

        $normalized = [
            'host' => [
                'enableReference' => ($host['enableReference'] ?? true) !== false,
                'enableLifecycle' => ($host['enableLifecycle'] ?? true) !== false,
                'enableBridge' => ($host['enableBridge'] ?? true) !== false,
                'allowedOrigins' => self::normalizeAllowedOrigins($host['allowedOrigins'] ?? null),
                'bootstrap' => [
                    'apiKeys' => self::normalizeApiKeys($bootstrap['apiKeys'] ?? null),
                    'signingSecret' => trim(BackendSupport::readString($bootstrap['signingSecret'] ?? null)),
                    'signingKeyId' => trim(BackendSupport::readString($bootstrap['signingKeyId'] ?? null)),
                    'verifierKeys' => self::normalizeSigningVerifierKeys($bootstrap['verifierKeys'] ?? null),
                    'ttlSeconds' => (int) (
                        (float) (($bootstrap['ttlSeconds'] ?? null) ?? 300) > 0
                            ? (($bootstrap['ttlSeconds'] ?? null) ?? 300)
                            : 300
                    ),
                    'consumeJti' => self::pickCallable(
                        $bootstrap['consumeJti'] ?? null,
                    ),
                    'rateLimitBootstrap' => self::pickCallable(
                        $bootstrap['rateLimitBootstrap'] ?? null,
                    ),
                    'auditBootstrap' => self::pickCallable(
                        $bootstrap['auditBootstrap'] ?? null,
                    ),
                    'deprecatedWarn' => self::pickCallable(
                        $bootstrap['deprecatedWarn'] ?? null,
                    ) ?: (($bootstrap['deprecatedWarn'] ?? false) === true),
                ],
                'session' => [
                    'signingSecret' => trim(BackendSupport::readString($session['signingSecret'] ?? null)),
                    'signingKeyId' => trim(BackendSupport::readString($session['signingKeyId'] ?? null)),
                    'verifierKeys' => self::normalizeSigningVerifierKeys($session['verifierKeys'] ?? null),
                    'cookieName' => trim(BackendSupport::readString($session['cookieName'] ?? null, 'xapps_host_session')) ?: 'xapps_host_session',
                    'absoluteTtlSeconds' => $sessionAbsoluteTtlSeconds,
                    'idleTtlSeconds' => (int) (
                        (float) (($session['idleTtlSeconds'] ?? null) ?? 0) > 0
                            ? (($session['idleTtlSeconds'] ?? null) ?? 0)
                            : 0
                    ),
                    'cookiePath' => trim(BackendSupport::readString($session['cookiePath'] ?? null, '/api')) ?: '/api',
                    'cookieDomain' => trim(BackendSupport::readString($session['cookieDomain'] ?? null)),
                    'cookieSameSite' => self::normalizeCookieSameSite(
                        $session['cookieSameSite'] ?? null,
                        'auto',
                    ),
                    'cookieSecure' => self::normalizeCookieSecure(
                        $session['cookieSecure'] ?? null,
                        'auto',
                    ),
                    'store' => [
                        'activate' => self::pickCallable(
                            $store['activate'] ?? null,
                        ),
                        'touch' => self::pickCallable(
                            $store['touch'] ?? null,
                        ),
                        'isRevoked' => self::pickCallable(
                            $store['isRevoked'] ?? null,
                        ),
                        'revoke' => self::pickCallable(
                            $store['revoke'] ?? null,
                        ),
                    ],
                    'resolveSameOriginSubjectId' => self::pickCallable(
                        $session['resolveSameOriginSubjectId'] ?? null,
                    ),
                    'rateLimitExchange' => self::pickCallable(
                        $session['rateLimitExchange'] ?? null,
                    ),
                    'rateLimitLogout' => self::pickCallable(
                        $session['rateLimitLogout'] ?? null,
                    ),
                    'auditExchange' => self::pickCallable(
                        $session['auditExchange'] ?? null,
                    ),
                    'auditLogout' => self::pickCallable(
                        $session['auditLogout'] ?? null,
                    ),
                    'auditRevocation' => self::pickCallable(
                        $session['auditRevocation'] ?? null,
                    ),
                ],
            ],
            'payments' => [
                'enabledModes' => $normalizeEnabledModes($payments['enabledModes'] ?? null),
                'ownerIssuer' => self::normalizeOwnerIssuer($payments['ownerIssuer'] ?? null),
                'paymentUrl' => self::readFirstNonEmptyString(
                    $payments['paymentUrl'] ?? null,
                    $payments['tenantPaymentUrl'] ?? null,
                ),
                'returnSecret' => BackendSupport::readString($payments['returnSecret'] ?? null),
                'returnSecretRef' => BackendSupport::readString($payments['returnSecretRef'] ?? null),
                'returnUrlAllowlist' => BackendSupport::readString($payments['returnUrlAllowlist'] ?? null),
            ],
            'assets' => [
                'seedLogo' => [
                    'filePath' => BackendSupport::readString($seedLogo['filePath'] ?? null),
                    'routePath' => BackendSupport::readString($seedLogo['routePath'] ?? null),
                    'contentType' => BackendSupport::readString($seedLogo['contentType'] ?? null, 'image/svg+xml'),
                ],
                'paymentPage' => [
                    'filePath' => BackendSupport::readString($paymentPage['filePath'] ?? null),
                ],
            ],
            'gateway' => [
                'baseUrl' => trim(BackendSupport::readString($gateway['baseUrl'] ?? null)),
                'apiKey' => trim(BackendSupport::readString($gateway['apiKey'] ?? null)),
            ],
            'branding' => [
                'tenantName' => BackendSupport::readString($branding['tenantName'] ?? null),
                'serviceName' => BackendSupport::readString($branding['serviceName'] ?? null),
                'stackLabel' => BackendSupport::readString($branding['stackLabel'] ?? null),
            ],
            'reference' => [
                'tenant' => BackendSupport::readString($reference['tenant'] ?? null),
                'workspace' => BackendSupport::readString($reference['workspace'] ?? null),
                'stack' => BackendSupport::readString($reference['stack'] ?? null),
                'mode' => BackendSupport::readString($reference['mode'] ?? null),
                'tenantPolicySlugs' => BackendSupport::readList($reference['tenantPolicySlugs'] ?? null),
                'proofSources' => BackendSupport::readList($reference['proofSources'] ?? null),
                'sdkPaths' => BackendSupport::readRecord($reference['sdkPaths'] ?? null),
                'hostSurfaces' => BackendSupport::readList($reference['hostSurfaces'] ?? null),
                'notes' => BackendSupport::readList($reference['notes'] ?? null),
                'embedSdkCandidateFiles' => BackendSupport::readList($reference['embedSdkCandidateFiles'] ?? null),
                'referenceAssets' => BackendSupport::readRecord($reference['referenceAssets'] ?? null),
            ],
            'subjectProfiles' => [
                'workspace' => BackendSupport::readString($subjectProfiles['workspace'] ?? null),
                'source' => BackendSupport::readString($subjectProfiles['source'] ?? null),
                'catalogJson' => BackendSupport::readString($subjectProfiles['catalogJson'] ?? null),
                'defaultProfiles' => BackendSupport::readList($subjectProfiles['defaultProfiles'] ?? null),
                'resolveCandidates' => is_callable($subjectResolver) ? $subjectResolver : null,
                'resolveCatalogCustomerProfile' => is_callable($catalogCustomerProfileResolver)
                    ? $catalogCustomerProfileResolver
                    : null,
            ],
            'overrides' => [
                'hostProxyService' => is_object($hostProxyService) ? $hostProxyService : null,
                'gatewayClient' => is_object($gatewayClient) ? $gatewayClient : null,
                'paymentHandler' => is_object($paymentHandler) ? $paymentHandler : null,
                'resolvePolicyRequest' => is_callable($policyResolver) ? $policyResolver : null,
            ],
        ];
        self::validateHostedSecurityOptions($normalized['host']);
        return $normalized;
    }

    public static function attachBackendOptions(array $app, array $normalizedOptions): array
    {
        $app['hostOptions'] = BackendSupport::readRecord($normalizedOptions['host'] ?? null);
        $app['paymentOptions'] = BackendSupport::readRecord($normalizedOptions['payments'] ?? null);
        $app['assetOptions'] = BackendSupport::readRecord($normalizedOptions['assets'] ?? null);
        $app['brandingOptions'] = BackendSupport::readRecord($normalizedOptions['branding'] ?? null);
        $app['referenceOptions'] = BackendSupport::readRecord($normalizedOptions['reference'] ?? null);
        $app['subjectProfileOptions'] = BackendSupport::readRecord($normalizedOptions['subjectProfiles'] ?? null);
        return $app;
    }

    public static function paymentReturnAllowlist(array $config): array
    {
        $raw = trim((string) ($config['tenantPaymentReturnUrlAllowlist'] ?? ''));
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[,\n]/', $raw) ?: [];
        return array_values(array_filter(array_map(
            static fn ($entry) => rtrim(trim((string) $entry), '/'),
            $parts,
        )));
    }
}
