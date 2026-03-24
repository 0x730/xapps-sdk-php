<?php

declare(strict_types=1);

namespace Xapps;

/**
 * High-level payment handler that encapsulates session management,
 * return URL validation, signed redirect generation, and gateway integration.
 *
 * Usage:
 *   $handler = new PaymentHandler([
 *       'secret' => env('TENANT_PAYMENT_RETURN_SECRET'),
 *       'issuer' => 'tenant',
 *       'returnUrlAllowlist' => env('TENANT_PAYMENT_RETURN_URL_ALLOWLIST'),
 *       'onSettlement' => fn($session) => ['status' => 'paid', 'receipt_id' => uniqid('rcpt_')],
 *   ]);
 *
 *   // In your controller:
 *   $result = $handler->handleGetSession(['query' => $request->query()]);
 *   return response()->json($result['body'], $result['status']);
 */
final class PaymentHandler
{
    private string $secret;
    private string $issuer;
    /** @var string[] */
    private array $allowlist;
    private int $sessionTtlMs;
    private PaymentSessionStoreInterface $store;
    private ?GatewayClient $gatewayClient;
    /** @var callable|null */
    private $onSettlement;
    /** @var callable|null */
    private $onGatewayNotify;

    /**
     * @param array{
     *   secret?: string,
     *   secretRef?: string,
     *   secretRefResolver?: callable,
     *   secretRefResolvers?: array<string,callable>,
     *   issuer?: string,
     *   returnUrlAllowlist?: string|string[],
     *   store?: PaymentSessionStoreInterface,
     *   sessionTtlSeconds?: int,
     *   onSettlement?: callable,
     *   gatewayClient?: GatewayClient,
     *   onGatewayNotify?: callable,
     *   requirePersistentStoreInProduction?: bool,
     * } $config
     */
    public function __construct(array $config)
    {
        $this->secret = trim((string) ($config['secret'] ?? ''));
        if ($this->secret === '' && isset($config['secretRef']) && trim((string) $config['secretRef']) !== '') {
            $secretRefOptions = [];
            if (isset($config['secretRefResolver']) && is_callable($config['secretRefResolver'])) {
                $secretRefOptions['resolveSecretRef'] = $config['secretRefResolver'];
            }
            if (isset($config['secretRefResolvers']) && is_array($config['secretRefResolvers'])) {
                $secretRefOptions['resolvers'] = $config['secretRefResolvers'];
            }
            $this->secret = PaymentReturn::resolveSecretFromRef((string) $config['secretRef'], $secretRefOptions);
        }
        if ($this->secret === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'PaymentHandler: secret or secretRef is required');
        }

        $this->issuer = strtolower(trim((string) ($config['issuer'] ?? 'tenant')));
        $this->allowlist = self::parseAllowlist($config['returnUrlAllowlist'] ?? '');
        $this->sessionTtlMs = max(60, (int) ($config['sessionTtlSeconds'] ?? 1800)) * 1000;
        $this->store = $config['store'] ?? new InMemoryPaymentSessionStore($this->sessionTtlMs);
        $this->gatewayClient = $config['gatewayClient'] ?? null;
        $this->onSettlement = $config['onSettlement'] ?? null;
        $this->onGatewayNotify = $config['onGatewayNotify'] ?? null;

