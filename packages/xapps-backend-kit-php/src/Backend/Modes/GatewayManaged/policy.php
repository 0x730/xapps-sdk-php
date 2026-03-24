<?php

declare(strict_types=1);

function xapps_backend_kit_gateway_managed_policy_reference_details(): array
{
    return [
        'policy_responsibilities' => [
            'verify signed payment evidence for the gateway-managed lane',
            'block and return the gateway-hosted payment action when evidence is missing',
        ],
        'policy_endpoints' => [
            [
                'method' => 'POST',
                'path' => '/xapps/requests',
                'purpose' => 'Runs the tenant payment policy for gateway-managed xapps.',
            ],
        ],
    ];
}

function xapps_backend_kit_gateway_managed_resolve_policy_request(array $payload, array $app): array
{
    $input = xapps_backend_kit_build_payment_policy_input(
        $payload,
        $app,
        xapps_backend_kit_gateway_managed_payment_mode_metadata(),
    );
    if (($input['paidByVerifiedEvidence'] ?? false) && !is_array($input['verificationFailure'] ?? null)) {
        return xapps_backend_kit_build_payment_policy_allowed_result($input);
    }
    return xapps_backend_kit_build_payment_policy_blocked_result($input, $app);
}
