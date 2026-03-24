<?php

declare(strict_types=1);

namespace Xapps;

final class HostedGatewayPaymentSession
{
    private function __construct()
    {
    }

    /**
     * @param array{
     *   gatewayClient:object,
     *   paymentHandler:object,
     *   payload?:array<string,mixed>|null,
     *   context?:array<string,mixed>|null,
     *   guard?:array<string,mixed>|null,
     *   guardConfig?:array<string,mixed>|null,
     *   amount:string|int|float,
     *   currency:string,
     *   defaultPaymentUrl:string,
     *   fallbackIssuer:string,
     *   storedIssuer:string,
     *   defaultSecret?:string|null,
     *   defaultSecretRef?:string|null,
     *   allowDefaultSecretFallback?:bool
     * } $input
     * @return array{paymentUrl:string,paymentSessionId:string,gatewaySession:?array<string,mixed>}
     */
    public static function buildHostedGatewayPaymentUrl(array $input): array
    {
        $gatewayClient = $input['gatewayClient'] ?? null;
        $paymentHandler = $input['paymentHandler'] ?? null;
        if (!is_object($gatewayClient) || !method_exists($gatewayClient, 'createPaymentSession')) {
            throw new XappsSdkError(
                XappsSdkError::INVALID_ARGUMENT,
                'HostedGatewayPaymentSession: gatewayClient with createPaymentSession(...) is required',
            );
        }
        if (!is_object($paymentHandler) || !method_exists($paymentHandler, 'upsertSession')) {
            throw new XappsSdkError(
                XappsSdkError::INVALID_ARGUMENT,
                'HostedGatewayPaymentSession: paymentHandler with upsertSession(...) is required',
            );
        }

        $payload = self::readObject($input['payload'] ?? null);
        $context = self::readObject($input['context'] ?? null);
        $guard = self::readObject($input['guard'] ?? null);
        $guardConfig = self::readObject($input['guardConfig'] ?? null);
        $base = self::readString(
            $guardConfig['payment_url'] ?? $guardConfig['paymentUrl'] ?? $input['defaultPaymentUrl'] ?? '',
        );
        if ($base === '') {
            throw new XappsSdkError(
                XappsSdkError::INVALID_ARGUMENT,
                'HostedGatewayPaymentSession: defaultPaymentUrl is required',
            );
        }

        $xappsResume = self::readAnyString(
            $payload['xapps_resume'] ?? null,
            $payload['xappsResume'] ?? null,
            $context['xapps_resume'] ?? null,
            $context['xappsResume'] ?? null,
        );
        $resume = self::parseResumeToken($xappsResume);
        $toolName = self::readAnyString(
            $context['toolName'] ?? null,
            $context['tool_name'] ?? null,
            $payload['toolName'] ?? null,
            $payload['tool_name'] ?? null,
            $resume['toolName'] ?? null,
            $resume['tool_name'] ?? null,
        );
        $xappId = self::readAnyString(
            $context['xappId'] ?? null,
            $context['xapp_id'] ?? null,
            $payload['xappId'] ?? null,
            $payload['xapp_id'] ?? null,
            $resume['xappId'] ?? null,
            $resume['xapp_id'] ?? null,
        );
        $subjectId = self::readAnyString(
            $context['subjectId'] ?? null,
            $context['subject_id'] ?? null,
            $payload['subjectId'] ?? null,
            $payload['subject_id'] ?? null,
            $resume['subjectId'] ?? null,
            $resume['subject_id'] ?? null,
        );
        $installationId = self::readAnyString(
            $context['installationId'] ?? null,
            $context['installation_id'] ?? null,
            $payload['installationId'] ?? null,
            $payload['installation_id'] ?? null,
            $resume['installationId'] ?? null,
            $resume['installation_id'] ?? null,
        );
        $clientId = self::readAnyString(
            $context['clientId'] ?? null,
            $context['client_id'] ?? null,
            $payload['clientId'] ?? null,
            $payload['client_id'] ?? null,
            $resume['clientId'] ?? null,
            $resume['client_id'] ?? null,
        );
        $returnUrl = self::readAnyString(
            $payload['return_url'] ?? null,
            $payload['returnUrl'] ?? null,
            $context['return_url'] ?? null,
            $context['returnUrl'] ?? null,
            $context['host_return_url'] ?? null,
            $context['hostReturnUrl'] ?? null,
            $payload['host_return_url'] ?? null,
            $payload['hostReturnUrl'] ?? null,
            $resume['return_url'] ?? null,
            $resume['host_return_url'] ?? null,
        );
        $cancelUrl = self::readAnyString(
            $payload['cancel_url'] ?? null,
            $payload['cancelUrl'] ?? null,
            $context['cancel_url'] ?? null,
            $context['cancelUrl'] ?? null,
            $returnUrl,
        );
        $paymentScheme = self::readAnyString(
            $payload['payment_scheme'] ?? null,
            $payload['scheme'] ?? null,
            $guardConfig['payment_scheme'] ?? null,
            $guardConfig['paymentScheme'] ?? null,
        );
        $allowedIssuers = self::resolveConfiguredAllowedIssuers($guardConfig);
        $paymentIssuer = $allowedIssuers[0] ?? self::readLowerString($input['fallbackIssuer'] ?? '');
        $paymentReturnSigning = self::resolvePaymentReturnSigning([
            'guardConfig' => $guardConfig,
            'paymentIssuer' => $paymentIssuer,
            'clientId' => $clientId,
            'defaultSecret' => $input['defaultSecret'] ?? null,
            'defaultSecretRef' => $input['defaultSecretRef'] ?? null,
            'allowDefaultSecretFallback' => (bool) ($input['allowDefaultSecretFallback'] ?? false),
        ]);

        $effectiveReturnUrl = $returnUrl;
        if ($effectiveReturnUrl === '') {
            $origin = self::readOrigin($base);
            $effectiveReturnUrl = $origin !== '' ? ($origin . '/tenant-payment.html') : '';
        }

        $created = $gatewayClient->createPaymentSession(
            ManagedGatewayPaymentSession::buildManagedGatewayPaymentSessionInput([
                'source' => 'guard-backend',
                'guardSlug' => self::readString($guard['slug'] ?? '') ?: 'payment-policy',
                'guardConfig' => $guardConfig,
                'pageUrl' => $base,
                'xappId' => $xappId,
                'toolName' => $toolName,
                'amount' => (string) ($input['amount'] ?? ''),
                'currency' => self::readString($input['currency'] ?? ''),
                'issuer' => $paymentIssuer,
                'paymentScheme' => $paymentScheme,
                'returnUrl' => $effectiveReturnUrl,
                'cancelUrl' => $cancelUrl,
                'xappsResume' => $xappsResume,
                'subjectId' => $subjectId,
                'installationId' => $installationId,
                'clientId' => $clientId,
                'paymentReturnSigning' => $paymentReturnSigning,
            ]),
        );

        $gatewaySession = is_array($created['session'] ?? null) ? $created['session'] : null;
        $paymentSessionId = self::readAnyString(
            $gatewaySession['payment_session_id'] ?? null,
            $gatewaySession['paymentSessionId'] ?? null,
        );
        $paymentPageUrl = self::readAnyString(
            $created['paymentPageUrl'] ?? null,
            $created['payment_page_url'] ?? null,
        );

        if ($paymentPageUrl !== '') {
            return [
                'paymentUrl' => $paymentPageUrl,
                'paymentSessionId' => $paymentSessionId,
                'gatewaySession' => $gatewaySession,
            ];
        }

        if ($paymentSessionId !== '') {
            $paymentHandler->upsertSession([
                'payment_session_id' => $paymentSessionId,
                'xapp_id' => $xappId,
                'tool_name' => $toolName,
                'amount' => (string) ($input['amount'] ?? ''),
                'currency' => self::readString($input['currency'] ?? ''),
                'issuer' => self::readString($input['storedIssuer'] ?? '') ?: $paymentIssuer,
                'subject_id' => $subjectId,
                'installation_id' => $installationId,
                'client_id' => $clientId,
                'return_url' => $effectiveReturnUrl,
                'cancel_url' => $cancelUrl,
                'xapps_resume' => $xappsResume,
            ]);
            return [
                'paymentUrl' => self::appendQuery($base, [
                    'payment_session_id' => $paymentSessionId,
                    'return_url' => $effectiveReturnUrl,
                    'cancel_url' => $cancelUrl,
                    'xapps_resume' => $xappsResume,
                ]),
                'paymentSessionId' => $paymentSessionId,
                'gatewaySession' => $gatewaySession,
            ];
        }

        return [
            'paymentUrl' => $base,
            'paymentSessionId' => '',
            'gatewaySession' => $gatewaySession,
        ];
    }

