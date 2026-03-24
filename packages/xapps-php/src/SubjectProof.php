<?php

declare(strict_types=1);

namespace Xapps;

final class SubjectProof
{
    /** @param array<string,mixed> $input @param array<string,mixed>|object $verifier */
    public static function verifySubjectProofEnvelope(array $input, array|object $verifier): array
    {
        return self::invokeVerifier('verifySubjectProofEnvelope', $input, $verifier);
    }

    /** @param array<string,mixed> $input @param array<string,mixed>|object $verifier */
    public static function verifyJwsSubjectProof(array $input, array|object $verifier): array
    {
        return self::invokeVerifier('verifyJwsSubjectProof', $input, $verifier);
    }

    /** @param array<string,mixed> $input @param array<string,mixed>|object $verifier */
    public static function verifyWebauthnSubjectProof(array $input, array|object $verifier): array
    {
        return self::invokeVerifier('verifyWebauthnSubjectProof', $input, $verifier);
    }

    /** @param array<string,mixed> $input @param array<string,mixed>|object $verifier */
    private static function invokeVerifier(string $method, array $input, array|object $verifier): array
    {
        $callable = self::resolveCallable($method, $verifier);
        if (!$callable) {
            throw new XappsSdkError(
                XappsSdkError::VERIFIER_UNAVAILABLE,
                $method . ' is unavailable on provided verifier module',
            );
        }
        $result = $callable($input);
        if (!is_array($result) || !array_key_exists('ok', $result) || !is_bool($result['ok'])) {
            throw new XappsSdkError(
                XappsSdkError::VERIFIER_INVALID_RESULT,
                $method . ' returned invalid result shape',
                null,
                false,
                ['result' => $result],
            );
        }
        if ($result['ok'] === false && trim((string) ($result['reason'] ?? '')) === '') {
            throw new XappsSdkError(
                XappsSdkError::VERIFIER_INVALID_RESULT,
                $method . ' returned invalid failure result (missing reason)',
                null,
                false,
                ['result' => $result],
            );
        }
        return $result;
    }

    /** @param array<string,mixed>|object $verifier */
    private static function resolveCallable(string $method, array|object $verifier): ?callable
    {
        if (is_array($verifier) && isset($verifier[$method]) && is_callable($verifier[$method])) {
            return $verifier[$method];
        }
        if (is_object($verifier) && method_exists($verifier, $method)) {
            $candidate = [$verifier, $method];
            if (is_callable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}
