<?php

declare(strict_types=1);

function xapps_backend_kit_gateway_managed_payment_mode_metadata(): array
{
    return [
        'expectedIssuer' => 'gateway',
        'fallbackIssuer' => 'gateway',
        'storedIssuer' => 'gateway',
        'paymentMode' => 'gateway_managed',
        'referenceMode' => null,
        'allowDefaultSecretFallback' => false,
    ];
}

function xapps_backend_kit_gateway_managed_payment_reference_details(): array
{
    return [
        'key' => 'gateway_managed',
        'label' => 'Gateway managed',
        'description' => 'Gateway owns hosted checkout and provider execution. Tenant mainly consumes the lane.',
        'payment_session_model' => [
            'uses_gateway_payment_session' => true,
            'tenant_owns_payment_page' => false,
            'tenant_owns_payment_session_endpoints' => false,
            'tenant_calls_gateway_payment_session_api_directly' => false,
            'tenant_session_reference' => 'gateway-hosted session only',
        ],
        'required_settings' => [
            'GUARD_INGEST_API_KEY',
            'TENANT_PAYMENT_RETURN_SECRET or TENANT_PAYMENT_RETURN_SECRET_REF',
        ],
        'payment_responsibilities' => [
            'keep gateway-managed payment lane configured',
            'do not implement a tenant payment page for this mode',
            'reuse gateway-hosted checkout returned by the platform',
        ],
        'payment_endpoints' => [],
        'guide_path' => 'packages/xapps-backend-kit-php/src/Backend/Modes/GatewayManaged/payment.php',
    ];
}

function xapps_backend_kit_gateway_managed_reference_endpoint_group(): array
{
    return [
        'key' => 'gateway_managed_payment_reference',
        'label' => 'Gateway-managed payment reference',
        'when_to_use' => 'Use this when the tenant keeps the first-lane managed payment flow and the gateway owns checkout orchestration.',
        'endpoints' => [],
        'notes' => [
            'Existing xapps use the gateway-hosted payment page for this mode.',
            'The tenant backend participates through guard execution and tenant configuration, not through a tenant payment page.',
        ],
    ];
}

function xapps_backend_kit_gateway_managed_register_routes(array &$routes, array $app, array $options = []): void
{
    unset($routes, $app, $options);
}
