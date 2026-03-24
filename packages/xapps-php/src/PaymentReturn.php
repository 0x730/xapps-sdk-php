<?php

declare(strict_types=1);

namespace Xapps;

final class PaymentReturn
{
    public const CONTRACT_V1 = 'xapps_payment_orchestration_v1';
    private const CANONICAL_AMOUNT_PATTERN = '/^\d+\.\d{2}$/';
    private const DECIMAL_AMOUNT_PATTERN = '/^\d+(?:\.\d+)?$/';
    /** @var array<int,string> */
    private const DEFAULT_ALLOWED_ISSUERS = ['gateway', 'tenant', 'publisher', 'tenant_delegated', 'publisher_delegated'];

    /** @var array<int,string> */
    private const CANONICAL_FIELDS = [
        'contract',
        'payment_session_id',
        'status',
        'receipt_id',
        'amount',
        'currency',
        'ts',
        'issuer',
        'xapp_id',
        'tool_name',
        'subject_id',
        'installation_id',
        'client_id',
    ];

    /** @return array<string,string>|null */
    public static function parseFromQueryString(string $query): ?array
    {
        parse_str($query, $params);
        return self::parsePaymentReturnEvidence($params);
    }

    /** @param array<string,mixed> $params @return array<string,string>|null */
    public static function parsePaymentReturnEvidence(array $params): ?array
    {
        $contract = strtolower(self::readStringField($params, ['xapps_payment_contract', 'contract']));
        $paymentSessionId = self::readStringField($params, ['xapps_payment_session_id', 'payment_session_id']);
        $status = strtolower(self::readStringField($params, ['xapps_payment_status', 'status']));
        $receiptId = self::readStringField($params, ['xapps_payment_receipt_id', 'receipt_id']);
        $amount = self::canonicalizeAmount(self::readStringField($params, ['xapps_payment_amount', 'amount']));
        $currency = strtoupper(self::readStringField($params, ['xapps_payment_currency', 'currency']));
        $ts = self::readStringField($params, ['xapps_payment_ts', 'ts']);
        $issuer = strtolower(self::readStringField($params, ['xapps_payment_issuer', 'issuer']));
        // Current contract keeps xapp/tool as plain query params; legacy prefixed names are tolerated on parse.
        $xappId = self::readStringField($params, ['xapp_id', 'xapps_payment_xapp_id']);
        $toolName = self::readStringField($params, ['tool_name', 'xapps_payment_tool_name']);
        $sig = self::readStringField($params, ['xapps_payment_sig', 'sig']);
        $subjectId = self::readStringField($params, ['xapps_payment_subject_id', 'subject_id']);
        $installationId = self::readStringField($params, ['xapps_payment_installation_id', 'installation_id']);
        $clientId = self::readStringField($params, ['xapps_payment_client_id', 'client_id']);
        $authorityLane = self::readStringField($params, ['xapps_payment_authority_lane', 'authority_lane']);
        $signingLane = self::readStringField($params, ['xapps_payment_signing_lane', 'signing_lane']);
        $resolverSource = self::readStringField($params, ['xapps_payment_resolver_source', 'resolver_source']);

        if (
            $contract === '' ||
            $paymentSessionId === '' ||
            $status === '' ||
            $receiptId === '' ||
            $amount === '' ||
            $currency === '' ||
            $ts === '' ||
            $issuer === '' ||
            $xappId === '' ||
            $toolName === '' ||
            $sig === ''
        ) {
            return null;
        }

        if ($contract !== self::CONTRACT_V1) {
            return null;
        }

        $parsed = [
            'contract' => self::CONTRACT_V1,
            'payment_session_id' => $paymentSessionId,
            'status' => $status,
            'receipt_id' => $receiptId,
            'amount' => $amount,
            'currency' => $currency,
            'ts' => $ts,
            'issuer' => $issuer,
            'xapp_id' => $xappId,
            'tool_name' => $toolName,
            'subject_id' => $subjectId,
            'installation_id' => $installationId,
            'client_id' => $clientId,
            'sig' => $sig,
        ];
        if ($authorityLane !== '') {
            $parsed['authority_lane'] = $authorityLane;
        }
        if ($signingLane !== '') {
            $parsed['signing_lane'] = $signingLane;
        }
        if ($resolverSource !== '') {
            $parsed['resolver_source'] = $resolverSource;
        }
        return $parsed;
    }

