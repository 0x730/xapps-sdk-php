<?php

declare(strict_types=1);

use Xapps\Signature;

return [
    [
        'name' => 'Signature verifies strict canonical source-aware requests',
        'run' => static function (): void {
            $method = 'POST';
            $pathWithQuery = '/v1/requests/req_1/events?debug=1';
            $body = '{"type":"request.updated"}';
            $timestamp = '1710000000';
            $source = 'tenant-backend';
            $secret = 'strict-secret';
            $canonical = implode("\n", [
                $method,
                $pathWithQuery,
                $timestamp,
                hash('sha256', $body),
                $source,
            ]);
            $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $canonical, $secret, true)), '+/', '-_'), '=');

            $result = Signature::verifyXappsSignature([
                'method' => $method,
                'pathWithQuery' => $pathWithQuery,
                'body' => $body,
                'timestamp' => $timestamp,
                'signature' => $signature,
                'secret' => $secret,
                'source' => $source,
                'requireSourceInSignature' => true,
                'allowLegacyWithoutSource' => false,
                'nowSeconds' => 1710000000,
            ]);

            xappsPhpAssertTrue($result['ok'] === true, 'strict signature should verify');
            xappsPhpAssertSame('strict', $result['mode'], 'strict mode should be reported');
        },
    ],
    [
        'name' => 'Signature keeps legacy compatibility when source is omitted',
        'run' => static function (): void {
            $method = 'POST';
            $pathWithQuery = '/v1/requests/req_2/complete';
            $body = '{"status":"completed"}';
            $timestamp = '1710000000';
            $secret = 'legacy-secret';
            $canonical = implode("\n", [
                $method,
                $pathWithQuery,
                $timestamp,
                hash('sha256', $body),
            ]);
            $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $canonical, $secret, true)), '+/', '-_'), '=');

            $result = Signature::verifyXappsSignature([
                'method' => $method,
                'pathWithQuery' => $pathWithQuery,
                'body' => $body,
                'timestamp' => $timestamp,
                'signature' => $signature,
                'secret' => $secret,
                'source' => 'tenant-backend',
                'allowLegacyWithoutSource' => true,
                'nowSeconds' => 1710000000,
            ]);

            xappsPhpAssertTrue($result['ok'] === true, 'legacy signature should verify');
            xappsPhpAssertSame('legacy', $result['mode'], 'legacy mode should be reported');
        },
    ],
];
