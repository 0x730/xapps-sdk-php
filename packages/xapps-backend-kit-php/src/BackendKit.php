<?php

declare(strict_types=1);

namespace Xapps\BackendKit;

use Xapps\EmbedHostProxyService;
use Xapps\GatewayClient;
use Xapps\HostedGatewayPaymentSession;
use Xapps\PaymentHandler;

require_once __DIR__ . '/Backend/Support.php';
require_once __DIR__ . '/Backend/Options.php';
require_once __DIR__ . '/Backend/Runtime.php';
require_once __DIR__ . '/Backend/Modules.php';
require_once __DIR__ . '/Backend/PaymentRuntime.php';
require_once __DIR__ . '/Backend/HostProxy.php';
require_once __DIR__ . '/Backend/Xms.php';

final class BackendKit
{
    private static function readRecord(mixed $value): array
    {
        return BackendSupport::readRecord($value);
    }

    private static function readString(mixed $value, string $fallback = ''): string
    {
        return BackendSupport::readString($value, $fallback);
    }

    private static function readList(mixed $value): array
    {
        return BackendSupport::readList($value);
    }

    /** @return array<int,array{key:string,label:string}> */
    private static function normalizeHostModes(mixed $value): array
    {
        return BackendSupport::normalizeHostModes($value);
    }

    public static function createRequestContext(?array $server = null): array
    {
        return BackendRuntime::createRequestContext($server);
    }

    public static function dispatch(array $app, array $request, array $deps = []): void
    {
        BackendRuntime::dispatch($app, $request, $deps);
    }

    public static function applyGatewayOverrides(array $config, array $gateway = []): array
    {
        return BackendOptions::applyGatewayOverrides($config, $gateway);
    }

    public static function applyPaymentOverrides(array $config, array $payments = []): array
    {
        return BackendOptions::applyPaymentOverrides($config, $payments);
    }

    public static function normalizeOptions(array $options = [], array $deps = []): array
    {
        return BackendOptions::normalizeOptions($options, $deps);
    }

    public static function bootstrap(array $config, array $options = [], array $deps = []): array
    {
        return BackendRuntime::bootstrap($config, $options, $deps);
    }

    public static function attachPaymentRuntime(array $app, array $normalizedOptions, array $deps = []): array
    {
        return BackendPaymentRuntime::attachPaymentRuntime($app, $normalizedOptions, $deps);
    }

    public static function createPlainPhpApp(array $config, array $normalizedOptions = [], array $deps = []): array
    {
        return BackendHostProxy::createPlainPhpApp($config, $normalizedOptions, $deps);
    }

    public static function attachBackendOptions(array $app, array $normalizedOptions): array
    {
        return BackendOptions::attachBackendOptions($app, $normalizedOptions);
    }

    public static function paymentReturnAllowlist(array $config): array
    {
        return BackendOptions::paymentReturnAllowlist($config);
    }

    public static function createPaymentHandler(array $config, ?object $gatewayClient = null, array $deps = []): PaymentHandler
    {
        return BackendPaymentRuntime::createPaymentHandler($config, $gatewayClient, $deps);
    }

    public static function verifyPaymentEvidence(
        array $app,
        array $payload,
        array $expected,
        int $maxAgeSeconds,
        array $deps = [],
    ): array {
        return BackendPaymentRuntime::verifyPaymentEvidence($app, $payload, $expected, $maxAgeSeconds, $deps);
    }

    public static function buildHostedGatewayPaymentUrl(array $app, array $input, array $deps = []): array
    {
        return BackendPaymentRuntime::buildHostedGatewayPaymentUrl($app, $input, $deps);
    }

    public static function buildModeHostedGatewayPaymentUrl(
        array $app,
        array $input,
        array $modeMeta = [],
        array $deps = [],
    ): array {
        return BackendPaymentRuntime::buildModeHostedGatewayPaymentUrl($app, $input, $modeMeta, $deps);
    }

    public static function startXappHostedPurchase(object $gatewayClient, array $input): array
    {
        return BackendXms::startXappHostedPurchase($gatewayClient, $input);
    }

    public static function readXappMonetizationSnapshot(object $gatewayClient, array $input): array
    {
        return BackendXms::readXappMonetizationSnapshot($gatewayClient, $input);
    }

    public static function buildXappMonetizationReferenceSummary(array $input): array
    {
        return BackendXms::buildXappMonetizationReferenceSummary($input);
    }

    public static function consumeXappWalletCredits(object $gatewayClient, array $input): array
    {
        return BackendXms::consumeXappWalletCredits($gatewayClient, $input);
    }

    public static function normalizeXappMonetizationScopeKind(mixed $value): string
    {
        return BackendXms::normalizeXappMonetizationScopeKind($value);
    }

    public static function resolveXappMonetizationScope(array $input): array
    {
        return BackendXms::resolveXappMonetizationScope($input);
    }

