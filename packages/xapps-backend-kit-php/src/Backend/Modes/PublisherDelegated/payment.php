<?php

declare(strict_types=1);

function xapps_backend_kit_publisher_delegated_payment_mode_metadata(): array
{
    return [
        'expectedIssuer' => 'publisher_delegated',
        'fallbackIssuer' => 'publisher_delegated',
        'storedIssuer' => 'publisher_delegated',
        'paymentMode' => 'publisher_delegated',
        'referenceMode' => null,
        'allowDefaultSecretFallback' => false,
    ];
}

function xapps_backend_kit_publisher_delegated_payment_reference_details(): array
{
    return [
        'key' => 'publisher_delegated',
        'label' => 'Gateway delegated by publisher',
        'description' => 'Gateway executes checkout with publisher-scoped delegated credentials and delegated signing.',
        'payment_session_model' => [
            'uses_gateway_payment_session' => true,
            'tenant_owns_payment_page' => false,
            'tenant_owns_payment_session_endpoints' => false,
            'tenant_calls_gateway_payment_session_api_directly' => false,
            'tenant_session_reference' => 'gateway-hosted delegated session only',
        ],
        'required_settings' => [
            'backend guard ingest API key',
            'delegated publisher payment credential refs in the manifest/guard config',
            'publisher delegated payment return secret or secret ref',
        ],
        'payment_responsibilities' => [
            'keep publisher-delegated payment lane configured',
            'provide delegated publisher credential/signing configuration',
            'reuse gateway-hosted checkout returned by the platform',
        ],
        'payment_endpoints' => [],
        'guide_path' => 'packages/xapps-backend-kit-php/src/Backend/Modes/PublisherDelegated/payment.php',
    ];
}

function xapps_backend_kit_publisher_delegated_reference_endpoint_group(): array
{
    return [
        'key' => 'publisher_delegated_payment_reference',
        'label' => 'Publisher-delegated payment reference',
        'when_to_use' => 'Use this when checkout is still gateway-hosted but publisher delegated credentials/signing are required.',
        'endpoints' => [],
        'notes' => [
            'Existing xapps keep the gateway-hosted payment page for this mode too.',
            'The backend participates through guard execution and delegated publisher configuration.',
        ],
    ];
}

function xapps_backend_kit_publisher_delegated_register_routes(array &$routes, array $app, array $options = []): void
{
    unset($routes, $app, $options);
}
