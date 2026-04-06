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
            'metadata' => (isset($input['metadata']) && is_array($input['metadata'])) ? $input['metadata'] : null,
            'linkId' => isset($input['linkId']) ? (string) $input['linkId'] : null,
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

    /** @return array{client:array<string,mixed>} */
    public function getClientSelf(): array
    {
        $response = $this->get('/v1/client-self');
        $payload = $this->extractGatewayResult($response, 'getClientSelf');
        $client = (isset($payload['client']) && is_array($payload['client'])) ? $payload['client'] : null;
        if (!is_array($client) || trim((string) ($client['id'] ?? '')) === '') {
            throw new XappsSdkError(
                XappsSdkError::GATEWAY_API_INVALID_RESPONSE,
                'Gateway getClientSelf returned malformed response',
                $response['status'],
                false,
                ['payload' => $payload],
            );
        }
        $policy = (isset($client['installation_policy']) && is_array($client['installation_policy']))
            ? $client['installation_policy']
            : [];
        $client['installation_policy'] = [
            'mode' => (($policy['mode'] ?? null) === 'auto_available') ? 'auto_available' : 'manual',
            'update_mode' => (($policy['update_mode'] ?? null) === 'auto_update_compatible')
                ? 'auto_update_compatible'
                : 'manual',
        ];
        return ['client' => $client];
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

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function getEmbedMyXappMonetization(array $input): array
    {
        $resolvedXappId = self::requireNonEmptyString((string) ($input['xappId'] ?? $input['xapp_id'] ?? ''), 'xappId');
        $token = self::requireNonEmptyString((string) ($input['token'] ?? ''), 'token');
        $query = self::withoutNullValues([
            'token' => $token,
            'installationId' => self::optionalTrimmedString($input['installationId'] ?? $input['installation_id'] ?? null),
            'locale' => self::optionalTrimmedString($input['locale'] ?? null),
            'country' => self::optionalTrimmedString($input['country'] ?? null),
            'realmRef' => self::optionalTrimmedString($input['realmRef'] ?? $input['realm_ref'] ?? null),
        ]);
        $response = $this->get('/embed/my-xapps/' . rawurlencode($resolvedXappId) . '/monetization', $query);
        return $this->extractGatewayResult($response, 'getEmbedMyXappMonetization');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function getEmbedMyXappMonetizationHistory(array $input): array
    {
        $resolvedXappId = self::requireNonEmptyString((string) ($input['xappId'] ?? $input['xapp_id'] ?? ''), 'xappId');
        $token = self::requireNonEmptyString((string) ($input['token'] ?? ''), 'token');
        $query = self::withoutNullValues([
            'token' => $token,
            'limit' => isset($input['limit']) && is_numeric((string) $input['limit']) ? (int) $input['limit'] : null,
        ]);
        $response = $this->get('/embed/my-xapps/' . rawurlencode($resolvedXappId) . '/monetization/history', $query);
        return $this->extractGatewayResult($response, 'getEmbedMyXappMonetizationHistory');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function prepareEmbedMyXappPurchaseIntent(array $input): array
    {
        $resolvedXappId = self::requireNonEmptyString((string) ($input['xappId'] ?? $input['xapp_id'] ?? ''), 'xappId');
        $token = self::requireNonEmptyString((string) ($input['token'] ?? ''), 'token');
        $response = $this->post(
            '/embed/my-xapps/' . rawurlencode($resolvedXappId) . '/monetization/purchase-intents/prepare?token=' . rawurlencode($token),
            self::withoutNullValues([
                'offering_id' => self::optionalTrimmedString($input['offeringId'] ?? $input['offering_id'] ?? null),
                'package_id' => self::optionalTrimmedString($input['packageId'] ?? $input['package_id'] ?? null),
                'price_id' => self::optionalTrimmedString($input['priceId'] ?? $input['price_id'] ?? null),
                'installation_id' => self::optionalTrimmedString($input['installationId'] ?? $input['installation_id'] ?? null),
                'locale' => self::optionalTrimmedString($input['locale'] ?? null),
                'country' => self::optionalTrimmedString($input['country'] ?? null),
            ]),
        );
        return $this->extractGatewayResult($response, 'prepareEmbedMyXappPurchaseIntent');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createEmbedMyXappPurchasePaymentSession(array $input): array
    {
        $resolvedXappId = self::requireNonEmptyString((string) ($input['xappId'] ?? $input['xapp_id'] ?? ''), 'xappId');
        $resolvedIntentId = self::requireNonEmptyString((string) ($input['intentId'] ?? $input['intent_id'] ?? ''), 'intentId');
        $token = self::requireNonEmptyString((string) ($input['token'] ?? ''), 'token');
        $response = $this->post(
            '/embed/my-xapps/' . rawurlencode($resolvedXappId) . '/monetization/purchase-intents/' . rawurlencode($resolvedIntentId) . '/payment-session?token=' . rawurlencode($token),
            self::withoutNullValues([
                'payment_guard_ref' => self::optionalTrimmedString($input['paymentGuardRef'] ?? $input['payment_guard_ref'] ?? null),
                'issuer' => self::optionalTrimmedString($input['issuer'] ?? null),
                'scheme' => self::optionalTrimmedString($input['scheme'] ?? null),
                'payment_scheme' => self::optionalTrimmedString($input['paymentScheme'] ?? $input['payment_scheme'] ?? null),
                'return_url' => self::optionalTrimmedString($input['returnUrl'] ?? $input['return_url'] ?? null),
                'cancel_url' => self::optionalTrimmedString($input['cancelUrl'] ?? $input['cancel_url'] ?? null),
                'xapps_resume' => self::optionalTrimmedString($input['xappsResume'] ?? $input['xapps_resume'] ?? null),
                'locale' => self::optionalTrimmedString($input['locale'] ?? null),
                'installation_id' => self::optionalTrimmedString($input['installationId'] ?? $input['installation_id'] ?? null),
                'metadata' => self::optionalRecord($input['metadata'] ?? null),
            ]),
        );
        return $this->extractGatewayResult($response, 'createEmbedMyXappPurchasePaymentSession');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function finalizeEmbedMyXappPurchasePaymentSession(array $input): array
    {
        $resolvedXappId = self::requireNonEmptyString((string) ($input['xappId'] ?? $input['xapp_id'] ?? ''), 'xappId');
        $resolvedIntentId = self::requireNonEmptyString((string) ($input['intentId'] ?? $input['intent_id'] ?? ''), 'intentId');
        $token = self::requireNonEmptyString((string) ($input['token'] ?? ''), 'token');
        $response = $this->post(
            '/embed/my-xapps/' . rawurlencode($resolvedXappId) . '/monetization/purchase-intents/' . rawurlencode($resolvedIntentId) . '/payment-session/finalize?token=' . rawurlencode($token),
            [],
        );
        return $this->extractGatewayResult($response, 'finalizeEmbedMyXappPurchasePaymentSession');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function runWidgetToolRequest(array $input): array
    {
        $token = self::requireNonEmptyString((string) ($input['token'] ?? ''), 'token');
        $installationId = self::requireNonEmptyString((string) ($input['installationId'] ?? $input['installation_id'] ?? ''), 'installationId');
        $toolName = self::requireNonEmptyString((string) ($input['toolName'] ?? $input['tool_name'] ?? ''), 'toolName');
        $created = $this->extractGatewayResult(
            $this->post(
                '/v1/requests',
                [
                    'installationId' => $installationId,
                    'toolName' => $toolName,
                    'payload' => (isset($input['payload']) && is_array($input['payload'])) ? $input['payload'] : [],
                ],
                ['Authorization' => 'Bearer ' . $token],
            ),
            'runWidgetToolRequest'
        );
        $requestRecord = (isset($created['request']) && is_array($created['request'])) ? $created['request'] : $created;
        $requestId = trim((string) ($requestRecord['id'] ?? ''));
        if ($requestId === '') {
            return $created;
        }

        $startedAt = microtime(true);
        while ((microtime(true) - $startedAt) < 15.0) {
            $detail = $this->extractGatewayResult(
                $this->get('/v1/requests/' . rawurlencode($requestId), [], ['Authorization' => 'Bearer ' . $token]),
                'runWidgetToolRequest'
            );
            $detailRequest = (isset($detail['request']) && is_array($detail['request'])) ? $detail['request'] : $detail;
            $status = strtoupper(trim((string) ($detailRequest['status'] ?? '')));
            if ($status === 'COMPLETED') {
                $response = $this->extractGatewayResult(
                    $this->get('/v1/requests/' . rawurlencode($requestId) . '/response', [], ['Authorization' => 'Bearer ' . $token]),
                    'runWidgetToolRequest'
                );
                if (isset($response['response']) && is_array($response['response'])) {
                    $responseRecord = $response['response'];
                    if (isset($responseRecord['result']) && is_array($responseRecord['result'])) {
                        return $responseRecord['result'];
                    }
                    return $responseRecord;
                }
                return $response;
            }
            if ($status === 'FAILED') {
                throw new XappsSdkError(
                    XappsSdkError::GATEWAY_API_HTTP_ERROR,
                    'Widget tool request failed',
                    null,
                    false,
                    ['details' => $detail],
                );
            }
            usleep(350000);
        }

        throw new XappsSdkError(
            XappsSdkError::GATEWAY_API_NETWORK_ERROR,
            'Widget tool request timed out',
        );
    }

    /** @param string|array<string,mixed> $input @return array<string,mixed> */
    public function getXappMonetizationCatalog(string|array $input): array
    {
        $payload = is_array($input) ? $input : ['xappId' => $input];
        $resolvedXappId = self::requireNonEmptyString((string) ($payload['xappId'] ?? $payload['xapp_id'] ?? ''), 'xappId');
        $query = self::buildMonetizationTargetingQuery($payload);
        $response = $this->get('/v1/xapps/' . rawurlencode($resolvedXappId) . '/monetization', $query);
        return $this->extractGatewayResult($response, 'getXappMonetizationCatalog');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function getXappMonetizationAccess(array $input): array
    {
        $resolvedXappId = self::requireNonEmptyString((string) ($input['xappId'] ?? $input['xapp_id'] ?? ''), 'xappId');
        $query = self::buildMonetizationScopeQuery($input);
        $response = $this->get('/v1/xapps/' . rawurlencode($resolvedXappId) . '/monetization/access', $query);
        return $this->extractGatewayResult($response, 'getXappMonetizationAccess');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function getXappCurrentSubscription(array $input): array
    {
        $resolvedXappId = self::requireNonEmptyString((string) ($input['xappId'] ?? $input['xapp_id'] ?? ''), 'xappId');
        $query = self::buildMonetizationScopeQuery($input);
        $response = $this->get('/v1/xapps/' . rawurlencode($resolvedXappId) . '/monetization/current-subscription', $query);
        return $this->extractGatewayResult($response, 'getXappCurrentSubscription');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function listXappEntitlements(array $input): array
    {
        $resolvedXappId = self::requireNonEmptyString((string) ($input['xappId'] ?? $input['xapp_id'] ?? ''), 'xappId');
        $query = self::buildMonetizationScopeQuery($input);
        $response = $this->get('/v1/xapps/' . rawurlencode($resolvedXappId) . '/monetization/entitlements', $query);
        return $this->extractGatewayResult($response, 'listXappEntitlements');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function listXappWalletAccounts(array $input): array
    {
        $resolvedXappId = self::requireNonEmptyString((string) ($input['xappId'] ?? $input['xapp_id'] ?? ''), 'xappId');
        $query = self::buildMonetizationScopeQuery($input);
        $response = $this->get('/v1/xapps/' . rawurlencode($resolvedXappId) . '/monetization/wallet-accounts', $query);
        return $this->extractGatewayResult($response, 'listXappWalletAccounts');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function listXappWalletLedger(array $input): array
    {
        $resolvedXappId = self::requireNonEmptyString((string) ($input['xappId'] ?? $input['xapp_id'] ?? ''), 'xappId');
        $query = self::withoutNullValues([
            'subject_id' => self::optionalTrimmedString($input['subjectId'] ?? $input['subject_id'] ?? null),
            'installation_id' => self::optionalTrimmedString($input['installationId'] ?? $input['installation_id'] ?? null),
            'realm_ref' => self::optionalTrimmedString($input['realmRef'] ?? $input['realm_ref'] ?? null),
            'wallet_account_id' => self::optionalTrimmedString($input['walletAccountId'] ?? $input['wallet_account_id'] ?? null),
            'payment_session_id' => self::optionalTrimmedString($input['paymentSessionId'] ?? $input['payment_session_id'] ?? null),
            'request_id' => self::optionalTrimmedString($input['requestId'] ?? $input['request_id'] ?? null),
            'settlement_ref' => self::optionalTrimmedString($input['settlementRef'] ?? $input['settlement_ref'] ?? null),
        ]);
        $response = $this->get('/v1/xapps/' . rawurlencode($resolvedXappId) . '/monetization/wallet-ledger', $query);
        return $this->extractGatewayResult($response, 'listXappWalletLedger');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function consumeXappWalletCredits(array $input): array
    {
        $resolvedXappId = self::requireNonEmptyString((string) ($input['xappId'] ?? $input['xapp_id'] ?? ''), 'xappId');
        $walletAccountId = self::requireNonEmptyString((string) ($input['walletAccountId'] ?? $input['wallet_account_id'] ?? ''), 'walletAccountId');
        $amount = self::requireNonEmptyString((string) ($input['amount'] ?? ''), 'amount');
        $response = $this->post(
            '/v1/xapps/' . rawurlencode($resolvedXappId) . '/monetization/wallet-accounts/' . rawurlencode($walletAccountId) . '/consume',
            self::withoutNullValues([
                'amount' => $amount,
                'source_ref' => self::optionalTrimmedString($input['sourceRef'] ?? $input['source_ref'] ?? null),
                'metadata' => self::optionalRecord($input['metadata'] ?? null),
            ])
        );
        return $this->extractGatewayResult($response, 'consumeXappWalletCredits');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function prepareXappPurchaseIntent(array $input): array
    {
        $resolvedXappId = self::requireNonEmptyString((string) ($input['xappId'] ?? $input['xapp_id'] ?? ''), 'xappId');
        $response = $this->post(
            '/v1/xapps/' . rawurlencode($resolvedXappId) . '/monetization/purchase-intents/prepare',
            self::withoutNullValues([
                'offering_id' => self::optionalTrimmedString($input['offeringId'] ?? $input['offering_id'] ?? null),
                'package_id' => self::optionalTrimmedString($input['packageId'] ?? $input['package_id'] ?? null),
                'price_id' => self::optionalTrimmedString($input['priceId'] ?? $input['price_id'] ?? null),
                'subject_id' => self::optionalTrimmedString($input['subjectId'] ?? $input['subject_id'] ?? null),
                'installation_id' => self::optionalTrimmedString($input['installationId'] ?? $input['installation_id'] ?? null),
                'realm_ref' => self::optionalTrimmedString($input['realmRef'] ?? $input['realm_ref'] ?? null),
                'locale' => self::optionalTrimmedString($input['locale'] ?? null),
                'country' => self::optionalTrimmedString($input['country'] ?? null),
                'source_kind' => self::optionalTrimmedString($input['sourceKind'] ?? $input['source_kind'] ?? null),
                'source_ref' => self::optionalTrimmedString($input['sourceRef'] ?? $input['source_ref'] ?? null),
                'payment_lane' => self::optionalTrimmedString($input['paymentLane'] ?? $input['payment_lane'] ?? null),
                'payment_session_id' => self::optionalTrimmedString($input['paymentSessionId'] ?? $input['payment_session_id'] ?? null),
                'request_id' => self::optionalTrimmedString($input['requestId'] ?? $input['request_id'] ?? null),
            ]),
        );
        return $this->extractGatewayResult($response, 'prepareXappPurchaseIntent');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function getXappPurchaseIntent(array $input): array
    {
        $resolvedXappId = self::requireNonEmptyString((string) ($input['xappId'] ?? $input['xapp_id'] ?? ''), 'xappId');
        $resolvedIntentId = self::requireNonEmptyString((string) ($input['intentId'] ?? $input['intent_id'] ?? ''), 'intentId');
        $response = $this->get(
            '/v1/xapps/' . rawurlencode($resolvedXappId) . '/monetization/purchase-intents/' . rawurlencode($resolvedIntentId)
        );
        return $this->extractGatewayResult($response, 'getXappPurchaseIntent');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createXappPurchaseTransaction(array $input): array
    {
        $resolvedXappId = self::requireNonEmptyString((string) ($input['xappId'] ?? $input['xapp_id'] ?? ''), 'xappId');
        $resolvedIntentId = self::requireNonEmptyString((string) ($input['intentId'] ?? $input['intent_id'] ?? ''), 'intentId');
        $response = $this->post(
            '/v1/xapps/' . rawurlencode($resolvedXappId) . '/monetization/purchase-intents/' . rawurlencode($resolvedIntentId) . '/transactions',
            self::withoutNullValues([
                'status' => self::optionalTrimmedString($input['status'] ?? null),
                'provider_ref' => self::optionalTrimmedString($input['providerRef'] ?? $input['provider_ref'] ?? null),
                'evidence_ref' => self::optionalTrimmedString($input['evidenceRef'] ?? $input['evidence_ref'] ?? null),
                'payment_session_id' => self::optionalTrimmedString($input['paymentSessionId'] ?? $input['payment_session_id'] ?? null),
                'request_id' => self::optionalTrimmedString($input['requestId'] ?? $input['request_id'] ?? null),
                'settlement_ref' => self::optionalTrimmedString($input['settlementRef'] ?? $input['settlement_ref'] ?? null),
                'amount' => array_key_exists('amount', $input) ? $input['amount'] : null,
                'currency' => self::optionalTrimmedString($input['currency'] ?? null),
                'occurred_at' => self::optionalTrimmedString($input['occurredAt'] ?? $input['occurred_at'] ?? null),
            ]),
        );
        return $this->extractGatewayResult($response, 'createXappPurchaseTransaction');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function listXappPurchaseTransactions(array $input): array
    {
        $resolvedXappId = self::requireNonEmptyString((string) ($input['xappId'] ?? $input['xapp_id'] ?? ''), 'xappId');
        $resolvedIntentId = self::requireNonEmptyString((string) ($input['intentId'] ?? $input['intent_id'] ?? ''), 'intentId');
        $response = $this->get(
            '/v1/xapps/' . rawurlencode($resolvedXappId) . '/monetization/purchase-intents/' . rawurlencode($resolvedIntentId) . '/transactions'
        );
        return $this->extractGatewayResult($response, 'listXappPurchaseTransactions');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createXappPurchasePaymentSession(array $input): array
    {
        $resolvedXappId = self::requireNonEmptyString((string) ($input['xappId'] ?? $input['xapp_id'] ?? ''), 'xappId');
        $resolvedIntentId = self::requireNonEmptyString((string) ($input['intentId'] ?? $input['intent_id'] ?? ''), 'intentId');
        $response = $this->post(
            '/v1/xapps/' . rawurlencode($resolvedXappId) . '/monetization/purchase-intents/' . rawurlencode($resolvedIntentId) . '/payment-session',
            self::withoutNullValues([
                'payment_guard_ref' => self::optionalTrimmedString($input['paymentGuardRef'] ?? $input['payment_guard_ref'] ?? null),
                'issuer' => self::optionalTrimmedString($input['issuer'] ?? null),
                'scheme' => self::optionalTrimmedString($input['scheme'] ?? null),
                'payment_scheme' => self::optionalTrimmedString($input['paymentScheme'] ?? $input['payment_scheme'] ?? null),
                'return_url' => self::optionalTrimmedString($input['returnUrl'] ?? $input['return_url'] ?? null),
                'cancel_url' => self::optionalTrimmedString($input['cancelUrl'] ?? $input['cancel_url'] ?? null),
                'xapps_resume' => self::optionalTrimmedString($input['xappsResume'] ?? $input['xapps_resume'] ?? null),
                'page_url' => self::optionalTrimmedString($input['pageUrl'] ?? $input['page_url'] ?? null),
                'locale' => self::optionalTrimmedString($input['locale'] ?? null),
                'subject_id' => self::optionalTrimmedString($input['subjectId'] ?? $input['subject_id'] ?? null),
                'installation_id' => self::optionalTrimmedString($input['installationId'] ?? $input['installation_id'] ?? null),
                'metadata' => (isset($input['metadata']) && is_array($input['metadata'])) ? $input['metadata'] : null,
            ]),
        );
        return $this->extractGatewayResult($response, 'createXappPurchasePaymentSession');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function reconcileXappPurchasePaymentSession(array $input): array
    {
        $resolvedXappId = self::requireNonEmptyString((string) ($input['xappId'] ?? $input['xapp_id'] ?? ''), 'xappId');
        $resolvedIntentId = self::requireNonEmptyString((string) ($input['intentId'] ?? $input['intent_id'] ?? ''), 'intentId');
        $response = $this->post(
            '/v1/xapps/' . rawurlencode($resolvedXappId) . '/monetization/purchase-intents/' . rawurlencode($resolvedIntentId) . '/payment-session/reconcile',
            [],
        );
        return $this->extractGatewayResult($response, 'reconcileXappPurchasePaymentSession');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function finalizeXappPurchasePaymentSession(array $input): array
    {
        $resolvedXappId = self::requireNonEmptyString((string) ($input['xappId'] ?? $input['xapp_id'] ?? ''), 'xappId');
        $resolvedIntentId = self::requireNonEmptyString((string) ($input['intentId'] ?? $input['intent_id'] ?? ''), 'intentId');
        $response = $this->post(
            '/v1/xapps/' . rawurlencode($resolvedXappId) . '/monetization/purchase-intents/' . rawurlencode($resolvedIntentId) . '/payment-session/finalize',
            [],
        );
        return $this->extractGatewayResult($response, 'finalizeXappPurchasePaymentSession');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function issueXappPurchaseAccess(array $input): array
    {
        $resolvedXappId = self::requireNonEmptyString((string) ($input['xappId'] ?? $input['xapp_id'] ?? ''), 'xappId');
        $resolvedIntentId = self::requireNonEmptyString((string) ($input['intentId'] ?? $input['intent_id'] ?? ''), 'intentId');
        $response = $this->post(
            '/v1/xapps/' . rawurlencode($resolvedXappId) . '/monetization/purchase-intents/' . rawurlencode($resolvedIntentId) . '/issue-access',
            [],
        );
        return $this->extractGatewayResult($response, 'issueXappPurchaseAccess');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function reconcileXappSubscriptionContractPaymentSession(array $input): array
    {
        $resolvedXappId = self::requireNonEmptyString((string) ($input['xappId'] ?? $input['xapp_id'] ?? ''), 'xappId');
        $resolvedContractId = self::requireNonEmptyString((string) ($input['contractId'] ?? $input['contract_id'] ?? ''), 'contractId');
        $paymentSessionId = self::requireNonEmptyString((string) ($input['paymentSessionId'] ?? $input['payment_session_id'] ?? ''), 'paymentSessionId');
        $response = $this->post(
            '/v1/xapps/' . rawurlencode($resolvedXappId) . '/monetization/subscription-contracts/' . rawurlencode($resolvedContractId) . '/reconcile-payment-session',
            ['payment_session_id' => $paymentSessionId],
        );
        return $this->extractGatewayResult($response, 'reconcileXappSubscriptionContractPaymentSession');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function cancelXappSubscriptionContract(array $input): array
    {
        $resolvedXappId = self::requireNonEmptyString((string) ($input['xappId'] ?? $input['xapp_id'] ?? ''), 'xappId');
        $resolvedContractId = self::requireNonEmptyString((string) ($input['contractId'] ?? $input['contract_id'] ?? ''), 'contractId');
        $response = $this->post(
            '/v1/xapps/' . rawurlencode($resolvedXappId) . '/monetization/subscription-contracts/' . rawurlencode($resolvedContractId) . '/cancel',
            [],
        );
        return $this->extractGatewayResult($response, 'cancelXappSubscriptionContract');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function refreshXappSubscriptionContractState(array $input): array
    {
        $resolvedXappId = self::requireNonEmptyString((string) ($input['xappId'] ?? $input['xapp_id'] ?? ''), 'xappId');
        $resolvedContractId = self::requireNonEmptyString((string) ($input['contractId'] ?? $input['contract_id'] ?? ''), 'contractId');
        $response = $this->post(
            '/v1/xapps/' . rawurlencode($resolvedXappId) . '/monetization/subscription-contracts/' . rawurlencode($resolvedContractId) . '/refresh-state',
            [],
        );
        return $this->extractGatewayResult($response, 'refreshXappSubscriptionContractState');
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

    /** @param array<string,mixed> $input @return array<string,string> */
    private static function buildMonetizationScopeQuery(array $input): array
    {
        return self::withoutNullValues([
            'subject_id' => self::optionalTrimmedString($input['subjectId'] ?? $input['subject_id'] ?? null),
            'installation_id' => self::optionalTrimmedString($input['installationId'] ?? $input['installation_id'] ?? null),
            'realm_ref' => self::optionalTrimmedString($input['realmRef'] ?? $input['realm_ref'] ?? null),
        ]);
    }

    /** @param array<string,mixed> $input @return array<string,string> */
    private static function buildMonetizationTargetingQuery(array $input): array
    {
        return self::withoutNullValues(array_merge(
            self::buildMonetizationScopeQuery($input),
            [
                'locale' => self::optionalTrimmedString($input['locale'] ?? null),
                'country' => self::optionalTrimmedString($input['country'] ?? null),
            ],
        ));
    }

    private static function requireNonEmptyString(string $value, string $label): string
    {
        $resolved = trim($value);
        if ($resolved === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, $label . ' is required');
        }
        return $resolved;
    }

    private static function optionalTrimmedString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $resolved = trim((string) $value);
        return $resolved !== '' ? $resolved : null;
    }

    /** @return array<string,mixed>|null */
    private static function optionalRecord(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }
}
