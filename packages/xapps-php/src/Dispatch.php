<?php

declare(strict_types=1);

namespace Xapps;

final class Dispatch
{
    /**
     * @return array{requestId:string,toolName:string,payload:array<string,mixed>,chainContext:array<string,mixed>|null,subjectId:string|null,userEmail:string|null,async:bool,callbackToken:string|null,subjectActionPayload:string|null,subjectProof:mixed}
     */
    public static function parseRequest(mixed $input): array
    {
        if (!is_array($input) || array_is_list($input)) {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'Dispatch payload must be an object');
        }
        $requestId = trim((string) ($input['requestId'] ?? ''));
        $toolName = trim((string) ($input['toolName'] ?? ''));
        $payload = $input['payload'] ?? null;

        if ($requestId === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'Dispatch payload missing requestId');
        }
        if ($toolName === '') {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'Dispatch payload missing toolName');
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new XappsSdkError(XappsSdkError::INVALID_ARGUMENT, 'Dispatch payload.payload must be an object');
        }

        $chainContext = $input['chainContext'] ?? null;
        if (!is_array($chainContext) || array_is_list($chainContext)) {
            $chainContext = null;
        }

        return [
            'requestId' => $requestId,
            'toolName' => $toolName,
            'payload' => $payload,
            'chainContext' => $chainContext,
            'subjectId' => self::nullableString($input['subjectId'] ?? null),
            'userEmail' => self::nullableString($input['userEmail'] ?? null),
            'async' => (bool) ($input['async'] ?? false),
            'callbackToken' => self::nullableString($input['callbackToken'] ?? null),
            'subjectActionPayload' => self::nullableString($input['subjectActionPayload'] ?? null),
            'subjectProof' => $input['subjectProof'] ?? null,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $normalized = trim((string) $value);
        return $normalized === '' ? null : $normalized;
    }
}
