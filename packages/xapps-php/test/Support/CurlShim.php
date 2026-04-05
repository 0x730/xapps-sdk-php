<?php

declare(strict_types=1);

namespace Xapps;

final class TestCurlHandle
{
    public string $url;
    /** @var array<int,mixed> */
    public array $options = [];
    public int $status = 0;
    public string $error = '';

    public function __construct(string $url)
    {
        $this->url = $url;
    }
}

final class TestCurlShim
{
    /** @var array<int,array<string,mixed>> */
    public static array $requests = [];

    public static function reset(): void
    {
        self::$requests = [];
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    public static function respond(TestCurlHandle $handle): array
    {
        $method = self::resolveMethod($handle);
        $headers = self::normalizeHeaders($handle->options[\CURLOPT_HTTPHEADER] ?? []);
        $payload = self::normalizePayload($handle->options[\CURLOPT_POSTFIELDS] ?? null);
        $parsed = parse_url($handle->url);
        $path = (string) ($parsed['path'] ?? '/');
        $query = [];
        if (isset($parsed['query'])) {
            parse_str((string) $parsed['query'], $query);
        }
        self::$requests[] = [
            'method' => $method,
            'url' => $handle->url,
            'path' => $path,
            'query' => $query,
            'headers' => $headers,
            'payload' => $payload,
        ];

        $response = self::route($method, $path, $query, $headers, $payload);
        $headerCallback = $handle->options[\CURLOPT_HEADERFUNCTION] ?? null;
        if (is_callable($headerCallback)) {
            $headerCallback($handle, "HTTP/1.1 " . (string) $response['status'] . " OK\r\n");
            foreach ($response['headers'] as $key => $value) {
                $headerCallback($handle, $key . ': ' . $value . "\r\n");
            }
        }
        return $response;
    }

    private static function resolveMethod(TestCurlHandle $handle): string
    {
        if (isset($handle->options[\CURLOPT_CUSTOMREQUEST])) {
            return strtoupper(trim((string) $handle->options[\CURLOPT_CUSTOMREQUEST]));
        }
        if (($handle->options[\CURLOPT_POST] ?? false) === true) {
            return 'POST';
        }
        return 'GET';
    }

    /** @param mixed $rawHeaders @return array<string,string> */
    private static function normalizeHeaders(mixed $rawHeaders): array
    {
        $headers = [];
        if (!is_array($rawHeaders)) {
            return $headers;
        }
        foreach ($rawHeaders as $line) {
            if (!is_string($line) || !str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($key))] = trim($value);
        }
        return $headers;
    }

