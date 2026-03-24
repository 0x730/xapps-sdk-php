<?php

declare(strict_types=1);

function xapps_backend_kit_read_bool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    $normalized = strtolower(trim((string) ($value ?? '')));
    return in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true);
}

function xapps_backend_kit_read_catalog_profiles(string $rawCatalog): array
{
    if (trim($rawCatalog) === '') {
        return [];
    }
    $parsed = json_decode($rawCatalog, true);
    if (!is_array($parsed)) {
        return [];
    }
    if (array_is_list($parsed)) {
        return $parsed;
    }
    $root = xapps_backend_kit_read_record($parsed);
    $directProfiles = array_merge(
        xapps_backend_kit_read_list($root['profiles'] ?? null),
        xapps_backend_kit_read_list($root['items'] ?? null),
    );
    $scoped = [];
    $bySubject = xapps_backend_kit_read_record($root['by_subject'] ?? $root['bySubject'] ?? $root['subjects'] ?? null);
    foreach ($bySubject as $subjectId => $value) {
        foreach (xapps_backend_kit_read_list($value) as $entry) {
            $record = xapps_backend_kit_read_record($entry);
            $record['subject_id'] = xapps_backend_kit_read_string(
                $record['subject_id'] ?? null,
                $record['subjectId'] ?? null,
                $subjectId,
            );
            $scoped[] = $record;
        }
    }
    return array_merge($directProfiles, $scoped);
}

function xapps_backend_kit_default_subject_profiles(): array
{
    return [
        [
            'id' => 'tenant_demo_identity',
            'label' => 'Tenant Demo Identity',
            'profile_family' => 'identity_basic',
            'is_default' => false,
            'data' => [
                'profile_family' => 'identity_basic',
                'name' => 'Tenant Demo User',
                'email' => 'user@tenant-demo.test',
                'phone' => '+40 700 000 000',
            ],
        ],
        [
            'id' => 'tenant_demo_business',
            'label' => 'Tenant Demo Business',
            'profile_family' => 'billing_business',
            'is_default' => true,
            'data' => [
                'profile_family' => 'billing_business',
                'company_name' => 'Tenant Demo SRL',
                'company_identification_number' => '12345678',
                'vat_code' => 'RO12345678',
                'company_registration_number' => 'J40/1234/2020',
                'address' => 'Str. Exemplu 10',
                'city' => 'Bucuresti',
                'country' => 'Romania',
                'country_code' => 'RO',
                'email' => 'billing@tenant-demo.test',
                'phone' => '+40 700 000 001',
                'linked_profiles' => [
                    [
                        'target_profile_id' => 'tenant_demo_identity',
                        'relation_type' => 'delegate',
                        'label' => 'Primary delegate',
                        'is_primary' => true,
                    ],
                ],
            ],
        ],
        [
            'id' => 'tenant_demo_individual',
            'label' => 'Tenant Demo Individual',
            'profile_family' => 'billing_individual',
            'is_default' => false,
            'data' => [
                'profile_family' => 'billing_individual',
                'name' => 'Tenant Demo User',
                'address' => 'Str. Exemplu 10',
                'city' => 'Bucuresti',
                'country' => 'Romania',
                'country_code' => 'RO',
                'email' => 'user@tenant-demo.test',
                'phone' => '+40 700 000 000',
            ],
        ],
    ];
}

function xapps_backend_kit_normalize_tenant_candidate(mixed $raw, int $index, ?string $requestedFamily, string $workspace): ?array
{
    $record = xapps_backend_kit_read_record($raw);
    $nestedData = xapps_backend_kit_read_record(
        $record['data'] ?? $record['profile'] ?? $record['customerProfile'] ?? $record['customer_profile'] ?? null,
    );

    $inferredFamily = xapps_backend_kit_read_string(
        $record['profile_family'] ?? null,
        $record['profileFamily'] ?? null,
        $nestedData['profile_family'] ?? null,
    );
    if ($inferredFamily === '') {
        $hasBusinessIdentity = xapps_backend_kit_read_string(
            $nestedData['company_name'] ?? null,
            $nestedData['companyName'] ?? null,
            $nestedData['company_identification_number'] ?? null,
            $nestedData['vat_code'] ?? null,
            $nestedData['company_registration_number'] ?? null,
        );
        if ($hasBusinessIdentity !== '') {
            $inferredFamily = 'billing_business';
        } else {
            $hasIdentityOnly = xapps_backend_kit_read_string(
                $nestedData['name'] ?? null,
                $nestedData['email'] ?? null,
                $nestedData['phone'] ?? null,
            ) !== '' && xapps_backend_kit_read_string(
                $nestedData['address'] ?? null,
                $nestedData['city'] ?? null,
                $nestedData['country'] ?? null,
            ) === '';
            $inferredFamily = $hasIdentityOnly ? 'identity_basic' : 'billing_individual';
        }
    }

    $data = count($nestedData) > 0
        ? array_merge($nestedData, ['profile_family' => $inferredFamily])
        : [
            'profile_family' => $inferredFamily,
            'company_name' => xapps_backend_kit_optional_string($record['company_name'] ?? null, $record['companyName'] ?? null),
            'company_identification_number' => xapps_backend_kit_optional_string($record['company_identification_number'] ?? null),
            'vat_code' => xapps_backend_kit_optional_string($record['vat_code'] ?? null),
            'company_registration_number' => xapps_backend_kit_optional_string($record['company_registration_number'] ?? null),
            'address' => xapps_backend_kit_optional_string($record['address'] ?? null),
            'city' => xapps_backend_kit_optional_string($record['city'] ?? null),
            'country' => xapps_backend_kit_optional_string($record['country'] ?? null),
            'email' => xapps_backend_kit_optional_string($record['email'] ?? null),
            'phone' => xapps_backend_kit_optional_string($record['phone'] ?? null),
            'name' => xapps_backend_kit_optional_string($record['name'] ?? null),
            'linked_profiles' => xapps_backend_kit_read_list($record['linked_profiles'] ?? $record['linkedProfiles'] ?? null),
        ];

    $candidate = [
        'id' => xapps_backend_kit_read_string(
            $record['id'] ?? null,
            $record['profile_id'] ?? null,
            $record['profileId'] ?? null,
        ) ?: ($workspace . '_tenant_' . ($index + 1)),
        'label' => xapps_backend_kit_read_string(
            $record['label'] ?? null,
            $record['profile_label'] ?? null,
            $record['profileLabel'] ?? null,
            $data['company_name'] ?? null,
            $data['name'] ?? null,
        ) ?: ($workspace . ' tenant profile ' . ($index + 1)),
        'profile_family' => $inferredFamily,
        'is_default' => xapps_backend_kit_read_bool($record['is_default'] ?? $record['isDefault'] ?? null) || $index === 0,
        'data' => $data,
        'subject_id' => xapps_backend_kit_optional_string($record['subject_id'] ?? null, $record['subjectId'] ?? null),
    ];

    if ($requestedFamily !== null && $candidate['profile_family'] !== $requestedFamily) {
        return null;
    }

    return $candidate;
}

