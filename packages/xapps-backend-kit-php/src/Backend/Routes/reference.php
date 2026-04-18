<?php

declare(strict_types=1);

function xapps_backend_kit_reference_time_iso(): string
{
    return gmdate('c');
}

function xapps_backend_kit_reference_capabilities(array $options = []): array
{
    return [
        'enableReference' => ($options['enableReference'] ?? true) !== false,
        'enableLifecycle' => ($options['enableLifecycle'] ?? true) !== false,
        'enableBridge' => ($options['enableBridge'] ?? true) !== false,
        'enabledModes' => xapps_backend_kit_normalize_enabled_backend_modes($options['enabledModes'] ?? null),
    ];
}

function xapps_backend_kit_reference_config(array $options = []): array
{
    $reference = xapps_backend_kit_read_record($options['reference'] ?? null);
    $branding = xapps_backend_kit_read_record($options['branding'] ?? null);
    $gateway = xapps_backend_kit_read_record($options['gateway'] ?? null);
    $referenceAssets = xapps_backend_kit_read_record($reference['referenceAssets'] ?? null);

    return [
        'tenant' => xapps_backend_kit_read_string($reference['tenant'] ?? null, 'tenant'),
        'workspace' => xapps_backend_kit_read_string($reference['workspace'] ?? null, 'tenant'),
        'stack' => xapps_backend_kit_read_string($reference['stack'] ?? null, 'plain-php'),
        'mode' => xapps_backend_kit_read_string($reference['mode'] ?? null, 'reference-marketplace-tenant-php'),
        'tenantPolicySlugs' => xapps_backend_kit_read_list($reference['tenantPolicySlugs'] ?? null),
        'proofSources' => xapps_backend_kit_read_list($reference['proofSources'] ?? null) ?: [
            '/api/reference',
            '/api/host-config',
            '/api/installations?subjectId=...',
        ],
        'sdkPaths' => xapps_backend_kit_read_record($reference['sdkPaths'] ?? null) ?: [
            'node' => '@xapps-platform/server-sdk',
            'php' => 'xapps-platform/xapps-php',
            'browser' => '@xapps-platform/embed-sdk',
        ],
        'hostSurfaces' => xapps_backend_kit_read_list($reference['hostSurfaces'] ?? null) ?: [
            ['key' => 'single-panel', 'label' => 'Single panel', 'recommended_for_first_lane' => true],
            ['key' => 'split-panel', 'label' => 'Split panel', 'recommended_for_first_lane' => false],
            ['key' => 'single-xapp', 'label' => 'Single xapp', 'recommended_for_first_lane' => false],
        ],
        'notes' => xapps_backend_kit_read_list($reference['notes'] ?? null),
        'referenceAssetEndpoints' => xapps_backend_kit_read_list($referenceAssets['endpoints'] ?? null),
        'gatewayUrl' => xapps_backend_kit_read_string($gateway['baseUrl'] ?? null),
        'displayName' => xapps_backend_kit_read_string($branding['tenantName'] ?? null),
        'stackLabel' => xapps_backend_kit_read_string($branding['stackLabel'] ?? null),
    ];
}

function xapps_backend_kit_reference_mode_summary(array $enabledModes): array
{
    return array_values(array_filter([
        ['key' => 'gateway_managed', 'label' => 'Gateway managed', 'default_for_first_lane' => true, 'page_owner' => 'gateway'],
        ['key' => 'tenant_delegated', 'label' => 'Gateway delegated by tenant', 'default_for_first_lane' => false, 'page_owner' => 'gateway'],
        ['key' => 'publisher_delegated', 'label' => 'Gateway delegated by publisher', 'default_for_first_lane' => false, 'page_owner' => 'gateway'],
        ['key' => 'owner_managed', 'reference_key' => 'owner_managed', 'label' => 'Owner managed', 'default_for_first_lane' => false, 'page_owner' => 'owner'],
    ], static fn(array $mode): bool => in_array((string) ($mode['key'] ?? ''), $enabledModes, true)));
}

