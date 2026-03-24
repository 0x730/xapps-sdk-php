<?php

declare(strict_types=1);

function xapps_backend_kit_tenant_delegated_policy_reference_details(): array
{
    return [
        'policy_responsibilities' => [
            'verify signed payment evidence for the tenant-delegated lane',
            'block and return the delegated gateway-hosted payment action when evidence is missing',
        ],
        'policy_endpoints' => [
            [
                'method' => 'POST',
                'path' => '/xapps/requests',
                'purpose' => 'Runs the tenant payment policy for tenant-delegated xapps.',
            ],
        ],
    ];
}

function xapps_backend_kit_tenant_delegated_resolve_policy_request(array $payload, array $app): array
{
    $input = xapps_backend_kit_build_payment_policy_input(
        $payload,
        $app,
        xapps_backend_kit_tenant_delegated_payment_mode_metadata(),
    );
    if (($input['paidByVerifiedEvidence'] ?? false) && !is_array($input['verificationFailure'] ?? null)) {
        return xapps_backend_kit_build_payment_policy_allowed_result($input);
    }
    return xapps_backend_kit_build_payment_policy_blocked_result($input, $app);
}
