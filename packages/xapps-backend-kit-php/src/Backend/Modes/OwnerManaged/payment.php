<?php

declare(strict_types=1);

function xapps_backend_kit_owner_managed_payment_mode_metadata(): array
{
    $ownerIssuer = 'tenant';
    if (func_num_args() > 0 && is_array(func_get_arg(0))) {
        $app = func_get_arg(0);
        $runtime = xapps_backend_kit_read_record($app['paymentRuntimeOptions'] ?? null);
        $paymentSettings = xapps_backend_kit_read_record($runtime['paymentSettings'] ?? null);
        $paymentOptions = xapps_backend_kit_read_record($app['paymentOptions'] ?? null);
        $ownerIssuer = \Xapps\BackendKit\BackendOptions::normalizeOwnerIssuer(
            $paymentSettings['ownerIssuer'] ?? null,
            xapps_backend_kit_read_string($paymentOptions['ownerIssuer'] ?? null, 'tenant'),
        );
    }
    return [
        'expectedIssuer' => $ownerIssuer,
        'fallbackIssuer' => $ownerIssuer,
        'storedIssuer' => $ownerIssuer,
        'paymentMode' => 'owner_managed',
        'referenceMode' => 'owner_managed',
        'allowDefaultSecretFallback' => true,
    ];
}

function xapps_backend_kit_owner_managed_payment_reference_details(): array
{
    return [
        'key' => 'owner_managed',
        'reference_key' => 'owner_managed',
        'label' => 'Owner managed',
        'description' => 'The owner controls the payment page flow. The packaged sample payment page is the default reference seam for this path.',
        'payment_session_model' => [
            'uses_gateway_payment_session' => true,
            'tenant_owns_payment_page' => true,
            'tenant_owns_payment_session_endpoints' => true,
            'tenant_calls_gateway_payment_session_api_directly' => true,
            'tenant_session_reference' => 'tenant page proxies gateway session through /api/tenant-payment/*',
        ],
        'required_settings' => [
            'XCONECTB_GUARD_INGEST_API_KEY',
            'XCONECTB_TENANT_PAYMENT_URL',
            'XCONECTB_TENANT_PAYMENT_RETURN_SECRET or XCONECTB_TENANT_PAYMENT_RETURN_SECRET_REF',
        ],
        'payment_responsibilities' => [
            'serve the tenant payment page',
            'implement tenant payment session endpoints',
            'complete/client-settle the hosted gateway session from the tenant page',
        ],
        'payment_endpoints' => [
            [
                'method' => 'GET',
                'path' => '/tenant-payment.html',
                'purpose' => 'Serves the tenant-owned payment page.',
            ],
            [
                'method' => 'GET',
                'path' => '/api/tenant-payment/session',
                'purpose' => 'Loads the current hosted gateway session for the tenant page.',
            ],
            [
                'method' => 'POST',
                'path' => '/api/tenant-payment/complete',
                'purpose' => 'Advances the hosted gateway session from the tenant page.',
            ],
            [
                'method' => 'POST',
                'path' => '/api/tenant-payment/client-settle',
                'purpose' => 'Fallback client settlement for the tenant page flow.',
            ],
        ],
        'guide_path' => 'packages/xapps-backend-kit-php/src/Backend/Modes/OwnerManaged/payment.php',
    ];
}

function xapps_backend_kit_owner_managed_reference_endpoint_group(): array
{
    return [
        'key' => 'owner_managed_payment_reference',
        'label' => 'Owner-managed payment page reference',
        'when_to_use' => 'Use this when the owner keeps the owner-managed lane and the packaged sample payment page is used.',
        'endpoints' => xapps_backend_kit_owner_managed_payment_reference_details()['payment_endpoints'],
    ];
}

function xapps_backend_kit_owner_managed_register_routes(array &$routes, array $app, array $options = []): void
{
    xapps_backend_kit_register_owner_managed_payment_api_routes($routes, $app, $options);
}
