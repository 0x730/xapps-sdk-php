<?php

declare(strict_types=1);

namespace Xapps;

final class PaymentProviderCredentials
{
    /**
     * Build canonical guard config `payment_provider_credentials_refs`.
     *
     * Input shape:
     * [
     *   'stripe' => ['STRIPE_SECRET_KEY' => 'env:STRIPE_SECRET_KEY'],
     *   'paypal' => ['bundle_ref' => 'platform://payment:gateway:paypal:bundle'],
     *   'paypal' => ['PAYPAL_CLIENT_SECRET' => 'platform://...'],
     * ]
     *
     * @param array<string,array<string,mixed>|null> $providerRefs
     * @return array<string,array<string,string>>
     */
    public static function buildRefsByProvider(array $providerRefs): array
    {
        $out = [];
        foreach ($providerRefs as $provider => $refsRaw) {
            $providerName = strtolower(trim((string) $provider));
            if ($providerName === '' || !is_array($refsRaw)) {
                continue;
            }
            $refs = self::normalizeProviderNode($refsRaw);
            if ($refs === []) {
                continue;
            }
            $out[$providerName] = $refs;
        }
        return $out;
    }

    /**
     * Build canonical session metadata `payment_provider_credentials`.
     *
     * Input shape:
     * [
     *   'refs' => ['COMMON_KEY' => 'env:COMMON_KEY'],
     *   'bundle_ref' => 'platform://payment:gateway:common:bundle',
     *   'providers' => [
     *     'stripe' => ['STRIPE_SECRET_KEY' => 'env:STRIPE_SECRET_KEY'],
     *     'paypal' => ['bundle_ref' => 'platform://payment:gateway:paypal:bundle'],
     *   ],
     * ]
     *
     * Output shape:
     * [
     *   'refs' => [...],
     *   'bundle_ref' => 'platform://...',
     *   'providers' => [
     *     'stripe' => ['refs' => [...]],
     *     'paypal' => ['bundle_ref' => 'platform://...'],
     *   ],
     * ]
     *
     * @param array{
     *   refs?: array<string,mixed>|null,
     *   bundle_ref?: string|null,
     *   providers?: array<string,array<string,mixed>|null>|null
     * } $input
     * @return array<string,mixed>
     */
    public static function buildBundle(array $input): array
    {
        $out = [];
        $refs = self::normalizeRefs(is_array($input['refs'] ?? null) ? $input['refs'] : []);
        if ($refs !== []) {
            $out['refs'] = $refs;
        }
        $bundleRef = self::normalizeBundleRef($input);
        if ($bundleRef !== null) {
            $out['bundle_ref'] = $bundleRef;
        }

        $providerRefs = self::buildRefsByProvider(is_array($input['providers'] ?? null) ? $input['providers'] : []);
        if ($providerRefs !== []) {
            $providers = [];
            foreach ($providerRefs as $provider => $providerNode) {
                $bundleRef = self::normalizeBundleRef($providerNode);
                $providerRefMap = self::normalizeRefs($providerNode);
                $next = [];
                if ($providerRefMap !== []) {
                    $next['refs'] = $providerRefMap;
                }
                if ($bundleRef !== null) {
                    $next['bundle_ref'] = $bundleRef;
                }
                if ($next === []) {
                    continue;
                }
                $providers[$provider] = $next;
            }
            if ($providers !== []) {
                $out['providers'] = $providers;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $nodeRaw
     * @return array<string,string>
     */
    private static function normalizeProviderNode(array $nodeRaw): array
    {
        $refs = self::normalizeRefs($nodeRaw);
        $bundleRef = self::normalizeBundleRef($nodeRaw);
        if ($bundleRef !== null) {
            $refs['bundle_ref'] = $bundleRef;
        }
        return $refs;
    }

    /**
     * @param array<string,mixed> $refsRaw
     * @return array<string,string>
     */
    private static function normalizeRefs(array $refsRaw): array
    {
        $out = [];
        foreach ($refsRaw as $key => $value) {
            $k = trim((string) $key);
            if (
                $k === '' ||
                $k === 'bundle_ref' ||
                $k === 'bundleRef' ||
                $k === 'secret_bundle_ref' ||
                $k === 'secretBundleRef' ||
                $k === 'refs' ||
                $k === 'secret_refs' ||
                $k === 'values' ||
                $k === 'credentials' ||
                $k === 'providers'
            ) {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                continue;
            }
            $v = trim((string) $value);
            if ($v === '') {
                continue;
            }
            $out[$k] = $v;
        }

        $nested = $refsRaw['refs'] ?? $refsRaw['secret_refs'] ?? null;
        if (is_array($nested)) {
            foreach ($nested as $key => $value) {
                $k = trim((string) $key);
                $v = trim((string) $value);
                if ($k === '' || $v === '') {
                    continue;
                }
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $input
     */
    private static function normalizeBundleRef(array $input): ?string
    {
        $value = trim((string) (
            $input['bundle_ref'] ??
            $input['bundleRef'] ??
            $input['secret_bundle_ref'] ??
            $input['secretBundleRef'] ??
            ''
        ));
        return $value !== '' ? $value : null;
    }
}
