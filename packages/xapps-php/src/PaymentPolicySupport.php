<?php

declare(strict_types=1);

namespace Xapps;

final class PaymentPolicySupport
{
    private function __construct()
    {
    }

    public static function hasUpstreamPaymentVerified($value): bool
    {
        if ($value === true) return true;
        if ($value === false || $value === null) return false;
        if (is_array($value)) return count($value) > 0;
        if (is_object($value)) return count(get_object_vars($value)) > 0;
        return in_array(self::readLowerString($value), ['1', 'true', 'yes', 'on'], true);
    }

    /** @param array<string,mixed>|null $payloadInput @return array<string,mixed> */
    public static function resolveMergedPaymentGuardContext(?array $payloadInput): array
    {
        $payload = self::readObject($payloadInput);
        $context = self::readObject($payload['context'] ?? null);
        $xappsResume = self::readAnyString(
            $payload['xapps_resume'] ?? null,
            $payload['xappsResume'] ?? null,
            $context['xapps_resume'] ?? null,
            $context['xappsResume'] ?? null,
        );
        $resume = self::parseResumeToken($xappsResume);

        $xappId = self::readAnyString(
            $context['xappId'] ?? null,
            $context['xapp_id'] ?? null,
            $payload['xappId'] ?? null,
            $payload['xapp_id'] ?? null,
            $resume['xappId'] ?? null,
            $resume['xapp_id'] ?? null,
        );
        $toolName = self::readAnyString(
            $context['toolName'] ?? null,
            $context['tool_name'] ?? null,
            $payload['toolName'] ?? null,
            $payload['tool_name'] ?? null,
            $resume['toolName'] ?? null,
            $resume['tool_name'] ?? null,
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
        $subjectId = self::readAnyString(
            $context['subjectId'] ?? null,
            $context['subject_id'] ?? null,
            $payload['subjectId'] ?? null,
            $payload['subject_id'] ?? null,
            $resume['subjectId'] ?? null,
            $resume['subject_id'] ?? null,
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
        );

        return [
            ...$context,
            ...($xappId !== '' ? ['xappId' => $xappId, 'xapp_id' => $xappId] : []),
            ...($toolName !== '' ? ['toolName' => $toolName, 'tool_name' => $toolName] : []),
            ...($installationId !== '' ? ['installationId' => $installationId, 'installation_id' => $installationId] : []),
            ...($clientId !== '' ? ['clientId' => $clientId, 'client_id' => $clientId] : []),
            ...($subjectId !== '' ? ['subjectId' => $subjectId, 'subject_id' => $subjectId] : []),
            ...($returnUrl !== '' ? ['returnUrl' => $returnUrl, 'return_url' => $returnUrl] : []),
            ...($cancelUrl !== '' ? ['cancelUrl' => $cancelUrl, 'cancel_url' => $cancelUrl] : []),
            ...($xappsResume !== '' ? ['xappsResume' => $xappsResume, 'xapps_resume' => $xappsResume] : []),
        ];
    }

    /** @param array<string,mixed>|null $guardConfig @param array<string,mixed>|null $context */
    public static function resolvePaymentGuardPriceAmount(?array $guardConfig, ?array $context)
    {
        $guardConfig = self::readObject($guardConfig);
        $context = self::readObject($context);
        $pricing = self::readObject($guardConfig['pricing'] ?? null);
        $toolName = self::readAnyString($context['toolName'] ?? null, $context['tool_name'] ?? null);
        $xappId = self::readAnyString($context['xappId'] ?? null, $context['xapp_id'] ?? null);
        $toolOverrides = self::readObject($pricing['tool_overrides'] ?? null);
        $xappPrices = self::readObject($pricing['xapp_prices'] ?? null);

        foreach ($toolOverrides as $key => $value) {
            $normalizedKey = (string) $key;
            if ($toolName === '') continue;
            if (str_ends_with($normalizedKey, ':' . $toolName) || $normalizedKey === $toolName) {
                return $value;
            }
        }
        if ($xappId !== '' && array_key_exists($xappId, $xappPrices)) {
            return $xappPrices[$xappId];
        }
        return $pricing['default_amount'] ?? 3;
    }

    /** @param array<string,mixed>|null $action @return array<string,mixed> */
    public static function buildPaymentGuardAction(?array $action): array
    {
        $action = self::readObject($action);
        $result = [
            'kind' => 'complete_payment',
            'url' => self::readString($action['url'] ?? ''),
            'label' => self::readLocalizedTextValue($action['label'] ?? null) ?? 'Open Payment',
            'title' => self::readLocalizedTextValue($action['title'] ?? null) ?? 'Complete Payment',
        ];
        $target = self::readString($action['target'] ?? '');
        if ($target !== '') {
            $result['target'] = $target;
        }
        return $result;
    }

    /** @param array<string,mixed>|null $guardConfig @return string[] */
    public static function normalizePaymentAllowedIssuers(?array $guardConfig, string $fallbackIssuer): array
    {
        $guardConfig = self::readObject($guardConfig);
        $configured = $guardConfig['payment_allowed_issuers'] ?? $guardConfig['paymentAllowedIssuers'] ?? [];
        $normalized = [];
        if (is_array($configured)) {
            foreach ($configured as $value) {
                $candidate = self::readLowerString($value);
                if (!in_array($candidate, ['gateway', 'tenant', 'publisher', 'tenant_delegated', 'publisher_delegated'], true)) {
                    continue;
                }
                if (!in_array($candidate, $normalized, true)) {
                    $normalized[] = $candidate;
                }
            }
        }
        return count($normalized) > 0 ? $normalized : [self::readLowerString($fallbackIssuer)];
    }

    /** @return array<string,mixed> */
    private static function parseResumeToken($token): array
    {
        $raw = self::readString($token);
        if ($raw === '') return [];

        $padding = strlen($raw) % 4;
        if ($padding > 0) {
            $raw .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($raw, '-_', '+/'), true);
        if ($decoded === false) return [];
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

    /** @return array<string,mixed>|string|null */
    private static function readLocalizedTextValue($value)
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed !== '' ? $trimmed : null;
        }
        if (!is_array($value)) return null;
        foreach ($value as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $value;
            }
        }
        return null;
    }
}
