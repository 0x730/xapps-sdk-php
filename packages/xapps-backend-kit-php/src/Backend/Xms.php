<?php

declare(strict_types=1);

namespace Xapps\BackendKit;

final class BackendXms
{
    private static function readLower(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    private static function readBoolean(mixed $value, bool $fallback = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value !== 0.0;
        }
        $normalized = self::readLower($value);
        if ($normalized === '') {
            return $fallback;
        }
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return $fallback;
    }

    /** @return array<string,mixed>|null */
    private static function asRecord(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    /** @return 'subject'|'installation'|'realm' */
    public static function normalizeXappMonetizationScopeKind(mixed $value): string
    {
        $raw = strtolower(trim((string) $value));
        if ($raw === 'installation' || $raw === 'realm') {
            return $raw;
        }
        return 'subject';
    }

    private static function requireGatewayMethod(object $gatewayClient, string $method): callable
    {
        if (!method_exists($gatewayClient, $method)) {
            throw new \InvalidArgumentException('gatewayClient must implement ' . $method);
        }
        /** @var callable(array<string,mixed>):array<string,mixed> $callable */
        $callable = [$gatewayClient, $method];
        return $callable;
    }

    private static function optionalTrimmedString(mixed $value): ?string
    {
        $normalized = trim((string) $value);
        return $normalized !== '' ? $normalized : null;
    }

    /** @return array<string,mixed>|null */
    private static function optionalRecord(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private static function buildScopeFields(array $input): array
    {
        $subjectId = self::optionalTrimmedString($input['subjectId'] ?? $input['subject_id'] ?? null);
        $installationId = self::optionalTrimmedString($input['installationId'] ?? $input['installation_id'] ?? null);
        $realmRef = self::optionalTrimmedString($input['realmRef'] ?? $input['realm_ref'] ?? null);

        return array_filter([
            'subject_id' => $subjectId,
            'installation_id' => $installationId,
            'realm_ref' => $realmRef,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private static function buildPreparePayload(array $input): array
    {
        return array_filter([
            'xappId' => trim((string) ($input['xappId'] ?? $input['xapp_id'] ?? '')),
            'offering_id' => self::optionalTrimmedString($input['offeringId'] ?? $input['offering_id'] ?? null),
            'package_id' => self::optionalTrimmedString($input['packageId'] ?? $input['package_id'] ?? null),
            'price_id' => self::optionalTrimmedString($input['priceId'] ?? $input['price_id'] ?? null),
            'source_kind' => self::optionalTrimmedString($input['sourceKind'] ?? $input['source_kind'] ?? null),
            'source_ref' => self::optionalTrimmedString($input['sourceRef'] ?? $input['source_ref'] ?? null),
            'payment_lane' => self::optionalTrimmedString($input['paymentLane'] ?? $input['payment_lane'] ?? null),
            'payment_session_id' => self::optionalTrimmedString($input['paymentSessionId'] ?? $input['payment_session_id'] ?? null),
            'request_id' => self::optionalTrimmedString($input['requestId'] ?? $input['request_id'] ?? null),
        ] + self::buildScopeFields($input), static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private static function buildSnapshotScopePayload(array $input): array
    {
        return array_filter([
            'xappId' => trim((string) ($input['xappId'] ?? $input['xapp_id'] ?? '')),
        ] + self::buildScopeFields($input), static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @param array<string,mixed> $item */
    private static function hasCurrentOwnedEntitlement(array $item): bool
    {
        $status = self::readLower($item['status'] ?? null);
        return in_array($status, ['active', 'issued', 'grace_period'], true);
    }

    /** @param array<string,mixed> $item @return array<string,mixed>|null */
    private static function normalizeReferencePackageSummary(array $item): ?array
    {
        $packageSlug = self::optionalTrimmedString($item['package_slug'] ?? $item['packageSlug'] ?? null);
        $paywallSlug = self::optionalTrimmedString($item['paywall_slug'] ?? $item['paywallSlug'] ?? null);
        $productFamily = self::optionalTrimmedString($item['product_family'] ?? $item['productFamily'] ?? null);
        $packageKind = self::optionalTrimmedString($item['package_kind'] ?? $item['packageKind'] ?? null);
        if ($packageSlug === null && $paywallSlug === null && $productFamily === null && $packageKind === null) {
            return null;
        }
        $purchasePolicyRecord = self::asRecord($item['purchase_policy'] ?? $item['purchasePolicy'] ?? null);
        return [
            'package_slug' => $packageSlug,
            'paywall_slug' => $paywallSlug,
            'product_family' => $productFamily,
            'package_kind' => $packageKind,
            'purchase_policy' => $purchasePolicyRecord
                ? [
                    'can_purchase' => self::readBoolean($purchasePolicyRecord['can_purchase'] ?? $purchasePolicyRecord['canPurchase'] ?? null, false),
                    'status' => match (self::readLower($purchasePolicyRecord['status'] ?? null)) {
                        'current_recurring_plan' => 'current_recurring_plan',
                        'owned_additive_unlock' => 'owned_additive_unlock',
                        default => 'available',
                    },
                    'transition_kind' => match (self::readLower($purchasePolicyRecord['transition_kind'] ?? $purchasePolicyRecord['transitionKind'] ?? null)) {
                        'start_recurring' => 'start_recurring',
                        'replace_recurring' => 'replace_recurring',
                        'buy_additive_unlock' => 'buy_additive_unlock',
                        'buy_credit_pack' => 'buy_credit_pack',
                        'activate_hybrid' => 'activate_hybrid',
                        default => 'none',
                    },
                    'reason' => self::optionalTrimmedString($purchasePolicyRecord['reason'] ?? null),
                ]
                : null,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function resolveXappMonetizationScope(array $input): array
    {
        $scopeKind = self::normalizeXappMonetizationScopeKind($input['scopeKind'] ?? $input['scope_kind'] ?? null);
        $context = isset($input['context']) && is_array($input['context']) ? $input['context'] : [];

        if ($scopeKind === 'installation') {
            $installationId = self::optionalTrimmedString($context['installation_id'] ?? null);
            if ($installationId === null) {
                throw new \RuntimeException('Installation context is required for installation-scoped monetization');
            }
            return [
                'scope_kind' => $scopeKind,
                'scope_fields' => ['installation_id' => $installationId],
            ];
        }

        if ($scopeKind === 'realm') {
            $realmRef = self::optionalTrimmedString($input['realmRef'] ?? $input['realm_ref'] ?? null);
            if ($realmRef === null) {
                throw new \RuntimeException('realmRef is required for realm-scoped monetization');
            }
            return [
                'scope_kind' => $scopeKind,
                'scope_fields' => ['realm_ref' => $realmRef],
            ];
        }

        $subjectId = self::optionalTrimmedString($context['subject_id'] ?? null);
        if ($subjectId === null) {
            throw new \RuntimeException('Subject context is required for subject-scoped monetization');
        }

        return [
            'scope_kind' => $scopeKind,
            'scope_fields' => ['subject_id' => $subjectId],
        ];
    }

    /** @param array<string,mixed> $prepared */
    private static function requireIntentId(array $prepared, string $methodName): string
    {
        $preparedIntent = isset($prepared['prepared_intent']) && is_array($prepared['prepared_intent'])
            ? $prepared['prepared_intent']
            : null;
        $intentId = self::optionalTrimmedString($preparedIntent['purchase_intent_id'] ?? null);
        if ($intentId === null) {
            throw new \RuntimeException($methodName . ' returned a purchase intent without purchase_intent_id');
        }
        return $intentId;
    }

    private static function normalizePaymentIssuerMode(mixed $value): string
    {
        $raw = strtolower(trim((string) $value));
        if (
            $raw === 'gateway_managed' ||
            $raw === 'tenant_delegated' ||
            $raw === 'publisher_delegated' ||
            $raw === 'owner_managed'
        ) {
            return $raw;
        }
        return '';
    }

    private static function readLocalizedText(mixed $value, string $fallback = ''): string
    {
        if (is_array($value)) {
            $en = trim((string) ($value['en'] ?? ''));
            if ($en !== '') {
                return $en;
            }
            $ro = trim((string) ($value['ro'] ?? ''));
            if ($ro !== '') {
                return $ro;
            }
            foreach ($value as $item) {
                $text = trim((string) $item);
                if ($text !== '') {
                    return $text;
                }
            }
            return $fallback;
        }
        $text = trim((string) $value);
        return $text !== '' ? $text : $fallback;
    }

    private static function formatPaymentIssuerModeLabel(string $issuerMode): string
    {
        $raw = self::normalizePaymentIssuerMode($issuerMode);
        if ($raw === 'gateway_managed') {
            return 'Gateway managed';
        }
        if ($raw === 'tenant_delegated') {
            return 'Tenant delegated';
        }
        if ($raw === 'publisher_delegated') {
            return 'Publisher delegated';
        }
        if ($raw === 'owner_managed') {
            return 'Owner managed';
        }
        return 'Unknown lane';
    }

    private static function formatPaymentSchemeLabel(mixed $value): string
    {
        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return '';
        }
        return str_replace('_', ' ', $raw);
    }

    /** @param array<string,mixed> $definition */
    private static function buildHostedPaymentPresetDescription(array $definition): string
    {
        $paymentUi = isset($definition['payment_ui']) && is_array($definition['payment_ui']) ? $definition['payment_ui'] : [];
        $copy = isset($paymentUi['copy']) && is_array($paymentUi['copy']) ? $paymentUi['copy'] : [];
        $subtitle = self::readLocalizedText($copy['subtitle'] ?? null);
        if ($subtitle !== '') {
            return $subtitle;
        }
        $title = self::readLocalizedText($copy['title'] ?? null);
        if ($title !== '') {
            return $title;
        }
        $issuerMode = self::normalizePaymentIssuerMode($definition['payment_issuer_mode'] ?? null);
        if ($issuerMode === '') {
            return 'Hosted payment lane from manifest definition.';
        }
        return str_replace('_', ' ', $issuerMode) . ' hosted payment lane.';
    }

    /** @param array<string,mixed> $definition @return array<int,string> */
    private static function listAcceptedPaymentSchemes(array $definition): array
    {
        $out = [];
        $primary = strtolower(trim((string) ($definition['payment_scheme'] ?? '')));
        if ($primary !== '') {
            $out[] = $primary;
        }
        $accepts = isset($definition['accepts']) && is_array($definition['accepts']) ? $definition['accepts'] : [];
        foreach ($accepts as $item) {
            if (!is_array($item)) {
                continue;
            }
            $scheme = strtolower(trim((string) ($item['scheme'] ?? '')));
            if ($scheme !== '' && !in_array($scheme, $out, true)) {
                $out[] = $scheme;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $definition */
    private static function buildHostedPaymentPresetDescriptionForScheme(array $definition, string $paymentScheme): string
    {
        $paymentUi = self::optionalRecord($definition['payment_ui'] ?? null) ?? [];
        $schemes = isset($paymentUi['schemes']) && is_array($paymentUi['schemes']) ? $paymentUi['schemes'] : [];
        foreach ($schemes as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (strtolower(trim((string) ($item['scheme'] ?? ''))) !== $paymentScheme) {
                continue;
            }
            $subtitle = self::readLocalizedText($item['subtitle'] ?? null);
            if ($subtitle !== '') {
                return $subtitle;
            }
            $title = self::readLocalizedText($item['title'] ?? null);
            if ($title !== '') {
                return $title;
            }
        }
        return self::buildHostedPaymentPresetDescription($definition);
    }

    /** @param array<string,mixed> $manifest */
    private static function findPaymentGuardDefinition(array $manifest, string $paymentGuardRef): ?array
    {
        $definitions = [];
        if (isset($manifest['payment_guard_definitions']) && is_array($manifest['payment_guard_definitions'])) {
            $definitions = $manifest['payment_guard_definitions'];
        } elseif (isset($manifest['paymentGuardDefinitions']) && is_array($manifest['paymentGuardDefinitions'])) {
            $definitions = $manifest['paymentGuardDefinitions'];
        }
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                continue;
            }
            if (trim((string) ($definition['name'] ?? '')) === $paymentGuardRef) {
                return $definition;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $input @return array<int,array<string,mixed>> */
    public static function listXappHostedPaymentPresets(array $input): array
    {
        $manifest = isset($input['manifest']) && is_array($input['manifest']) ? $input['manifest'] : [];
        $definitions = [];
        if (isset($manifest['payment_guard_definitions']) && is_array($manifest['payment_guard_definitions'])) {
            $definitions = $manifest['payment_guard_definitions'];
        } elseif (isset($manifest['paymentGuardDefinitions']) && is_array($manifest['paymentGuardDefinitions'])) {
            $definitions = $manifest['paymentGuardDefinitions'];
        }
        $items = [];
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $key = trim((string) ($definition['name'] ?? ''));
            $issuerMode = self::normalizePaymentIssuerMode($definition['payment_issuer_mode'] ?? null);
            if ($key === '' || $issuerMode === '') {
                continue;
            }
            foreach (self::listAcceptedPaymentSchemes($definition) as $index => $scheme) {
                $schemeLabel = self::formatPaymentSchemeLabel($scheme);
                $items[] = [
                    'key' => $key . ':' . ($scheme !== '' ? $scheme : (string) $index),
                    'label' => $schemeLabel !== ''
                        ? self::formatPaymentIssuerModeLabel($issuerMode) . ' (' . $schemeLabel . ')'
                        : self::formatPaymentIssuerModeLabel($issuerMode),
                    'description' => self::buildHostedPaymentPresetDescriptionForScheme($definition, $scheme),
                    'paymentGuardRef' => $key,
                    'paymentScheme' => $scheme !== '' ? $scheme : null,
                    'issuerMode' => $issuerMode,
                    'delegated' => $issuerMode === 'tenant_delegated' || $issuerMode === 'publisher_delegated',
                ];
            }
        }
        return $items;
    }

    /** @param array<string,mixed> $input @return array<string,mixed>|null */
    public static function findXappHostedPaymentPreset(array $input): ?array
    {
        $paymentGuardRef = trim((string) ($input['paymentGuardRef'] ?? $input['payment_guard_ref'] ?? ''));
        $paymentScheme = strtolower(trim((string) ($input['paymentScheme'] ?? $input['payment_scheme'] ?? '')));
        if ($paymentGuardRef === '') {
            return null;
        }
        foreach (self::listXappHostedPaymentPresets($input) as $item) {
            if (
                trim((string) ($item['paymentGuardRef'] ?? '')) === $paymentGuardRef &&
                ($paymentScheme === '' || strtolower(trim((string) ($item['paymentScheme'] ?? ''))) === $paymentScheme)
            ) {
                return $item;
            }
        }
        return null;
    }

    /** @param array<string,string|int|float|bool|null> $env @return array<string,string> */
    private static function readDelegatedReturnSecret(string $issuerMode, array $env): array
    {
        if ($issuerMode === 'tenant_delegated') {
            $secretRef = trim((string) ($env['TENANT_DELEGATED_PAYMENT_RETURN_SECRET_REF'] ?? $env['XCONECT_TENANT_PAYMENT_RETURN_SECRET_REF'] ?? $env['TENANT_PAYMENT_RETURN_SECRET_REF'] ?? ''));
            $secret = trim((string) ($env['TENANT_DELEGATED_PAYMENT_RETURN_SECRET'] ?? $env['XCONECT_TENANT_PAYMENT_RETURN_SECRET'] ?? $env['TENANT_PAYMENT_RETURN_SECRET'] ?? ''));
            return array_filter([
                'secret_ref' => $secretRef !== '' ? $secretRef : null,
                'secret' => $secret !== '' ? $secret : null,
            ], static fn (mixed $value): bool => is_string($value) && $value !== '');
        }
        if ($issuerMode === 'publisher_delegated') {
            $secretRef = trim((string) ($env['PUBLISHER_DELEGATED_PAYMENT_RETURN_SECRET_REF'] ?? $env['PUBLISHER_PAYMENT_RETURN_SECRET_REF'] ?? ''));
            $secret = trim((string) ($env['PUBLISHER_DELEGATED_PAYMENT_RETURN_SECRET'] ?? $env['PUBLISHER_PAYMENT_RETURN_SECRET'] ?? ''));
            return array_filter([
                'secret_ref' => $secretRef !== '' ? $secretRef : null,
                'secret' => $secret !== '' ? $secret : null,
            ], static fn (mixed $value): bool => is_string($value) && $value !== '');
        }
        return [];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function resolveXappHostedPaymentDefinition(array $input): array
    {
        $manifest = isset($input['manifest']) && is_array($input['manifest']) ? $input['manifest'] : [];
        $paymentGuardRef = trim((string) ($input['paymentGuardRef'] ?? $input['payment_guard_ref'] ?? ''));
        if ($paymentGuardRef === '') {
            throw new \InvalidArgumentException('paymentGuardRef is required');
        }
        $definition = self::findPaymentGuardDefinition($manifest, $paymentGuardRef);
        if ($definition === null) {
            throw new \RuntimeException('Unknown payment lane. Choose one of the configured payment definitions.');
        }
        $issuerMode = self::normalizePaymentIssuerMode($definition['payment_issuer_mode'] ?? null);
        if ($issuerMode === '') {
            throw new \RuntimeException('Unsupported payment issuer mode for ' . $paymentGuardRef);
        }
        $requestedScheme = strtolower(trim((string) ($input['paymentScheme'] ?? $input['payment_scheme'] ?? '')));
        $supportedSchemes = self::listAcceptedPaymentSchemes($definition);
        $scheme = $requestedScheme !== '' ? $requestedScheme : ($supportedSchemes[0] ?? null);
        if ($requestedScheme !== '' && !in_array($requestedScheme, $supportedSchemes, true)) {
            throw new \RuntimeException('Unsupported payment scheme ' . $requestedScheme . ' for ' . $paymentGuardRef);
        }
        $env = isset($input['env']) && is_array($input['env']) ? $input['env'] : [];
        if ($issuerMode === 'tenant_delegated' || $issuerMode === 'publisher_delegated') {
            $signingSecret = self::readDelegatedReturnSecret($issuerMode, $env);
            if (!isset($signingSecret['secret_ref']) && !isset($signingSecret['secret'])) {
                throw new \RuntimeException(
                    $issuerMode === 'tenant_delegated'
                        ? 'Tenant-delegated payment return secret or secret ref is not configured.'
                        : 'Publisher-delegated payment return secret or secret ref is not configured.'
                );
            }
            return [
                'paymentGuardRef' => $paymentGuardRef,
                'issuerMode' => $issuerMode,
                'scheme' => $scheme,
                'definition' => $definition,
                'metadata' => [
                    'payment_return_signing' => [
                        'issuer' => $issuerMode,
                        'signing_lane' => $issuerMode,
                        'resolver_source' => 'session_metadata_delegated',
                    ] + $signingSecret,
                ],
            ];
        }
        return [
            'paymentGuardRef' => $paymentGuardRef,
            'issuerMode' => $issuerMode,
            'scheme' => $scheme,
            'definition' => $definition,
            'metadata' => null,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function readXappMonetizationSnapshot(object $gatewayClient, array $input): array
    {
        $getMonetizationAccess = self::requireGatewayMethod($gatewayClient, 'getXappMonetizationAccess');
        $getCurrentSubscription = self::requireGatewayMethod($gatewayClient, 'getXappCurrentSubscription');
        $listEntitlements = method_exists($gatewayClient, 'listXappEntitlements')
            ? self::requireGatewayMethod($gatewayClient, 'listXappEntitlements')
            : null;
        $listWalletAccounts = self::requireGatewayMethod($gatewayClient, 'listXappWalletAccounts');
        $scopePayload = self::buildSnapshotScopePayload($input);
        $includeWalletAccounts = !array_key_exists('includeWalletAccounts', $input) || $input['includeWalletAccounts'] !== false;
        $includeWalletLedger = array_key_exists('includeWalletLedger', $input) && $input['includeWalletLedger'] === true;

        $accessResult = $getMonetizationAccess($scopePayload);
        $currentSubscriptionResult = $getCurrentSubscription($scopePayload);
        $entitlementsResult = $listEntitlements ? $listEntitlements($scopePayload) : null;
        $walletAccountsResult = $includeWalletAccounts ? $listWalletAccounts($scopePayload) : null;
        $walletLedgerResult = null;
        if ($includeWalletLedger) {
            $listWalletLedger = self::requireGatewayMethod($gatewayClient, 'listXappWalletLedger');
            $walletLedgerResult = $listWalletLedger(array_filter([
                'xappId' => trim((string) ($input['xappId'] ?? $input['xapp_id'] ?? '')),
                'subject_id' => self::optionalTrimmedString($input['subjectId'] ?? $input['subject_id'] ?? null),
                'installation_id' => self::optionalTrimmedString($input['installationId'] ?? $input['installation_id'] ?? null),
                'realm_ref' => self::optionalTrimmedString($input['realmRef'] ?? $input['realm_ref'] ?? null),
                'wallet_account_id' => self::optionalTrimmedString($input['walletAccountId'] ?? $input['wallet_account_id'] ?? null),
                'payment_session_id' => self::optionalTrimmedString($input['paymentSessionId'] ?? $input['payment_session_id'] ?? null),
                'request_id' => self::optionalTrimmedString($input['requestId'] ?? $input['request_id'] ?? null),
                'settlement_ref' => self::optionalTrimmedString($input['settlementRef'] ?? $input['settlement_ref'] ?? null),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''));
        }

        return [
            'access_projection' => isset($accessResult['access_projection']) && is_array($accessResult['access_projection']) ? $accessResult['access_projection'] : null,
            'current_subscription' => isset($currentSubscriptionResult['current_subscription']) && is_array($currentSubscriptionResult['current_subscription']) ? $currentSubscriptionResult['current_subscription'] : null,
            'entitlements' => isset($entitlementsResult['items']) && is_array($entitlementsResult['items']) ? $entitlementsResult['items'] : [],
            'wallet_accounts' => isset($walletAccountsResult['items']) && is_array($walletAccountsResult['items']) ? $walletAccountsResult['items'] : [],
            'wallet_ledger' => isset($walletLedgerResult['items']) && is_array($walletLedgerResult['items']) ? $walletLedgerResult['items'] : [],
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function buildXappMonetizationReferenceSummary(array $input): array
    {
        $snapshot = self::asRecord($input['snapshot'] ?? null) ?? [];
        $currentSubscription = self::asRecord($snapshot['current_subscription'] ?? null);
        $entitlements = isset($snapshot['entitlements']) && is_array($snapshot['entitlements']) ? $snapshot['entitlements'] : [];
        $accessProjection = self::asRecord($snapshot['access_projection'] ?? null);
        $rawPackages = isset($input['paywallPackages']) && is_array($input['paywallPackages'])
            ? $input['paywallPackages']
            : (isset($input['paywall_packages']) && is_array($input['paywall_packages']) ? $input['paywall_packages'] : []);

        $paywallPackages = [];
        foreach ($rawPackages as $item) {
            if (!is_array($item)) {
                continue;
            }
            $normalized = self::normalizeReferencePackageSummary($item);
            if ($normalized !== null) {
                $paywallPackages[] = $normalized;
            }
        }

        return [
            'current_recurring_plan' => $currentSubscription
                ? [
                    'product_slug' => self::optionalTrimmedString($currentSubscription['product_slug'] ?? null),
                    'package_slug' => self::optionalTrimmedString($currentSubscription['package_slug'] ?? null),
                    'status' => self::optionalTrimmedString($currentSubscription['status'] ?? null),
                    'renews_at' => self::optionalTrimmedString($currentSubscription['renews_at'] ?? null),
                ]
                : null,
            'owned_additive_unlocks' => array_values(array_filter($paywallPackages, static fn (array $item): bool => (($item['purchase_policy']['status'] ?? null) === 'owned_additive_unlock'))),
            'available_additive_unlocks' => array_values(array_filter($paywallPackages, static function (array $item): bool {
                $productFamily = self::readLower($item['product_family'] ?? null);
                $packageKind = self::readLower($item['package_kind'] ?? null);
                return ($productFamily === 'one_time_unlock' || $packageKind === 'one_time_unlock') &&
                    (($item['purchase_policy']['can_purchase'] ?? true) !== false);
            })),
            'recurring_options' => array_values(array_filter($paywallPackages, static function (array $item): bool {
                $transitionKind = $item['purchase_policy']['transition_kind'] ?? 'none';
                return $transitionKind === 'start_recurring' || $transitionKind === 'replace_recurring';
            })),
            'credit_topups' => array_values(array_filter($paywallPackages, static fn (array $item): bool => (($item['purchase_policy']['transition_kind'] ?? null) === 'buy_credit_pack'))),
            'blocked_packages' => array_values(array_filter($paywallPackages, static fn (array $item): bool => (($item['purchase_policy']['can_purchase'] ?? true) === false))),
            'owned_entitlement_count' => count(array_filter($entitlements, static fn (mixed $item): bool => is_array($item) && self::hasCurrentOwnedEntitlement($item))),
            'has_current_access' => self::readBoolean($accessProjection['has_current_access'] ?? null, false),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function consumeXappWalletCredits(object $gatewayClient, array $input): array
    {
        $consumeWalletCredits = self::requireGatewayMethod($gatewayClient, 'consumeXappWalletCredits');
        $walletAccountId = self::optionalTrimmedString($input['walletAccountId'] ?? $input['wallet_account_id'] ?? null);
        if ($walletAccountId === null) {
            throw new \InvalidArgumentException('walletAccountId is required');
        }
        $amount = trim((string) ($input['amount'] ?? ''));
        if ($amount === '') {
            throw new \InvalidArgumentException('amount is required');
        }
        $consumed = $consumeWalletCredits(array_filter([
            'xappId' => trim((string) ($input['xappId'] ?? $input['xapp_id'] ?? '')),
            'walletAccountId' => $walletAccountId,
            'amount' => $amount,
            'source_ref' => self::optionalTrimmedString($input['sourceRef'] ?? $input['source_ref'] ?? null),
            'metadata' => self::optionalRecord($input['metadata'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        return [
            'wallet_account' => isset($consumed['wallet_account']) && is_array($consumed['wallet_account']) ? $consumed['wallet_account'] : null,
            'wallet_ledger' => isset($consumed['wallet_ledger']) && is_array($consumed['wallet_ledger']) ? $consumed['wallet_ledger'] : null,
            'access_projection' => isset($consumed['access_projection']) && is_array($consumed['access_projection']) ? $consumed['access_projection'] : null,
            'snapshot_id' => self::optionalTrimmedString($consumed['snapshot_id'] ?? null),
            'consumed' => $consumed,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function startXappHostedPurchase(object $gatewayClient, array $input): array
    {
        $preparePurchaseIntent = self::requireGatewayMethod($gatewayClient, 'prepareXappPurchaseIntent');
        $createPurchasePaymentSession = self::requireGatewayMethod($gatewayClient, 'createXappPurchasePaymentSession');

        $prepared = $preparePurchaseIntent(self::buildPreparePayload($input));
        $intentId = self::requireIntentId($prepared, 'prepareXappPurchaseIntent');
        $paymentSession = $createPurchasePaymentSession(array_filter([
            'xappId' => trim((string) ($input['xappId'] ?? $input['xapp_id'] ?? '')),
            'intentId' => $intentId,
            'payment_guard_ref' => self::optionalTrimmedString($input['paymentGuardRef'] ?? $input['payment_guard_ref'] ?? null),
            'issuer' => self::optionalTrimmedString($input['issuer'] ?? null),
            'scheme' => self::optionalTrimmedString($input['scheme'] ?? null),
            'payment_scheme' => self::optionalTrimmedString($input['paymentScheme'] ?? $input['payment_scheme'] ?? null),
            'return_url' => self::optionalTrimmedString($input['returnUrl'] ?? $input['return_url'] ?? null),
            'cancel_url' => self::optionalTrimmedString($input['cancelUrl'] ?? $input['cancel_url'] ?? null),
            'xapps_resume' => self::optionalTrimmedString($input['xappsResume'] ?? $input['xapps_resume'] ?? null),
            'page_url' => self::optionalTrimmedString($input['pageUrl'] ?? $input['page_url'] ?? null),
            'locale' => self::optionalTrimmedString($input['locale'] ?? null),
            'metadata' => self::optionalRecord($input['metadata'] ?? null),
        ] + self::buildScopeFields($input), static fn (mixed $value): bool => $value !== null && $value !== ''));

        return [
            'prepared_intent' => isset($prepared['prepared_intent']) && is_array($prepared['prepared_intent']) ? $prepared['prepared_intent'] : null,
            'payment_session' => isset($paymentSession['payment_session']) && is_array($paymentSession['payment_session']) ? $paymentSession['payment_session'] : null,
            'payment_page_url' => self::optionalTrimmedString($paymentSession['payment_page_url'] ?? null),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function finalizeXappHostedPurchase(object $gatewayClient, array $input): array
    {
        $finalizePurchasePaymentSession = self::requireGatewayMethod($gatewayClient, 'finalizeXappPurchasePaymentSession');
        $intentId = self::optionalTrimmedString($input['intentId'] ?? $input['intent_id'] ?? null);
        if ($intentId === null) {
            throw new \InvalidArgumentException('intentId is required');
        }
        $xappId = trim((string) ($input['xappId'] ?? $input['xapp_id'] ?? ''));
        if ($xappId === '') {
            throw new \InvalidArgumentException('xappId is required');
        }

        $finalized = $finalizePurchasePaymentSession([
            'xappId' => $xappId,
            'intentId' => $intentId,
        ]);

        return [
            'prepared_intent' => isset($finalized['prepared_intent']) && is_array($finalized['prepared_intent']) ? $finalized['prepared_intent'] : null,
            'payment_session' => isset($finalized['payment_session']) && is_array($finalized['payment_session']) ? $finalized['payment_session'] : null,
            'transaction' => isset($finalized['transaction']) && is_array($finalized['transaction']) ? $finalized['transaction'] : null,
            'access_projection' => isset($finalized['access_projection']) && is_array($finalized['access_projection']) ? $finalized['access_projection'] : null,
            'issued' => $finalized,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function activateXappPurchaseReference(object $gatewayClient, array $input): array
    {
        $preparePurchaseIntent = self::requireGatewayMethod($gatewayClient, 'prepareXappPurchaseIntent');
        $createPurchaseTransaction = self::requireGatewayMethod($gatewayClient, 'createXappPurchaseTransaction');
        $issuePurchaseAccess = self::requireGatewayMethod($gatewayClient, 'issueXappPurchaseAccess');

        $prepared = $preparePurchaseIntent(self::buildPreparePayload($input));
        $intentId = self::requireIntentId($prepared, 'prepareXappPurchaseIntent');
        $preparedIntent = isset($prepared['prepared_intent']) && is_array($prepared['prepared_intent']) ? $prepared['prepared_intent'] : null;
        $preparedPrice = isset($preparedIntent['price']) && is_array($preparedIntent['price']) ? $preparedIntent['price'] : null;
        $xappId = trim((string) ($input['xappId'] ?? $input['xapp_id'] ?? ''));

        $transaction = $createPurchaseTransaction(array_filter([
            'xappId' => $xappId,
            'intentId' => $intentId,
            'status' => self::optionalTrimmedString($input['status'] ?? null) ?? 'verified',
            'provider_ref' => self::optionalTrimmedString($input['providerRef'] ?? $input['provider_ref'] ?? null),
            'evidence_ref' => self::optionalTrimmedString($input['evidenceRef'] ?? $input['evidence_ref'] ?? null),
            'payment_session_id' => self::optionalTrimmedString($input['paymentSessionId'] ?? $input['payment_session_id'] ?? null),
            'request_id' => self::optionalTrimmedString($input['requestId'] ?? $input['request_id'] ?? null),
            'settlement_ref' => self::optionalTrimmedString($input['settlementRef'] ?? $input['settlement_ref'] ?? null),
            'amount' => array_key_exists('amount', $input) ? $input['amount'] : ($preparedPrice['amount'] ?? null),
            'currency' => self::optionalTrimmedString($input['currency'] ?? null) ?? self::optionalTrimmedString($preparedPrice['currency'] ?? null),
            'occurred_at' => self::optionalTrimmedString($input['occurredAt'] ?? $input['occurred_at'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        $issued = $issuePurchaseAccess([
            'xappId' => $xappId,
            'intentId' => $intentId,
        ]);

        return [
            'prepared_intent' => $preparedIntent,
            'transaction' => isset($transaction['transaction']) && is_array($transaction['transaction']) ? $transaction['transaction'] : null,
            'issued' => $issued,
        ];
    }
}
