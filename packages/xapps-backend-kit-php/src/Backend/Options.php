<?php

declare(strict_types=1);

namespace Xapps\BackendKit;

final class BackendOptions
{
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

    public static function normalizeOptions(array $options = [], array $deps = []): array
    {
        $defaults = BackendSupport::readRecord($deps['defaults'] ?? null);
        $normalizeEnabledModes = $deps['normalizeEnabledModes'] ?? null;
        if (!is_callable($normalizeEnabledModes)) {
            throw new \InvalidArgumentException('normalizeEnabledModes is required');
        }

        $host = BackendSupport::readRecord($options['host'] ?? null);
        $payments = BackendSupport::readRecord($options['payments'] ?? null);
        $assets = BackendSupport::readRecord($options['assets'] ?? null);
        $seedLogo = BackendSupport::readRecord($assets['seedLogo'] ?? null);
        $paymentPage = BackendSupport::readRecord($assets['paymentPage'] ?? null);
        $gateway = BackendSupport::readRecord($options['gateway'] ?? null);
        $branding = BackendSupport::readRecord($options['branding'] ?? null);
        $reference = BackendSupport::readRecord($options['reference'] ?? null);
        $subjectProfiles = BackendSupport::readRecord($options['subjectProfiles'] ?? null);
        $overrides = BackendSupport::readRecord($options['overrides'] ?? null);
        $defaultHost = BackendSupport::readRecord($defaults['host'] ?? null);
        $defaultPayments = BackendSupport::readRecord($defaults['payments'] ?? null);
        $defaultGateway = BackendSupport::readRecord($defaults['gateway'] ?? null);

        $subjectResolver = $subjectProfiles['resolveCandidates'] ?? null;
        $catalogCustomerProfileResolver = $subjectProfiles['resolveCatalogCustomerProfile'] ?? null;
        $policyResolver = $overrides['resolvePolicyRequest'] ?? null;
        $hostProxyService = $overrides['hostProxyService'] ?? null;
        $gatewayClient = $overrides['gatewayClient'] ?? null;
        $paymentHandler = $overrides['paymentHandler'] ?? null;

        return [
            'host' => [
                'enableReference' => ($host['enableReference'] ?? true) !== false
                    && ($defaultHost['enableReference'] ?? true) !== false,
                'enableLifecycle' => ($host['enableLifecycle'] ?? true) !== false
                    && ($defaultHost['enableLifecycle'] ?? true) !== false,
                'enableBridge' => ($host['enableBridge'] ?? true) !== false
                    && ($defaultHost['enableBridge'] ?? true) !== false,
                'allowedOrigins' => self::normalizeAllowedOrigins($host['allowedOrigins'] ?? ($defaultHost['allowedOrigins'] ?? null)),
                'bootstrap' => [
                    'apiKeys' => self::normalizeApiKeys(
                        BackendSupport::readRecord($host['bootstrap'] ?? null)['apiKeys'] ?? ($defaultHost['bootstrap']['apiKeys'] ?? null),
                    ),
                    'signingSecret' => trim(
                        BackendSupport::readString(
                            BackendSupport::readRecord($host['bootstrap'] ?? null)['signingSecret'] ?? null,
                            BackendSupport::readString($defaultHost['bootstrap']['signingSecret'] ?? null),
                        ),
                    ),
                    'ttlSeconds' => (int) (
                        (float) (
                            BackendSupport::readRecord($host['bootstrap'] ?? null)['ttlSeconds']
                            ?? ($defaultHost['bootstrap']['ttlSeconds'] ?? 300)
                        ) > 0
                            ? BackendSupport::readRecord($host['bootstrap'] ?? null)['ttlSeconds']
                                ?? ($defaultHost['bootstrap']['ttlSeconds'] ?? 300)
                            : 300
                    ),
                ],
            ],
            'payments' => [
                'enabledModes' => $normalizeEnabledModes($payments['enabledModes'] ?? null),
                'ownerIssuer' => self::normalizeOwnerIssuer(
                    $payments['ownerIssuer'] ?? null,
                    self::normalizeOwnerIssuer($defaultPayments['ownerIssuer'] ?? null),
                ),
                'paymentUrl' => BackendSupport::readString($payments['paymentUrl'] ?? null, BackendSupport::readString($payments['tenantPaymentUrl'] ?? null))
                    ?: BackendSupport::readString($defaultPayments['paymentUrl'] ?? null),
                'returnSecret' => array_key_exists('returnSecret', $payments)
                    ? BackendSupport::readString($payments['returnSecret'] ?? null)
                    : BackendSupport::readString($defaultPayments['returnSecret'] ?? null),
                'returnSecretRef' => array_key_exists('returnSecretRef', $payments)
                    ? BackendSupport::readString($payments['returnSecretRef'] ?? null)
                    : BackendSupport::readString($defaultPayments['returnSecretRef'] ?? null),
                'returnUrlAllowlist' => array_key_exists('returnUrlAllowlist', $payments)
                    ? BackendSupport::readString($payments['returnUrlAllowlist'] ?? null)
                    : BackendSupport::readString($defaultPayments['returnUrlAllowlist'] ?? null),
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
                'baseUrl' => BackendSupport::readString($gateway['baseUrl'] ?? null) ?: BackendSupport::readString($defaultGateway['baseUrl'] ?? null),
                'apiKey' => BackendSupport::readString($gateway['apiKey'] ?? null) ?: BackendSupport::readString($defaultGateway['apiKey'] ?? null),
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