    /** @return array<string,mixed> */
    private static function normalizePayload(mixed $rawPayload): array
    {
        if (is_array($rawPayload)) {
            return $rawPayload;
        }
        if (!is_string($rawPayload) || trim($rawPayload) === '') {
            return [];
        }
        $decoded = json_decode($rawPayload, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $query @param array<string,string> $headers @param array<string,mixed> $payload @return array{status:int,headers:array<string,string>,body:string} */
    private static function route(
        string $method,
        string $path,
        array $query,
        array $headers,
        array $payload,
    ): array {
        if ($method === 'POST' && preg_match('#^/v1/requests/([^/]+)/events$#', $path, $matches) === 1) {
            return self::json(200, [
                'ok' => true,
                'request_id' => $matches[1],
                'payload' => $payload,
                'idempotency_key' => $headers['idempotency-key'] ?? '',
                'authorization' => $headers['authorization'] ?? '',
            ]);
        }
        if ($method === 'POST' && preg_match('#^/v1/requests/([^/]+)/complete$#', $path, $matches) === 1) {
            return self::json(200, [
                'ok' => true,
                'request_id' => $matches[1],
                'payload' => $payload,
                'idempotency_key' => $headers['idempotency-key'] ?? '',
                'authorization' => $headers['authorization'] ?? '',
            ]);
        }
        if ($method === 'POST' && $path === '/v1/payment-sessions') {
            $paymentSessionId = trim((string) ($payload['payment_session_id'] ?? 'pay_fixture'));
            return self::json(200, [
                'result' => [
                    'session' => [
                        'payment_session_id' => $paymentSessionId,
                        'status' => 'pending',
                    ],
                    'payment_page_url' => 'https://pay.example.test/session/' . rawurlencode($paymentSessionId),
                ],
            ]);
        }
        if ($method === 'POST' && $path === '/v1/subjects/resolve') {
            $email = strtolower(trim((string) ($payload['email'] ?? 'user@example.com')));
            return self::json(200, [
                'result' => [
                    'subjectId' => 'subj_' . substr(md5($email), 0, 12),
                ],
            ]);
        }
        if ($method === 'POST' && $path === '/v1/catalog-sessions') {
            $subjectId = trim((string) ($payload['subjectId'] ?? 'subj_fixture'));
            return self::json(200, [
                'result' => [
                    'token' => 'catalog_token_' . $subjectId,
                    'embedUrl' => 'https://embed.example.test/catalog?token=' . rawurlencode('catalog_token_' . $subjectId),
                ],
            ]);
        }
        if ($method === 'POST' && $path === '/v1/widget-sessions') {
            $installationId = trim((string) ($payload['installationId'] ?? 'inst_fixture'));
            $widgetId = trim((string) ($payload['widgetId'] ?? 'widget_fixture'));
            return self::json(200, [
                'result' => [
                    'token' => 'widget_token_' . $installationId . '_' . $widgetId,
                    'embedUrl' => 'https://embed.example.test/widget/' . rawurlencode($installationId) . '/' . rawurlencode($widgetId),
                    'context' => [
                        'installationId' => $installationId,
                        'widgetId' => $widgetId,
                    ],
                    'widget' => [
                        'id' => $widgetId,
                    ],
                    'tool' => null,
                ],
            ]);
        }
        if ($method === 'POST' && $path === '/v1/requests') {
            return self::json(200, [
                'result' => [
                    'request' => [
                        'id' => 'req_widget_tool_1',
                        'status' => 'PENDING',
                    ],
                ],
            ]);
        }
        if ($method === 'GET' && $path === '/v1/requests/req_widget_tool_1') {
            return self::json(200, [
                'result' => [
                    'request' => [
                        'id' => 'req_widget_tool_1',
                        'status' => 'COMPLETED',
                    ],
                ],
            ]);
        }
        if ($method === 'GET' && $path === '/v1/requests/req_widget_tool_1/response') {
            return self::json(200, [
                'result' => [
                    'response' => [
                        'result' => [
                            'profile_id' => 'profile_fixture_1',
                            'source' => 'subject_self_profile',
                        ],
                    ],
                ],
            ]);
        }
        if ($method === 'GET' && $path === '/v1/requests/latest') {
            return self::json(200, [
                'result' => [
                    'requestId' => 'req_latest_' . substr(md5(json_encode($query)), 0, 8),
                ],
            ]);
        }
        if ($method === 'GET' && $path === '/v1/installations') {
            return self::json(200, [
                'result' => [
                    'items' => [
                        [
                            'installationId' => 'inst_fixture_1',
                            'xappId' => 'xapp_demo',
                        ],
                    ],
                ],
            ]);
        }
        if ($method === 'POST' && $path === '/v1/installations') {
            $xappId = trim((string) ($payload['xappId'] ?? 'xapp_demo'));
            return self::json(200, [
                'result' => [
                    'installation' => [
                        'installationId' => 'inst_' . $xappId,
                        'xappId' => $xappId,
                    ],
                ],
            ]);
        }
        if ($method === 'POST' && preg_match('#^/v1/installations/([^/]+)/update$#', $path, $matches) === 1) {
            return self::json(200, [
                'result' => [
                    'installation' => [
                        'installationId' => $matches[1],
                        'xappId' => 'xapp_demo',
                        'updated' => true,
                    ],
                ],
            ]);
        }
        if ($method === 'DELETE' && preg_match('#^/v1/installations/([^/]+)$#', $path, $matches) === 1) {
            return self::json(200, [
                'result' => [
                    'ok' => true,
                    'installationId' => $matches[1],
                ],
            ]);
        }
        if ($method === 'GET' && preg_match('#^/v1/payment-sessions/([^/]+)$#', $path, $matches) === 1) {
            return self::json(200, [
                'result' => [
                    'session' => [
                        'payment_session_id' => $matches[1],
                        'status' => 'pending',
                    ],
                ],
            ]);
        }
        if ($method === 'POST' && preg_match('#^/v1/payment-sessions/([^/]+)/complete$#', $path, $matches) === 1) {
            return self::json(200, [
                'result' => [
                    'payment_session_id' => $matches[1],
                    'flow' => 'hosted_redirect',
                    'redirect_url' => 'https://checkout.example.test/payment-session/' . rawurlencode($matches[1]),
                ],
            ]);
        }
        if ($method === 'GET' && $path === '/v1/gateway-payment/session') {
            $paymentSessionId = trim((string) ($query['payment_session_id'] ?? 'pay_fixture'));
            return self::json(200, [
                'result' => [
                    'payment_session_id' => $paymentSessionId,
                    'status' => 'pending',
                    'return_url' => (string) ($query['return_url'] ?? ''),
                    'xapps_resume' => (string) ($query['xapps_resume'] ?? ''),
                ],
            ]);
        }
        if ($method === 'POST' && $path === '/v1/gateway-payment/complete') {
            $paymentSessionId = trim((string) ($payload['payment_session_id'] ?? 'pay_fixture'));
            return self::json(200, [
                'result' => [
                    'payment_session_id' => $paymentSessionId,
                    'flow' => 'hosted_redirect',
                    'redirect_url' => 'https://checkout.example.test/gateway/' . rawurlencode($paymentSessionId),
                    'metadata' => [
                        'phase' => 'hosted_complete',
                    ],
                ],
            ]);
        }
        if ($method === 'POST' && $path === '/v1/gateway-payment/client-settle') {
            $paymentSessionId = trim((string) ($payload['payment_session_id'] ?? 'pay_fixture'));
            return self::json(200, [
                'result' => [
                    'payment_session_id' => $paymentSessionId,
                    'flow' => 'immediate',
                    'metadata' => [
                        'client_token' => (string) ($payload['client_token'] ?? ''),
                        'status' => (string) ($payload['status'] ?? ''),
                    ],
                ],
            ]);
        }
        if ($method === 'GET' && preg_match('#^/v1/xapps/([^/]+)/monetization$#', $path, $matches) === 1) {
            return self::json(200, [
                'xapp_id' => $matches[1],
                'version_id' => 'ver_' . $matches[1],
                'sellables' => [
                    [
                        'id' => 'offering_fixture_1',
                        'slug' => 'creator_default_paywall',
                    ],
                ],
            ]);
        }
        if ($method === 'GET' && preg_match('#^/v1/xapps/([^/]+)/monetization/access$#', $path, $matches) === 1) {
            return self::json(200, [
                'xapp_id' => $matches[1],
                'version_id' => 'ver_' . $matches[1],
                'access_projection' => [
                    'entitlement_state' => 'active',
                    'has_current_access' => true,
                    'credits_remaining' => 500,
                ],
            ]);
        }
        if ($method === 'GET' && preg_match('#^/v1/xapps/([^/]+)/monetization/current-subscription$#', $path, $matches) === 1) {
            return self::json(200, [
                'xapp_id' => $matches[1],
                'version_id' => 'ver_' . $matches[1],
                'current_subscription' => [
                    'id' => 'sub_fixture_1',
                    'status' => 'active',
                    'tier' => 'creator_team_hybrid_access',
                ],
            ]);
        }
        if ($method === 'GET' && preg_match('#^/v1/xapps/([^/]+)/monetization/entitlements$#', $path, $matches) === 1) {
            return self::json(200, [
                'xapp_id' => $matches[1],
                'version_id' => 'ver_' . $matches[1],
                'items' => [
                    [
                        'id' => 'entitlement_fixture_1',
                        'status' => 'active',
                        'product_slug' => 'creator_starter_unlock',
                    ],
                ],
            ]);
        }
        if ($method === 'GET' && preg_match('#^/embed/my-xapps/([^/]+)/monetization$#', $path, $matches) === 1) {
            return self::json(200, [
                'xapp_id' => $matches[1],
                'version_id' => 'ver_' . $matches[1],
                'access_projection' => [
                    'entitlement_state' => 'active',
                    'has_current_access' => true,
                    'credits_remaining' => 500,
                ],
                'current_subscription' => [
                    'id' => 'sub_fixture_1',
                    'status' => 'active',
                    'package_slug' => 'creator_pro_monthly',
                ],
                'paywalls' => [
                    [
                        'slug' => 'creator_workspace_default',
                        'packages' => [
                            [
                                'package_slug' => 'creator_pro_monthly',
                                'purchase_policy' => [
                                    'can_purchase' => false,
                                    'status' => 'current_recurring_plan',
                                    'transition_kind' => 'none',
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
        }
        if ($method === 'GET' && preg_match('#^/embed/my-xapps/([^/]+)/monetization/history$#', $path, $matches) === 1) {
            return self::json(200, [
                'xapp_id' => $matches[1],
                'version_id' => 'ver_' . $matches[1],
                'history' => [
                    'purchase_intents' => [
                        'total' => 1,
                        'items' => [
                            [
                                'id' => 'intent_fixture_1',
                                'status' => 'paid',
                            ],
                        ],
                    ],
                    'transactions' => [
                        'total' => 1,
                        'items' => [
                            [
                                'id' => 'txn_fixture_1',
                                'status' => 'settled',
                            ],
                        ],
                    ],
                ],
            ]);
        }
        if ($method === 'GET' && preg_match('#^/v1/xapps/([^/]+)/monetization/wallet-accounts$#', $path, $matches) === 1) {
            return self::json(200, [
                'xapp_id' => $matches[1],
                'version_id' => 'ver_' . $matches[1],
                'items' => [
                    [
                        'id' => 'wallet_fixture_1',
                        'status' => 'active',
                        'product_slug' => 'creator_credits_500',
                        'balance_remaining' => '500',
                    ],
                ],
            ]);
        }
        if ($method === 'GET' && preg_match('#^/v1/xapps/([^/]+)/monetization/wallet-ledger$#', $path, $matches) === 1) {
            return self::json(200, [
                'xapp_id' => $matches[1],
                'version_id' => 'ver_' . $matches[1],
                'items' => [
                    [
                        'id' => 'ledger_fixture_1',
                        'wallet_account_id' => (string) (($query['wallet_account_id'] ?? 'wallet_fixture_1')),
                        'event_kind' => 'top_up',
                        'payment_session_id' => (string) (($query['payment_session_id'] ?? '')),
                        'amount' => '500',
                    ],
                ],
            ]);
        }
        if ($method === 'POST' && preg_match('#^/v1/xapps/([^/]+)/monetization/wallet-accounts/([^/]+)/consume$#', $path, $matches) === 1) {
            return self::json(200, [
                'xapp_id' => $matches[1],
                'version_id' => 'ver_' . $matches[1],
                'wallet_account' => [
                    'id' => $matches[2],
                    'status' => 'active',
                    'product_slug' => 'creator_credits_500',
                    'balance_remaining' => '475',
                ],
                'wallet_ledger' => [
                    'id' => 'ledger_consume_fixture_1',
                    'wallet_account_id' => $matches[2],
                    'event_kind' => 'consume',
                    'source_ref' => (string) ($payload['source_ref'] ?? ''),
                    'amount' => (string) ($payload['amount'] ?? ''),
                ],
                'access_projection' => [
                    'has_current_access' => true,
                    'credits_remaining' => 475,
                ],
                'snapshot_id' => 'snapshot_fixture_1',
            ]);
        }
        if ($method === 'POST' && preg_match('#^/v1/xapps/([^/]+)/monetization/purchase-intents/prepare$#', $path, $matches) === 1) {
            return self::json(200, [
                'xapp_id' => $matches[1],
                'version_id' => 'ver_' . $matches[1],
                'prepared_intent' => [
                    'purchase_intent_id' => 'intent_fixture_1',
                    'status' => 'created',
                    'package' => [
                        'id' => (string) ($payload['package_id'] ?? 'pkg_fixture_1'),
                    ],
                    'price' => [
                        'id' => (string) ($payload['price_id'] ?? 'price_fixture_1'),
                        'amount' => '29',
                        'currency' => 'RON',
                    ],
                ],
            ]);
        }
        if ($method === 'POST' && preg_match('#^/embed/my-xapps/([^/]+)/monetization/purchase-intents/prepare$#', $path, $matches) === 1) {
            return self::json(200, [
                'xapp_id' => $matches[1],
                'version_id' => 'ver_' . $matches[1],
                'prepared_intent' => [
                    'purchase_intent_id' => 'intent_fixture_1',
                    'status' => 'created',
                    'package' => [
                        'id' => (string) ($payload['package_id'] ?? 'pkg_fixture_1'),
                    ],
                    'price' => [
                        'id' => (string) ($payload['price_id'] ?? 'price_fixture_1'),
                        'amount' => '29',
                        'currency' => 'RON',
                    ],
                ],
            ]);
        }
        if ($method === 'GET' && preg_match('#^/v1/xapps/([^/]+)/monetization/purchase-intents/([^/]+)$#', $path, $matches) === 1) {
            return self::json(200, [
                'xapp_id' => $matches[1],
                'version_id' => 'ver_' . $matches[1],
                'prepared_intent' => [
                    'purchase_intent_id' => $matches[2],
                    'status' => 'awaiting_payment',
                ],
            ]);
        }
        if ($method === 'POST' && preg_match('#^/v1/xapps/([^/]+)/monetization/purchase-intents/([^/]+)/transactions$#', $path, $matches) === 1) {
            return self::json(200, [
                'xapp_id' => $matches[1],
                'version_id' => 'ver_' . $matches[1],
                'transaction' => [
                    'id' => 'txn_' . $matches[2],
                    'status' => (string) ($payload['status'] ?? 'created'),
                    'payment_session_id' => (string) ($payload['payment_session_id'] ?? ''),
                ],
            ]);
        }
        if ($method === 'GET' && preg_match('#^/v1/xapps/([^/]+)/monetization/purchase-intents/([^/]+)/transactions$#', $path, $matches) === 1) {
            return self::json(200, [
                'xapp_id' => $matches[1],
                'version_id' => 'ver_' . $matches[1],
                'items' => [
                    [
                        'id' => 'txn_' . $matches[2],
                        'status' => 'verified',
                    ],
                ],
            ]);
        }
        if ($method === 'POST' && preg_match('#^/v1/xapps/([^/]+)/monetization/purchase-intents/([^/]+)/payment-session$#', $path, $matches) === 1) {
            return self::json(200, [
                'xapp_id' => $matches[1],
                'version_id' => 'ver_' . $matches[1],
                'payment_session' => [
                    'payment_session_id' => 'pay_' . $matches[2],
                    'status' => 'pending',
                    'issuer' => (string) ($payload['issuer'] ?? 'gateway'),
                ],
                'payment_page_url' => 'https://pay.example.test/session/' . rawurlencode('pay_' . $matches[2]),
            ]);
        }
        if ($method === 'POST' && preg_match('#^/embed/my-xapps/([^/]+)/monetization/purchase-intents/([^/]+)/payment-session$#', $path, $matches) === 1) {
            return self::json(200, [
                'xapp_id' => $matches[1],
                'version_id' => 'ver_' . $matches[1],
                'payment_session' => [
                    'payment_session_id' => 'pay_fixture_1',
                    'status' => 'pending',
                    'issuer' => (string) ($payload['issuer'] ?? 'gateway'),
                ],
                'payment_page_url' => 'https://pay.example.test/session/' . rawurlencode('pay_fixture_1'),
            ]);
        }
        if ($method === 'POST' && preg_match('#^/v1/xapps/([^/]+)/monetization/purchase-intents/([^/]+)/payment-session/reconcile$#', $path, $matches) === 1) {
            return self::json(200, [
                'xapp_id' => $matches[1],
                'version_id' => 'ver_' . $matches[1],
                'prepared_intent' => [
                    'purchase_intent_id' => $matches[2],
                    'status' => 'paid',
                ],
                'payment_session' => [
                    'payment_session_id' => 'pay_' . $matches[2],
                    'status' => 'completed',
                ],
                'transaction' => [
                    'id' => 'txn_' . $matches[2],
                    'status' => 'verified',
                ],
            ]);
        }
        if ($method === 'POST' && preg_match('#^/v1/xapps/([^/]+)/monetization/purchase-intents/([^/]+)/payment-session/finalize$#', $path, $matches) === 1) {
            return self::json(200, [
                'xapp_id' => $matches[1],
                'version_id' => 'ver_' . $matches[1],
                'prepared_intent' => [
                    'purchase_intent_id' => $matches[2],
                    'status' => 'paid',
                ],
                'payment_session' => [
                    'payment_session_id' => 'pay_' . $matches[2],
                    'status' => 'completed',
                ],
                'transaction' => [
                    'id' => 'txn_' . $matches[2],
                    'status' => 'verified',
                ],
                'access_projection' => [
                    'has_current_access' => true,
                ],
            ]);
        }
        if ($method === 'POST' && preg_match('#^/embed/my-xapps/([^/]+)/monetization/purchase-intents/([^/]+)/payment-session/finalize$#', $path, $matches) === 1) {
            return self::json(200, [
                'xapp_id' => $matches[1],
                'version_id' => 'ver_' . $matches[1],
                'prepared_intent' => [
                    'purchase_intent_id' => $matches[2],
                    'status' => 'paid',
                ],
                'payment_session' => [
                    'payment_session_id' => 'pay_fixture_1',
                    'status' => 'completed',
                ],
                'transaction' => [
                    'id' => 'txn_' . $matches[2],
                    'status' => 'settled',
                ],
                'access_projection' => [
                    'has_current_access' => true,
                ],
            ]);
        }
        if ($method === 'POST' && preg_match('#^/v1/xapps/([^/]+)/monetization/purchase-intents/([^/]+)/issue-access$#', $path, $matches) === 1) {
            return self::json(200, [
                'xapp_id' => $matches[1],
                'version_id' => 'ver_' . $matches[1],
                'prepared_intent' => [
                    'purchase_intent_id' => $matches[2],
                    'status' => 'paid',
                ],
                'issuance_mode' => 'one_time_purchase',
            ]);
        }
        if ($method === 'POST' && preg_match('#^/v1/xapps/([^/]+)/monetization/subscription-contracts/([^/]+)/reconcile-payment-session$#', $path, $matches) === 1) {
            return self::json(200, [
                'xapp_id' => $matches[1],
                'version_id' => 'ver_' . $matches[1],
                'payment_session' => [
                    'payment_session_id' => (string) ($payload['payment_session_id'] ?? ''),
                    'status' => 'completed',
                ],
                'subscription_contract' => [
                    'id' => $matches[2],
                    'status' => 'active',
                ],
                'current_subscription' => [
                    'id' => $matches[2],
                    'status' => 'active',
                ],
                'access_projection' => [
                    'entitlement_state' => 'active',
                ],
                'transaction' => [
                    'id' => 'txn_' . $matches[2],
                    'status' => 'verified',
                ],
            ]);
        }
        if ($method === 'POST' && preg_match('#^/v1/xapps/([^/]+)/monetization/subscription-contracts/([^/]+)/cancel$#', $path, $matches) === 1) {
            return self::json(200, [
                'xapp_id' => $matches[1],
                'version_id' => 'ver_' . $matches[1],
                'payment_session' => null,
                'subscription_contract' => [
                    'id' => $matches[2],
                    'status' => 'cancelled',
                ],
                'current_subscription' => [
                    'id' => $matches[2],
                    'status' => 'cancelled',
                ],
                'access_projection' => [
                    'entitlement_state' => 'active',
                ],
                'transaction' => null,
            ]);
        }
        if ($method === 'POST' && preg_match('#^/v1/xapps/([^/]+)/monetization/subscription-contracts/([^/]+)/refresh-state$#', $path, $matches) === 1) {
            return self::json(200, [
                'xapp_id' => $matches[1],
                'version_id' => 'ver_' . $matches[1],
                'payment_session' => null,
                'subscription_contract' => [
                    'id' => $matches[2],
                    'status' => 'past_due',
                ],
                'current_subscription' => [
                    'id' => $matches[2],
                    'status' => 'past_due',
                ],
                'access_projection' => [
                    'entitlement_state' => 'suspended',
                ],
                'transaction' => null,
            ]);
        }
        if ($method === 'POST' && $path === '/publisher/import-manifest') {
            $slug = trim((string) ($payload['slug'] ?? 'demo'));
            return self::json(200, [
                'xappId' => 'xapp_' . $slug,
                'versionId' => 'ver_' . $slug,
            ]);
        }
        if ($method === 'POST' && preg_match('#^/publisher/xapp-versions/([^/]+)/publish$#', $path, $matches) === 1) {
            return self::json(200, [
                'version' => [
                    'id' => $matches[1],
                    'status' => 'published',
                ],
            ]);
        }
        if ($method === 'GET' && $path === '/publisher/xapps') {
            return self::json(200, [
                'items' => [
                    ['id' => 'xapp_demo', 'slug' => 'demo'],
                ],
            ]);
        }
        if ($method === 'GET' && $path === '/publisher/clients') {
            return self::json(200, [
                'items' => [
                    ['id' => 'client_demo', 'slug' => 'tenant-demo', 'name' => 'Tenant Demo'],
                ],
            ]);
        }
        if ($method === 'GET' && preg_match('#^/publisher/xapps/([^/]+)/versions$#', $path, $matches) === 1) {
            return self::json(200, [
                'items' => [
                    ['id' => 'ver_' . $matches[1], 'status' => 'published'],
                ],
            ]);
        }
        if ($method === 'GET' && preg_match('#^/publisher/xapp-versions/([^/]+)/endpoints$#', $path, $matches) === 1) {
            return self::json(200, [
                'items' => [
                    ['id' => 'endpoint_' . $matches[1], 'env' => 'production'],
                ],
            ]);
        }
        if ($method === 'GET' && preg_match('#^/publisher/endpoints/([^/]+)/credentials$#', $path, $matches) === 1) {
            return self::json(200, [
                'items' => [
                    ['id' => 'cred_' . $matches[1], 'status' => 'active'],
                ],
            ]);
        }
        if ($method === 'POST' && preg_match('#^/publisher/endpoints/([^/]+)/credentials$#', $path, $matches) === 1) {
            return self::json(200, [
                'credential' => [
                    'id' => 'cred_' . $matches[1],
                    'authType' => (string) ($payload['authType'] ?? ''),
                ],
            ]);
        }
        if ($method === 'POST' && $path === '/v1/publisher/links/complete') {
            return self::json(200, [
                'success' => true,
                'link_id' => 'lnk_fixture',
            ]);
        }
        if ($method === 'POST' && $path === '/v1/publisher/links/revoke') {
            return self::json(200, [
                'revoked' => true,
                'alreadyRevoked' => false,
                'deleted' => 1,
            ]);
        }
        if ($method === 'GET' && $path === '/v1/publisher/links/status') {
            return self::json(200, [
                'linked' => true,
                'publisherUserId' => 'publisher-user-123',
                'link_id' => 'lnk_fixture',
            ]);
        }
        if ($method === 'POST' && $path === '/v1/publisher/bridge/token') {
            if (
                trim((string) ($payload['publisher_id'] ?? '')) === 'pub_conflict'
                && (($payload['link_required'] ?? false) === true)
            ) {
                return self::json(409, [
                    'message' => 'Linking required before vendor assertion minting',
                    'code' => 'NEEDS_LINKING',
                    'setup_url' => 'https://publisher.example.test/link',
                ]);
            }
            return self::json(200, [
                'vendor_assertion' => 'vendor_assertion_fixture',
                'issuer' => 'xapps',
                'subject_id' => 'sub_fixture',
                'link_id' => 'lnk_fixture',
                'expires_in' => 900,
            ]);
        }
        return self::json(404, [
            'message' => 'Not found',
            'path' => $path,
            'method' => $method,
        ]);
    }

    /** @param array<string,mixed> $payload @return array{status:int,headers:array<string,string>,body:string} */
    private static function json(int $status, array $payload): array
    {
        return [
            'status' => $status,
            'headers' => [
                'content-type' => 'application/json',
            ],
            'body' => (string) json_encode($payload, \JSON_UNESCAPED_SLASHES),
        ];
    }
}

function curl_init(?string $url = null): TestCurlHandle
{
    return new TestCurlHandle((string) ($url ?? ''));
}

/** @param array<int,mixed> $options */
function curl_setopt_array(TestCurlHandle $handle, array $options): bool
{
    foreach ($options as $key => $value) {
        $handle->options[(int) $key] = $value;
    }
    return true;
}

function curl_exec(TestCurlHandle $handle): string|false
{
    $response = TestCurlShim::respond($handle);
    $handle->status = $response['status'];
    return $response['body'];
}

function curl_getinfo(TestCurlHandle $handle, int $option = 0): mixed
{
    if ($option === \CURLINFO_RESPONSE_CODE) {
        return $handle->status;
    }
    return null;
}

function curl_error(TestCurlHandle $handle): string
{
    return $handle->error;
}

function curl_close(TestCurlHandle $handle): void
{
}