    public static function extractHostedPaymentSessionId(string $paymentUrl): ?string
    {
        $query = parse_url($paymentUrl, PHP_URL_QUERY);
        if (!is_string($query) || trim($query) === '') {
            return null;
        }
        parse_str($query, $params);
        $paymentSessionId = self::readAnyString(
            $params['payment_session_id'] ?? null,
            $params['paymentSessionId'] ?? null,
        );
        return $paymentSessionId !== '' ? $paymentSessionId : null;
    }

    /** @param array<string,mixed>|null $guardConfig @return string[] */
    private static function resolveConfiguredAllowedIssuers(?array $guardConfig): array
    {
        $guardConfig = self::readObject($guardConfig);
        $configured = [];
        $raw = $guardConfig['payment_allowed_issuers'] ?? $guardConfig['paymentAllowedIssuers'] ?? [];
        if (is_array($raw)) {
            $configured = $raw;
        }
        $out = [];
        foreach ($configured as $value) {
            $normalized = self::readLowerString($value);
            if ($normalized === '') continue;
            if (!in_array($normalized, ['gateway', 'tenant', 'publisher', 'tenant_delegated', 'publisher_delegated'], true)) {
                continue;
            }
            if (!in_array($normalized, $out, true)) {
                $out[] = $normalized;
            }
        }
        return $out;
    }

