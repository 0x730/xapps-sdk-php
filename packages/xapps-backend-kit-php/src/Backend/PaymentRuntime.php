<?php

declare(strict_types=1);

namespace Xapps\BackendKit;

use Xapps\GatewayClient;
use Xapps\HostedGatewayPaymentSession;
use Xapps\PaymentHandler;

final class BackendPaymentRuntime
{
    public static function attachPaymentRuntime(array $app, array $normalizedOptions, array $deps = []): array
    {
        $createGatewayClient = $deps['createGatewayClient'] ?? null;
        $createPaymentHandler = $deps['createPaymentHandler'] ?? null;
        $overrides = BackendSupport::readRecord($normalizedOptions['overrides'] ?? null);
        $payments = BackendSupport::readRecord($normalizedOptions['payments'] ?? null);

        $gatewayClient = is_object($overrides['gatewayClient'] ?? null)
            ? $overrides['gatewayClient']
            : (is_callable($createGatewayClient)
                ? $createGatewayClient($app['config'], $normalizedOptions)
                : new GatewayClient(
                    (string) (($app['config']['gatewayUrl'] ?? '')),
                    (string) (($app['config']['gatewayApiKey'] ?? '')),
                    20,
                ));

        $paymentHandler = is_object($overrides['paymentHandler'] ?? null)
            ? $overrides['paymentHandler']
            : (is_callable($createPaymentHandler) ? $createPaymentHandler($app['config'], $gatewayClient, $normalizedOptions) : null);

        if (is_object($gatewayClient)) {
            $app['paymentGatewayClient'] = $gatewayClient;
        }
        if (is_object($paymentHandler)) {
            $app['paymentHandler'] = $paymentHandler;
        }

        $app['paymentRuntimeOptions'] = [
            'hostProxyService' => is_object($overrides['hostProxyService'] ?? null) ? $overrides['hostProxyService'] : null,
            'gatewayClient' => $gatewayClient,
            'paymentHandler' => $paymentHandler,
            'paymentPageFile' => BackendSupport::readString(
                BackendSupport::readRecord($normalizedOptions['assets'] ?? null)['paymentPage']['filePath'] ?? null,
            ),
            'resolvePolicyRequest' => is_callable($overrides['resolvePolicyRequest'] ?? null)
                ? $overrides['resolvePolicyRequest']
                : null,
            'paymentSettings' => [
                'ownerIssuer' => BackendOptions::normalizeOwnerIssuer($payments['ownerIssuer'] ?? null),
                'paymentUrl' => BackendSupport::readString($payments['paymentUrl'] ?? null),
                'returnSecret' => BackendSupport::readString($payments['returnSecret'] ?? null),
                'returnSecretRef' => BackendSupport::readString($payments['returnSecretRef'] ?? null),
                'returnUrlAllowlist' => BackendSupport::readString($payments['returnUrlAllowlist'] ?? null),
            ],
        ];

        return $app;
    }

    public static function createPaymentHandler(array $config, ?object $gatewayClient = null, array $deps = []): PaymentHandler
    {
        $createGatewayClient = $deps['createGatewayClient'] ?? null;
        $resolvedGatewayClient = $gatewayClient;
        if (!$resolvedGatewayClient && is_callable($createGatewayClient)) {
            $resolvedGatewayClient = $createGatewayClient($config);
        }

        return new PaymentHandler([
            'secret' => (string) ($config['tenantPaymentReturnSecret'] ?? ''),
            'secretRef' => (string) ($config['tenantPaymentReturnSecretRef'] ?? ''),
            'issuer' => BackendOptions::normalizeOwnerIssuer($config['paymentOwnerIssuer'] ?? null),
            'returnUrlAllowlist' => BackendOptions::paymentReturnAllowlist($config),
            'gatewayClient' => $resolvedGatewayClient,
            'store' => is_object($deps['store'] ?? null) ? $deps['store'] : null,
        ]);
    }

