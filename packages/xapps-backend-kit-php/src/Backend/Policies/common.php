<?php

declare(strict_types=1);

use Xapps\BackendKit\BackendKit;
use Xapps\HostedGatewayPaymentSession;
use Xapps\PaymentPolicySupport;

function xapps_backend_kit_payment_numeric_amount(mixed $amount): mixed
{
    if (is_int($amount) || is_float($amount)) {
        return $amount;
    }
    if (is_string($amount) && is_numeric($amount)) {
        return (float) $amount;
    }
    return $amount;
}

function xapps_backend_kit_resolve_localized_text(mixed $value, string $locale, string $fallback = 'en'): string
{
    if (is_string($value)) {
        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : '';
    }
    if (!is_array($value)) return '';
    $normalized = trim(str_replace('_', '-', $locale));
    $normalized = $normalized !== '' ? $normalized : $fallback;
    $candidates = [$normalized];
    $dashPos = strpos($normalized, '-');
    if ($dashPos !== false && $dashPos > 0) {
        $candidates[] = substr($normalized, 0, $dashPos);
    }
    if (!in_array($fallback, $candidates, true)) {
        $candidates[] = $fallback;
    }
    if (!in_array('en', $candidates, true)) {
        $candidates[] = 'en';
    }
    foreach ($candidates as $candidate) {
        $resolved = $value[$candidate] ?? null;
        if (is_string($resolved) && trim($resolved) !== '') {
            return trim($resolved);
        }
    }
    foreach ($value as $resolved) {
        if (is_string($resolved) && trim($resolved) !== '') {
            return trim($resolved);
        }
    }
    return '';
}

