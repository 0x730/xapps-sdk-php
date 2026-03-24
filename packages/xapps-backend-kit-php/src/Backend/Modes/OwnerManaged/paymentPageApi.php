<?php

declare(strict_types=1);

use Xapps\BackendKit\BackendKit;

function xapps_backend_kit_register_owner_managed_payment_api_routes(array &$routes, array $app, array $options = []): void
{
    BackendKit::registerPaymentPageApiRoutes($routes, $app, [
        'pathPrefix' => '/api/tenant-payment',
        'gatewayClient' => $options['gatewayClient'] ?? null,
    ], [
        'readRecord' => 'xapps_backend_kit_read_record',
        'readString' => 'xapps_backend_kit_read_string',
        'optionalString' => 'xapps_backend_kit_optional_string',
        'sendJson' => 'xapps_backend_kit_send_json',
        'sendServiceError' => 'xapps_backend_kit_send_service_error',
        'mapHostedSessionResult' => [BackendKit::class, 'mapHostedSessionResult'],
    ]);
}
