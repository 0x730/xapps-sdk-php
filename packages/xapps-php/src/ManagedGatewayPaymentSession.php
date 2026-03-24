<?php

declare(strict_types=1);

namespace Xapps;

final class ManagedGatewayPaymentSession
{
    private function __construct()
    {
    }

    public static function humanizePaymentSchemeLabel($scheme): string
    {
        $normalized = self::readLowerString($scheme);
        if ($normalized === '') return '';
        if ($normalized === 'mock_manual') return 'Mock Hosted Redirect';
        if ($normalized === 'mock_immediate') return 'Mock Immediate';
        if ($normalized === 'mock_client_collect') return 'Mock Client Collect';
        if ($normalized === 'mock_decline') return 'Mock Decline';
        if ($normalized === 'stripe') return 'Stripe';
        if ($normalized === 'paypal') return 'PayPal';
        if ($normalized === 'netopia') return 'Netopia';

        $parts = preg_split('/[_-]+/', $normalized) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') continue;
            $out[] = ucfirst($part);
        }
        return implode(' ', $out);
    }

    /** @param array<string,mixed>|null $guardConfig @return array<int,array<string,mixed>> */
    public static function buildGatewaySessionAccepts(?array $guardConfig, $paymentScheme): array
    {
        $primaryScheme = self::readLowerString($paymentScheme);
        $configured = is_array($guardConfig['accepts'] ?? null) ? $guardConfig['accepts'] : [];
        $out = [];
        $seen = [];

        $pushAccept = function ($entry) use (&$out, &$seen): void {
            if (is_string($entry)) {
                $entry = ['scheme' => $entry];
            }
            if (!is_array($entry)) return;
            $scheme = self::readLowerString(
                $entry['scheme'] ?? $entry['payment_scheme'] ?? $entry['paymentScheme'] ?? '',
            );
            if ($scheme === '' || isset($seen[$scheme])) return;
            $seen[$scheme] = true;
            $label = self::readString($entry['label'] ?? $entry['title'] ?? '');
            $entry['scheme'] = $scheme;
            $entry['label'] = $label !== '' ? $label : self::humanizePaymentSchemeLabel($scheme);
            $out[] = $entry;
        };

        if ($primaryScheme !== '') {
            $pushAccept(['scheme' => $primaryScheme]);
        }
        foreach ($configured as $candidate) {
            $pushAccept($candidate);
        }
        return $out;
    }

    /** @param array<string,mixed>|null $guardConfig @return array<string,mixed> */
    public static function readGatewaySessionUi(?array $guardConfig): array
    {
        $guardConfig = is_array($guardConfig) ? $guardConfig : [];
        $value = $guardConfig['hosted_ui'] ?? $guardConfig['payment_ui'] ?? $guardConfig['paymentUi'] ?? [];
        return is_array($value) ? $value : [];
    }

    /** @param array<string,mixed>|null $guardConfig */
    private static function normalizePaymentType(?array $guardConfig): string
    {
        $guardConfig = is_array($guardConfig) ? $guardConfig : [];
        $raw = self::readLowerString($guardConfig['payment_type'] ?? $guardConfig['paymentType'] ?? '');
        if ($raw === 'pay_by_request' || $raw === 'pay_per_request' || $raw === 'per_request') {
            return 'pay_by_request';
        }
        if ($raw === 'one_time_unlock' || $raw === 'one_time' || $raw === 'per_use' || $raw === 'per_result') {
            return 'one_time_unlock';
        }
        if ($raw === 'subscription') return 'subscription';
        if ($raw === 'credit_based' || $raw === 'credits') return 'credit_based';

        $legacy = self::readLowerString($guardConfig['pricing_model'] ?? $guardConfig['pricingModel'] ?? $guardConfig['mode'] ?? '');
        if ($legacy === 'pay_per_request' || $legacy === 'per_request') return 'pay_by_request';
        if ($legacy === 'subscription') return 'subscription';
        if ($legacy === 'credit_based' || $legacy === 'credits') return 'credit_based';
        return 'one_time_unlock';
    }

    /** @param array<string,mixed>|null $guardConfig */
    private static function readRetryWindowSec(?array $guardConfig): int
    {
        $guardConfig = is_array($guardConfig) ? $guardConfig : [];
        $raw = $guardConfig['payment_return_retry_window_s'] ?? $guardConfig['paymentReturnRetryWindowS'] ?? 120;
        $value = (int) $raw;
        return max(15, $value > 0 ? $value : 120);
    }

    /**
     * @param array{
     *   source:string,
     *   guardConfig?:array<string,mixed>|null,
     *   guardSlug?:string|null,
     *   guard_slug?:string|null,
     *   paymentScheme?:mixed,
     *   payment_scheme?:mixed,
     *   paymentReturnSigning?:array<string,mixed>|null,
     *   payment_return_signing?:array<string,mixed>|null,
     *   metadata?:array<string,mixed>|null
     * } $input
     * @return array<string,mixed>
     */
    public static function buildManagedGatewaySessionMetadata(array $input): array
    {
        $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
        $source = self::readString($input['source'] ?? '');
        $guardSlug = self::readString($input['guardSlug'] ?? $input['guard_slug'] ?? '');
        $paymentScheme = self::readString($input['paymentScheme'] ?? $input['payment_scheme'] ?? '');
        $accepts = self::buildGatewaySessionAccepts(
            is_array($input['guardConfig'] ?? null) ? $input['guardConfig'] : [],
            $paymentScheme,
        );
        $paymentUi = self::readGatewaySessionUi(
            is_array($input['guardConfig'] ?? null) ? $input['guardConfig'] : [],
        );
        $paymentReturnSigning = is_array($input['paymentReturnSigning'] ?? null)
            ? $input['paymentReturnSigning']
            : (is_array($input['payment_return_signing'] ?? null) ? $input['payment_return_signing'] : []);
        $paymentType = self::normalizePaymentType(
            is_array($input['guardConfig'] ?? null) ? $input['guardConfig'] : [],
        );

        if ($source !== '') $metadata['source'] = $source;
        if ($guardSlug !== '') $metadata['guard_slug'] = $guardSlug;
        if (count($accepts) > 0) $metadata['accepts'] = $accepts;
        if (count($paymentUi) > 0) $metadata['payment_ui'] = $paymentUi;
        if (count($paymentReturnSigning) > 0) $metadata['payment_return_signing'] = $paymentReturnSigning;
        if ($guardSlug !== '' && $paymentType === 'pay_by_request') {
            $metadata['usage_credit_scope'] = [
                'guard_slug' => $guardSlug,
                'trigger' => 'before:tool_run',
                'payment_type' => 'pay_by_request',
                'retry_window_sec' => self::readRetryWindowSec(
                    is_array($input['guardConfig'] ?? null) ? $input['guardConfig'] : [],
                ),
            ];
        }

        return $metadata;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function buildManagedGatewayPaymentSessionInput(array $input): array
    {
        $paymentScheme = self::readString($input['paymentScheme'] ?? $input['payment_scheme'] ?? '');
        $issuer = self::readString($input['paymentIssuer'] ?? $input['issuer'] ?? '');

        $out = [
            'amount' => $input['amount'] ?? '',
            'metadata' => self::buildManagedGatewaySessionMetadata([
                'source' => $input['source'] ?? '',
                'guardConfig' => is_array($input['guardConfig'] ?? null) ? $input['guardConfig'] : [],
                'guardSlug' => $input['guardSlug'] ?? $input['guard_slug'] ?? null,
                'paymentScheme' => $paymentScheme,
                'paymentReturnSigning' => is_array($input['paymentReturnSigning'] ?? null)
                    ? $input['paymentReturnSigning']
                    : (is_array($input['payment_return_signing'] ?? null) ? $input['payment_return_signing'] : null),
                'metadata' => is_array($input['metadata'] ?? null) ? $input['metadata'] : null,
            ]),
        ];

        foreach ([
            ['paymentSessionId', 'payment_session_id'],
            ['pageUrl', 'page_url'],
            ['xappId', 'xapp_id'],
            ['toolName', 'tool_name'],
            ['currency', 'currency'],
            ['returnUrl', 'return_url'],
            ['cancelUrl', 'cancel_url'],
            ['xappsResume', 'xapps_resume'],
            ['subjectId', 'subject_id'],
            ['installationId', 'installation_id'],
            ['clientId', 'client_id'],
        ] as [$preferredKey, $canonicalKey]) {
            $value = self::readString($input[$preferredKey] ?? $input[$canonicalKey] ?? '');
            if ($value !== '') $out[$preferredKey] = $value;
        }

        if ($issuer !== '') $out['issuer'] = $issuer;
        if ($paymentScheme !== '') $out['paymentScheme'] = $paymentScheme;

        return $out;
    }

    private static function readString($value): string
    {
        return trim((string) ($value ?? ''));
    }

    private static function readLowerString($value): string
    {
        return strtolower(self::readString($value));
    }
}