function xapps_backend_kit_build_payment_policy_input(array $payload, array $app, array $modeMeta): array
{
    $context = PaymentPolicySupport::resolveMergedPaymentGuardContext($payload);
    $guard = xapps_backend_kit_read_record($payload['guard'] ?? null);
    $guardConfig = xapps_backend_kit_read_record($guard['config'] ?? null);
    $policy = xapps_backend_kit_read_record($guardConfig['policy'] ?? null);
    $actionCfg = xapps_backend_kit_read_record($guardConfig['action'] ?? null);
    $guardSlug = xapps_backend_kit_read_string($guard['slug'] ?? null, 'xconect-tenant-payment-policy');

    $amount = PaymentPolicySupport::resolvePaymentGuardPriceAmount($guardConfig, $context);
    $currency = strtoupper(xapps_backend_kit_read_string(
        xapps_backend_kit_read_record($guardConfig['pricing'] ?? null)['currency'] ?? null,
        $guardConfig['currency'] ?? null,
        'USD',
    ));
    $allowedIssuers = PaymentPolicySupport::normalizePaymentAllowedIssuers(
        $guardConfig,
        (string) $modeMeta['expectedIssuer'],
    );
    $plainStatus = strtolower(xapps_backend_kit_read_string(
        $payload['payment_status'] ?? null,
        xapps_backend_kit_read_record($payload['payment'] ?? null)['status'] ?? null,
    ));
    $policyContext = xapps_backend_kit_read_record($payload['policyContext'] ?? null);
    $gatewayPaymentVerified =
        PaymentPolicySupport::hasUpstreamPaymentVerified($payload['payment_verified'] ?? null)
        || PaymentPolicySupport::hasUpstreamPaymentVerified($payload['paymentVerified'] ?? null)
        || PaymentPolicySupport::hasUpstreamPaymentVerified($policyContext['payment_verified'] ?? null)
        || PaymentPolicySupport::hasUpstreamPaymentVerified($policyContext['paymentVerified'] ?? null);

    $orchestration = xapps_backend_kit_read_record($context['orchestration'] ?? null);
    $orchestrationEntry = xapps_backend_kit_read_record($orchestration[$guardSlug] ?? null);
    $orchestrationPayment = xapps_backend_kit_read_record($orchestrationEntry['payment'] ?? null);
    $verifiedPayment = null;
    $verificationFailure = null;

    if (
        $gatewayPaymentVerified
        && strtolower(xapps_backend_kit_read_string($orchestrationPayment['status'] ?? null)) === 'paid'
    ) {
        $verifiedPayment = $orchestrationPayment;
    } else {
        $verifyResult = BackendKit::verifyPaymentEvidence($app, $payload, [
            'issuer' => (string) $modeMeta['expectedIssuer'],
            'issuers' => $allowedIssuers,
            'amount' => $amount,
            'currency' => $currency,
            'xapp_id' => xapps_backend_kit_optional_string($context['xappId'] ?? null, $context['xapp_id'] ?? null),
            'tool_name' => xapps_backend_kit_optional_string($context['toolName'] ?? null, $context['tool_name'] ?? null),
            'subject_id' => xapps_backend_kit_optional_string($context['subjectId'] ?? null, $context['subject_id'] ?? null),
            'installation_id' => xapps_backend_kit_optional_string($context['installationId'] ?? null, $context['installation_id'] ?? null),
            'client_id' => xapps_backend_kit_optional_string($context['clientId'] ?? null, $context['client_id'] ?? null),
        ], (int) ($guardConfig['payment_return_max_age_s'] ?? 900));
        if (($verifyResult['ok'] ?? false) === true) {
            $verifiedPayment = is_array($verifyResult['evidence'] ?? null) ? $verifyResult['evidence'] : null;
        } elseif (($verifyResult['reason'] ?? '') !== 'payment_evidence_not_found' && ($verifyResult['reason'] ?? '') !== 'verification_secret_missing') {
            $verificationFailure = $verifyResult;
        }
    }

    return [
        'payload' => $payload,
        'context' => $context,
        'guard' => $guard,
        'guardConfig' => $guardConfig,
        'policy' => $policy,
        'actionCfg' => $actionCfg,
        'guardSlug' => $guardSlug,
        'amount' => $amount,
        'currency' => $currency,
        'allowedIssuers' => $allowedIssuers,
        'plainStatus' => $plainStatus,
        'gatewayPaymentVerified' => $gatewayPaymentVerified,
        'verifiedPayment' => $verifiedPayment,
        'verificationFailure' => $verificationFailure,
        'paidByVerifiedEvidence' => is_array($verifiedPayment) && count($verifiedPayment) > 0,
        'modeMeta' => $modeMeta,
    ];
}

function xapps_backend_kit_build_payment_policy_allowed_result(array $input): array
{
    $verifiedPayment = xapps_backend_kit_read_record($input['verifiedPayment'] ?? null);
    $modeMeta = xapps_backend_kit_read_record($input['modeMeta'] ?? null);
    $policy = xapps_backend_kit_read_record($input['policy'] ?? null);
    $plainStatus = xapps_backend_kit_read_string($input['plainStatus'] ?? null);

    $details = [
        'payment_status' => xapps_backend_kit_read_string($verifiedPayment['status'] ?? null, $plainStatus, 'paid'),
        'orchestrationApproved' => false,
        'gatewayPaymentVerified' => (bool) ($input['gatewayPaymentVerified'] ?? false),
        'paidByGatewayHint' => false,
        'paidByPlainStatusFallback' => false,
        'paidByVerifiedEvidence' => true,
        'verified_contract' => $verifiedPayment['contract'] ?? null,
        'payment_mode' => $modeMeta['paymentMode'] ?? null,
    ];
    if (($modeMeta['referenceMode'] ?? null) !== null) {
        $details['reference_mode'] = $modeMeta['referenceMode'];
    }

    return [
        'allowed' => true,
        'reason' => xapps_backend_kit_read_string($policy['reason'] ?? null, 'tenant_payment_passed'),
        'message' => 'Tenant payment policy satisfied',
        'details' => $details,
    ];
}