    /**
     * @param array{
     *   guardConfig:array<string,mixed>|null,
     *   paymentIssuer:string,
     *   clientId:string,
     *   defaultSecret?:string|null,
     *   defaultSecretRef?:string|null,
     *   allowDefaultSecretFallback?:bool
     * } $input
     * @return array<string,mixed>|null
     */
    private static function resolvePaymentReturnSigning(array $input): ?array
    {
        $guardConfig = self::readObject($input['guardConfig'] ?? null);
        $issuer = self::readLowerString($input['paymentIssuer'] ?? '');
        if ($issuer === '') return null;

        $refs = self::readObject($guardConfig['payment_return_hmac_secret_refs'] ?? null);
        $secrets = self::readObject($guardConfig['payment_return_hmac_secrets'] ?? null);
        $delegatedRefsByIssuer = self::readObject($guardConfig['payment_return_hmac_delegated_secret_refs'] ?? null);
        $delegatedSecretsByIssuer = self::readObject($guardConfig['payment_return_hmac_delegated_secrets'] ?? null);

        $delegatedLane = in_array($issuer, ['tenant_delegated', 'publisher_delegated'], true);
        $clientId = self::readString($input['clientId'] ?? '');
        $ref = '';
        $secret = '';

        if ($delegatedLane) {
            $delegatedRefs = self::readObject($delegatedRefsByIssuer[$issuer] ?? null);
            $delegatedSecrets = self::readObject($delegatedSecretsByIssuer[$issuer] ?? null);
            $manifestRef = self::readAnyString($clientId !== '' ? ($delegatedRefs[$clientId] ?? null) : null);
            $manifestSecret = self::readAnyString($clientId !== '' ? ($delegatedSecrets[$clientId] ?? null) : null);
            $envRef = self::isPlaceholderToken($input['defaultSecretRef'] ?? null)
                ? ''
                : self::readAnyString($input['defaultSecretRef'] ?? null);
            $envSecret = self::isPlaceholderToken($input['defaultSecret'] ?? null)
                ? ''
                : self::readAnyString($input['defaultSecret'] ?? null);
            $ref = self::isPlaceholderToken($manifestRef) ? '' : $manifestRef;
            $secret = self::isPlaceholderToken($manifestSecret) ? '' : $manifestSecret;
            $ref = $ref !== '' ? $ref : $envRef;
            $secret = $secret !== '' ? $secret : $envSecret;
        } else {
            $manifestRef = self::readAnyString($refs[$issuer] ?? null);
            $manifestSecret = self::readAnyString($secrets[$issuer] ?? null);
            $ref = self::isPlaceholderToken($manifestRef) ? '' : $manifestRef;
            $secret = self::isPlaceholderToken($manifestSecret) ? '' : $manifestSecret;
        }

        if ($ref !== '' || $secret !== '') {
            if ($delegatedLane && $secret !== '') {
                return [
                    'issuer' => $issuer,
                    'signing_lane' => $issuer,
                    'resolver_source' => 'session_metadata_delegated',
                    'secret' => $secret,
                ];
            }
            return [
                'issuer' => $issuer,
                'signing_lane' => $issuer,
                'resolver_source' => $delegatedLane
                    ? ($ref !== '' ? 'session_metadata_secret_ref_delegated' : 'session_metadata_delegated')
                    : ($ref !== '' ? 'guard_config_secret_ref' : 'guard_config_secret'),
                ...($ref !== '' ? ['secret_ref' => $ref] : []),
                ...($secret !== '' ? ['secret' => $secret] : []),
            ];
        }

        if (($input['allowDefaultSecretFallback'] ?? false) && $issuer === 'tenant' && !$delegatedLane) {
            $defaultRef = self::readString($input['defaultSecretRef'] ?? '');
            $defaultSecret = self::readString($input['defaultSecret'] ?? '');
            if ($defaultRef !== '' || $defaultSecret !== '') {
                return [
                    'issuer' => 'tenant',
                    'signing_lane' => 'tenant',
                    'resolver_source' => $defaultRef !== '' ? 'default_secret_ref' : 'default_secret',
                    ...($defaultRef !== '' ? ['secret_ref' => $defaultRef] : []),
                    ...($defaultSecret !== '' ? ['secret' => $defaultSecret] : []),
                ];
            }
        }

        return null;
    }