        $requirePersistent = $config['requirePersistentStoreInProduction'] ?? true;
        if (
            $requirePersistent
            && $this->store instanceof InMemoryPaymentSessionStore
            && in_array(getenv('APP_ENV') ?: getenv('PHP_ENV'), ['production', 'prod'], true)
        ) {
            throw new XappsSdkError(
                XappsSdkError::INVALID_ARGUMENT,
                'PaymentHandler: InMemoryPaymentSessionStore not suitable for production. '
                . 'Provide a persistent store via config[\'store\'].',
            );
        }
    }

    // ── Route Handlers ───────────────────────────────────────────────

    /**
     * Handle GET /session?payment_session_id=X
     *
     * @param array{query:array<string,string>,body?:array<string,mixed>} $request
     * @return array{status:int,body:array<string,mixed>}
     */
    public function handleGetSession(array $request): array
    {
        $query = $request['query'] ?? [];
        $paymentSessionId = self::readStringField($query, ['payment_session_id', 'paymentSessionId']);
        $queryReturnUrl = self::readStringField($query, ['return_url', 'returnUrl']);
        $queryCancelUrl = self::readStringField($query, ['cancel_url', 'cancelUrl']);
        $queryResume = self::readStringField($query, ['xapps_resume', 'xappsResume']);

        if ($paymentSessionId === '') {
            return ['status' => 400, 'body' => ['message' => 'payment_session_id is required']];
        }

        $session = $this->getSession($paymentSessionId);

        // Fall back to gateway if configured
        if ($session === null && $this->gatewayClient !== null) {
            try {
                $gwResult = $this->gatewayClient->get("/v1/payment-sessions/{$paymentSessionId}");
                $gwSession = $gwResult['json']['result'] ?? $gwResult['json'] ?? null;
                if (is_array($gwSession)) {
                    $overrides = [];
                    if ($queryReturnUrl !== '' && $this->isAllowedReturnUrl($queryReturnUrl)) {
                        $overrides['return_url'] = $queryReturnUrl;
                    }
                    if ($queryCancelUrl !== '') {
                        $overrides['cancel_url'] = $queryCancelUrl;
                    }
                    if ($queryResume !== '') {
                        $overrides['xapps_resume'] = $queryResume;
                    }
                    $session = $this->upsertSession(self::mirrorGatewaySession($gwSession, $this->issuer, $overrides));
                }
            } catch (\Throwable) {
                return ['status' => 502, 'body' => ['message' => 'gateway payment session fetch failed']];
            }
        }

        if ($session === null) {
            return ['status' => 404, 'body' => ['message' => 'payment session not found or expired']];
        }

        // Apply query overrides
        $dirty = false;
        if ($queryReturnUrl !== '' && $this->isAllowedReturnUrl($queryReturnUrl)) {
            $session['return_url'] = $queryReturnUrl;
            $dirty = true;
        }
        if ($queryCancelUrl !== '') {
            $session['cancel_url'] = $queryCancelUrl;
            $dirty = true;
        }
        if ($queryResume !== '') {
            $session['xapps_resume'] = $queryResume;
            $dirty = true;
        }
        if ($dirty) {
            $this->store->set($session['payment_session_id'], $session);
        }

        return [
            'status' => 200,
            'body' => [
                'status' => 'success',
                'result' => [
                    'payment_session_id' => $session['payment_session_id'],
                    'xapp_id' => $session['xapp_id'] ?? '',
                    'tool_name' => $session['tool_name'] ?? '',
                    'amount' => $session['amount'] ?? '',
                    'currency' => $session['currency'] ?? 'USD',
                    'issuer' => $session['issuer'] ?? $this->issuer,
                    'subject_id' => $session['subject_id'] ?? null,
                    'installation_id' => $session['installation_id'] ?? null,
                    'client_id' => $session['client_id'] ?? null,
                    'return_url' => $session['return_url'] ?? '',
                    'cancel_url' => ($session['cancel_url'] ?? '') ?: ($session['return_url'] ?? null),
                    'expires_at' => isset($session['expires_at_ms'])
                        ? gmdate('Y-m-d\TH:i:s.v\Z', (int) ($session['expires_at_ms'] / 1000))
                        : null,
                    'status' => $session['status'] ?? 'pending',
                ],
            ],
        ];
    }

    /**
     * Handle POST /complete { payment_session_id, ... }
     *
     * @param array{query?:array<string,string>,body:array<string,mixed>} $request
     * @return array{status:int,body:array<string,mixed>}
     */
    public function handleComplete(array $request): array
    {
        try {
            $body = $request['body'] ?? [];
            $paymentSessionId = self::readStringField($body, ['payment_session_id', 'paymentSessionId']);

            if ($paymentSessionId === '') {
                return ['status' => 400, 'body' => ['message' => 'payment_session_id is required']];
            }

            $session = $this->getSession($paymentSessionId);

            // Fall back to gateway
            if ($session === null && $this->gatewayClient !== null) {
                try {
                    $gwResult = $this->gatewayClient->get("/v1/payment-sessions/{$paymentSessionId}");
                    $gwSession = $gwResult['json']['result'] ?? $gwResult['json'] ?? null;
                    if (is_array($gwSession)) {
                        $session = $this->upsertSession(self::mirrorGatewaySession($gwSession, $this->issuer));
                    }
                } catch (\Throwable) {
                    return ['status' => 502, 'body' => ['message' => 'gateway payment session fetch failed']];
                }
            }

            if ($session === null) {
                return ['status' => 404, 'body' => ['message' => 'payment session not found or expired']];
            }
            if (($session['status'] ?? '') === 'authorized') {
                return ['status' => 409, 'body' => ['message' => 'payment session already used']];
            }
            if (($session['status'] ?? '') === 'completed' && !empty($session['completed_redirect_url'])) {
                return [
                    'status' => 200,
                    'body' => ['status' => 'success', 'result' => ['redirect_url' => $session['completed_redirect_url']]],
                ];
            }

            // Resolve return URL from body, resume token, or session
            $bodyResume = self::readStringField($body, ['xapps_resume', 'xappsResume']);
            $bodyReturnUrl = self::readStringField($body, ['return_url', 'returnUrl']);
            $bodyCancelUrl = self::readStringField($body, ['cancel_url', 'cancelUrl']);
            $xappsResume = $bodyResume ?: ($session['xapps_resume'] ?? '');

            if ($bodyReturnUrl !== '' && $this->isAllowedReturnUrl($bodyReturnUrl)) {
                $session['return_url'] = $bodyReturnUrl;
            }
            if ($bodyCancelUrl !== '') {
                $session['cancel_url'] = $bodyCancelUrl;
            }
            if ($bodyResume !== '') {
                $session['xapps_resume'] = $bodyResume;
            }

            $parsedResume = self::parseResumeToken($xappsResume);
            $resumeReturnUrl = self::readStringField($parsedResume ?? [], ['return_url', 'host_return_url']);
            $effectiveReturnUrl = $resumeReturnUrl ?: ($session['return_url'] ?? '');

            if ($effectiveReturnUrl === '') {
                return ['status' => 400, 'body' => ['message' => 'payment session missing return_url']];
            }
            if (!$this->isAllowedReturnUrl($effectiveReturnUrl)) {
                return ['status' => 400, 'body' => ['message' => 'return_url is not allowed by the configured allowlist']];
            }

            // Settlement callback
            $settlementStatus = 'paid';
            $receiptId = self::randomId('rcpt');
            if ($this->onSettlement !== null) {
                $result = ($this->onSettlement)($session);
                $settlementStatus = $result['status'] ?? 'paid';
                $receiptId = $result['receipt_id'] ?? $receiptId;
            }

            $ts = gmdate('c');
            $evidence = [
                'contract' => PaymentReturn::CONTRACT_V1,
                'payment_session_id' => $paymentSessionId,
                'status' => $settlementStatus,
                'receipt_id' => $receiptId,
                'amount' => $session['amount'] ?? '',
                'currency' => $session['currency'] ?? 'USD',
                'ts' => $ts,
                'issuer' => $session['issuer'] ?? $this->issuer,
                'xapp_id' => $session['xapp_id'] ?? '',
                'tool_name' => $session['tool_name'] ?? '',
            ];
            if (!empty($session['subject_id'])) {
                $evidence['subject_id'] = $session['subject_id'];
            }
            if (!empty($session['installation_id'])) {
                $evidence['installation_id'] = $session['installation_id'];
            }
            if (!empty($session['client_id'])) {
                $evidence['client_id'] = $session['client_id'];
            }

            $redirectUrl = PaymentReturn::buildSignedPaymentReturnRedirectUrl(
                $effectiveReturnUrl,
                $evidence,
                $this->secret,
                $xappsResume !== '' ? $xappsResume : null,
            );

            // Update session state
            $session['status'] = 'completed';
            $session['receipt_id'] = $receiptId;
            $session['completed_redirect_url'] = $redirectUrl;
            $session['completed_at_ms'] = (int) (microtime(true) * 1000);
            $this->store->set($session['payment_session_id'], $session);

            // Gateway notification (best-effort)
            if ($this->onGatewayNotify !== null) {
                try {
                    ($this->onGatewayNotify)($session, $redirectUrl);
                } catch (\Throwable) {
                    // fire-and-forget
                }
            } elseif ($this->gatewayClient !== null) {
                try {
                    $completePayload = ['payment_session_id' => $paymentSessionId];
                    if ($xappsResume !== '') {
                        $completePayload['xapps_resume'] = $xappsResume;
                    }
                    $this->gatewayClient->post(
                        "/v1/payment-sessions/{$paymentSessionId}/complete",
                        $completePayload,
                    );
                } catch (\Throwable) {
                    // fire-and-forget
                }
            }

            return [
                'status' => 200,
                'body' => ['status' => 'success', 'result' => ['redirect_url' => $redirectUrl]],
            ];
        } catch (\Throwable $e) {
            return ['status' => 500, 'body' => ['message' => $e->getMessage() ?: 'payment complete failed']];
        }
    }

    // ── Evidence Verification ─────────────────────────────────────────

    /**
     * Verify payment return evidence from a guard payload.
     *
     * Performs the full verification workflow:
     * 1. Parse evidence from payload
     * 2. Fetch session (local store, then gateway mirror path when configured)
     * 3. Check session status (completed, not already authorized)
     * 4. Verify HMAC signature against expected fields
     * 5. Mark session as authorized
     *
     * @param array{
     *   payload: array<string,mixed>,
     *   maxAgeSeconds?: int,
     *   expected?: array{issuer?:string,amount?:string|int|float,currency?:string,xapp_id?:string,tool_name?:string,subject_id?:string,installation_id?:string,client_id?:string},
     * } $input
     * @return array{ok:true,evidence:array<string,mixed>,session:array<string,mixed>}|array{ok:false,reason:string,details?:array<string,mixed>}
     */
    public function handleVerifyEvidence(array $input): array
    {
        $payload = $input['payload'] ?? [];
        $evidence = PaymentReturn::parsePaymentReturnEvidence($payload);
        if ($evidence === null) {
            return ['ok' => false, 'reason' => 'payment_evidence_not_found'];
        }

        $paymentSessionId = trim((string) ($evidence['payment_session_id'] ?? ''));
        if ($paymentSessionId === '') {
            return ['ok' => false, 'reason' => 'payment_evidence_missing_session_id'];
        }

        // Fetch session: local store, then gateway mirror path when configured.
        $session = $this->getSession($paymentSessionId);
        if ($session === null && $this->gatewayClient !== null) {
            try {
                $gwResult = $this->gatewayClient->get("/v1/payment-sessions/{$paymentSessionId}");
                $gwSession = $gwResult['json']['result'] ?? $gwResult['json'] ?? null;
                if (is_array($gwSession)) {
                    $session = $this->upsertSession(self::mirrorGatewaySession($gwSession, $this->issuer));
                }
            } catch (\Throwable) {
                // Gateway unavailable — fall through to "not found"
            }
        }

        if ($session === null) {
            return ['ok' => false, 'reason' => 'payment_session_unknown_or_expired'];
        }
        if (($session['status'] ?? '') !== 'completed') {
            return ['ok' => false, 'reason' => 'payment_session_not_completed', 'details' => ['status' => $session['status'] ?? null]];
        }
        if (!empty($session['authorized_at_ms'])) {
            return ['ok' => false, 'reason' => 'payment_session_already_used'];
        }

        // Build expected values: explicit overrides → session fields
        $exp = $input['expected'] ?? [];
        $maxAgeSeconds = (int) ($input['maxAgeSeconds'] ?? 900);
        $verification = PaymentReturn::verifyPaymentReturnEvidence([
            'evidence' => $evidence,
            'secret' => $this->secret,
            'maxAgeSeconds' => $maxAgeSeconds,
            'expected' => [
                'issuer' => $exp['issuer'] ?? $session['issuer'] ?? $this->issuer,
                'issuers' => isset($exp['issuers']) && is_array($exp['issuers']) ? $exp['issuers'] : null,
                'amount' => $exp['amount'] ?? $session['amount'] ?? null,
                'currency' => $exp['currency'] ?? $session['currency'] ?? null,
                'xapp_id' => $exp['xapp_id'] ?? $session['xapp_id'] ?? null,
                'tool_name' => $exp['tool_name'] ?? $session['tool_name'] ?? null,
                'subject_id' => $exp['subject_id'] ?? $session['subject_id'] ?? null,
                'installation_id' => $exp['installation_id'] ?? $session['installation_id'] ?? null,
                'client_id' => $exp['client_id'] ?? $session['client_id'] ?? null,
            ],
        ]);

        if (!($verification['ok'] ?? false)) {
            return [
                'ok' => false,
                'reason' => $verification['reason'] ?? 'payment_signature_invalid',
                ...(!empty($verification['details']) ? ['details' => $verification['details']] : []),
            ];
        }

        // Mark session as authorized
        $session['authorized_at_ms'] = (int) (microtime(true) * 1000);
        $session['status'] = 'authorized';
        $this->store->set($session['payment_session_id'], $session);

        return ['ok' => true, 'evidence' => $evidence, 'session' => $session];
    }

    // ── Public Utilities ─────────────────────────────────────────────

    /**
     * Check if a return URL is allowed by the configured allowlist.
     */
    public function isAllowedReturnUrl(string $input): bool
    {
        $url = trim($input);
        if ($url === '') {
            return false;
        }
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }
        if (!in_array($parsed['scheme'], ['http', 'https'], true)) {
            return false;
        }
        if (count($this->allowlist) > 0) {
            $origin = $parsed['scheme'] . '://' . $parsed['host']
                . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
            foreach ($this->allowlist as $entry) {
                if (str_starts_with($url, $entry) || $origin === $entry) {
                    return true;
                }
            }
            return false;
        }
        $host = strtolower($parsed['host']);
        return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1';
    }

    /**
     * Decode a base64url-encoded JSON resume token.
     *
     * @return array<string,mixed>|null
     */
    public static function parseResumeToken(string $input): ?array
    {
        $raw = trim($input);
        if ($raw === '') {
            return null;
        }
        try {
            $base64 = strtr($raw, '-_', '+/');
            $padded = $base64 . str_repeat('=', (4 - (strlen($base64) % 4)) % 4);
            $json = base64_decode($padded, true);
            if ($json === false) {
                return null;
            }
            $parsed = json_decode($json, true);
            if (!is_array($parsed)) {
                return null;
            }
            return $parsed;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Encode a JSON payload as a base64url resume token.
     *
     * @param array<string,mixed> $payload
     */
    public static function buildResumeToken(array $payload): string
    {
        return rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    }

    /**
     * Retrieve a session from the store.
     *
     * @return array<string,mixed>|null
     */
    public function getSession(string $id): ?array
    {
        $this->store->prune();
        return $this->store->get($id);
    }

    /**
     * Create or update a session in the store.
     *
     * @param array<string,mixed> $input Must contain 'payment_session_id'.
     * @return array<string,mixed>
     */
    public function upsertSession(array $input): array
    {
        $id = trim((string) ($input['payment_session_id'] ?? ''));
        if ($id === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'payment_session_id is required');
        }

        $existing = $this->store->get($id);
        $nowMs = (int) (microtime(true) * 1000);

        if ($existing !== null) {
            $merged = array_merge($existing, $input);
            $this->store->set($id, $merged);
            return $merged;
        }

        $session = [
            'payment_session_id' => $id,
            'xapp_id' => trim((string) ($input['xapp_id'] ?? '')),
            'tool_name' => trim((string) ($input['tool_name'] ?? '')),
            'amount' => trim((string) ($input['amount'] ?? '')),
            'currency' => strtoupper(trim((string) ($input['currency'] ?? 'USD'))),
            'issuer' => strtolower(trim((string) ($input['issuer'] ?? $this->issuer))),
            'subject_id' => trim((string) ($input['subject_id'] ?? '')) ?: null,
            'installation_id' => trim((string) ($input['installation_id'] ?? '')) ?: null,
            'client_id' => trim((string) ($input['client_id'] ?? '')) ?: null,
            'return_url' => trim((string) ($input['return_url'] ?? '')),
            'cancel_url' => trim((string) ($input['cancel_url'] ?? '')) ?: null,
            'xapps_resume' => trim((string) ($input['xapps_resume'] ?? '')) ?: null,
            'status' => $input['status'] ?? 'pending',
            'created_at_ms' => $input['created_at_ms'] ?? $nowMs,
            'expires_at_ms' => $input['expires_at_ms'] ?? ($nowMs + $this->sessionTtlMs),
            'receipt_id' => $input['receipt_id'] ?? null,
            'completed_redirect_url' => $input['completed_redirect_url'] ?? null,
            'completed_at_ms' => $input['completed_at_ms'] ?? null,
            'authorized_at_ms' => $input['authorized_at_ms'] ?? null,
        ];

        $this->store->set($id, $session);
        return $session;
    }

    // ── Private Helpers ──────────────────────────────────────────────

    /** @return string[] */
    private static function parseAllowlist(string|array $input): array
    {
        if (is_array($input)) {
            return array_values(array_filter(array_map(
                fn($e) => rtrim(trim((string) $e), '/'),
                $input,
            )));
        }
        return array_values(array_filter(array_map(
            fn($e) => rtrim(trim($e), '/'),
            preg_split('/[,\n]/', (string) $input) ?: [],
        )));
    }

    /**
     * @param array<string,mixed> $data
     * @param string[] $keys
     */
    private static function readStringField(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
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

    private static function randomId(string $prefix): string
    {
        return $prefix . '_' . (string) time() . '_' . substr(bin2hex(random_bytes(6)), 0, 12);
    }

    /**
     * @param array<string,mixed> $gw
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function mirrorGatewaySession(array $gw, string $issuer, array $overrides = []): array
    {
        $expiresRaw = (string) ($gw['expires_at'] ?? '');
        $expiresAtMs = $expiresRaw !== '' ? (int) (strtotime($expiresRaw) * 1000) : null;

        $session = [
            'payment_session_id' => trim((string) ($gw['payment_session_id'] ?? '')),
            'xapp_id' => trim((string) ($overrides['xapp_id'] ?? $gw['xapp_id'] ?? '')),
            'tool_name' => trim((string) ($overrides['tool_name'] ?? $gw['tool_name'] ?? '')),
            'amount' => trim((string) ($overrides['amount'] ?? $gw['amount'] ?? '')),
            'currency' => strtoupper(trim((string) ($overrides['currency'] ?? $gw['currency'] ?? 'USD'))),
            'issuer' => strtolower(trim((string) ($overrides['issuer'] ?? $gw['issuer'] ?? $issuer))),
            'subject_id' => trim((string) ($overrides['subject_id'] ?? $gw['subject_id'] ?? '')) ?: null,
            'installation_id' => trim((string) ($overrides['installation_id'] ?? $gw['installation_id'] ?? '')) ?: null,
            'client_id' => trim((string) ($overrides['client_id'] ?? $gw['client_context_id'] ?? $gw['client_id'] ?? '')) ?: null,
            'return_url' => trim((string) ($overrides['return_url'] ?? $gw['return_url'] ?? '')),
            'cancel_url' => trim((string) ($overrides['cancel_url'] ?? $gw['cancel_url'] ?? '')) ?: null,
            'xapps_resume' => trim((string) ($overrides['xapps_resume'] ?? $gw['xapps_resume'] ?? '')) ?: null,
            'status' => trim((string) ($gw['status'] ?? 'pending')) ?: 'pending',
        ];

        if ($expiresAtMs !== null && $expiresAtMs > 0) {
            $session['expires_at_ms'] = $expiresAtMs;
        }

        return $session;
    }
}
