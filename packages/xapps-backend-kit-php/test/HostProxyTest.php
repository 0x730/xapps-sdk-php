<?php

declare(strict_types=1);

use Xapps\BackendKit\BackendHostProxy;

return [
    [
        'name' => 'host proxy exposes request-scoped installation policy from gateway client self',
        'run' => static function (): void {
            $gateway = new class {
                public function getClientSelf(): array
                {
                    return [
                        'client' => [
                            'id' => 'client_fixture',
                            'installation_policy' => [
                                'mode' => 'auto_available',
                                'update_mode' => 'auto_update_compatible',
                            ],
                        ],
                    ];
                }
            };

            $service = BackendHostProxy::createHostProxyService([
                'gatewayUrl' => 'http://gateway.example.test',
                'gatewayApiKey' => 'gateway-key',
            ], [
                'reference' => [
                    'hostSurfaces' => [
                        ['key' => 'single-panel', 'label' => 'Single panel'],
                    ],
                ],
            ], [
                'createGatewayClient' => static fn (string $baseUrl, string $apiKey): object => $gateway,
                'createEmbedHostProxyService' => static fn (object $gatewayClient, array $hostOptions): object => new class($hostOptions) {
                    public function __construct(private array $hostOptions)
                    {
                    }

                    public function getHostConfigForRequest(): array
                    {
                        $resolver = $this->hostOptions['resolveInstallationPolicy'] ?? null;
                        return [
                            'installationPolicy' => is_callable($resolver) ? $resolver() : null,
                        ];
                    }
                },
            ]);

            $config = $service->getHostConfigForRequest();
            xappsBackendKitPhpAssertSame(
                'auto_available',
                (string) ($config['installationPolicy']['mode'] ?? ''),
                'host config should expose install mode',
            );
            xappsBackendKitPhpAssertSame(
                'auto_update_compatible',
                (string) ($config['installationPolicy']['update_mode'] ?? ''),
                'host config should expose update mode',
            );
        },
    ],
];