function xapps_backend_kit_reference_assets_group(array $reference): array
{
    $endpoints = $reference['referenceAssetEndpoints'] ?? [];
    if (!is_array($endpoints) || count($endpoints) === 0) {
        $endpoints = [
            ['method' => 'GET', 'path' => '/', 'purpose' => 'Entry page for the marketplace host reference.'],
            ['method' => 'GET', 'path' => '/marketplace.html', 'purpose' => 'Marketplace host shell with single-panel and split-panel embed modes.'],
            ['method' => 'GET', 'path' => '/single-xapp.html', 'purpose' => 'Focused single-xapp host surface using the same shared host/runtime contract.'],
            ['method' => 'GET', 'path' => '/embed/sdk/xapps-embed-sdk.esm.js', 'purpose' => 'Serves the embed SDK bundle used by the local reference host surfaces.'],
            ['method' => 'GET', 'path' => '/host/marketplace-host.js', 'purpose' => 'Browser bootstrap for the marketplace host reference.'],
            ['method' => 'GET', 'path' => '/host/single-xapp-host.js', 'purpose' => 'Browser bootstrap for the single-xapp host reference.'],
            ['method' => 'GET', 'path' => '/host/host-shell.js', 'purpose' => 'Repo reference host-shell helpers for marketplace and single-xapp surfaces.'],
            ['method' => 'GET', 'path' => '/host/marketplace-runtime.js', 'purpose' => 'Shared marketplace runtime wiring over the browser SDK contract.'],
            ['method' => 'GET', 'path' => '/host/reference-runtime.js', 'purpose' => 'Repo reference theme/runtime helpers for the standard marketplace host flow.'],
            ['method' => 'GET', 'path' => '/host/host-status.js', 'purpose' => 'Repo reference host proof/status renderer used by tenant host surfaces.'],
        ];
    }

    return [
        'key' => 'reference_assets',
        'label' => 'Reference assets',
        'when_to_use' => 'Only for the current local/reference browser flow.',
        'endpoints' => $endpoints,
    ];
}

