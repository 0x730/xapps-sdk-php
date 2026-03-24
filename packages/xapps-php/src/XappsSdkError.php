<?php

declare(strict_types=1);

namespace Xapps;

final class XappsSdkError extends \RuntimeException
{
    public const INVALID_ARGUMENT = 'INVALID_ARGUMENT';
    public const CALLBACK_NETWORK_ERROR = 'CALLBACK_NETWORK_ERROR';
    public const CALLBACK_HTTP_ERROR = 'CALLBACK_HTTP_ERROR';
    public const CALLBACK_RETRY_EXHAUSTED = 'CALLBACK_RETRY_EXHAUSTED';
    public const GATEWAY_API_NETWORK_ERROR = 'GATEWAY_API_NETWORK_ERROR';
    public const GATEWAY_API_HTTP_ERROR = 'GATEWAY_API_HTTP_ERROR';
    public const GATEWAY_API_UNAUTHORIZED = 'GATEWAY_API_UNAUTHORIZED';
    public const GATEWAY_API_NOT_FOUND = 'GATEWAY_API_NOT_FOUND';
    public const GATEWAY_API_CONFLICT = 'GATEWAY_API_CONFLICT';
    public const GATEWAY_API_INVALID_RESPONSE = 'GATEWAY_API_INVALID_RESPONSE';
    public const GATEWAY_API_RETRY_EXHAUSTED = 'GATEWAY_API_RETRY_EXHAUSTED';
    public const PUBLISHER_API_NETWORK_ERROR = 'PUBLISHER_API_NETWORK_ERROR';
    public const PUBLISHER_API_HTTP_ERROR = 'PUBLISHER_API_HTTP_ERROR';
    public const PUBLISHER_API_UNAUTHORIZED = 'PUBLISHER_API_UNAUTHORIZED';
    public const PUBLISHER_API_NOT_FOUND = 'PUBLISHER_API_NOT_FOUND';
    public const PUBLISHER_API_CONFLICT = 'PUBLISHER_API_CONFLICT';
    public const PUBLISHER_API_INVALID_RESPONSE = 'PUBLISHER_API_INVALID_RESPONSE';
    // Backward-compatible aliases (prefer GATEWAY_API_* names for cross-SDK parity with Node).
    public const GATEWAY_NETWORK_ERROR = self::GATEWAY_API_NETWORK_ERROR;
    public const GATEWAY_HTTP_ERROR = self::GATEWAY_API_HTTP_ERROR;
    public const GATEWAY_UNAUTHORIZED = self::GATEWAY_API_UNAUTHORIZED;
    public const GATEWAY_NOT_FOUND = self::GATEWAY_API_NOT_FOUND;
    public const GATEWAY_CONFLICT = self::GATEWAY_API_CONFLICT;
    public const GATEWAY_INVALID_RESPONSE = self::GATEWAY_API_INVALID_RESPONSE;
    public const GATEWAY_RETRY_EXHAUSTED = self::GATEWAY_API_RETRY_EXHAUSTED;
    public const VERIFIER_UNAVAILABLE = 'VERIFIER_UNAVAILABLE';
    public const VERIFIER_INVALID_RESULT = 'VERIFIER_INVALID_RESULT';

    public readonly string $errorCode;
    public readonly ?int $status;
    public readonly bool $retryable;
    /** @var array<string,mixed> */
    public readonly array $details;

    /** @param array<string,mixed> $details */
    public function __construct(
        string $errorCode,
        string $message,
        ?int $status = null,
        bool $retryable = false,
        array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
        $this->status = $status;
        $this->retryable = $retryable;
        $this->details = $details;
    }
}
