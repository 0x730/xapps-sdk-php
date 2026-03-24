<?php

declare(strict_types=1);

function xapps_backend_kit_payment_mode_from_guard_config(array $guardConfig): string
{
    $mode = strtolower(xapps_backend_kit_read_string(
        $guardConfig['payment_issuer_mode'] ?? null,
        $guardConfig['paymentIssuerMode'] ?? null,
    ));
    if ($mode === 'gateway_managed') {
        return 'gateway_managed';
    }
    if ($mode === 'tenant_delegated') {
        return 'tenant_delegated';
    }
    if ($mode === 'publisher_delegated') {
        return 'publisher_delegated';
    }
    return 'owner_managed';
}

function xapps_backend_kit_all_backend_mode_keys(): array
{
    return ['gateway_managed', 'tenant_delegated', 'publisher_delegated', 'owner_managed'];
}

function xapps_backend_kit_normalize_enabled_backend_modes(mixed $enabledModes = null): array
{
    $allModes = xapps_backend_kit_all_backend_mode_keys();
    if (!is_array($enabledModes) || count($enabledModes) === 0) {
        return $allModes;
    }

    $selected = [];
    foreach ($enabledModes as $value) {
        $mode = strtolower(trim((string) $value));
        if ($mode === 'tenant_managed') {
            $mode = 'owner_managed';
        }
        if (in_array($mode, $allModes, true) && !in_array($mode, $selected, true)) {
            $selected[] = $mode;
        }
    }

    return count($selected) > 0 ? $selected : $allModes;
}

function xapps_backend_kit_backend_mode_handlers(mixed $enabledModes = null): array
{
    $handlers = [
        'gateway_managed' => [
            'registerRoutes' => 'xapps_backend_kit_gateway_managed_register_routes',
            'resolvePolicyRequest' => 'xapps_backend_kit_gateway_managed_resolve_policy_request',
        ],
        'tenant_delegated' => [
            'registerRoutes' => 'xapps_backend_kit_tenant_delegated_register_routes',
            'resolvePolicyRequest' => 'xapps_backend_kit_tenant_delegated_resolve_policy_request',
        ],
        'publisher_delegated' => [
            'registerRoutes' => 'xapps_backend_kit_publisher_delegated_register_routes',
            'resolvePolicyRequest' => 'xapps_backend_kit_publisher_delegated_resolve_policy_request',
        ],
        'owner_managed' => [
            'registerRoutes' => 'xapps_backend_kit_owner_managed_register_routes',
            'resolvePolicyRequest' => 'xapps_backend_kit_owner_managed_resolve_policy_request',
        ],
    ];

    $selected = array_flip(xapps_backend_kit_normalize_enabled_backend_modes($enabledModes));
    return array_filter(
        $handlers,
        static fn(string $key): bool => isset($selected[$key]),
        ARRAY_FILTER_USE_KEY,
    );
}

function xapps_backend_kit_backend_mode_endpoint_groups(mixed $enabledModes = null): array
{
    $groups = [
        'gateway_managed' => xapps_backend_kit_gateway_managed_reference_endpoint_group(),
        'tenant_delegated' => xapps_backend_kit_tenant_delegated_reference_endpoint_group(),
        'publisher_delegated' => xapps_backend_kit_publisher_delegated_reference_endpoint_group(),
        'owner_managed' => xapps_backend_kit_owner_managed_reference_endpoint_group(),
    ];
    $selected = array_flip(xapps_backend_kit_normalize_enabled_backend_modes($enabledModes));
    return array_values(array_filter(
        $groups,
        static fn(string $key): bool => isset($selected[$key]),
        ARRAY_FILTER_USE_KEY,
    ));
}

function xapps_backend_kit_backend_mode_reference_details(mixed $enabledModes = null): array
{
    $details = [
        'gateway_managed' => array_merge(
            xapps_backend_kit_gateway_managed_payment_reference_details(),
            xapps_backend_kit_gateway_managed_policy_reference_details(),
        ),
        'tenant_delegated' => array_merge(
            xapps_backend_kit_tenant_delegated_payment_reference_details(),
            xapps_backend_kit_tenant_delegated_policy_reference_details(),
        ),
        'publisher_delegated' => array_merge(
            xapps_backend_kit_publisher_delegated_payment_reference_details(),
            xapps_backend_kit_publisher_delegated_policy_reference_details(),
        ),
        'owner_managed' => array_merge(
            xapps_backend_kit_owner_managed_payment_reference_details(),
            xapps_backend_kit_owner_managed_policy_reference_details(),
        ),
    ];
    $selected = array_flip(xapps_backend_kit_normalize_enabled_backend_modes($enabledModes));
    return array_values(array_filter(
        $details,
        static fn(string $key): bool => isset($selected[$key]),
        ARRAY_FILTER_USE_KEY,
    ));
}

function xapps_backend_kit_register_backend_mode_routes(array &$routes, array $app, mixed $enabledModes = null, array $options = []): void
{
    foreach (xapps_backend_kit_backend_mode_handlers($enabledModes) as $handler) {
        $register = $handler['registerRoutes'] ?? null;
        if (is_string($register) && function_exists($register)) {
            $register($routes, $app, $options);
        }
    }
}

function xapps_backend_kit_resolve_backend_payment_policy(array $payload, array $app, mixed $enabledModes = null): array
{
    $guard = xapps_backend_kit_read_record($payload['guard'] ?? null);
    $guardConfig = xapps_backend_kit_read_record($guard['config'] ?? null);
    $paymentMode = xapps_backend_kit_payment_mode_from_guard_config($guardConfig);
    $normalizedEnabledModes = xapps_backend_kit_normalize_enabled_backend_modes($enabledModes);
    if (!in_array($paymentMode, $normalizedEnabledModes, true)) {
        return [
            'allowed' => false,
            'reason' => 'payment_mode_not_enabled',
            'message' => 'Payment mode is not enabled for this backend.',
            'details' => [
                'payment_mode' => $paymentMode,
                'enabled_payment_modes' => $normalizedEnabledModes,
            ],
        ];
    }
    $handlers = xapps_backend_kit_backend_mode_handlers($normalizedEnabledModes);
    $handler = $handlers[$paymentMode] ?? $handlers['owner_managed'];
    $resolver = $handler['resolvePolicyRequest'] ?? null;
    if (!is_string($resolver) || !function_exists($resolver)) {
        throw new RuntimeException('Payment mode resolver is not registered: ' . $paymentMode);
    }
    return $resolver($payload, $app);
}