    public static function verifyPaymentEvidence(
        array $app,
        array $payload,
        array $expected,
        int $maxAgeSeconds,
        array $deps = [],
    ): array {
        if (isset($app['paymentHandler']) && is_object($app['paymentHandler'])) {
            return $app['paymentHandler']->handleVerifyEvidence([
                'payload' => $payload,
                'maxAgeSeconds' => $maxAgeSeconds,
                'expected' => $expected,
            ]);
        }

        $config = BackendSupport::readRecord($app['config'] ?? null);
        $secret = trim((string) ($config['tenantPaymentReturnSecret'] ?? ''));
        $secretRef = trim((string) ($config['tenantPaymentReturnSecretRef'] ?? ''));
        if ($secret === '' && $secretRef === '') {
            return ['ok' => false, 'reason' => 'verification_secret_missing'];
        }

        $createPaymentHandler = $deps['createPaymentHandler'] ?? null;
        $handler = is_callable($createPaymentHandler)
            ? $createPaymentHandler($config)
            : self::createPaymentHandler($config);
        return $handler->handleVerifyEvidence([
            'payload' => $payload,
            'maxAgeSeconds' => $maxAgeSeconds,
            'expected' => $expected,
        ]);
    }

    public static function buildHostedGatewayPaymentUrl(array $app, array $input, array $deps = []): array
    {
        $config = BackendSupport::readRecord($app['config'] ?? null);
        $runtime = BackendSupport::readRecord($app['paymentRuntimeOptions'] ?? null);
        $paymentSettings = BackendSupport::readRecord($runtime['paymentSettings'] ?? null);
        $gatewayClient = is_object($app['paymentGatewayClient'] ?? null)
            ? $app['paymentGatewayClient']
            : (is_object($runtime['gatewayClient'] ?? null) ? $runtime['gatewayClient'] : null);
        $paymentHandler = is_object($app['paymentHandler'] ?? null)
            ? $app['paymentHandler']
            : (is_object($runtime['paymentHandler'] ?? null) ? $runtime['paymentHandler'] : null);

        $createGatewayClient = $deps['createGatewayClient'] ?? null;
        $createPaymentHandler = $deps['createPaymentHandler'] ?? null;
        if (!$gatewayClient && is_callable($createGatewayClient)) {
            $gatewayClient = $createGatewayClient($config);
        }
        if (!$paymentHandler && is_callable($createPaymentHandler)) {
            $paymentHandler = $createPaymentHandler($config, $gatewayClient);
        }

        $ownerIssuer = BackendOptions::normalizeOwnerIssuer(
            $paymentSettings['ownerIssuer'] ?? null,
            BackendSupport::readString($config['paymentOwnerIssuer'] ?? null, 'tenant'),
        );

        return HostedGatewayPaymentSession::buildHostedGatewayPaymentUrl([
            'gatewayClient' => $gatewayClient,
            'paymentHandler' => $paymentHandler,
            'payload' => $input['payload'] ?? [],
            'context' => $input['context'] ?? [],
            'guard' => $input['guard'] ?? [],
            'guardConfig' => $input['guardConfig'] ?? [],
            'amount' => $input['amount'] ?? 0,
            'currency' => $input['currency'] ?? 'USD',
            'defaultPaymentUrl' => BackendSupport::readString(
                $paymentSettings['paymentUrl'] ?? null,
                BackendSupport::readString($config['tenantPaymentUrl'] ?? null),
            ),
            'fallbackIssuer' => BackendSupport::readString($input['fallbackIssuer'] ?? null, $ownerIssuer),
            'storedIssuer' => BackendSupport::readString(
                $input['storedIssuer'] ?? null,
                BackendSupport::readString($input['fallbackIssuer'] ?? null, $ownerIssuer),
            ),
            'defaultSecret' => BackendSupport::readString(
                $paymentSettings['returnSecret'] ?? null,
                BackendSupport::readString($config['tenantPaymentReturnSecret'] ?? null),
            ),
            'defaultSecretRef' => BackendSupport::readString(
                $paymentSettings['returnSecretRef'] ?? null,
                BackendSupport::readString($config['tenantPaymentReturnSecretRef'] ?? null),
            ),
            'allowDefaultSecretFallback' => (bool) ($input['allowDefaultSecretFallback'] ?? false),
        ]);
    }

