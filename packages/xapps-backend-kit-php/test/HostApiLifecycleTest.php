<?php

declare(strict_types=1);

return [
    [
        'name' => 'hostApi registers current-user monetization lifecycle routes and preflights',
        'run' => static function (): void {
            $routes = [];
            $service = new class {
                public function getMyXappMonetization(array $input): array
                {
                    return ['ok' => true, 'input' => $input];
                }

                public function getMyXappMonetizationHistory(array $input): array
                {
                    return ['ok' => true, 'input' => $input];
                }

                public function runWidgetToolRequest(array $input): array
                {
                    return ['ok' => true, 'input' => $input];
                }

                public function prepareMyXappPurchaseIntent(array $input): array
                {
                    return ['ok' => true, 'input' => $input];
                }

                public function createMyXappPurchasePaymentSession(array $input): array
                {
                    return ['ok' => true, 'input' => $input];
                }

                public function finalizeMyXappPurchasePaymentSession(array $input): array
                {
                    return ['ok' => true, 'input' => $input];
                }
            };

            xapps_backend_kit_register_host_api_routes($routes, [
                'hostProxyService' => $service,
            ], [
                'allowedOrigins' => ['https://host.example.test'],
                'enableLifecycle' => true,
                'enableBridge' => false,
            ]);

            $paths = [
                '/api/widget-tool-request',
                '/api/my-xapps/:xappId/monetization',
                '/api/my-xapps/:xappId/monetization/history',
                '/api/my-xapps/:xappId/monetization/purchase-intents/prepare',
                '/api/my-xapps/:xappId/monetization/purchase-intents/:intentId/payment-session',
                '/api/my-xapps/:xappId/monetization/purchase-intents/:intentId/payment-session/finalize',
            ];
            foreach ($paths as $path) {
                $route = xappsBackendKitPhpFindRoute($routes, 'OPTIONS', $path);
                xappsBackendKitPhpAssertTrue(is_callable($route['handler'] ?? null), 'Missing OPTIONS handler for ' . $path);
            }
        },
    ],
    [
        'name' => 'hostApi monetization lifecycle handlers proxy expected payloads',
        'run' => static function (): void {
            $routes = [];
            $service = new class {
                public array $calls = [];

                public function getMyXappMonetization(array $input): array
                {
                    $this->calls[] = ['method' => 'get', 'input' => $input];
                    return [
                        'xapp_id' => $input['xappId'] ?? null,
                        'access' => ['state' => 'active'],
                    ];
                }

                public function getMyXappMonetizationHistory(array $input): array
                {
                    $this->calls[] = ['method' => 'history', 'input' => $input];
                    return [
                        'xapp_id' => $input['xappId'] ?? null,
                        'history' => [
                            'purchase_intents' => [
                                'total' => 1,
                                'items' => [
                                    ['id' => 'intent_123'],
                                ],
                            ],
                        ],
                    ];
                }

                public function runWidgetToolRequest(array $input): array
                {
                    $this->calls[] = ['method' => 'widget_tool', 'input' => $input];
                    return [
                        'profile_id' => 'profile_fixture_1',
                        'source' => $input['payload']['source'] ?? null,
                    ];
                }

                public function prepareMyXappPurchaseIntent(array $input): array
                {
                    $this->calls[] = ['method' => 'prepare', 'input' => $input];
                    return [
                        'prepared_intent' => [
                            'purchase_intent_id' => 'intent_123',
                        ],
                    ];
                }

                public function createMyXappPurchasePaymentSession(array $input): array
                {
                    $this->calls[] = ['method' => 'payment_session', 'input' => $input];
                    return [
                        'payment_session' => [
                            'payment_session_id' => 'pay_123',
                        ],
                    ];
                }

                public function finalizeMyXappPurchasePaymentSession(array $input): array
                {
                    $this->calls[] = ['method' => 'finalize', 'input' => $input];
                    return [
                        'transaction' => [
                            'status' => 'completed',
                        ],
                    ];
                }
            };

            xapps_backend_kit_register_host_api_lifecycle($routes, [
                'hostProxyService' => $service,
            ], ['https://host.example.test'], []);

            $getRoute = xappsBackendKitPhpFindRoute($routes, 'GET', '/api/my-xapps/:xappId/monetization');
            $getResponse = xappsBackendKitPhpInvokeRoute($getRoute['handler'], [
                'headers' => ['origin' => 'https://host.example.test'],
                'params' => ['xappId' => 'xapp_123'],
                'query' => [
                    'token' => 'tok_123',
                    'installationId' => 'inst_123',
                    'locale' => 'ro',
                    'country' => 'RO',
                    'realmRef' => 'realm_123',
                ],
            ]);
            xappsBackendKitPhpAssertSame(200, $getResponse['status'], 'GET monetization should return 200');
            xappsBackendKitPhpAssertSame('xapp_123', $getResponse['body']['xapp_id'] ?? null, 'GET monetization should return xapp id');
            xappsBackendKitPhpAssertSame([
                'method' => 'get',
                'input' => [
                    'xappId' => 'xapp_123',
                    'token' => 'tok_123',
                    'installationId' => 'inst_123',
                    'locale' => 'ro',
                    'country' => 'RO',
                    'realmRef' => 'realm_123',
                ],
            ], $service->calls[0] ?? null, 'GET monetization should proxy expected payload');

            $historyRoute = xappsBackendKitPhpFindRoute($routes, 'GET', '/api/my-xapps/:xappId/monetization/history');
            $historyResponse = xappsBackendKitPhpInvokeRoute($historyRoute['handler'], [
                'headers' => ['origin' => 'https://host.example.test'],
                'params' => ['xappId' => 'xapp_123'],
                'query' => [
                    'token' => 'tok_123',
                    'installationId' => 'inst_123',
                    'limit' => '8',
                ],
            ]);
            xappsBackendKitPhpAssertSame(200, $historyResponse['status'], 'GET history should return 200');
            xappsBackendKitPhpAssertSame('intent_123', $historyResponse['body']['history']['purchase_intents']['items'][0]['id'] ?? null, 'GET history should return purchase intent');
            xappsBackendKitPhpAssertSame([
                'method' => 'history',
                'input' => [
                    'xappId' => 'xapp_123',
                    'token' => 'tok_123',
                    'limit' => '8',
                ],
            ], $service->calls[1] ?? null, 'GET history should proxy expected payload');

            $widgetToolRoute = xappsBackendKitPhpFindRoute($routes, 'POST', '/api/widget-tool-request');
            $widgetToolResponse = xappsBackendKitPhpInvokeRoute($widgetToolRoute['handler'], [
                'headers' => ['origin' => 'https://host.example.test'],
                'body' => [
                    'token' => 'tok_123',
                    'installationId' => 'inst_123',
                    'toolName' => 'complete_subject_profile',
                    'payload' => ['source' => 'subject_self_profile'],
                ],
            ]);
            xappsBackendKitPhpAssertSame(200, $widgetToolResponse['status'], 'Widget tool route should return 200');
            xappsBackendKitPhpAssertSame('profile_fixture_1', $widgetToolResponse['body']['profile_id'] ?? null, 'Widget tool route should return result');
            xappsBackendKitPhpAssertSame([
                'method' => 'widget_tool',
                'input' => [
                    'token' => 'tok_123',
                    'installationId' => 'inst_123',
                    'toolName' => 'complete_subject_profile',
                    'payload' => ['source' => 'subject_self_profile'],
                ],
            ], $service->calls[2] ?? null, 'Widget tool route should proxy expected payload');

            $prepareRoute = xappsBackendKitPhpFindRoute($routes, 'POST', '/api/my-xapps/:xappId/monetization/purchase-intents/prepare');
            $prepareResponse = xappsBackendKitPhpInvokeRoute($prepareRoute['handler'], [
                'headers' => ['origin' => 'https://host.example.test'],
                'params' => ['xappId' => 'xapp_123'],
                'query' => ['token' => 'tok_123'],
                'body' => [
                    'offeringId' => 'offer_123',
                    'packageId' => 'pkg_123',
                    'priceId' => 'price_123',
                    'installationId' => 'inst_123',
                    'locale' => 'ro',
                    'country' => 'RO',
                ],
            ]);
            xappsBackendKitPhpAssertSame(200, $prepareResponse['status'], 'Prepare should return 200');
            xappsBackendKitPhpAssertSame('intent_123', $prepareResponse['body']['prepared_intent']['purchase_intent_id'] ?? null, 'Prepare should return purchase intent');
            xappsBackendKitPhpAssertSame([
                'method' => 'prepare',
                'input' => [
                    'xappId' => 'xapp_123',
                    'token' => 'tok_123',
                    'offeringId' => 'offer_123',
                    'packageId' => 'pkg_123',
                    'priceId' => 'price_123',
                    'installationId' => 'inst_123',
                    'locale' => 'ro',
                    'country' => 'RO',
                ],
            ], $service->calls[3] ?? null, 'Prepare should proxy expected payload');

            $paymentRoute = xappsBackendKitPhpFindRoute($routes, 'POST', '/api/my-xapps/:xappId/monetization/purchase-intents/:intentId/payment-session');
            $paymentResponse = xappsBackendKitPhpInvokeRoute($paymentRoute['handler'], [
                'headers' => ['origin' => 'https://host.example.test'],
                'params' => ['xappId' => 'xapp_123', 'intentId' => 'intent_123'],
                'query' => ['token' => 'tok_123'],
                'body' => [
                    'returnUrl' => 'https://host.example.test/return',
                    'cancelUrl' => 'https://host.example.test/cancel',
                    'xappsResume' => 'https://host.example.test/resume',
                    'locale' => 'ro',
                    'installationId' => 'inst_123',
                    'paymentGuardRef' => 'guard_123',
                    'issuer' => 'gateway',
                    'scheme' => 'card',
                    'paymentScheme' => 'stripe',
                    'metadata' => ['source' => 'test'],
                ],
            ]);
            xappsBackendKitPhpAssertSame(200, $paymentResponse['status'], 'Payment-session should return 200');
            xappsBackendKitPhpAssertSame('pay_123', $paymentResponse['body']['payment_session']['payment_session_id'] ?? null, 'Payment-session should return session id');
            xappsBackendKitPhpAssertSame([
                'method' => 'payment_session',
                'input' => [
                    'xappId' => 'xapp_123',
                    'intentId' => 'intent_123',
                    'token' => 'tok_123',
                    'returnUrl' => 'https://host.example.test/return',
                    'cancelUrl' => 'https://host.example.test/cancel',
                    'xappsResume' => 'https://host.example.test/resume',
                    'locale' => 'ro',
                    'installationId' => 'inst_123',
                    'paymentGuardRef' => 'guard_123',
                    'issuer' => 'gateway',
                    'scheme' => 'card',
                    'paymentScheme' => 'stripe',
                    'metadata' => ['source' => 'test'],
                ],
            ], $service->calls[4] ?? null, 'Payment-session should proxy expected payload');

            $finalizeRoute = xappsBackendKitPhpFindRoute($routes, 'POST', '/api/my-xapps/:xappId/monetization/purchase-intents/:intentId/payment-session/finalize');
            $finalizeResponse = xappsBackendKitPhpInvokeRoute($finalizeRoute['handler'], [
                'headers' => ['origin' => 'https://host.example.test'],
                'params' => ['xappId' => 'xapp_123', 'intentId' => 'intent_123'],
                'query' => ['token' => 'tok_123'],
                'body' => [],
            ]);
            xappsBackendKitPhpAssertSame(200, $finalizeResponse['status'], 'Finalize should return 200');
            xappsBackendKitPhpAssertSame('completed', $finalizeResponse['body']['transaction']['status'] ?? null, 'Finalize should return completed transaction');
            xappsBackendKitPhpAssertSame([
                'method' => 'finalize',
                'input' => [
                    'xappId' => 'xapp_123',
                    'intentId' => 'intent_123',
                    'token' => 'tok_123',
                ],
            ], $service->calls[5] ?? null, 'Finalize should proxy expected payload');
        },
    ],
    [
        'name' => 'hostApi monetization lifecycle rejects disallowed origins',
        'run' => static function (): void {
            $routes = [];
            $service = new class {
                public function getMyXappMonetization(array $input): array
                {
                    return ['xapp_id' => $input['xappId'] ?? null];
                }

                public function getMyXappMonetizationHistory(array $input): array
                {
                    return ['xapp_id' => $input['xappId'] ?? null];
                }
            };

            xapps_backend_kit_register_host_api_lifecycle($routes, [
                'hostProxyService' => $service,
            ], ['https://host.example.test'], []);

            $route = xappsBackendKitPhpFindRoute($routes, 'GET', '/api/my-xapps/:xappId/monetization');
            $response = xappsBackendKitPhpInvokeRoute($route['handler'], [
                'headers' => ['origin' => 'https://evil.example.test'],
                'params' => ['xappId' => 'xapp_123'],
                'query' => ['token' => 'tok_123'],
            ]);

            xappsBackendKitPhpAssertSame(403, $response['status'], 'Disallowed origin should return 403');
            xappsBackendKitPhpAssertSame('Origin is not allowed', $response['body']['message'] ?? null, 'Disallowed origin should return expected message');
        },
    ],
];
