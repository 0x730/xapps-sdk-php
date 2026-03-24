<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/index.php';

use Xapps\GatewayClient;
use Xapps\ManagedGatewayPaymentSession;

function envOrDefault(string $name, string $default): string
{
    $value = getenv($name);
    if ($value === false) {
        return $default;
    }
    $trimmed = trim((string) $value);
    return $trimmed !== '' ? $trimmed : $default;
}

$gateway = new GatewayClient(
    envOrDefault('XAPPS_GATEWAY_URL', 'http://localhost:3000'),
    envOrDefault('XAPPS_PUBLISHER_API_KEY', 'xapps_test_publisher_key_123456789'),
);

$guardConfig = [
    'payment_scheme' => 'stripe',
    'accepts' => [
        ['scheme' => 'mock_manual', 'label' => 'Mock Hosted Redirect'],
    ],
    'payment_ui' => [
        'brand' => [
            'name' => 'Publisher Example',
            'accent' => '#635bff',
        ],
        'schemes' => [
            [
                'scheme' => 'stripe',
                'title' => 'Pay with Stripe',
                'subtitle' => 'Secure card checkout hosted by Stripe.',
                'cta_label' => 'Continue to Stripe',
            ],
            [
                'scheme' => 'mock_manual',
                'title' => 'Hosted Checkout Test',
                'subtitle' => 'Provider-style redirect and signed return flow.',
                'cta_label' => 'Continue to Checkout',
            ],
        ],
    ],
];

$input = ManagedGatewayPaymentSession::buildManagedGatewayPaymentSessionInput([
    'source' => 'publisher-backend',
    'guardSlug' => 'publisher-payment-policy',
    'guardConfig' => $guardConfig,
    'xappId' => envOrDefault('XAPPS_XAPP_ID', '01ABCEXAMPLE'),
    'toolName' => envOrDefault('XAPPS_TOOL_NAME', 'submit_form'),
    'amount' => envOrDefault('XAPPS_PAYMENT_AMOUNT', '3.00'),
    'currency' => envOrDefault('XAPPS_PAYMENT_CURRENCY', 'USD'),
    'paymentIssuer' => 'gateway',
    'paymentScheme' => 'stripe',
    'returnUrl' => envOrDefault('XAPPS_RETURN_URL', 'https://publisher.example.test/host'),
    'cancelUrl' => envOrDefault('XAPPS_CANCEL_URL', 'https://publisher.example.test/host'),
    'subjectId' => envOrDefault('XAPPS_SUBJECT_ID', 'subj_example'),
    'installationId' => envOrDefault('XAPPS_INSTALLATION_ID', 'inst_example'),
    'clientId' => envOrDefault('XAPPS_CLIENT_ID', 'client_example'),
]);

echo "[publisher managed-lane] createPaymentSession input\n";
echo json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

$created = $gateway->createPaymentSession($input);
echo "[publisher managed-lane] created session\n";
echo json_encode($created, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