    /** @param array<string,string> $params */
    private static function appendQuery(string $base, array $params): string
    {
        $parsed = parse_url($base);
        if ($parsed === false) {
            return $base;
        }

        $existing = [];
        if (isset($parsed['query'])) {
            parse_str((string) $parsed['query'], $existing);
        }
        foreach ($params as $key => $value) {
            if (trim((string) $value) !== '') {
                $existing[$key] = (string) $value;
            }
        }

        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '';
        $host = (string) ($parsed['host'] ?? '');
        $port = isset($parsed['port']) ? ':' . (string) $parsed['port'] : '';
        $user = (string) ($parsed['user'] ?? '');
        $pass = isset($parsed['pass']) ? ':' . (string) $parsed['pass'] : '';
        $auth = $user !== '' ? $user . $pass . '@' : '';
        $path = (string) ($parsed['path'] ?? '');
        $fragment = isset($parsed['fragment']) ? '#' . (string) $parsed['fragment'] : '';
        $query = http_build_query($existing);

        return $scheme . $auth . $host . $port . $path . ($query !== '' ? '?' . $query : '') . $fragment;
    }

    private static function readOrigin(string $url): string
    {
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            return '';
        }
        return (string) $parsed['scheme']
            . '://'
            . (string) $parsed['host']
            . (isset($parsed['port']) ? ':' . (string) $parsed['port'] : '');
    }

    /** @return array<string,mixed> */
    private static function parseResumeToken($token): array
    {
        $raw = self::readString($token);
        if ($raw === '') return [];

        $decoded = base64_decode(strtr($raw, '-_', '+/'), true);
        if ($decoded === false) return [];
        $padding = strlen($raw) % 4;
        if ($padding > 0) {
            $decoded = base64_decode(strtr($raw . str_repeat('=', 4 - $padding), '-_', '+/'), true);
            if ($decoded === false) return [];
        }
        $parsed = json_decode($decoded, true);
        return is_array($parsed) ? $parsed : [];
    }

    /** @return array<string,mixed> */
    private static function readObject($value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function readAnyString(...$values): string
    {
        foreach ($values as $value) {
            $normalized = self::readString($value);
            if ($normalized !== '') return $normalized;
        }
        return '';
    }

    private static function readString($value): string
    {
        return trim((string) ($value ?? ''));
    }

    private static function readLowerString($value): string
    {
        return strtolower(self::readString($value));
    }

    private static function isPlaceholderToken($value): bool
    {
        return preg_match('/^__[^_].*__$/', self::readString($value)) === 1;
    }
}