function xapps_backend_kit_build_payment_policy_blocked_result(array $input, array $app): array
{
    $payload = xapps_backend_kit_read_record($input['payload'] ?? null);
    $context = xapps_backend_kit_read_record($input['context'] ?? null);
    $guard = xapps_backend_kit_read_record($input['guard'] ?? null);
    $guardConfig = xapps_backend_kit_read_record($input['guardConfig'] ?? null);
    $policy = xapps_backend_kit_read_record($input['policy'] ?? null);
    $actionCfg = xapps_backend_kit_read_record($input['actionCfg'] ?? null);
    $modeMeta = xapps_backend_kit_read_record($input['modeMeta'] ?? null);
    $amount = $input['amount'] ?? 0;
    $currency = xapps_backend_kit_read_string($input['currency'] ?? null, 'USD');
    $plainStatus = xapps_backend_kit_read_string($input['plainStatus'] ?? null);
    $verificationFailure = xapps_backend_kit_read_record($input['verificationFailure'] ?? null);
    $locale = xapps_backend_kit_read_string($context['locale'] ?? null, $payload['locale'] ?? null, 'en');

    $paymentUrlResult = BackendKit::buildModeHostedGatewayPaymentUrl($app, [
        'payload' => $payload,
        'context' => $context,
        'guard' => $guard,
        'guardConfig' => $guardConfig,
        'amount' => $amount,
        'currency' => $currency,
    ], $modeMeta);
    $paymentUrl = (string) ($paymentUrlResult['paymentUrl'] ?? '');
    $paymentSessionId = xapps_backend_kit_read_string(
        $paymentUrlResult['paymentSessionId'] ?? null,
        HostedGatewayPaymentSession::extractHostedPaymentSessionId($paymentUrl) ?? null,
    );
    $builtAction = PaymentPolicySupport::buildPaymentGuardAction($actionCfg);
    $action = [
        ...$builtAction,
        'url' => $paymentUrl,
    ];

    $details = [
        'uiRequired' => true,
        'orchestration' => [
            'mode' => 'blocking',
            'surface' => xapps_backend_kit_read_string($guardConfig['ui_mode'] ?? null, 'redirect'),
            'status' => 'pending_user_action',
            'payment_session_id' => $paymentSessionId !== '' ? $paymentSessionId : null,
            'payment_mode' => $modeMeta['paymentMode'] ?? null,
        ],
        'payment_status' => $plainStatus !== '' ? $plainStatus : null,
        'expected_amount' => xapps_backend_kit_payment_numeric_amount($amount),
        'expected_currency' => $currency,
    ];
    if (($modeMeta['referenceMode'] ?? null) !== null) {
        $details['orchestration']['reference_mode'] = $modeMeta['referenceMode'];
    }
    if (($verificationFailure['ok'] ?? true) === false) {
        $details['verification_failure'] = $verificationFailure;
    }

    return [
        'allowed' => false,
        'reason' => xapps_backend_kit_read_string(
            ($verificationFailure['reason'] ?? null),
            $policy['reason'] ?? null,
            'payment_required',
        ),
        'message' => ($verificationFailure['ok'] ?? true) === false
            ? 'Payment verification failed'
            : xapps_backend_kit_read_string(
                xapps_backend_kit_resolve_localized_text($policy['message'] ?? null, $locale),
                'Payment is required before continuing.',
            ),
        'action' => $action,
        'details' => $details,
    ];
}

function xapps_backend_kit_payment_guard_fail_closed_result(int $upstreamStatus): array
{
    return [
        'allowed' => false,
        'reason' => 'payment_session_create_failed',
        'message' => 'Payment session could not be created at this time',
        'action' => [
            'kind' => 'complete_payment',
            'label' => 'Open Payment',
            'title' => 'Complete Payment',
        ],
        'details' => [
            'uiRequired' => true,
            'orchestration' => [
                'mode' => 'blocking',
                'surface' => 'redirect',
                'status' => 'failed_dependency',
            ],
            'upstream_status' => $upstreamStatus,
        ],
    ];
}
