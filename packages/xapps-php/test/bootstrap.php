<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/CurlShim.php';
require_once dirname(__DIR__) . '/src/index.php';

function xappsPhpAssertTrue(bool $condition, string $message = 'Expected condition to be true'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function xappsPhpAssertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $prefix = $message !== '' ? $message . ': ' : '';
        throw new RuntimeException(
            $prefix . 'expected ' . var_export($expected, true) . ' but received ' . var_export($actual, true),
        );
    }
}

function xappsPhpAssertContains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        $prefix = $message !== '' ? $message . ': ' : '';
        throw new RuntimeException(
            $prefix . 'expected to find ' . var_export($needle, true) . ' in ' . var_export($haystack, true),
        );
    }
}

function xappsPhpTestBaseUrl(): string
{
    $baseUrl = trim((string) getenv('XAPPS_PHP_TEST_BASE_URL'));
    return $baseUrl !== '' ? $baseUrl : 'http://fixture.local';
}