function xapps_backend_kit_build_tenant_candidate_envelope(array $payload, array $options = []): array
{
    $guardContext = xapps_backend_kit_read_record($payload['guard_context'] ?? $payload['guardContext'] ?? null);
    $subjectId = xapps_backend_kit_read_string(
        $payload['subjectId'] ?? null,
        $payload['subject_id'] ?? null,
        $guardContext['subjectId'] ?? null,
    );
    $requestedFamily = xapps_backend_kit_optional_string(
        $payload['profile_family'] ?? null,
        $payload['profileFamily'] ?? null,
    );
    $xappSlug = xapps_backend_kit_read_string($payload['xapp_slug'] ?? null, $payload['xappSlug'] ?? null);
    $toolName = xapps_backend_kit_read_string($payload['tool_name'] ?? null, $payload['toolName'] ?? null);
    $workspace = xapps_backend_kit_read_string($options['workspace'] ?? null, 'tenant');
    $source = xapps_backend_kit_read_string($options['source'] ?? null, 'tenant_subject_profile');
    $resolver = $options['resolveCandidates'] ?? null;

    $configuredProfiles = xapps_backend_kit_read_catalog_profiles(xapps_backend_kit_read_string($options['catalogJson'] ?? null));
    $resolvedProfiles = [];
    if (is_callable($resolver)) {
        $resolved = $resolver([
            'payload' => $payload,
            'subjectId' => $subjectId !== '' ? $subjectId : null,
            'requestedFamily' => $requestedFamily,
            'xappSlug' => $xappSlug !== '' ? $xappSlug : null,
            'toolName' => $toolName !== '' ? $toolName : null,
        ]);
        if (is_array($resolved)) {
            $resolvedProfiles = $resolved;
        }
    }
    $defaultProfiles = xapps_backend_kit_read_list($options['defaultProfiles'] ?? null);
    $effectiveCatalog = count($resolvedProfiles) > 0
        ? $resolvedProfiles
        : (count($configuredProfiles) > 0
            ? $configuredProfiles
            : (count($defaultProfiles) > 0 ? $defaultProfiles : xapps_backend_kit_default_subject_profiles()));

    $profiles = [];
    foreach ($effectiveCatalog as $index => $entry) {
        $candidate = xapps_backend_kit_normalize_tenant_candidate($entry, (int) $index, $requestedFamily, $workspace);
        if ($candidate === null) {
            continue;
        }
        $scopedSubjectId = xapps_backend_kit_read_string($candidate['subject_id'] ?? null);
        if ($subjectId !== '' && $scopedSubjectId !== '' && $scopedSubjectId !== $subjectId) {
            continue;
        }
        $profiles[] = $candidate;
    }

    $selected = null;
    foreach ($profiles as $entry) {
        if (($entry['is_default'] ?? false) === true) {
            $selected = $entry;
            break;
        }
    }
    if ($selected === null && count($profiles) > 0) {
        $selected = $profiles[0];
    }

    $publicProfiles = array_map(
        static function (array $entry): array {
            $copy = $entry;
            unset($copy['subject_id']);
            return $copy;
        },
        $profiles,
    );

    return [
        'ok' => true,
        'selected_profile_id' => $selected['id'] ?? null,
        'profiles' => $publicProfiles,
        'source' => $source,
        'metadata' => [
            'workspace' => $workspace,
            'subject_id' => $subjectId !== '' ? $subjectId : null,
            'requested_family' => $requestedFamily,
            'xapp_slug' => $xappSlug !== '' ? $xappSlug : null,
            'tool_name' => $toolName !== '' ? $toolName : null,
            'profile_count' => count($profiles),
            'configured_catalog' => count($configuredProfiles) > 0,
            'default_catalog' => count($configuredProfiles) === 0,
        ],
    ];
}

function xapps_backend_kit_register_subject_profile_routes(array &$routes, array $app, array $options = []): void
{
    $routes[] = [
        'method' => 'POST',
        'path' => '/guard/subject-profiles/tenant-candidates',
        'handler' => static function (array $request) use ($options): void {
            $body = xapps_backend_kit_read_record($request['body']);
            $payload = xapps_backend_kit_read_record($body['payload'] ?? null);
            if (count($payload) === 0) {
                $payload = $body;
            }
            xapps_backend_kit_send_json(xapps_backend_kit_build_tenant_candidate_envelope($payload, $options));
        },
    ];
}
