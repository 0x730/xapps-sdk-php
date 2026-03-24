<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/index.php';

use Xapps\Dispatch;
use Xapps\PaymentReturn;
use Xapps\Signature;
use Xapps\SubjectProof;
use Xapps\XappsSdkError;

function assertTrue(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

function assertThrows(callable $fn, string $expectedCode, string $label): void
{
    try {
        $fn();
    } catch (XappsSdkError $err) {
        assertTrue($err->errorCode === $expectedCode, $label . ' threw unexpected code: ' . $err->errorCode);
        return;
    } catch (Throwable $err) {
        throw new RuntimeException($label . ' threw unexpected throwable: ' . get_class($err) . ' ' . $err->getMessage());
    }
    throw new RuntimeException($label . ' did not throw');
}

echo "xapps-php smoke: start\n";

$dispatch = Dispatch::parseRequest([
    'requestId' => 'req_demo_1',
    'toolName' => 'submit_demo',
    'payload' => ['hello' => 'world'],
]);
assertTrue($dispatch['requestId'] === 'req_demo_1', 'Dispatch parse requestId mismatch');

$signatureSecret = 'demo_shared_secret';
$canonical = "POST\n/v1/demo?x=1\n1700000000\n" . hash('sha256', '{"ok":true}');
$sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $canonical, $signatureSecret, true)), '+/', '-_'), '=');
$signatureResult = Signature::verifyXappsSignature([
    'method' => 'POST',
    'pathWithQuery' => '/v1/demo?x=1',
    'timestamp' => '1700000000',
    'body' => '{"ok":true}',
    'signature' => $sig,
    'secret' => $signatureSecret,
    'nowSeconds' => 1700000000,
]);
assertTrue(($signatureResult['ok'] ?? false) === true, 'Signature verification failed');

$paymentEvidence = [
    'contract' => PaymentReturn::CONTRACT_V1,
    'payment_session_id' => 'pay_1',
    'status' => 'paid',
    'receipt_id' => 'rcpt_1',
    'amount' => '3.00',
    'currency' => 'USD',
    'ts' => gmdate('c'),
    'issuer' => 'tenant',
    'xapp_id' => 'xapp_demo',
    'tool_name' => 'submit_demo',
    'subject_id' => 'subj_demo',
    'installation_id' => 'inst_demo',
    'client_id' => 'client_demo',
];
$paymentEvidence['sig'] = PaymentReturn::sign($paymentEvidence, 'payment_secret');
$redirectUrl = PaymentReturn::buildSignedPaymentReturnRedirectUrl(
    'https://tenant.example.test/pay/return',
    $paymentEvidence,
    'payment_secret',
    'resume_demo'
);
$parsedRedirectEvidence = PaymentReturn::parseFromQueryString(parse_url($redirectUrl, PHP_URL_QUERY) ?: '');
assertTrue(is_array($parsedRedirectEvidence), 'Payment redirect parse failed');
assertTrue(($parsedRedirectEvidence['xapp_id'] ?? '') === 'xapp_demo', 'Payment redirect xapp_id param mismatch');
assertTrue(($parsedRedirectEvidence['tool_name'] ?? '') === 'submit_demo', 'Payment redirect tool_name param mismatch');
$verifyResult = PaymentReturn::verify([
    'evidence' => $paymentEvidence,
    'secret' => 'payment_secret',
    'expected' => [
        'issuer' => 'tenant',
        'xapp_id' => 'xapp_demo',
        'tool_name' => 'submit_demo',
        'amount' => '3.00',
        'currency' => 'USD',
    ],
]);
assertTrue(($verifyResult['ok'] ?? false) === true, 'Payment verify failed');

$proofResult = SubjectProof::verifySubjectProofEnvelope(
    ['subjectActionPayload' => '{"action":"approve"}', 'subjectProof' => ['kind' => 'none']],
    [
        'verifySubjectProofEnvelope' => static function (array $input): array {
            return ['ok' => true];
        },
        'verifyJwsSubjectProof' => static fn(array $input): array => ['ok' => true],
        'verifyWebauthnSubjectProof' => static fn(array $input): array => ['ok' => true],
    ],
);
assertTrue(($proofResult['ok'] ?? false) === true, 'Subject proof verify failed');

assertThrows(
    static function (): void {
        new Xapps\CallbackClient('', 'token');
    },
    XappsSdkError::INVALID_ARGUMENT,
    'CallbackClient invalid argument',
);

assertThrows(
    static function (): void {
        new Xapps\GatewayClient('', 'key');
    },
    XappsSdkError::INVALID_ARGUMENT,
    'GatewayClient invalid argument',
);

$gateway = new Xapps\GatewayClient('http://localhost:3000', 'xapps_test_key_demo');
assertThrows(
    static function () use ($gateway): void {
        $gateway->getPaymentSession('');
    },
    XappsSdkError::INVALID_ARGUMENT,
    'GatewayClient getPaymentSession invalid argument',
);

echo "xapps-php smoke: ok\n";