    public static function buildModeHostedGatewayPaymentUrl(
        array $app,
        array $input,
        array $modeMeta = [],
        array $deps = [],
    ): array {
        $runtime = BackendSupport::readRecord($app['paymentRuntimeOptions'] ?? null);
        $paymentSettings = BackendSupport::readRecord($runtime['paymentSettings'] ?? null);
        $config = BackendSupport::readRecord($app['config'] ?? null);
        $ownerIssuer = BackendOptions::normalizeOwnerIssuer(
            $paymentSettings['ownerIssuer'] ?? null,
            BackendSupport::readString($config['paymentOwnerIssuer'] ?? null, 'tenant'),
        );
        return self::buildHostedGatewayPaymentUrl($app, [
            ...$input,
            'fallbackIssuer' => BackendSupport::readString(
                $modeMeta['fallbackIssuer'] ?? null,
                BackendSupport::readString($input['fallbackIssuer'] ?? null, $ownerIssuer),
            ),
            'storedIssuer' => BackendSupport::readString(
                $modeMeta['storedIssuer'] ?? null,
                BackendSupport::readString(
                    $input['storedIssuer'] ?? null,
                    BackendSupport::readString(
                        $modeMeta['fallbackIssuer'] ?? null,
                        BackendSupport::readString($input['fallbackIssuer'] ?? null, $ownerIssuer),
                    ),
                ),
            ),
            'allowDefaultSecretFallback' => (bool) ($modeMeta['allowDefaultSecretFallback'] ?? $input['allowDefaultSecretFallback'] ?? false),
        ], $deps);
    }