    /** @return array<string,string>|null */
    public static function parsePaymentReturnEvidenceFromSearch(string $search): ?array
    {
        return self::parseFromQueryString(ltrim($search, '?'));
    }

    public static function buildCanonicalString(array $evidence): string
    {
        $normalized = self::normalizeEvidenceForSigning($evidence);
        $lines = [];
        foreach (self::CANONICAL_FIELDS as $field) {
            $lines[] = $field . '=' . (string) ($normalized[$field] ?? '');
        }
        $authorityLane = trim((string) ($normalized['authority_lane'] ?? ''));
        $signingLane = trim((string) ($normalized['signing_lane'] ?? ''));
        $resolverSource = trim((string) ($normalized['resolver_source'] ?? ''));
        if ($authorityLane !== '' || $signingLane !== '' || $resolverSource !== '') {
            $lines[] = 'authority_lane=' . $authorityLane;
            $lines[] = 'signing_lane=' . $signingLane;
            $lines[] = 'resolver_source=' . $resolverSource;
        }
        return implode("\n", $lines);
    }

    public static function buildPaymentReturnCanonicalString(array $evidence): string
    {
        return self::buildCanonicalString($evidence);
    }

    public static function sign(array $evidence, string $secret): string
    {
        $canonical = self::buildCanonicalString($evidence);
        $mac = hash_hmac('sha256', $canonical, $secret, true);
        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    public static function signPaymentReturnEvidence(array $evidence, string $secret): string
    {
        return self::sign($evidence, $secret);
    }

    /** @return array{ok:bool,reason:string|null,details:array<string,mixed>} */
    public static function verify(array $input): array
    {
        $evidenceRaw = $input['evidence'] ?? null;
        if (!is_array($evidenceRaw)) {
            return ['ok' => false, 'reason' => 'payment_evidence_malformed', 'details' => ['message' => 'evidence must be an object']];
        }
        $evidence = $evidenceRaw;
        $secret = trim((string) ($input['secret'] ?? ''));
        $expected = is_array($input['expected'] ?? null) ? $input['expected'] : [];
        $maxAgeSeconds = max(30, (int) ($input['maxAgeSeconds'] ?? 900));
        $nowMs = (int) ($input['nowMs'] ?? ((int) floor(microtime(true) * 1000)));

        $requiredCoreFields = [
            'contract',
            'payment_session_id',
            'status',
            'receipt_id',
            'amount',
            'currency',
            'ts',
            'issuer',
            'xapp_id',
            'tool_name',
            'sig',
        ];
        foreach ($requiredCoreFields as $field) {
            if (trim((string) ($evidence[$field] ?? '')) === '') {
                return ['ok' => false, 'reason' => 'payment_evidence_malformed', 'details' => ['field' => $field]];
            }
        }

        $contract = strtolower(trim((string) ($evidence['contract'] ?? '')));
        if ($contract !== self::CONTRACT_V1) {
            return ['ok' => false, 'reason' => 'payment_evidence_contract_unsupported', 'details' => ['contract' => $contract]];
        }

        $status = strtolower(trim((string) ($evidence['status'] ?? '')));
        if ($status !== 'paid' && ($expected['require_paid_status'] ?? true)) {
            return ['ok' => false, 'reason' => 'payment_not_settled', 'details' => ['status' => ($evidence['status'] ?? null)]];
        }

        $ts = trim((string) ($evidence['ts'] ?? ''));
        $tsMs = strtotime($ts);
        if ($ts === '' || $tsMs === false) {
            return ['ok' => false, 'reason' => 'payment_evidence_timestamp_invalid', 'details' => ['ts' => $ts]];
        }
        if (abs($nowMs - ((int) $tsMs * 1000)) > ($maxAgeSeconds * 1000)) {
            return ['ok' => false, 'reason' => 'payment_evidence_expired', 'details' => ['ts' => $ts, 'max_age_s' => $maxAgeSeconds]];
        }

        if ($secret === '') {
            return ['ok' => false, 'reason' => 'payment_signature_invalid', 'details' => ['message' => 'secret is required']];
        }

        $providedSig = trim((string) ($evidence['sig'] ?? ''));
        $computedSig = self::sign(self::normalizeEvidenceForSigning($evidence), $secret);
        if ($providedSig === '' || !hash_equals($computedSig, $providedSig)) {
            return ['ok' => false, 'reason' => 'payment_signature_invalid', 'details' => ['issuer' => ($evidence['issuer'] ?? null), 'contract' => ($evidence['contract'] ?? null)]];
        }

        $issuer = strtolower(trim((string) ($evidence['issuer'] ?? '')));
        $allowedIssuers = [];
        if (isset($expected['issuer']) && trim((string) $expected['issuer']) !== '') {
            $allowedIssuers[] = strtolower(trim((string) $expected['issuer']));
        }
        if (isset($expected['issuers']) && is_array($expected['issuers'])) {
            foreach ($expected['issuers'] as $value) {
                $issuerValue = strtolower(trim((string) $value));
                if ($issuerValue !== '') {
                    $allowedIssuers[] = $issuerValue;
                }
            }
        }
        if (count($allowedIssuers) === 0) {
            $allowedIssuers = self::DEFAULT_ALLOWED_ISSUERS;
        }
        if (!in_array($issuer, $allowedIssuers, true)) {
            return ['ok' => false, 'reason' => 'payment_issuer_not_allowed', 'details' => ['issuer' => $issuer, 'allowed_issuers' => array_values(array_unique($allowedIssuers))]];
        }

        foreach (['xapp_id', 'tool_name', 'subject_id', 'installation_id', 'client_id'] as $field) {
            $expectedValue = trim((string) ($expected[$field] ?? ''));
            if ($expectedValue === '') {
                continue;
            }
            $actualValue = trim((string) ($evidence[$field] ?? ''));
            if ($actualValue !== $expectedValue) {
                return ['ok' => false, 'reason' => 'payment_context_mismatch', 'details' => ['field' => $field, 'expected' => $expectedValue, 'actual' => ($actualValue !== '' ? $actualValue : null)]];
            }
        }

        $actualAmountCanonical = self::canonicalizeAmount((string) ($evidence['amount'] ?? ''));
        if ($actualAmountCanonical === '') {
            return ['ok' => false, 'reason' => 'payment_evidence_malformed', 'details' => ['field' => 'amount', 'amount' => ($evidence['amount'] ?? null)]];
        }
        if (isset($expected['amount']) && trim((string) $expected['amount']) !== '') {
            $expectedAmountCanonical = self::canonicalizeAmount((string) $expected['amount']);
            if ($expectedAmountCanonical === '' || $actualAmountCanonical !== $expectedAmountCanonical) {
                return ['ok' => false, 'reason' => 'payment_amount_mismatch', 'details' => ['expected_amount' => ($expectedAmountCanonical !== '' ? $expectedAmountCanonical : trim((string) $expected['amount'])), 'actual_amount' => $actualAmountCanonical]];
            }
        }

        if (isset($expected['currency']) && trim((string) $expected['currency']) !== '') {
            $expectedCurrency = strtoupper(trim((string) $expected['currency']));
            $actualCurrency = strtoupper(trim((string) ($evidence['currency'] ?? '')));
            if ($actualCurrency !== $expectedCurrency) {
                return ['ok' => false, 'reason' => 'payment_currency_mismatch', 'details' => ['expected_currency' => $expectedCurrency, 'actual_currency' => ($actualCurrency !== '' ? $actualCurrency : null)]];
            }
        }

        return ['ok' => true, 'reason' => null, 'details' => []];
    }

    /** @return array{ok:bool,reason:string|null,details:array<string,mixed>} */
    public static function verifyPaymentReturnEvidence(array $input): array
    {
        return self::verify($input);
    }

    public static function buildSignedRedirectUrl(string $returnUrl, array $evidence, string $secret, ?string $resumeToken = null): string
    {
        $normalized = self::normalizeEvidenceForSigning([
            'contract' => ($evidence['contract'] ?? self::CONTRACT_V1),
            'payment_session_id' => ($evidence['payment_session_id'] ?? ''),
            'status' => ($evidence['status'] ?? 'paid'),
            'receipt_id' => ($evidence['receipt_id'] ?? ''),
            'amount' => ($evidence['amount'] ?? ''),
            'currency' => ($evidence['currency'] ?? 'USD'),
            'ts' => ($evidence['ts'] ?? gmdate('c')),
            'issuer' => ($evidence['issuer'] ?? 'tenant'),
            'xapp_id' => ($evidence['xapp_id'] ?? ''),
            'tool_name' => ($evidence['tool_name'] ?? ''),
            'subject_id' => ($evidence['subject_id'] ?? ''),
            'installation_id' => ($evidence['installation_id'] ?? ''),
            'client_id' => ($evidence['client_id'] ?? ''),
            'authority_lane' => ($evidence['authority_lane'] ?? ''),
            'signing_lane' => ($evidence['signing_lane'] ?? ''),
            'resolver_source' => ($evidence['resolver_source'] ?? ''),
        ]);
        $normalized['sig'] = self::sign($normalized, $secret);

        $url = $returnUrl;
        $separator = str_contains($url, '?') ? '&' : '?';
        $query = [
            'xapps_payment_contract' => $normalized['contract'],
            'xapps_payment_session_id' => $normalized['payment_session_id'],
            'xapps_payment_status' => $normalized['status'],
            'xapps_payment_receipt_id' => $normalized['receipt_id'],
            'xapps_payment_amount' => $normalized['amount'],
            'xapps_payment_currency' => $normalized['currency'],
            'xapps_payment_ts' => $normalized['ts'],
            'xapps_payment_issuer' => $normalized['issuer'],
        ];
        if ($normalized['subject_id'] !== '') {
            $query['xapps_payment_subject_id'] = $normalized['subject_id'];
        }
        if ($normalized['installation_id'] !== '') {
            $query['xapps_payment_installation_id'] = $normalized['installation_id'];
        }
        if ($normalized['client_id'] !== '') {
            $query['xapps_payment_client_id'] = $normalized['client_id'];
        }
        if ($normalized['authority_lane'] !== '') {
            $query['xapps_payment_authority_lane'] = $normalized['authority_lane'];
        }
        if ($normalized['signing_lane'] !== '') {
            $query['xapps_payment_signing_lane'] = $normalized['signing_lane'];
        }
        if ($normalized['resolver_source'] !== '') {
            $query['xapps_payment_resolver_source'] = $normalized['resolver_source'];
        }
        $query['xapps_payment_sig'] = $normalized['sig'];
        $query['xapp_id'] = $normalized['xapp_id'];
        $query['tool_name'] = $normalized['tool_name'];
        if ($resumeToken !== null && trim($resumeToken) !== '') {
            $query['xapps_resume'] = trim($resumeToken);
        }

        return $url . $separator . http_build_query($query);
    }

    public static function buildSignedPaymentReturnRedirectUrl(string $returnUrl, array $evidence, string $secret, ?string $resumeToken = null): string
    {
        return self::buildSignedRedirectUrl($returnUrl, $evidence, $secret, $resumeToken);
    }

    /** @return array<string,string> */
    public static function buildPaymentReturnQueryParams(array $evidence, ?string $resumeToken = null): array
    {
        $normalized = self::normalizeEvidenceForSigning($evidence);
        $params = [
            'xapps_payment_contract' => $normalized['contract'],
            'xapps_payment_session_id' => $normalized['payment_session_id'],
            'xapps_payment_status' => $normalized['status'],
            'xapps_payment_receipt_id' => $normalized['receipt_id'],
            'xapps_payment_amount' => $normalized['amount'],
            'xapps_payment_currency' => $normalized['currency'],
            'xapps_payment_ts' => $normalized['ts'],
            'xapps_payment_issuer' => $normalized['issuer'],
        ];
        if ($normalized['subject_id'] !== '') {
            $params['xapps_payment_subject_id'] = $normalized['subject_id'];
        }
        if ($normalized['installation_id'] !== '') {
            $params['xapps_payment_installation_id'] = $normalized['installation_id'];
        }
        if ($normalized['client_id'] !== '') {
            $params['xapps_payment_client_id'] = $normalized['client_id'];
        }
        if ($normalized['authority_lane'] !== '') {
            $params['xapps_payment_authority_lane'] = $normalized['authority_lane'];
        }
        if ($normalized['signing_lane'] !== '') {
            $params['xapps_payment_signing_lane'] = $normalized['signing_lane'];
        }
        if ($normalized['resolver_source'] !== '') {
            $params['xapps_payment_resolver_source'] = $normalized['resolver_source'];
        }
        $params['xapps_payment_sig'] = trim((string) ($evidence['sig'] ?? ''));
        $params['xapp_id'] = $normalized['xapp_id'];
        $params['tool_name'] = $normalized['tool_name'];
        if ($resumeToken !== null && trim($resumeToken) !== '') {
            $params['xapps_resume'] = trim($resumeToken);
        }
        return $params;
    }

    /**
     * Resolve a secret reference string.
     *
     * Native SDK support: `env:`, `file:`.
     * External scheme support (`vault://`, `awssm://`, `platform://`) is additive via
     * resolver callbacks passed in `$options`.
     *
     * Supported options:
     * - resolveSecretRef: callable(string $ref, string $scheme): ?string
     * - resolvers: array<string, callable(string $ref, string $scheme): ?string>
     *
     * @param array<string,mixed> $options
     * @throws \RuntimeException if the ref scheme is unsupported or the value cannot be resolved
     */
    public static function resolveSecretFromRef(string $ref, array $options = []): string
    {
        $trimmed = trim($ref);
        if (str_starts_with($trimmed, 'env:')) {
            $envName = trim(substr($trimmed, 4));
            if ($envName === '') {
                throw new \RuntimeException('Secret ref env: requires a variable name');
            }
            $val = getenv($envName);
            if ($val === false || $val === '') {
                throw new \RuntimeException("Secret ref env:{$envName} not found in environment");
            }
            return $val;
        }
        if (str_starts_with($trimmed, 'file:')) {
            $filePath = trim(substr($trimmed, 5));
            if ($filePath === '') {
                throw new \RuntimeException('Secret ref file: requires a file path');
            }
            $resolved = realpath($filePath);
            if ($resolved === false) {
                throw new \RuntimeException("Secret ref file: cannot resolve path: {$filePath}");
            }
            if (!is_file($resolved)) {
                throw new \RuntimeException("Secret ref file: path is not a regular file: {$resolved}");
            }
            if (filesize($resolved) > 8192) {
                throw new \RuntimeException("Secret ref file: file exceeds 8192 byte limit");
            }
            $content = trim((string) file_get_contents($resolved));
            if ($content === '') {
                throw new \RuntimeException("Secret ref file: file is empty after trimming");
            }
            return $content;
        }
        if (
            str_starts_with($trimmed, 'vault://')
            || str_starts_with($trimmed, 'awssm://')
            || str_starts_with($trimmed, 'platform://')
        ) {
            $resolved = self::resolveExternalSecretRef($trimmed, $options);
            if ($resolved !== null && $resolved !== '') {
                return $resolved;
            }
            $scheme = self::extractSecretRefScheme($trimmed) ?? 'external';
            throw new \RuntimeException(
                "Secret ref {$scheme} requires resolver callback. "
                . "Provide options['resolveSecretRef'] or options['resolvers'][scheme].",
            );
        }
        throw new \RuntimeException(
            "Unsupported secret ref scheme in SDK: {$trimmed}. "
            . "SDK supports env:, file:, and resolver-adapted vault://, awssm://, platform://.",
        );
    }

    /** @param array<string,mixed> $options */
    private static function resolveExternalSecretRef(string $ref, array $options): ?string
    {
        $scheme = self::extractSecretRefScheme($ref);
        if ($scheme === null) {
            return null;
        }
        $resolver = null;
        if (isset($options['resolvers']) && is_array($options['resolvers'])) {
            $candidate = $options['resolvers'][$scheme] ?? null;
            if (is_callable($candidate)) {
                $resolver = $candidate;
            }
        }
        if ($resolver === null && isset($options['resolveSecretRef']) && is_callable($options['resolveSecretRef'])) {
            $resolver = $options['resolveSecretRef'];
        }
        if ($resolver === null) {
            return null;
        }
        $raw = $resolver($ref, $scheme);
        if ($raw === null) {
            return null;
        }
        $value = trim((string) $raw);
        if ($value === '') {
            throw new \RuntimeException("Secret ref {$scheme} resolved to empty value");
        }
        return $value;
    }

    private static function extractSecretRefScheme(string $ref): ?string
    {
        $trimmed = trim($ref);
        if ($trimmed === '') {
            return null;
        }
        if (str_starts_with($trimmed, 'env:')) {
            return 'env';
        }
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):\\/\\//', $trimmed, $m)) {
            return strtolower((string) ($m[1] ?? ''));
        }
        return null;
    }

    /** @return array<string,string> */
    private static function normalizeEvidenceForSigning(array $evidence): array
    {
        return [
            'contract' => strtolower(trim((string) ($evidence['contract'] ?? self::CONTRACT_V1))),
            'payment_session_id' => trim((string) ($evidence['payment_session_id'] ?? '')),
            'status' => strtolower(trim((string) ($evidence['status'] ?? ''))),
            'receipt_id' => trim((string) ($evidence['receipt_id'] ?? '')),
            'amount' => self::canonicalizeAmount((string) ($evidence['amount'] ?? '')),
            'currency' => strtoupper(trim((string) ($evidence['currency'] ?? ''))),
            'ts' => trim((string) ($evidence['ts'] ?? '')),
            'issuer' => strtolower(trim((string) ($evidence['issuer'] ?? 'tenant'))),
            'xapp_id' => trim((string) ($evidence['xapp_id'] ?? '')),
            'tool_name' => trim((string) ($evidence['tool_name'] ?? '')),
            'subject_id' => trim((string) ($evidence['subject_id'] ?? '')),
            'installation_id' => trim((string) ($evidence['installation_id'] ?? '')),
            'client_id' => trim((string) ($evidence['client_id'] ?? '')),
            'authority_lane' => trim((string) ($evidence['authority_lane'] ?? '')),
            'signing_lane' => trim((string) ($evidence['signing_lane'] ?? '')),
            'resolver_source' => trim((string) ($evidence['resolver_source'] ?? '')),
        ];
    }

    private static function canonicalizeAmount(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        if (preg_match(self::CANONICAL_AMOUNT_PATTERN, $trimmed) === 1) {
            return $trimmed;
        }
        if (preg_match(self::DECIMAL_AMOUNT_PATTERN, $trimmed) !== 1) {
            return '';
        }
        $num = (float) $trimmed;
        if (!is_finite($num) || $num < 0) {
            return '';
        }
        return number_format($num, 2, '.', '');
    }

    /** @param array<string,mixed> $params @param array<int,string> $keys */
    private static function readStringField(array $params, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $params)) {
                continue;
            }
            $value = $params[$key];
            if (is_array($value)) {
                continue;
            }
            $normalized = trim((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }
        return '';
    }
}
