<?php

declare(strict_types=1);

function xapps_backend_kit_tenant_delegated_payment_mode_metadata(): array
{
    return [
        'expectedIssuer' => 'tenant_delegated',
        'fallbackIssuer' => 'tenant_delegated',
        'storedIssuer' => 'tenant_delegated',
        'paymentMode' => 'tenant_delegated',
        'referenceMode' => null,
        'allowDefaultSecretFallback' => false,
    ];
}

function xapps_backend_kit_tenant_delegated_payment_reference_details(): array
{
    return [
        'key' => 'tenant_delegated',
        'label' => 'Gateway delegated by tenant',
        'description' => 'Gateway executes checkout with tenant-scoped delegated credentials and delegated signing.',
        'payment_session_model' => [
            'uses_gateway_payment_session' => true,
            'tenant_owns_payment_page' => false,
            'tenant_owns_payment_session_endpoints' => false,
            'tenant_calls_gateway_payment_session_api_directly' => false,
            'tenant_session_reference' => 'gateway-hosted delegated session only',
        ],
        'required_settings' => [
            'GUARD_INGEST_API_KEY',
            'delegated payment credential refs in the manifest/guard config',
            'TENANT_PAYMENT_RETURN_SECRET or TENANT_PAYMENT_RETURN_SECRET_REF',
        ],
        'payment_responsibilities' => [
            'keep tenant-delegated payment lane configured',
            'provide delegated credential/signing configuration',
            'reuse gateway-hosted checkout returned by the platform',
        ],
        'payment_endpoints' => [],
        'guide_path' => 'packages/xapps-backend-kit-php/src/Backend/Modes/TenantDelegated/payment.php',
    ];
}

function xapps_backend_kit_tenant_delegated_reference_endpoint_group(): array
{
    return [
        'key' => 'tenant_delegated_payment_reference',
        'label' => 'Tenant-delegated payment reference',
        'when_to_use' => 'Use this when checkout is still gateway-hosted but tenant delegated credentials/signing are required.',
        'endpoints' => [],
        'notes' => [
            'Existing xapps keep the gateway-hosted payment page for this mode too.',
            'The tenant backend participates through guard execution and delegated tenant configuration.',
        ],
    ];
}

function xapps_backend_kit_tenant_delegated_register_routes(array &$routes, array $app, array $options = []): void
{
    unset($routes, $app, $options);
}