    public static function resolveXappHostedPaymentDefinition(array $input): array
    {
        return BackendXms::resolveXappHostedPaymentDefinition($input);
    }

    public static function listXappHostedPaymentPresets(array $input): array
    {
        return BackendXms::listXappHostedPaymentPresets($input);
    }

    public static function findXappHostedPaymentPreset(array $input): ?array
    {
        return BackendXms::findXappHostedPaymentPreset($input);
    }

    public static function finalizeXappHostedPurchase(object $gatewayClient, array $input): array
    {
        return BackendXms::finalizeXappHostedPurchase($gatewayClient, $input);
    }

    public static function activateXappPurchaseReference(object $gatewayClient, array $input): array
    {
        return BackendXms::activateXappPurchaseReference($gatewayClient, $input);
    }

    public static function registerPaymentPageApiRoutes(
        array &$routes,
        array $app,
        array $options = [],
        array $deps = [],
    ): void {
        BackendPaymentRuntime::registerPaymentPageApiRoutes($routes, $app, $options, $deps);
    }

    public static function createHostProxyService(array $config, array $normalizedOptions = [], array $deps = []): object
    {
        return BackendHostProxy::createHostProxyService($config, $normalizedOptions, $deps);
    }

    public static function verifyBrowserWidgetContext(object $gatewayClient, array $input): array
    {
        if (!method_exists($gatewayClient, 'verifyBrowserWidgetContext')) {
            throw new \InvalidArgumentException('gatewayClient must implement verifyBrowserWidgetContext');
        }
        /** @var callable(array<string,mixed>):array<string,mixed> $callable */
        $callable = [$gatewayClient, 'verifyBrowserWidgetContext'];
        return $callable($input);
    }

    public static function normalizeWidgetBootstrapAllowedOrigins(mixed $value): array
    {
        $entries = self::readList($value);
        $normalized = [];
        foreach ($entries as $entry) {
            $origin = self::normalizeOrigin(self::readString($entry));
            if ($origin === '') {
                continue;
            }
            $normalized[$origin] = true;
        }

        return array_keys($normalized);
    }

    public static function evaluateWidgetBootstrapOriginPolicy(array $input): array
    {
        $hostOrigin = self::normalizeOrigin(self::readString($input['hostOrigin'] ?? $input['host_origin'] ?? ''));
        $allowedOrigins = self::normalizeWidgetBootstrapAllowedOrigins($input['allowedOrigins'] ?? $input['allowed_origins'] ?? []);

        if ($hostOrigin === '') {
            return [
                'ok' => false,
                'code' => 'HOST_ORIGIN_REQUIRED',
                'message' => 'Host origin is required to verify browser widget context',
                'hostOrigin' => null,
                'allowedOrigins' => $allowedOrigins,
            ];
        }

        if ($allowedOrigins !== [] && !in_array($hostOrigin, $allowedOrigins, true)) {
            return [
                'ok' => false,
                'code' => 'HOST_ORIGIN_NOT_ALLOWED',
                'message' => 'Host origin is not allowed for widget bootstrap verification',
                'hostOrigin' => $hostOrigin,
                'allowedOrigins' => $allowedOrigins,
            ];
        }

        return [
            'ok' => true,
            'hostOrigin' => $hostOrigin,
            'allowedOrigins' => $allowedOrigins,
        ];
    }

    public static function registerHostReferenceModuleRoutes(array &$routes, array $app, array $options = [], array $deps = []): void
    {
        BackendModules::registerHostReferenceModuleRoutes($routes, $app, $options, $deps);
    }

    public static function registerGatewayExecutionModuleRoutes(array &$routes, array $app, array $options = [], array $deps = []): void
    {
        BackendModules::registerGatewayExecutionModuleRoutes($routes, $app, $options, $deps);
    }

    public static function registerReferenceSurfaceModuleRoutes(array &$routes, array $app, array $options = [], array $deps = []): void
    {
        BackendModules::registerReferenceSurfaceModuleRoutes($routes, $app, $options, $deps);
    }

    public static function registerBackendKitRoutes(array &$routes, array $app, array $deps = []): void
    {
        BackendModules::registerBackendKitRoutes($routes, $app, $deps);
    }

    public static function mapHostedSessionResult(array $input): array
    {
        return BackendPaymentRuntime::mapHostedSessionResult($input);
    }

    private static function normalizeOrigin(string $value): string
    {
        $raw = trim($value);
        if ($raw === '') {
            return '';
        }

        $parts = parse_url($raw);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme === '' || $host === '') {
            return '';
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $isDefaultPort =
            $port === null ||
            ($scheme === 'https' && $port === 443) ||
            ($scheme === 'http' && $port === 80);

        return $scheme . '://' . $host . ($isDefaultPort ? '' : ':' . $port);
    }
}