function xapps_backend_kit_reference_endpoint_groups(array $capabilities = [], array $reference = []): array
{
    $groups = array_merge([
        [
            'key' => 'core_health',
            'label' => 'Core health',
            'when_to_use' => 'Always present in the reference backend.',
            'endpoints' => [
                ['method' => 'GET', 'path' => '/health', 'purpose' => 'Basic backend health.'],
            ],
        ],
        [
            'key' => 'guard_execution',
            'label' => 'Tenant guard execution reference',
            'when_to_use' => 'Needed when the tenant owns payment-policy or subject-profile policy execution.',
            'endpoints' => [
                [
                    'method' => 'POST',
                    'path' => '/xapps/requests',
                    'purpose' => 'Receives tenant-owned guard/policy tool execution.',
                ],
            ],
        ],
    ], xapps_backend_kit_backend_mode_endpoint_groups($capabilities['enabledModes'] ?? null), [
        [
            'key' => 'tenant_subject_profile_reference',
            'label' => 'Tenant subject-profile reference seam',
            'when_to_use' => 'Optional seam when the tenant wants to provide tenant-owned billing profile candidates.',
            'endpoints' => [
                [
                    'method' => 'POST',
                    'path' => '/guard/subject-profiles/tenant-candidates',
                    'purpose' => 'Returns tenant-owned billing/profile candidates for the guard.',
                ],
            ],
        ],
        xapps_backend_kit_reference_assets_group($reference),
        [
            'key' => 'reference_marketplace_host_core',
            'label' => 'Reference marketplace host proxy: core contract',
            'when_to_use' => 'Use this first. It is the core tenant browser->backend contract for the marketplace host.',
            'endpoints' => [
                ['method' => 'GET', 'path' => '/api/host-config', 'purpose' => 'Returns current host config such as gateway base URL and supported embed modes.'],
                ['method' => 'POST', 'path' => '/api/resolve-subject', 'purpose' => 'Resolves a stable subject id from email for the stateless host bootstrap.'],
                ['method' => 'POST', 'path' => '/api/create-catalog-session', 'purpose' => 'Proxies catalog session creation for the host page.'],
                ['method' => 'POST', 'path' => '/api/catalog-customer-profile', 'purpose' => 'Resolves the default tenant billing profile used to prefill subject-bound catalog sessions.'],
                ['method' => 'POST', 'path' => '/api/create-widget-session', 'purpose' => 'Proxies widget session creation for the host page.'],
            ],
        ],
        [
            'key' => 'reference_marketplace_host_lifecycle',
            'label' => 'Reference marketplace host proxy: lifecycle',
            'when_to_use' => 'Required for a real tenant marketplace host, while still kept as a separate layer for clarity.',
            'endpoints' => [
                ['method' => 'GET', 'path' => '/api/installations', 'purpose' => 'Lists installations for the current subject in the host page.'],
                ['method' => 'POST', 'path' => '/api/install', 'purpose' => 'Install mutation proxy used by the host page.'],
                ['method' => 'POST', 'path' => '/api/update', 'purpose' => 'Update mutation proxy used by the host page.'],
                ['method' => 'POST', 'path' => '/api/uninstall', 'purpose' => 'Uninstall mutation proxy used by the host page.'],
                ['method' => 'GET', 'path' => '/api/my-xapps/:xappId/monetization', 'purpose' => 'Reads current-user app monetization state for the host plans surface.'],
                ['method' => 'GET', 'path' => '/api/my-xapps/:xappId/monetization/history', 'purpose' => 'Reads recent current-user XMS history for purchases, access, wallets, and invoices.'],
                ['method' => 'POST', 'path' => '/api/my-xapps/:xappId/monetization/purchase-intents/prepare', 'purpose' => 'Prepares a current-user purchase intent for the host plans surface.'],
                ['method' => 'POST', 'path' => '/api/my-xapps/:xappId/monetization/purchase-intents/:intentId/payment-session', 'purpose' => 'Creates a hosted payment session for the current-user host plans surface.' ],
                ['method' => 'POST', 'path' => '/api/my-xapps/:xappId/monetization/purchase-intents/:intentId/payment-session/finalize', 'purpose' => 'Finalizes hosted payment return for the current-user host plans surface.'],
            ],
        ],
        [
            'key' => 'reference_marketplace_host_bridge',
            'label' => 'Reference marketplace host proxy: advanced bridge',
            'when_to_use' => 'Add these only when the tenant host needs bridge renewal or advanced signing seams.',
            'endpoints' => [
                ['method' => 'POST', 'path' => '/api/bridge/token-refresh', 'purpose' => 'Bridge v2 token-refresh helper for widget session renewal.'],
                ['method' => 'POST', 'path' => '/api/bridge/sign', 'purpose' => 'Optional bridge signing seam for advanced host integrations.'],
                ['method' => 'POST', 'path' => '/api/bridge/vendor-assertion', 'purpose' => 'Optional vendor assertion seam for advanced linked integrations.'],
            ],
        ],
    ]);

    $filteredStaticGroups = array_values(array_filter($groups, static function (array $group) use ($capabilities): bool {
        if (($group['key'] ?? '') === 'reference_marketplace_host_lifecycle') {
            return ($capabilities['enableLifecycle'] ?? true) !== false;
        }
        if (($group['key'] ?? '') === 'reference_marketplace_host_bridge') {
            return ($capabilities['enableBridge'] ?? true) !== false;
        }
        return !in_array(
            (string) ($group['key'] ?? ''),
            ['gateway_managed_payment_reference', 'tenant_delegated_payment_reference', 'owner_managed_payment_reference'],
            true,
        );
    }));

    $modeGroups = xapps_backend_kit_backend_mode_endpoint_groups($capabilities['enabledModes'] ?? null);
    $guardIndex = -1;
    foreach ($filteredStaticGroups as $index => $group) {
        if (($group['key'] ?? '') === 'guard_execution') {
            $guardIndex = $index;
            break;
        }
    }
    if ($guardIndex === -1) {
        return array_values(array_merge($filteredStaticGroups, $modeGroups));
    }
    return array_values(array_merge(
        array_slice($filteredStaticGroups, 0, $guardIndex + 1),
        $modeGroups,
        array_slice($filteredStaticGroups, $guardIndex + 1),
    ));
}

