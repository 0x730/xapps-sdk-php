<?php

declare(strict_types=1);

namespace Xapps;

final class CallbackClient
{
    private string $baseUrl;
    private string $callbackToken;
    private int $connectTimeoutSeconds;
    private int $timeoutSeconds;
    /** @var array{maxAttempts:int,baseDelayMs:int,maxDelayMs:int,retryOnStatus:array<int,int>} */
    private array $retryPolicy;
    /** @var null|callable(array{operation:string,requestId:string,payload:array<string,mixed>}):string */
    private $idempotencyKeyFactory;

    /** @param array<string,mixed> $options */
    public function __construct(string $baseUrl, string $callbackToken, array $options = [])
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->callbackToken = trim($callbackToken);
        if ($this->baseUrl === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'baseUrl is required');
        }
        if (!filter_var($this->baseUrl, FILTER_VALIDATE_URL)) {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'baseUrl must be a valid URL');
        }
        if ($this->callbackToken === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'callbackToken is required');
        }
        $this->connectTimeoutSeconds = max(1, (int) ($options['connectTimeoutSeconds'] ?? 5));
        $this->timeoutSeconds = max(1, (int) ($options['timeoutSeconds'] ?? 20));
        $this->retryPolicy = self::normalizeRetryPolicy(
            is_array($options['retry'] ?? null) ? $options['retry'] : [],
        );
        $factory = $options['idempotencyKeyFactory'] ?? null;
        $this->idempotencyKeyFactory = is_callable($factory) ? $factory : null;
    }

    /** @param array<string,mixed> $event @param array<string,mixed> $options @return array{status:int,body:mixed} */
    public function sendEvent(string $requestId, array $event, ?string $idempotencyKey = null, array $options = []): array
    {
        $id = trim($requestId);
        if ($id === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'sendEvent.requestId is required');
        }
        $eventType = trim((string) ($event['type'] ?? ''));
        if ($eventType === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'sendEvent.event.type is required');
        }
        $payload = [
            'type' => $eventType,
            'message' => $event['message'] ?? null,
            'data' => $event['data'] ?? null,
        ];
        $resolvedIdempotencyKey = trim((string) ($idempotencyKey ?? ''));
        if ($resolvedIdempotencyKey === '' && $this->idempotencyKeyFactory) {
            $generated = ($this->idempotencyKeyFactory)([
                'operation' => 'event',
                'requestId' => $id,
                'payload' => $payload,
            ]);
            $resolvedIdempotencyKey = trim((string) $generated);
        }
        return $this->post(
            "/v1/requests/" . rawurlencode($id) . "/events",
            $payload,
            $resolvedIdempotencyKey !== '' ? $resolvedIdempotencyKey : null,
            $this->resolveRetryPolicy($options),
        );
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $options @return array{status:int,body:mixed} */
    public function complete(string $requestId, array $payload, ?string $idempotencyKey = null, array $options = []): array
    {
        $id = trim($requestId);
        if ($id === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'complete.requestId is required');
        }
        $status = trim((string) ($payload['status'] ?? ''));
        if ($status === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'complete.payload.status is required');
        }
        $resolvedIdempotencyKey = trim((string) ($idempotencyKey ?? ''));
        if ($resolvedIdempotencyKey === '' && $this->idempotencyKeyFactory) {
            $generated = ($this->idempotencyKeyFactory)([
                'operation' => 'complete',
                'requestId' => $id,
                'payload' => $payload,
            ]);
            $resolvedIdempotencyKey = trim((string) $generated);
        }
        return $this->post(
            "/v1/requests/" . rawurlencode($id) . "/complete",
            $payload,
            $resolvedIdempotencyKey !== '' ? $resolvedIdempotencyKey : null,
            $this->resolveRetryPolicy($options),
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @param array{maxAttempts:int,baseDelayMs:int,maxDelayMs:int,retryOnStatus:array<int,int>} $retryPolicy
     * @return array{status:int,body:mixed}
     */
    private function post(string $path, array $payload, ?string $idempotencyKey = null, array $retryPolicy = []): array
    {
        $policy = count($retryPolicy) > 0 ? $retryPolicy : $this->retryPolicy;
        $lastError = null;
        for ($attempt = 1; $attempt <= $policy['maxAttempts']; $attempt += 1) {
            try {
                return $this->postOnce($path, $payload, $idempotencyKey, $policy);
            } catch (\Throwable $error) {
                $lastError = $error;
                $retryable = $error instanceof XappsSdkError
                    ? $error->retryable
                    : false;
                if (!$retryable || $attempt >= $policy['maxAttempts']) {
                    break;
                }
                $delayMs = min($policy['maxDelayMs'], $policy['baseDelayMs'] * (2 ** ($attempt - 1)));
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }
        throw new XappsSdkError(
            XappsSdkError::CALLBACK_RETRY_EXHAUSTED,
            'Callback request failed after retries',
            $lastError instanceof XappsSdkError ? $lastError->status : null,
            false,
            ['cause' => $lastError ? $lastError->getMessage() : null],
            $lastError instanceof \Throwable ? $lastError : null,
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @param array{maxAttempts:int,baseDelayMs:int,maxDelayMs:int,retryOnStatus:array<int,int>} $policy
     * @return array{status:int,body:mixed}
     */
    private function postOnce(string $path, array $payload, ?string $idempotencyKey, array $policy): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->callbackToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
            $headers[] = 'Idempotency-Key: ' . trim($idempotencyKey);
        }

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        if (!class_exists('CurlHandle', false) || !($ch instanceof \CurlHandle)) {
            curl_close($ch);
        }

        if ($body === false) {
            throw new XappsSdkError(
                XappsSdkError::CALLBACK_NETWORK_ERROR,
                'Callback request failed: ' . $err,
                null,
                true,
            );
        }
        $decoded = json_decode($body, true);
        $responseBody = json_last_error() === JSON_ERROR_NONE ? $decoded : (string) $body;
        if ($status < 200 || $status >= 300) {
            $message = 'Callback request failed';
            if (is_array($responseBody) && isset($responseBody['message']) && is_string($responseBody['message']) && trim($responseBody['message']) !== '') {
                $message .= ': ' . trim($responseBody['message']);
            } else {
                $message .= ' (' . $status . ')';
            }
            throw new XappsSdkError(
                XappsSdkError::CALLBACK_HTTP_ERROR,
                $message,
                $status,
                in_array($status, $policy['retryOnStatus'], true),
                ['response' => $responseBody],
            );
        }
        return ['status' => $status, 'body' => $responseBody];
    }

    /** @param array<string,mixed> $input @return array{maxAttempts:int,baseDelayMs:int,maxDelayMs:int,retryOnStatus:array<int,int>} */
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
        return [
            'maxAttempts' => $maxAttempts,
            'baseDelayMs' => $baseDelayMs,
            'maxDelayMs' => $maxDelayMs,
            'retryOnStatus' => array_values(array_unique($retryOnStatus)),
        ];
    }

    /** @param array<string,mixed> $options @return array{maxAttempts:int,baseDelayMs:int,maxDelayMs:int,retryOnStatus:array<int,int>} */
    private function resolveRetryPolicy(array $options): array
    {
        $inline = is_array($options['retry'] ?? null) ? $options['retry'] : [];
        if (count($inline) === 0) {
            return $this->retryPolicy;
        }
        return self::normalizeRetryPolicy($inline);
    }
}
