<?php

declare(strict_types=1);

namespace Xapps\BackendKit;

use Xapps\EmbedHostProxyService;
use Xapps\GatewayClient;

final class BackendHostProxy
{
    public static function createPlainPhpApp(array $config, array $normalizedOptions = [], array $deps = []): array
    {
        $createHostProxyService = $deps['createHostProxyService'] ?? null;
        $overrides = BackendSupport::readRecord($normalizedOptions['overrides'] ?? null);
        $hostProxyService = is_object($overrides['hostProxyService'] ?? null)
            ? $overrides['hostProxyService']
            : null;

        if (!$hostProxyService && is_callable($createHostProxyService)) {
            $hostProxyService = $createHostProxyService($config, $normalizedOptions);
        }
        if (!$hostProxyService) {
            $hostProxyService = self::createHostProxyService($config, $normalizedOptions);
        }

        return [
            'config' => $config,
            'hostProxyService' => $hostProxyService,
            'routes' => [],
        ];
    }

    public static function createHostProxyService(array $config, array $normalizedOptions = [], array $deps = []): object
    {
        $createGatewayClient = $deps['createGatewayClient'] ?? null;
        $createEmbedHostProxyService = $deps['createEmbedHostProxyService'] ?? null;
        $createGatewayClient = is_callable($createGatewayClient)
            ? $createGatewayClient
            : static fn (string $baseUrl, string $apiKey): GatewayClient => new GatewayClient($baseUrl, $apiKey, 20);
        $createEmbedHostProxyService = is_callable($createEmbedHostProxyService)
            ? $createEmbedHostProxyService
            : static fn (GatewayClient $gatewayClient, array $hostOptions): EmbedHostProxyService => new EmbedHostProxyService($gatewayClient, $hostOptions);

        $gateway = BackendSupport::readRecord($normalizedOptions['gateway'] ?? null);
        $reference = BackendSupport::readRecord($normalizedOptions['reference'] ?? null);
        $gatewayUrl = trim(BackendSupport::readString($gateway['baseUrl'] ?? null, BackendSupport::readString($config['gatewayUrl'] ?? null)));
        $apiKey = trim(BackendSupport::readString($gateway['apiKey'] ?? null, BackendSupport::readString($config['gatewayApiKey'] ?? null)));
        $hostModes = BackendSupport::normalizeHostModes($reference['hostSurfaces'] ?? null);

        return $createEmbedHostProxyService(
            $createGatewayClient($gatewayUrl, $apiKey),
            [
                'gatewayUrl' => $gatewayUrl,
                'hostModes' => count($hostModes) > 0 ? $hostModes : null,
            ],
        );
    }
}
