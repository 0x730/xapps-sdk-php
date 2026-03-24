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
}
