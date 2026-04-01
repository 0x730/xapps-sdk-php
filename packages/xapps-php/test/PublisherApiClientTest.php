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
    [
        'name' => 'PublisherApiClient covers linking and bridge helpers',
        'run' => static function (): void {
            $client = new PublisherApiClient(xappsPhpTestBaseUrl(), '', 20, [
                'token' => 'publisher-token',
            ]);

            $completed = $client->completeLink([
                'subjectId' => 'sub_123',
                'xappId' => 'xapp_demo',
                'publisherUserId' => 'publisher-user-123',
                'metadata' => ['email' => 'demo@example.test'],
            ]);
            xappsPhpAssertSame(true, (bool) ($completed['success'] ?? false));
            xappsPhpAssertSame('lnk_fixture', (string) ($completed['link_id'] ?? ''));

            $status = $client->getLinkStatus();
            xappsPhpAssertSame(true, (bool) ($status['linked'] ?? false));
            xappsPhpAssertSame('publisher-user-123', (string) ($status['publisherUserId'] ?? ''));

            $revoked = $client->revokeLink([
                'subjectId' => 'sub_123',
                'xappId' => 'xapp_demo',
                'publisherUserId' => 'publisher-user-123',
                'reason' => 'user_disconnect',
            ]);
            xappsPhpAssertSame(true, (bool) ($revoked['revoked'] ?? false));
            xappsPhpAssertSame(1, (int) ($revoked['deleted'] ?? 0));

            $bridge = $client->exchangeBridgeToken([
                'publisher_id' => 'pub_demo',
                'scopes' => ['publisher.api:read', 'publisher.api:read', ''],
                'link_required' => true,
            ]);
            xappsPhpAssertSame('vendor_assertion_fixture', (string) ($bridge['vendor_assertion'] ?? ''));
            xappsPhpAssertSame('lnk_fixture', (string) ($bridge['link_id'] ?? ''));
        },
    ],
];
