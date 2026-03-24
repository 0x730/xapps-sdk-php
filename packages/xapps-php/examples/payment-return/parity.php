<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/index.php';

use Xapps\PaymentReturn;

function assertSameValue($actual, $expected, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function assertTrueValue(bool $actual, string $message): void
{
    if (!$actual) {
        throw new RuntimeException($message);
    }
}

echo "xapps-php payment-return parity: start\n";

$secret = 'golden_secret';
$evidence = [
    'contract' => PaymentReturn::CONTRACT_V1,
    'payment_session_id' => 'pay_golden_1',
    'status' => 'paid',
    'receipt_id' => 'rcpt_golden_1',
    'amount' => '3.00',
    'currency' => 'USD',
    'ts' => '2026-02-24T12:34:56.000Z',
    'issuer' => 'tenant',
    'xapp_id' => 'xapp_golden',
    'tool_name' => 'submit_form',
    'subject_id' => 'subj_1',
    'installation_id' => 'inst_1',
    'client_id' => 'client_1',
];

$expectedCanonical = "contract=xapps_payment_orchestration_v1\n"
    . "payment_session_id=pay_golden_1\n"
    . "status=paid\n"
    . "receipt_id=rcpt_golden_1\n"
    . "amount=3.00\n"
    . "currency=USD\n"
    . "ts=2026-02-24T12:34:56.000Z\n"
    . "issuer=tenant\n"
    . "xapp_id=xapp_golden\n"
    . "tool_name=submit_form\n"
    . "subject_id=subj_1\n"
    . "installation_id=inst_1\n"
    . "client_id=client_1";
$expectedSig = 't9yTNkCjsxMlyZOuDJXqQ26UXvsc65gSsV_qSG3eNQg';
$expectedQuery = 'xapps_payment_contract=xapps_payment_orchestration_v1'
    . '&xapps_payment_session_id=pay_golden_1'
    . '&xapps_payment_status=paid'
    . '&xapps_payment_receipt_id=rcpt_golden_1'
    . '&xapps_payment_amount=3.00'
    . '&xapps_payment_currency=USD'
    . '&xapps_payment_ts=2026-02-24T12%3A34%3A56.000Z'
    . '&xapps_payment_issuer=tenant'
    . '&xapps_payment_subject_id=subj_1'
    . '&xapps_payment_installation_id=inst_1'
    . '&xapps_payment_client_id=client_1'
    . '&xapps_payment_sig=' . rawurlencode($expectedSig)
    . '&xapp_id=xapp_golden'
    . '&tool_name=submit_form'
    . '&xapps_resume=resume_1';

$canonical = PaymentReturn::buildPaymentReturnCanonicalString($evidence);
assertSameValue($canonical, $expectedCanonical, 'Canonical payment-return string drifted from Node golden vector');

$sig = PaymentReturn::signPaymentReturnEvidence($evidence, $secret);
assertSameValue($sig, $expectedSig, 'Payment-return signature drifted from Node golden vector');

$signedEvidence = $evidence;
$signedEvidence['sig'] = $sig;
$queryParams = PaymentReturn::buildPaymentReturnQueryParams($signedEvidence, 'resume_1');
$query = http_build_query($queryParams);
assertSameValue($query, $expectedQuery, 'Payment-return query params drifted from Node golden vector');

$redirect = PaymentReturn::buildSignedPaymentReturnRedirectUrl(
    'https://tenant.example.test/return?foo=1',
    $evidence,
    $secret,
    'resume_1'
);
$expectedRedirect = 'https://tenant.example.test/return?foo=1&' . $expectedQuery;
assertSameValue($redirect, $expectedRedirect, 'Payment-return redirect URL drifted from Node golden vector');

$parsed = PaymentReturn::parsePaymentReturnEvidenceFromSearch((string) parse_url($redirect, PHP_URL_QUERY));
assertTrueValue(is_array($parsed), 'Failed to parse payment evidence from generated redirect');
assertSameValue($parsed['xapp_id'] ?? '', 'xapp_golden', 'Parsed xapp_id mismatch');
assertSameValue($parsed['tool_name'] ?? '', 'submit_form', 'Parsed tool_name mismatch');

$verify = PaymentReturn::verifyPaymentReturnEvidence([
    'evidence' => $parsed,
    'secret' => $secret,
    'expected' => [
        'issuer' => 'tenant',
        'xapp_id' => 'xapp_golden',
        'tool_name' => 'submit_form',
        'amount' => '3.00',
        'currency' => 'USD',
    ],
    'nowMs' => 1771936496000, // 2026-02-24T12:34:56.000Z
]);
assertTrueValue(($verify['ok'] ?? false) === true, 'Verify failed for golden vector');

$missingXapp = PaymentReturn::parsePaymentReturnEvidence([
    'xapps_payment_contract' => PaymentReturn::CONTRACT_V1,
    'xapps_payment_session_id' => 'pay_bad',
    'xapps_payment_status' => 'paid',
    'xapps_payment_receipt_id' => 'rcpt_bad',
    'xapps_payment_amount' => '1.00',
    'xapps_payment_currency' => 'USD',
    'xapps_payment_ts' => '2026-02-24T12:34:56.000Z',
    'xapps_payment_issuer' => 'tenant',
    'tool_name' => 'submit_form',
    'xapps_payment_sig' => 'abc',
]);
assertSameValue($missingXapp, null, 'Malformed evidence should parse as null');

$legacyPrefixed = PaymentReturn::parsePaymentReturnEvidence([
    'xapps_payment_contract' => PaymentReturn::CONTRACT_V1,
    'xapps_payment_session_id' => 'pay_legacy',
    'xapps_payment_status' => 'paid',
    'xapps_payment_receipt_id' => 'rcpt_legacy',
    'xapps_payment_amount' => '1.00',
    'xapps_payment_currency' => 'USD',
    'xapps_payment_ts' => '2026-02-24T12:34:56.000Z',
    'xapps_payment_issuer' => 'tenant',
    'xapps_payment_xapp_id' => 'xapp_legacy',
    'xapps_payment_tool_name' => 'submit_legacy',
    'xapps_payment_sig' => 'abc',
]);
assertTrueValue(is_array($legacyPrefixed), 'Legacy prefixed xapp/tool parse compatibility regressed');
assertSameValue($legacyPrefixed['xapp_id'] ?? '', 'xapp_legacy', 'Legacy xapp parse mismatch');
assertSameValue($legacyPrefixed['tool_name'] ?? '', 'submit_legacy', 'Legacy tool parse mismatch');

// Optional delegated audit metadata fields are additive and must be included
// in canonical/sign/query behavior when present.
$withAudit = $evidence;
$withAudit['authority_lane'] = 'tenant_delegated';
$withAudit['signing_lane'] = 'tenant_delegated';
$withAudit['resolver_source'] = 'guard_config_delegated_lane';
$withAuditSig = PaymentReturn::signPaymentReturnEvidence($withAudit, $secret);
$withAuditSigned = $withAudit;
$withAuditSigned['sig'] = $withAuditSig;
$withAuditQueryParams = PaymentReturn::buildPaymentReturnQueryParams($withAuditSigned);
assertSameValue(
    $withAuditQueryParams['xapps_payment_authority_lane'] ?? '',
    'tenant_delegated',
    'Audit authority_lane query serialization mismatch'
);
assertSameValue(
    $withAuditQueryParams['xapps_payment_signing_lane'] ?? '',
    'tenant_delegated',
    'Audit signing_lane query serialization mismatch'
);
assertSameValue(
    $withAuditQueryParams['xapps_payment_resolver_source'] ?? '',
    'guard_config_delegated_lane',
    'Audit resolver_source query serialization mismatch'
);
$parsedWithAudit = PaymentReturn::parsePaymentReturnEvidence($withAuditQueryParams);
assertTrueValue(is_array($parsedWithAudit), 'Failed to parse evidence with optional audit fields');
assertSameValue(
    $parsedWithAudit['signing_lane'] ?? '',
    'tenant_delegated',
    'Audit signing_lane parse mismatch'
);
assertSameValue(
    $parsedWithAudit['resolver_source'] ?? '',
    'guard_config_delegated_lane',
    'Audit resolver_source parse mismatch'
);

echo "xapps-php payment-return parity: ok\n";
