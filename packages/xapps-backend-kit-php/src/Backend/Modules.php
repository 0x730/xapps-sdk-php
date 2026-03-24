<?php

declare(strict_types=1);

namespace Xapps\BackendKit;

final class BackendModules
{
    public static function registerHostReferenceModuleRoutes(array &$routes, array $app, array $options = [], array $deps = []): void
    {
        $registerHostPageRoutes = $deps['registerHostPageRoutes'] ?? null;
        $registerHostApiRoutes = $deps['registerHostApiRoutes'] ?? null;
        if (!is_callable($registerHostPageRoutes) || !is_callable($registerHostApiRoutes)) {
            throw new \InvalidArgumentException('host reference module dependencies are incomplete');
        }

        $registerHostPageRoutes($routes, $app);
        $registerHostApiRoutes($routes, $app, $options);
    }

    public static function registerGatewayExecutionModuleRoutes(array &$routes, array $app, array $options = [], array $deps = []): void
    {
        $registerPaymentRoutes = $deps['registerPaymentRoutes'] ?? null;
        $registerGuardRoutes = $deps['registerGuardRoutes'] ?? null;
        $registerSubjectProfileRoutes = $deps['registerSubjectProfileRoutes'] ?? null;
        $readRecord = $deps['readRecord'] ?? null;
        if (!is_callable($registerPaymentRoutes) || !is_callable($registerGuardRoutes) || !is_callable($registerSubjectProfileRoutes) || !is_callable($readRecord)) {
            throw new \InvalidArgumentException('gateway execution module dependencies are incomplete');
        }

        $registerPaymentRoutes($routes, $app, $options);
        $registerGuardRoutes($routes, $app, $options);
        $registerSubjectProfileRoutes($routes, $app, $readRecord($options['subjectProfiles'] ?? null));
    }

    public static function registerReferenceSurfaceModuleRoutes(array &$routes, array $app, array $options = [], array $deps = []): void
    {
        $registerReferenceRoutes = $deps['registerReferenceRoutes'] ?? null;
        if (!is_callable($registerReferenceRoutes)) {
            throw new \InvalidArgumentException('reference surface module dependencies are incomplete');
        }

        $registerReferenceRoutes($routes, $app, array_merge(
            $options,
            ['enabledModes' => $app['paymentOptions']['enabledModes'] ?? null],
        ));
    }

    public static function registerBackendKitRoutes(array &$routes, array $app, array $deps = []): void
    {
        $hostOptions = BackendSupport::readRecord($app['hostOptions'] ?? null);
        $paymentOptions = BackendSupport::readRecord($app['paymentOptions'] ?? null);
        $referenceOptions = BackendSupport::readRecord($app['referenceOptions'] ?? null);
        $brandingOptions = BackendSupport::readRecord($app['brandingOptions'] ?? null);

        self::registerReferenceSurfaceModuleRoutes($routes, $app, array_merge(
            $hostOptions,
            ['reference' => $referenceOptions, 'branding' => $brandingOptions],
        ), [
            'registerReferenceRoutes' => $deps['registerReferenceRoutes'] ?? null,
        ]);

        self::registerHostReferenceModuleRoutes($routes, $app, $hostOptions, [
            'registerHostPageRoutes' => $deps['registerHostPageRoutes'] ?? null,
            'registerHostApiRoutes' => $deps['registerHostApiRoutes'] ?? null,
        ]);

        self::registerGatewayExecutionModuleRoutes($routes, $app, array_merge(
            $paymentOptions,
            ['subjectProfiles' => $app['subjectProfileOptions'] ?? []],
            ['paymentRuntime' => $app['paymentRuntimeOptions'] ?? []],
        ), [
            'registerPaymentRoutes' => $deps['registerPaymentRoutes'] ?? null,
            'registerGuardRoutes' => $deps['registerGuardRoutes'] ?? null,
            'registerSubjectProfileRoutes' => $deps['registerSubjectProfileRoutes'] ?? null,
            'readRecord' => static fn (mixed $value): array => BackendSupport::readRecord($value),
        ]);
    }
}