function xapps_backend_kit_reference_integration_options(array $capabilities = [], array $reference = []): array
{
    $allowedGroupKeys = array_values(array_map(
        static fn(array $group): string => (string) ($group['key'] ?? ''),
        xapps_backend_kit_reference_endpoint_groups($capabilities, $reference),
    ));
    $allowedGroupSet = array_fill_keys(array_filter($allowedGroupKeys), true);

    return array_map(static function (array $option) use ($allowedGroupSet): array {
        $option['reference_endpoint_groups'] = array_values(array_filter(
            is_array($option['reference_endpoint_groups'] ?? null) ? $option['reference_endpoint_groups'] : [],
            static fn($key): bool => isset($allowedGroupSet[(string) $key]),
        ));
        return $option;
    }, [
        [
            'key' => 'lean_managed_first_lane',
            'label' => 'Lean first version',
            'recommended' => true,
            'platform_managed' => ['payments: stripe gateway-managed', 'invoicing', 'notifications'],
            'tenant_must_own' => [
                'tenant identity/bootstrap',
                'core marketplace host proxy contract',
                'marketplace lifecycle routes',
                'payment return signing configuration',
                'guard execution endpoint',
                'tenant configuration for the chosen gateway lane',
            ],
            'reference_endpoint_groups' => [
                'core_health',
                'guard_execution',
                'gateway_managed_payment_reference',
                'reference_marketplace_host_core',
                'reference_marketplace_host_lifecycle',
                'tenant_subject_profile_reference',
            ],
        ],
        [
            'key' => 'tenant_payment_control',
            'label' => 'Tenant payment method / delegated evolution',
            'recommended' => false,
            'platform_managed' => ['invoicing', 'notifications'],
            'tenant_must_own' => [
                'payment lane selection/configuration',
                'core marketplace host proxy contract',
                'marketplace lifecycle routes',
                'payment return signing policy',
                'tenant payment page or equivalent UX if owner-managed mode is selected',
            ],
            'reference_endpoint_groups' => [
                'core_health',
                'guard_execution',
                'tenant_delegated_payment_reference',
                'owner_managed_payment_reference',
                'reference_marketplace_host_core',
                'reference_marketplace_host_lifecycle',
                'reference_marketplace_host_bridge',
                'tenant_subject_profile_reference',
            ],
            'notes' => [
                'Current tenant backend is the plain-PHP reference seam for tenant-controlled payment evolution.',
                'The tenant contract should stay aligned with the Node reference even when the implementation stack changes.',
            ],
        ],
        [
            'key' => 'tenant_own_invoicing',
            'label' => 'Tenant-owned invoicing later',
            'recommended' => false,
            'platform_managed' => [],
            'tenant_must_own' => [
                'tenant invoice provider configuration',
                'invoice execution policy/refs on the gateway lane',
            ],
            'reference_endpoint_groups' => [],
            'notes' => [
                'No dedicated tenant backend endpoint is required in the current lean lane for managed invoicing.',
                'If tenant-owned invoicing is selected later, follow docs/specifications/expansions/09-invoicing-hook-provider-model.md.',
            ],
        ],
        [
            'key' => 'tenant_own_notifications',
            'label' => 'Tenant-owned notifications later',
            'recommended' => false,
            'platform_managed' => [],
            'tenant_must_own' => [
                'tenant notification provider configuration',
                'notification execution policy/refs on the gateway lane',
            ],
            'reference_endpoint_groups' => [],
            'notes' => [
                'No dedicated tenant backend endpoint is required in the current lean lane for managed notifications.',
                'If tenant-owned notifications are selected later, follow docs/specifications/expansions/08-notification-hook-provider-model.md.',
            ],
        ],
    ]);
}

function xapps_backend_kit_register_reference_routes(array &$routes, array $app, array $options = []): void
{
    $capabilities = xapps_backend_kit_reference_capabilities($options);
    if (($capabilities['enableReference'] ?? true) === false) {
        return;
    }
    $reference = xapps_backend_kit_reference_config($options);

    $routes[] = [
        'method' => 'GET',
        'path' => '/api/reference',
        'handler' => static function () use ($app, $capabilities, $reference): void {
            xapps_backend_kit_send_json([
                'ok' => true,
                'tenant' => $reference['tenant'],
                'mode' => $reference['mode'],
                'workspace' => $reference['workspace'],
                'stack' => $reference['stack'],
                'time' => xapps_backend_kit_reference_time_iso(),
                'gateway_url' => $reference['gatewayUrl'] !== '' ? $reference['gatewayUrl'] : ($app['config']['gatewayUrl'] ?? ''),
                ...($reference['displayName'] !== '' ? ['display_name' => $reference['displayName']] : []),
                ...($reference['stackLabel'] !== '' ? ['stack_label' => $reference['stackLabel']] : []),
                'sdk_paths' => $reference['sdkPaths'],
                'host_surfaces' => $reference['hostSurfaces'],
                'payment_modes' => xapps_backend_kit_reference_mode_summary($capabilities['enabledModes'] ?? []),
                'payment_mode_reference_details' => xapps_backend_kit_backend_mode_reference_details($capabilities['enabledModes'] ?? null),
                'tenant_policy_slugs' => $reference['tenantPolicySlugs'],
                'proof_sources' => $reference['proofSources'],
                'endpoint_groups' => xapps_backend_kit_reference_endpoint_groups($capabilities, $reference),
                'integration_options' => xapps_backend_kit_reference_integration_options($capabilities, $reference),
                ...(!empty($reference['notes']) ? ['notes' => $reference['notes']] : []),
            ]);
        },
    ];
}