    public static function registerPaymentPageApiRoutes(
        array &$routes,
        array $app,
        array $options = [],
        array $deps = [],
    ): void {
        $pathPrefix = rtrim((string) ($options['pathPrefix'] ?? '/api/tenant-payment'), '/');
        $gatewayClient = is_object($options['gatewayClient'] ?? null)
            ? $options['gatewayClient']
            : (is_object($app['paymentGatewayClient'] ?? null) ? $app['paymentGatewayClient'] : null);

        $createGatewayClient = $deps['createGatewayClient'] ?? null;
        $readRecord = $deps['readRecord'] ?? null;
        $readString = $deps['readString'] ?? null;
        $optionalString = $deps['optionalString'] ?? null;
        $sendJson = $deps['sendJson'] ?? null;
        $sendServiceError = $deps['sendServiceError'] ?? null;
        $mapHostedSessionResult = $deps['mapHostedSessionResult'] ?? null;
        if (
            !is_callable($readRecord)
            || !is_callable($readString)
            || !is_callable($optionalString)
            || !is_callable($sendJson)
            || !is_callable($sendServiceError)
            || !is_callable($mapHostedSessionResult)
        ) {
            throw new \InvalidArgumentException('tenant payment page route dependencies are incomplete');
        }

        if (!$gatewayClient && is_callable($createGatewayClient)) {
            $gatewayClient = $createGatewayClient(BackendSupport::readRecord($app['config'] ?? null));
        }

        $routes[] = [
            'method' => 'GET',
            'path' => $pathPrefix . '/session',
            'handler' => static function (array $request) use ($gatewayClient, $readRecord, $readString, $optionalString, $sendJson, $sendServiceError): void {
                $query = $readRecord($request['query'] ?? null);
                $paymentSessionId = $readString(
                    $query['payment_session_id'] ?? null,
                    $query['paymentSessionId'] ?? null,
                );
                if ($paymentSessionId === '') {
                    $sendJson(['message' => 'payment_session_id is required'], 400);
                    return;
                }
                try {
                    $hosted = $gatewayClient->getGatewayPaymentSession([
                        'paymentSessionId' => $paymentSessionId,
                        'returnUrl' => $optionalString(
                            $query['return_url'] ?? null,
                            $query['returnUrl'] ?? null,
                        ),
                        'cancelUrl' => $optionalString(
                            $query['cancel_url'] ?? null,
                            $query['cancelUrl'] ?? null,
                        ),
                        'xappsResume' => $optionalString(
                            $query['xapps_resume'] ?? null,
                            $query['xappsResume'] ?? null,
                        ),
                    ]);
                    $sendJson([
                        'status' => 'success',
                        'result' => $hosted['session'] ?? null,
                    ], 200);
                } catch (\Throwable $error) {
                    $sendServiceError($error, 'gateway payment session fetch failed');
                }
            },
        ];

        $routes[] = [
            'method' => 'POST',
            'path' => $pathPrefix . '/complete',
            'handler' => static function (array $request) use ($gatewayClient, $readRecord, $readString, $optionalString, $sendJson, $sendServiceError, $mapHostedSessionResult): void {
                $body = $readRecord($request['body'] ?? null);
                $paymentSessionId = $readString(
                    $body['payment_session_id'] ?? null,
                    $body['paymentSessionId'] ?? null,
                );
                if ($paymentSessionId === '') {
                    $sendJson(['message' => 'payment_session_id is required'], 400);
                    return;
                }
                try {
                    $hosted = $gatewayClient->completeGatewayPayment([
                        'paymentSessionId' => $paymentSessionId,
                        'returnUrl' => $optionalString(
                            $body['return_url'] ?? null,
                            $body['returnUrl'] ?? null,
                        ),
                        'cancelUrl' => $optionalString(
                            $body['cancel_url'] ?? null,
                            $body['cancelUrl'] ?? null,
                        ),
                        'xappsResume' => $optionalString(
                            $body['xapps_resume'] ?? null,
                            $body['xappsResume'] ?? null,
                        ),
                    ]);
                    $sendJson([
                        'status' => 'success',
                        'result' => $mapHostedSessionResult($hosted),
                    ], 200);
                } catch (\Throwable $error) {
                    $sendServiceError($error, 'gateway payment completion failed');
                }
            },
        ];

        $routes[] = [
            'method' => 'POST',
            'path' => $pathPrefix . '/client-settle',
            'handler' => static function (array $request) use ($gatewayClient, $readRecord, $readString, $optionalString, $sendJson, $sendServiceError, $mapHostedSessionResult): void {
                $body = $readRecord($request['body'] ?? null);
                $paymentSessionId = $readString(
                    $body['payment_session_id'] ?? null,
                    $body['paymentSessionId'] ?? null,
                );
                if ($paymentSessionId === '') {
                    $sendJson(['message' => 'payment_session_id is required'], 400);
                    return;
                }
                try {
                    $status = $readString($body['status'] ?? null);
                    $hosted = $gatewayClient->clientSettleGatewayPayment([
                        'paymentSessionId' => $paymentSessionId,
                        'returnUrl' => $optionalString(
                            $body['return_url'] ?? null,
                            $body['returnUrl'] ?? null,
                        ),
                        'xappsResume' => $optionalString(
                            $body['xapps_resume'] ?? null,
                            $body['xappsResume'] ?? null,
                        ),
                        'status' => $status === 'failed' || $status === 'cancelled' ? $status : 'paid',
                        'clientToken' => $optionalString(
                            $body['client_token'] ?? null,
                            $body['clientToken'] ?? null,
                        ),
                        'metadata' => $readRecord($body['metadata'] ?? null),
                    ]);
                    $sendJson([
                        'status' => 'success',
                        'result' => $mapHostedSessionResult($hosted),
                    ], 200);
                } catch (\Throwable $error) {
                    $sendServiceError($error, 'client_settle_failed');
                }
            },
        ];
    }

    public static function mapHostedSessionResult(array $input): array
    {
        $result = [];
        if (isset($input['redirectUrl'])) {
            $result['redirect_url'] = $input['redirectUrl'];
        }
        if (isset($input['flow'])) {
            $result['flow'] = $input['flow'];
        }
        if (isset($input['paymentSessionId'])) {
            $result['payment_session_id'] = $input['paymentSessionId'];
        }
        if (isset($input['clientSettleUrl'])) {
            $result['client_settle_url'] = $input['clientSettleUrl'];
        }
        if (array_key_exists('providerReference', $input)) {
            $result['provider_reference'] = $input['providerReference'];
        }
        if (isset($input['scheme'])) {
            $result['scheme'] = $input['scheme'];
        }
        if (isset($input['metadata'])) {
            $result['metadata'] = $input['metadata'];
        }
        return $result;
    }
}
