<?php

declare(strict_types=1);

namespace Xapps;

final class GatewayClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $token;
    private int $timeoutSeconds;
    private int $connectTimeoutSeconds;
    /** @var array{maxAttempts:int,baseDelayMs:int,maxDelayMs:int,retryOnStatus:array<int,int>,methods:array<int,string>} */
    private array $retryPolicy;

    /** @param array<string,mixed> $options */
    public function __construct(string $baseUrl, string $apiKey = '', int $timeoutSeconds = 20, array $options = [])
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = trim($apiKey);
        $this->token = trim((string) ($options['token'] ?? ''));
        $this->timeoutSeconds = max(1, $timeoutSeconds);
        $this->connectTimeoutSeconds = max(1, (int) ($options['connectTimeoutSeconds'] ?? 5));
        if ($this->baseUrl === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'baseUrl is required');
        }
        if (!filter_var($this->baseUrl, FILTER_VALIDATE_URL)) {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'baseUrl must be a valid URL');
        }
        if ($this->apiKey === '' && $this->token === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'apiKey or token is required');
        }
        $this->retryPolicy = self::normalizeRetryPolicy(
            is_array($options['retry'] ?? null) ? $options['retry'] : [],
        );
    }

    /** @return array{status:int,headers:array<string,string>,body:string,json:array<string,mixed>|null} */
    public function get(string $path, array $query = [], array $headers = []): array
    {
        $url = $this->baseUrl . $path;
        if (count($query) > 0) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        return $this->request('GET', $url, null, $headers);
    }

    /** @return array{status:int,headers:array<string,string>,body:string,json:array<string,mixed>|null} */
    public function post(string $path, array $payload = [], array $headers = []): array
    {
        return $this->request('POST', $this->baseUrl . $path, $payload, $headers);
    }

    /** @return array{status:int,headers:array<string,string>,body:string,json:array<string,mixed>|null} */
    public function delete(string $path, array $headers = []): array
    {
        return $this->request('DELETE', $this->baseUrl . $path, null, $headers);
    }

    /** @param array<string,mixed> $input @return array{session:array<string,mixed>,paymentPageUrl:?string} */
    public function createPaymentSession(array $input): array
    {
        $response = $this->post('/v1/payment-sessions', [
            'payment_session_id' => isset($input['paymentSessionId']) ? (string) $input['paymentSessionId'] : (isset($input['payment_session_id']) ? (string) $input['payment_session_id'] : null),
            'page_url' => isset($input['pageUrl']) ? (string) $input['pageUrl'] : (isset($input['page_url']) ? (string) $input['page_url'] : null),
            'xapp_id' => (string) ($input['xappId'] ?? $input['xapp_id'] ?? ''),
            'tool_name' => (string) ($input['toolName'] ?? $input['tool_name'] ?? ''),
            'amount' => ($input['amount'] ?? ''),
            'currency' => ($input['currency'] ?? null),
            'issuer' => ($input['issuer'] ?? null),
            'scheme' => (isset($input['scheme']) ? (string) $input['scheme'] : null),
            'payment_scheme' => (isset($input['paymentScheme']) ? (string) $input['paymentScheme'] : (isset($input['payment_scheme']) ? (string) $input['payment_scheme'] : null)),
            'return_url' => (string) ($input['returnUrl'] ?? $input['return_url'] ?? ''),
            'cancel_url' => ($input['cancelUrl'] ?? $input['cancel_url'] ?? null),
            'xapps_resume' => ($input['xappsResume'] ?? $input['xapps_resume'] ?? null),
            'subject_id' => ($input['subjectId'] ?? $input['subject_id'] ?? null),
            'installation_id' => ($input['installationId'] ?? $input['installation_id'] ?? null),
            'client_id' => ($input['clientId'] ?? $input['client_id'] ?? null),
            'metadata' => (isset($input['metadata']) && is_array($input['metadata'])) ? $input['metadata'] : null,
        ]);
        $payload = $this->extractGatewayResult($response, 'createPaymentSession');
        $session = (isset($payload['session']) && is_array($payload['session'])) ? $payload['session'] : $payload;
        if (!is_array($session)) {
            throw new XappsSdkError(
                XappsSdkError::GATEWAY_API_INVALID_RESPONSE,
                'Gateway createPaymentSession returned malformed response',
                $response['status'],
                false,
                ['payload' => $payload],
            );
        }
        return [
            'session' => $session,
            'paymentPageUrl' => isset($payload['payment_page_url'])
                ? (string) $payload['payment_page_url']
                : (isset($payload['paymentPageUrl']) ? (string) $payload['paymentPageUrl'] : null),
        ];
    }

    /** @return array{session:array<string,mixed>} */
    public function getPaymentSession(string $paymentSessionId): array
    {
        $id = trim($paymentSessionId);
        if ($id === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'paymentSessionId is required');
        }
        $response = $this->get('/v1/payment-sessions/' . rawurlencode($id));
        $payload = $this->extractGatewayResult($response, 'getPaymentSession');
        $session = (isset($payload['session']) && is_array($payload['session'])) ? $payload['session'] : $payload;
        if (!is_array($session)) {
            throw new XappsSdkError(
                XappsSdkError::GATEWAY_API_INVALID_RESPONSE,
                'Gateway getPaymentSession returned malformed response',
                $response['status'],
                false,
                ['payload' => $payload],
            );
        }
        return ['session' => $session];
    }

    /** @param array<string,mixed> $input @return array{session:?array<string,mixed>,redirectUrl:?string,flow:?string,paymentSessionId:?string,clientSettleUrl:?string,providerReference:?string,scheme:?array<string,mixed>,metadata:?array<string,mixed>} */
    public function completePaymentSession(string $paymentSessionId, array $input = []): array
    {
        $id = trim($paymentSessionId);
        if ($id === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'paymentSessionId is required');
        }
        $response = $this->post('/v1/payment-sessions/' . rawurlencode($id) . '/complete', [
            'issuer' => ($input['issuer'] ?? null),
            'status' => ($input['status'] ?? null),
            'xapps_resume' => ($input['xappsResume'] ?? $input['xapps_resume'] ?? null),
            'metadata' => (isset($input['metadata']) && is_array($input['metadata'])) ? $input['metadata'] : null,
        ]);
        $payload = $this->extractGatewayResult($response, 'completePaymentSession');
        return $this->parseCompleteLikePayload($payload);
    }

    /** @param array<string,mixed> $input @return array{session:array<string,mixed>} */
    public function getGatewayPaymentSession(array $input): array
    {
        $id = trim((string) ($input['paymentSessionId'] ?? $input['payment_session_id'] ?? ''));
        if ($id === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'paymentSessionId is required');
        }
        $query = ['payment_session_id' => $id];
        $returnUrl = trim((string) ($input['returnUrl'] ?? $input['return_url'] ?? ''));
        $cancelUrl = trim((string) ($input['cancelUrl'] ?? $input['cancel_url'] ?? ''));
        $xappsResume = trim((string) ($input['xappsResume'] ?? $input['xapps_resume'] ?? ''));
        if ($returnUrl !== '') $query['return_url'] = $returnUrl;
        if ($cancelUrl !== '') $query['cancel_url'] = $cancelUrl;
        if ($xappsResume !== '') $query['xapps_resume'] = $xappsResume;
        $response = $this->get('/v1/gateway-payment/session', $query);
        $payload = $this->extractGatewayResult($response, 'getGatewayPaymentSession');
        if (!is_array($payload)) {
            throw new XappsSdkError(
                XappsSdkError::GATEWAY_API_INVALID_RESPONSE,
                'Gateway getGatewayPaymentSession returned malformed response',
                $response['status'],
                false,
                ['payload' => $payload],
            );
        }
        return ['session' => $payload];
    }

    /** @param array<string,mixed> $input @return array{session:?array<string,mixed>,redirectUrl:?string,flow:?string,paymentSessionId:?string,clientSettleUrl:?string,providerReference:?string,scheme:?array<string,mixed>,metadata:?array<string,mixed>} */
    public function completeGatewayPayment(array $input): array
    {
        $id = trim((string) ($input['paymentSessionId'] ?? $input['payment_session_id'] ?? ''));
        if ($id === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'paymentSessionId is required');
        }
        $response = $this->post('/v1/gateway-payment/complete', [
            'payment_session_id' => $id,
            'return_url' => ($input['returnUrl'] ?? $input['return_url'] ?? null),
            'cancel_url' => ($input['cancelUrl'] ?? $input['cancel_url'] ?? null),
            'xapps_resume' => ($input['xappsResume'] ?? $input['xapps_resume'] ?? null),
        ]);
        $payload = $this->extractGatewayResult($response, 'completeGatewayPayment');
        return $this->parseCompleteLikePayload($payload);
    }

    /** @param array<string,mixed> $input @return array{session:?array<string,mixed>,redirectUrl:?string,flow:?string,paymentSessionId:?string,clientSettleUrl:?string,providerReference:?string,scheme:?array<string,mixed>,metadata:?array<string,mixed>} */
    public function clientSettleGatewayPayment(array $input): array
    {
        $id = trim((string) ($input['paymentSessionId'] ?? $input['payment_session_id'] ?? ''));
        if ($id === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'paymentSessionId is required');
        }
        $response = $this->post('/v1/gateway-payment/client-settle', [
            'payment_session_id' => $id,
            'return_url' => ($input['returnUrl'] ?? $input['return_url'] ?? null),
            'xapps_resume' => ($input['xappsResume'] ?? $input['xapps_resume'] ?? null),
            'status' => ($input['status'] ?? null),
            'client_token' => ($input['clientToken'] ?? $input['client_token'] ?? null),
            'metadata' => (isset($input['metadata']) && is_array($input['metadata'])) ? $input['metadata'] : null,
        ]);
        $payload = $this->extractGatewayResult($response, 'clientSettleGatewayPayment');
        return $this->parseCompleteLikePayload($payload);
    }

    /** @param array<string,mixed> $input @return array{subjectId:string} */
    public function resolveSubject(array $input): array
    {
        $idType = trim((string) ($input['identifier']['idType'] ?? ''));
        $identifierValue = trim((string) ($input['identifier']['value'] ?? ''));
        if ($idType === '' || $identifierValue === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'identifier.idType and identifier.value are required');
        }
        $response = $this->post('/v1/subjects/resolve', [
            'type' => (string) ($input['type'] ?? 'user'),
            'identifier' => [
                'idType' => $idType,
                'value' => $identifierValue,
                'hint' => isset($input['identifier']['hint']) ? (string) $input['identifier']['hint'] : null,
            ],
            'email' => isset($input['email']) ? (string) $input['email'] : null,
        ]);
        $payload = $this->extractGatewayResult($response, 'resolveSubject');
        $subjectId = trim((string) ($payload['subjectId'] ?? ''));
        if ($subjectId === '') {
            throw new XappsSdkError(
                XappsSdkError::GATEWAY_API_INVALID_RESPONSE,
                'Gateway resolveSubject returned malformed response',
                $response['status'],
                false,
                ['payload' => $payload],
            );
        }
        return ['subjectId' => $subjectId];
    }

    /** @param array<string,mixed> $input @return array{token:string,embedUrl:string} */
    public function createCatalogSession(array $input): array
    {
        $origin = trim((string) ($input['origin'] ?? ''));
        if ($origin === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'origin is required');
        }
        $publishers = self::normalizeOptionalStringList(
            is_array($input['publishers'] ?? null) ? $input['publishers'] : null,
        );
        $tags = self::normalizeOptionalStringList(
            is_array($input['tags'] ?? null) ? $input['tags'] : null,
        );
        $response = $this->post('/v1/catalog-sessions', self::withoutNullValues([
            'origin' => $origin,
            'subjectId' => isset($input['subjectId']) ? (string) $input['subjectId'] : null,
            'xappId' => isset($input['xappId']) ? (string) $input['xappId'] : null,
            'publishers' => $publishers,
            'tags' => $tags,
        ]));
        $payload = $this->extractGatewayResult($response, 'createCatalogSession');
        $token = trim((string) ($payload['token'] ?? ''));
        $embedUrl = trim((string) ($payload['embedUrl'] ?? ''));
        if ($token === '' || $embedUrl === '') {
            throw new XappsSdkError(
                XappsSdkError::GATEWAY_API_INVALID_RESPONSE,
                'Gateway createCatalogSession returned malformed response',
                $response['status'],
                false,
                ['payload' => $payload],
            );
        }
        return [
            'token' => $token,
            'embedUrl' => $embedUrl,
        ];
    }

    /** @param array<string,mixed> $input @return array{token:string,embedUrl:string,context?:array<string,mixed>,widget?:array<string,mixed>,tool?:array<string,mixed>|null} */
    public function createWidgetSession(array $input): array
    {
        $installationId = trim((string) ($input['installationId'] ?? ''));
        $widgetId = trim((string) ($input['widgetId'] ?? ''));
        if ($installationId === '' || $widgetId === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'installationId and widgetId are required');
        }
        $headers = [];
        $origin = trim((string) ($input['origin'] ?? ''));
        if ($origin !== '') {
            $headers['Origin'] = $origin;
        }
        $response = $this->post('/v1/widget-sessions', self::withoutNullValues([
            'installationId' => $installationId,
            'widgetId' => $widgetId,
            'locale' => isset($input['locale']) ? (string) $input['locale'] : null,
            'xappId' => isset($input['xappId']) ? (string) $input['xappId'] : null,
            'subjectId' => isset($input['subjectId']) ? (string) $input['subjectId'] : null,
            'requestId' => isset($input['requestId']) ? (string) $input['requestId'] : null,
            'hostReturnUrl' => isset($input['hostReturnUrl']) ? (string) $input['hostReturnUrl'] : null,
            'resultPresentation' => isset($input['resultPresentation']) ? (string) $input['resultPresentation'] : null,
            'guardUi' => is_array($input['guardUi'] ?? null) ? $input['guardUi'] : null,
        ]), $headers);
        $payload = $this->extractGatewayResult($response, 'createWidgetSession');
        $token = trim((string) ($payload['token'] ?? ''));
        $embedUrl = trim((string) ($payload['embedUrl'] ?? ''));
        if ($token === '' || $embedUrl === '') {
            throw new XappsSdkError(
                XappsSdkError::GATEWAY_API_INVALID_RESPONSE,
                'Gateway createWidgetSession returned malformed response',
                $response['status'],
                false,
                ['payload' => $payload],
            );
        }
        $result = [
            'token' => $token,
            'embedUrl' => $embedUrl,
        ];
        if (isset($payload['context']) && is_array($payload['context'])) {
            $result['context'] = $payload['context'];
        }
        if (isset($payload['widget']) && is_array($payload['widget'])) {
            $result['widget'] = $payload['widget'];
        }
        if (array_key_exists('tool', $payload)) {
            $result['tool'] = is_array($payload['tool']) ? $payload['tool'] : null;
        }
        return $result;
    }

    /** @param array<string,mixed> $input @return array{verified:bool,latestRequestId:?string,result:array<string,mixed>} */
    public function verifyBrowserWidgetContext(array $input): array
    {
        $hostOrigin = trim((string) ($input['hostOrigin'] ?? $input['host_origin'] ?? ''));
        $bootstrapTicket = trim((string) ($input['bootstrapTicket'] ?? $input['bootstrap_ticket'] ?? ''));
        if ($hostOrigin === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'hostOrigin is required');
        }

        $query = [];
        $installationId = trim((string) ($input['installationId'] ?? $input['installation_id'] ?? ''));
        $toolName = trim((string) ($input['bindToolName'] ?? $input['bind_tool_name'] ?? $input['toolName'] ?? $input['tool_name'] ?? ''));
        $subjectId = trim((string) ($input['subjectId'] ?? $input['subject_id'] ?? ''));
        if ($installationId !== '') $query['installationId'] = $installationId;
        if ($toolName !== '') $query['toolName'] = $toolName;
        if ($subjectId !== '') $query['subjectId'] = $subjectId;

        $headers = ['Origin' => $hostOrigin];
        if ($bootstrapTicket !== '') {
            $headers['Authorization'] = 'Bearer ' . $bootstrapTicket;
        }

        $response = $this->get('/v1/requests/latest', $query, $headers);
        $payload = $this->extractGatewayResult($response, 'verifyBrowserWidgetContext');
        if (!is_array($payload)) {
            throw new XappsSdkError(
                XappsSdkError::GATEWAY_API_INVALID_RESPONSE,
                'Gateway verifyBrowserWidgetContext returned malformed response',
                $response['status'],
                false,
                ['payload' => $payload],
            );
        }
        $latestRequestId = trim((string) ($payload['requestId'] ?? $payload['id'] ?? ''));
        return [
            'verified' => true,
            'latestRequestId' => $latestRequestId !== '' ? $latestRequestId : null,
            'result' => $payload,
        ];
    }

    /** @param array<string,mixed> $input @return array{items:array<int,array<string,mixed>>} */
    public function listInstallations(array $input = []): array
    {
        $path = '/v1/installations';
        $subjectId = trim((string) ($input['subjectId'] ?? ''));
        if ($subjectId !== '') {
            $path .= '?subjectId=' . rawurlencode($subjectId);
        }
        $response = $this->get($path);
        $payload = $this->extractGatewayResult($response, 'listInstallations');
        $items = [];
        if (isset($payload['items']) && is_array($payload['items'])) {
            $items = $payload['items'];
        } elseif (array_is_list($payload)) {
            $items = $payload;
        }
        return ['items' => $items];
    }

    /** @param array<string,mixed> $input @return array{installation:array<string,mixed>} */
    public function installXapp(array $input): array
    {
        $xappId = trim((string) ($input['xappId'] ?? ''));
        if ($xappId === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'xappId is required');
        }
        $path = '/v1/installations';
        $subjectId = trim((string) ($input['subjectId'] ?? ''));
        if ($subjectId !== '') {
            $path .= '?subjectId=' . rawurlencode($subjectId);
        }
        $response = $this->post($path, [
            'xappId' => $xappId,
            'termsAccepted' => isset($input['termsAccepted']) && is_bool($input['termsAccepted']) ? $input['termsAccepted'] : null,
        ]);
        $payload = $this->extractGatewayResult($response, 'installXapp');
        $installation = isset($payload['installation']) && is_array($payload['installation']) ? $payload['installation'] : null;
        if ($installation === null) {
            throw new XappsSdkError(
                XappsSdkError::GATEWAY_API_INVALID_RESPONSE,
                'Gateway installXapp returned malformed response',
                $response['status'],
                false,
                ['payload' => $payload],
            );
        }
        return ['installation' => $installation];
    }

    /** @param array<string,mixed> $input @return array{installation:array<string,mixed>} */
    public function updateInstallation(array $input): array
    {
        $installationId = trim((string) ($input['installationId'] ?? ''));
        if ($installationId === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'installationId is required');
        }
        $path = '/v1/installations/' . rawurlencode($installationId) . '/update';
        $subjectId = trim((string) ($input['subjectId'] ?? ''));
        if ($subjectId !== '') {
            $path .= '?subjectId=' . rawurlencode($subjectId);
        }
        $response = $this->post($path, [
            'termsAccepted' => isset($input['termsAccepted']) && is_bool($input['termsAccepted']) ? $input['termsAccepted'] : null,
        ]);
        $payload = $this->extractGatewayResult($response, 'updateInstallation');
        $installation = isset($payload['installation']) && is_array($payload['installation']) ? $payload['installation'] : null;
        if ($installation === null) {
            throw new XappsSdkError(
                XappsSdkError::GATEWAY_API_INVALID_RESPONSE,
                'Gateway updateInstallation returned malformed response',
                $response['status'],
                false,
                ['payload' => $payload],
            );
        }
        return ['installation' => $installation];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function uninstallInstallation(array $input): array
    {
        $installationId = trim((string) ($input['installationId'] ?? ''));
        if ($installationId === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'installationId is required');
        }
        $path = '/v1/installations/' . rawurlencode($installationId);
        $subjectId = trim((string) ($input['subjectId'] ?? ''));
        if ($subjectId !== '') {
            $path .= '?subjectId=' . rawurlencode($subjectId);
        }
        $response = $this->delete($path);
        $payload = $this->extractGatewayResult($response, 'uninstallInstallation');
        return is_array($payload) ? $payload : [];
    }

    /** @param array<string,mixed> $payload @return array{session:?array<string,mixed>,redirectUrl:?string,flow:?string,paymentSessionId:?string,clientSettleUrl:?string,providerReference:?string,scheme:?array<string,mixed>,metadata:?array<string,mixed>} */
    private function parseCompleteLikePayload(array $payload): array
    {
        $scheme = (isset($payload['scheme']) && is_array($payload['scheme'])) ? $payload['scheme'] : null;
        $metadata = (isset($payload['metadata']) && is_array($payload['metadata'])) ? $payload['metadata'] : null;
        return [
            'session' => (isset($payload['session']) && is_array($payload['session'])) ? $payload['session'] : null,
            'redirectUrl' => isset($payload['redirect_url'])
                ? (string) $payload['redirect_url']
                : (isset($payload['redirectUrl']) ? (string) $payload['redirectUrl'] : null),
            'flow' => isset($payload['flow'])
                ? (string) $payload['flow']
                : (isset($payload['provider_flow']) ? (string) $payload['provider_flow'] : null),
            'paymentSessionId' => isset($payload['payment_session_id'])
                ? (string) $payload['payment_session_id']
                : (isset($payload['paymentSessionId']) ? (string) $payload['paymentSessionId'] : null),
            'clientSettleUrl' => isset($payload['client_settle_url'])
                ? (string) $payload['client_settle_url']
                : (isset($payload['clientSettleUrl']) ? (string) $payload['clientSettleUrl'] : null),
            'providerReference' => isset($payload['provider_reference'])
                ? (string) $payload['provider_reference']
                : (isset($payload['providerReference']) ? (string) $payload['providerReference'] : null),
            'scheme' => $scheme,
            'metadata' => $metadata,
        ];
    }

    /** @return array{status:int,headers:array<string,string>,body:string,json:array<string,mixed>|null} */
    private function request(string $method, string $url, ?array $payload = null, array $headers = []): array
    {
        $lastError = null;
        $allowedMethods = $this->retryPolicy['methods'];
        $shouldRetryMethod = in_array(strtoupper($method), $allowedMethods, true);
        $attempts = $shouldRetryMethod ? $this->retryPolicy['maxAttempts'] : 1;

        for ($attempt = 1; $attempt <= $attempts; $attempt += 1) {
            try {
                return $this->requestOnce($method, $url, $payload, $headers);
            } catch (\Throwable $error) {
                $lastError = $error;
                $retryable = $error instanceof XappsSdkError ? $error->retryable : false;
                if (!$retryable || $attempt >= $attempts) {
                    break;
                }
                $delayMs = min($this->retryPolicy['maxDelayMs'], $this->retryPolicy['baseDelayMs'] * (2 ** ($attempt - 1)));
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }
        throw new XappsSdkError(
            XappsSdkError::GATEWAY_API_RETRY_EXHAUSTED,
            'Gateway request failed after retries',
            $lastError instanceof XappsSdkError ? $lastError->status : null,
            false,
            ['cause' => $lastError ? $lastError->getMessage() : null],
            $lastError instanceof \Throwable ? $lastError : null,
        );
    }

    /** @return array{status:int,headers:array<string,string>,body:string,json:array<string,mixed>|null} */
    private function requestOnce(string $method, string $url, ?array $payload = null, array $headers = []): array
    {
        $responseHeaders = [];
        $headerLines = ['Accept: application/json'];
        if ($this->token !== '') {
            $headerLines[] = 'Authorization: Bearer ' . $this->token;
        }
        if ($this->apiKey !== '') {
            $headerLines[] = 'X-API-Key: ' . $this->apiKey;
        }
        foreach ($headers as $key => $value) {
            $headerLines[] = is_int($key) ? (string) $value : ($key . ': ' . $value);
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $trimmed = trim($line);
                if ($trimmed !== '' && str_contains($trimmed, ':')) {
                    [$key, $value] = explode(':', $trimmed, 2);
                    $responseHeaders[strtolower(trim($key))] = trim($value);
                }
                return strlen($line);
            },
        ];

        if ($payload !== null) {
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($payload);
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        if (!class_exists('CurlHandle', false) || !($ch instanceof \CurlHandle)) {
            curl_close($ch);
        }

        if ($body === false) {
            throw new XappsSdkError(
                XappsSdkError::GATEWAY_API_NETWORK_ERROR,
                'Gateway request failed: ' . $error,
                null,
                true,
            );
        }

        $json = json_decode($body, true);
        return [
            'status' => (int) $status,
            'headers' => $responseHeaders,
            'body' => (string) $body,
            'json' => is_array($json) ? $json : null,
        ];
    }

    /** @param array{status:int,headers:array<string,string>,body:string,json:array<string,mixed>|null} $response @return array<string,mixed> */
    private function extractGatewayResult(array $response, string $operation): array
    {
        if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
            $this->throwGatewayHttpError($response, $operation);
        }

        $payload = $response['json'] ?? null;
        if (!is_array($payload)) {
            throw new XappsSdkError(
                XappsSdkError::GATEWAY_API_INVALID_RESPONSE,
                'Gateway API returned malformed response',
                $response['status'] ?? null,
                false,
                ['operation' => $operation, 'body' => $response['body'] ?? ''],
            );
        }

        if (isset($payload['result']) && is_array($payload['result'])) {
            return $payload['result'];
        }
        return $payload;
    }

    /** @param array{status:int,headers:array<string,string>,body:string,json:array<string,mixed>|null} $response */
    private function throwGatewayHttpError(array $response, string $operation): void
    {
        $status = (int) ($response['status'] ?? 0);
        $payload = $response['json'] ?? null;
        $message = 'Gateway API request failed (' . (string) $status . ')';
        if (is_array($payload) && isset($payload['message']) && is_string($payload['message']) && trim($payload['message']) !== '') {
            $message = trim($payload['message']);
        }
        $retryable = in_array($status, $this->retryPolicy['retryOnStatus'], true);
        $code = XappsSdkError::GATEWAY_API_HTTP_ERROR;
        if ($status === 401 || $status === 403) {
            $code = XappsSdkError::GATEWAY_API_UNAUTHORIZED;
        } elseif ($status === 404) {
            $code = XappsSdkError::GATEWAY_API_NOT_FOUND;
        } elseif ($status === 409) {
            $code = XappsSdkError::GATEWAY_API_CONFLICT;
        }
        throw new XappsSdkError(
            $code,
            $message,
            $status > 0 ? $status : null,
            $retryable,
            ['operation' => $operation, 'payload' => $payload, 'body' => $response['body'] ?? ''],
        );
    }

    /** @param array<string,mixed> $input @return array{maxAttempts:int,baseDelayMs:int,maxDelayMs:int,retryOnStatus:array<int,int>,methods:array<int,string>} */
    private static function normalizeRetryPolicy(array $input): array
    {
        $maxAttempts = max(1, min(6, (int) ($input['maxAttempts'] ?? 1)));
        $baseDelayMs = max(0, (int) ($input['baseDelayMs'] ?? 150));
        $maxDelayMs = max($baseDelayMs, (int) ($input['maxDelayMs'] ?? 1200));
        $defaultStatuses = [408, 425, 429, 500, 502, 503, 504];
        $rawStatuses = isset($input['retryOnStatus']) && is_array($input['retryOnStatus'])
            ? $input['retryOnStatus']
            : $defaultStatuses;
        $retryOnStatus = [];
        foreach ($rawStatuses as $status) {
            $n = (int) $status;
            if ($n >= 100 && $n <= 599) {
                $retryOnStatus[] = $n;
            }
        }
        if (count($retryOnStatus) === 0) {
            $retryOnStatus = $defaultStatuses;
        }
        $rawMethods = isset($input['methods']) && is_array($input['methods'])
            ? $input['methods']
            : ['GET', 'HEAD', 'OPTIONS', 'DELETE'];
        $methods = [];
        foreach ($rawMethods as $method) {
            $name = strtoupper(trim((string) $method));
            if ($name !== '') {
                $methods[] = $name;
            }
        }
        if (count($methods) === 0) {
            $methods = ['GET', 'HEAD', 'OPTIONS', 'DELETE'];
        }
        return [
            'maxAttempts' => $maxAttempts,
            'baseDelayMs' => $baseDelayMs,
            'maxDelayMs' => $maxDelayMs,
            'retryOnStatus' => array_values(array_unique($retryOnStatus)),
            'methods' => array_values(array_unique($methods)),
        ];
    }

    /** @param array<int,mixed>|null $input @return array<int,string>|null */
    private static function normalizeOptionalStringList(?array $input): ?array
    {
        if ($input === null) {
            return null;
        }
        $items = [];
        foreach ($input as $value) {
            $normalized = trim((string) $value);
            if ($normalized === '') {
                continue;
            }
            $items[$normalized] = $normalized;
        }
        return count($items) > 0 ? array_values($items) : null;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private static function withoutNullValues(array $input): array
    {
        $result = [];
        foreach ($input as $key => $value) {
            if ($value === null) {
                continue;
            }
            $result[$key] = $value;
        }
        return $result;
    }
}
