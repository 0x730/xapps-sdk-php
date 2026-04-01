<?php

declare(strict_types=1);

namespace Xapps;

final class PublisherApiClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $token;
    private int $timeoutSeconds;
    private int $connectTimeoutSeconds;

    /** @param array<string,mixed> $options */
    public function __construct(string $baseUrl, string $apiKey = '', int $timeoutSeconds = 20, array $options = [])
    {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
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
    }

    /** @return array{xappId:string,versionId:string} */
    public function importManifest(array $manifest): array
    {
        $payload = $this->postJson('/publisher/import-manifest', $manifest);
        $xappId = isset($payload['xappId']) && is_string($payload['xappId']) ? trim($payload['xappId']) : '';
        $versionId = isset($payload['versionId']) && is_string($payload['versionId']) ? trim($payload['versionId']) : '';
        if ($xappId === '' || $versionId === '') {
            throw new XappsSdkError(
                XappsSdkError::PUBLISHER_API_INVALID_RESPONSE,
                'Publisher import-manifest returned malformed response',
                null,
                false,
                ['payload' => $payload],
            );
        }
        return ['xappId' => $xappId, 'versionId' => $versionId];
    }

    /** @return array{version:array<string,mixed>} */
    public function publishVersion(string $xappVersionId): array
    {
        $id = trim($xappVersionId);
        if ($id === '') {
            throw new XappsSdkError(XappsSdkError::PUBLISHER_API_INVALID_RESPONSE, 'xappVersionId is required');
        }
        $payload = $this->postJson('/publisher/xapp-versions/' . rawurlencode($id) . '/publish', []);
        if (!isset($payload['version']) || !is_array($payload['version'])) {
            throw new XappsSdkError(
                XappsSdkError::PUBLISHER_API_INVALID_RESPONSE,
                'Publisher publishVersion returned malformed response',
                null,
                false,
                ['payload' => $payload],
            );
        }
        return ['version' => $payload['version']];
    }

    /** @return array{xappId:string,versionId:string,version:array<string,mixed>} */
    public function importAndPublishManifest(array $manifest): array
    {
        $imported = $this->importManifest($manifest);
        $published = $this->publishVersion($imported['versionId']);
        return [
            'xappId' => $imported['xappId'],
            'versionId' => $imported['versionId'],
            'version' => $published['version'],
        ];
    }

    /** @return array{items:array<int,array<string,mixed>>} */
    public function listXapps(): array
    {
        $payload = $this->getJson('/publisher/xapps');
        if (!isset($payload['items']) || !is_array($payload['items'])) {
            throw new XappsSdkError(
                XappsSdkError::PUBLISHER_API_INVALID_RESPONSE,
                'Publisher listXapps returned malformed response',
                null,
                false,
                ['payload' => $payload],
            );
        }
        return ['items' => $payload['items']];
    }

    /** @return array{items:array<int,array<string,mixed>>} */
    public function listClients(): array
    {
        $payload = $this->getJson('/publisher/clients');
        if (!isset($payload['items']) || !is_array($payload['items'])) {
            throw new XappsSdkError(
                XappsSdkError::PUBLISHER_API_INVALID_RESPONSE,
                'Publisher listClients returned malformed response',
                null,
                false,
                ['payload' => $payload],
            );
        }
        return ['items' => $payload['items']];
    }

    /** @return array{items:array<int,array<string,mixed>>} */
    public function listXappVersions(string $xappId): array
    {
        $id = trim($xappId);
        if ($id === '') {
            throw new XappsSdkError(XappsSdkError::PUBLISHER_API_INVALID_RESPONSE, 'xappId is required');
        }
        $payload = $this->getJson('/publisher/xapps/' . rawurlencode($id) . '/versions');
        if (!isset($payload['items']) || !is_array($payload['items'])) {
            throw new XappsSdkError(
                XappsSdkError::PUBLISHER_API_INVALID_RESPONSE,
                'Publisher listXappVersions returned malformed response',
                null,
                false,
                ['payload' => $payload],
            );
        }
        return ['items' => $payload['items']];
    }

    /** @return array{items:array<int,array<string,mixed>>} */
    public function listEndpoints(string $xappVersionId): array
    {
        $id = trim($xappVersionId);
        if ($id === '') {
            throw new XappsSdkError(XappsSdkError::PUBLISHER_API_INVALID_RESPONSE, 'xappVersionId is required');
        }
        $payload = $this->getJson('/publisher/xapp-versions/' . rawurlencode($id) . '/endpoints');
        if (!isset($payload['items']) || !is_array($payload['items'])) {
            throw new XappsSdkError(
                XappsSdkError::PUBLISHER_API_INVALID_RESPONSE,
                'Publisher listEndpoints returned malformed response',
                null,
                false,
                ['payload' => $payload],
            );
        }
        return ['items' => $payload['items']];
    }

    /** @param array<string,mixed> $input @return array{credential:array<string,mixed>} */
    public function createEndpointCredential(string $endpointId, array $input): array
    {
        $id = trim($endpointId);
        if ($id === '') {
            throw new XappsSdkError(XappsSdkError::PUBLISHER_API_INVALID_RESPONSE, 'endpointId is required');
        }
        $payload = $this->postJson('/publisher/endpoints/' . rawurlencode($id) . '/credentials', $input);
        if (!isset($payload['credential']) || !is_array($payload['credential'])) {
            throw new XappsSdkError(
                XappsSdkError::PUBLISHER_API_INVALID_RESPONSE,
                'Publisher createEndpointCredential returned malformed response',
                null,
                false,
                ['payload' => $payload],
            );
        }
        return ['credential' => $payload['credential']];
    }

    /** @return array{items:array<int,array<string,mixed>>} */
    public function listEndpointCredentials(string $endpointId): array
    {
        $id = trim($endpointId);
        if ($id === '') {
            throw new XappsSdkError(XappsSdkError::PUBLISHER_API_INVALID_RESPONSE, 'endpointId is required');
        }
        $payload = $this->getJson('/publisher/endpoints/' . rawurlencode($id) . '/credentials');
        if (!isset($payload['items']) || !is_array($payload['items'])) {
            throw new XappsSdkError(
                XappsSdkError::PUBLISHER_API_INVALID_RESPONSE,
                'Publisher listEndpointCredentials returned malformed response',
                null,
                false,
                ['payload' => $payload],
            );
        }
        return ['items' => $payload['items']];
    }

    /** @param array{subjectId:string,xappId:string,publisherUserId:string,realm?:string,metadata?:array<string,mixed>} $input @return array{success:bool,link_id?:string} */
    public function completeLink(array $input): array
    {
        $subjectId = trim((string) ($input['subjectId'] ?? ''));
        $xappId = trim((string) ($input['xappId'] ?? ''));
        $publisherUserId = trim((string) ($input['publisherUserId'] ?? ''));
        if ($subjectId === '' || $xappId === '' || $publisherUserId === '') {
            throw new XappsSdkError(
                XappsSdkError::PUBLISHER_API_INVALID_RESPONSE,
                'subjectId, xappId, and publisherUserId are required',
                null,
                false,
                ['input' => $input],
            );
        }
        $payload = [
            'subjectId' => $subjectId,
            'xappId' => $xappId,
            'publisherUserId' => $publisherUserId,
        ];
        if (isset($input['realm']) && trim((string) $input['realm']) !== '') {
            $payload['realm'] = trim((string) $input['realm']);
        }
        if (isset($input['metadata']) && is_array($input['metadata'])) {
            $payload['metadata'] = $input['metadata'];
        }
        $result = $this->postJson('/v1/publisher/links/complete', $payload);
        if (!isset($result['success']) || !is_bool($result['success'])) {
            throw new XappsSdkError(
                XappsSdkError::PUBLISHER_API_INVALID_RESPONSE,
                'Publisher completeLink returned malformed response',
                null,
                false,
                ['payload' => $result],
            );
        }
        return $result;
    }

    /** @param array{subjectId:string,xappId:string,publisherUserId:string,reason?:string} $input @return array{revoked:bool,alreadyRevoked?:bool,deleted?:int} */
    public function revokeLink(array $input): array
    {
        $subjectId = trim((string) ($input['subjectId'] ?? ''));
        $xappId = trim((string) ($input['xappId'] ?? ''));
        $publisherUserId = trim((string) ($input['publisherUserId'] ?? ''));
        if ($subjectId === '' || $xappId === '' || $publisherUserId === '') {
            throw new XappsSdkError(
                XappsSdkError::PUBLISHER_API_INVALID_RESPONSE,
                'subjectId, xappId, and publisherUserId are required',
                null,
                false,
                ['input' => $input],
            );
        }
        $payload = [
            'subjectId' => $subjectId,
            'xappId' => $xappId,
            'publisherUserId' => $publisherUserId,
        ];
        if (isset($input['reason']) && trim((string) $input['reason']) !== '') {
            $payload['reason'] = trim((string) $input['reason']);
        }
        $result = $this->postJson('/v1/publisher/links/revoke', $payload);
        if (!isset($result['revoked']) || !is_bool($result['revoked'])) {
            throw new XappsSdkError(
                XappsSdkError::PUBLISHER_API_INVALID_RESPONSE,
                'Publisher revokeLink returned malformed response',
                null,
                false,
                ['payload' => $result],
            );
        }
        return $result;
    }

    /** @return array{linked:bool,reason?:string,publisherUserId?:string,link_id?:string} */
    public function getLinkStatus(): array
    {
        $payload = $this->getJson('/v1/publisher/links/status');
        if (!isset($payload['linked']) || !is_bool($payload['linked'])) {
            throw new XappsSdkError(
                XappsSdkError::PUBLISHER_API_INVALID_RESPONSE,
                'Publisher getLinkStatus returned malformed response',
                null,
                false,
                ['payload' => $payload],
            );
        }
        return $payload;
    }

    /** @param array{publisher_id:string,scopes?:array<int,string>,link_required?:bool} $input @return array{vendor_assertion:string,issuer?:string,subject_id?:string,link_id?:string,expires_in?:int} */
    public function exchangeBridgeToken(array $input): array
    {
        $publisherId = trim((string) ($input['publisher_id'] ?? ''));
        if ($publisherId === '') {
            throw new XappsSdkError(
                XappsSdkError::PUBLISHER_API_INVALID_RESPONSE,
                'publisher_id is required',
                null,
                false,
                ['input' => $input],
            );
        }
        $payload = ['publisher_id' => $publisherId];
        if (isset($input['scopes']) && is_array($input['scopes'])) {
            $payload['scopes'] = array_values(array_filter(array_map(
                static fn(mixed $value): string => trim((string) $value),
                $input['scopes'],
            ), static fn(string $value): bool => $value !== ''));
        }
        if (isset($input['link_required'])) {
            $payload['link_required'] = (bool) $input['link_required'];
        }
        $result = $this->postJson('/v1/publisher/bridge/token', $payload);
        if (!isset($result['vendor_assertion']) || !is_string($result['vendor_assertion']) || trim($result['vendor_assertion']) === '') {
            throw new XappsSdkError(
                XappsSdkError::PUBLISHER_API_INVALID_RESPONSE,
                'Publisher exchangeBridgeToken returned malformed response',
                null,
                false,
                ['payload' => $result],
            );
        }
        return $result;
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function postJson(string $path, array $body): array
    {
        return $this->requestJson('POST', $path, $body);
    }

    /** @return array<string,mixed> */
    private function getJson(string $path): array
    {
        return $this->requestJson('GET', $path, null);
    }

    /** @param array<string,mixed>|null $body @return array<string,mixed> */
    private function requestJson(string $method, string $path, ?array $body): array
    {
        $response = $this->request($method, $this->baseUrl . $path, $body);
        $status = (int) ($response['status'] ?? 0);
        $payload = $response['json'];
        if ($status < 200 || $status >= 300) {
            $this->throwHttpError($status, is_array($payload) ? $payload : null, (string) ($response['body'] ?? ''));
        }
        if (!is_array($payload)) {
            throw new XappsSdkError(
                XappsSdkError::PUBLISHER_API_INVALID_RESPONSE,
                'Publisher API returned malformed response',
                $status > 0 ? $status : null,
                false,
                ['body' => (string) ($response['body'] ?? '')],
            );
        }
        return $payload;
    }

    /** @param array<string,mixed>|null $payload */
    private function throwHttpError(int $status, ?array $payload, string $rawBody): void
    {
        $message = 'Publisher API request failed (' . (string) $status . ')';
        if (is_array($payload) && isset($payload['message']) && is_string($payload['message']) && trim($payload['message']) !== '') {
            $message = trim($payload['message']);
        }
        $code = XappsSdkError::PUBLISHER_API_HTTP_ERROR;
        if ($status === 401 || $status === 403) {
            $code = XappsSdkError::PUBLISHER_API_UNAUTHORIZED;
        } elseif ($status === 404) {
            $code = XappsSdkError::PUBLISHER_API_NOT_FOUND;
        } elseif ($status === 409) {
            $code = XappsSdkError::PUBLISHER_API_CONFLICT;
        }
        throw new XappsSdkError(
            $code,
            $message,
            $status > 0 ? $status : null,
            false,
            ['payload' => $payload, 'body' => $rawBody],
        );
    }

    /** @param array<string,mixed>|null $payload @return array{status:int,body:string,json:array<string,mixed>|null} */
    private function request(string $method, string $url, ?array $payload): array
    {
        $headers = ['Accept: application/json'];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        if ($this->apiKey !== '') {
            $headers[] = 'X-API-Key: ' . $this->apiKey;
        }
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ];
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload);
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        if (!class_exists('CurlHandle', false) || !($ch instanceof \CurlHandle)) {
            curl_close($ch);
        }

        if ($body === false) {
            throw new XappsSdkError(
                XappsSdkError::PUBLISHER_API_NETWORK_ERROR,
                'Publisher API request failed: ' . $error,
                null,
                false,
            );
        }
        $json = json_decode((string) $body, true);
        return [
            'status' => $status,
            'body' => (string) $body,
            'json' => is_array($json) ? $json : null,
        ];
    }
}
