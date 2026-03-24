<?php

declare(strict_types=1);

use Xapps\PublisherApiClient;

return [
    [
        'name' => 'PublisherApiClient covers list and import/publish parity helpers',
        'run' => static function (): void {
            $client = new PublisherApiClient(xappsPhpTestBaseUrl(), '', 20, [
                'token' => 'publisher-token',
            ]);

            $xapps = $client->listXapps();
            xappsPhpAssertSame('demo', (string) ($xapps['items'][0]['slug'] ?? ''));

            $clients = $client->listClients();
            xappsPhpAssertSame('tenant-demo', (string) ($clients['items'][0]['slug'] ?? ''));

            $imported = $client->importAndPublishManifest([
                'slug' => 'demo',
            ]);
            xappsPhpAssertSame('xapp_demo', $imported['xappId']);
            xappsPhpAssertSame('ver_demo', $imported['versionId']);
            xappsPhpAssertSame('published', (string) ($imported['version']['status'] ?? ''));
        },
    ],
    [
        'name' => 'PublisherApiClient keeps endpoint helper parity',
        'run' => static function (): void {
            $client = new PublisherApiClient(xappsPhpTestBaseUrl(), '', 20, [
                'token' => 'publisher-token',
            ]);

            $endpoints = $client->listEndpoints('ver_demo');
            xappsPhpAssertSame('endpoint_ver_demo', (string) ($endpoints['items'][0]['id'] ?? ''));

            $created = $client->createEndpointCredential('endpoint_ver_demo', [
                'authType' => 'api-key',
            ]);
            xappsPhpAssertSame('cred_endpoint_ver_demo', (string) ($created['credential']['id'] ?? ''));

            $credentials = $client->listEndpointCredentials('endpoint_ver_demo');
            xappsPhpAssertSame('cred_endpoint_ver_demo', (string) ($credentials['items'][0]['id'] ?? ''));
        },
    ],
];
