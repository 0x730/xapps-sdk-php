<?php

declare(strict_types=1);

function envOrDefault(string $name, string $default): string
{
    $value = getenv($name);
    if ($value === false) {
        return $default;
    }
    $trimmed = trim((string) $value);
    return $trimmed !== '' ? $trimmed : $default;
}

function normalizeHostBootstrapPayload(array $input): array
{
    $subjectId = trim((string) ($input['subjectId'] ?? ''));
    $type = trim((string) ($input['type'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    $name = trim((string) ($input['name'] ?? ''));
    $identifier = isset($input['identifier']) && is_array($input['identifier']) ? $input['identifier'] : null;
    $metadata = isset($input['metadata']) && is_array($input['metadata']) ? $input['metadata'] : null;

    return array_filter([
        'subjectId' => $subjectId !== '' ? $subjectId : null,
        'type' => $type !== '' ? $type : null,
        'identifier' => $identifier,
        'email' => $email !== '' ? $email : null,
        'name' => $name !== '' ? $name : null,
        'metadata' => $metadata,
    ], static fn ($value): bool => $value !== null);
}

function forwardHostBootstrap(array $input): array
{
    $tenantBaseUrl = rtrim(envOrDefault('XAPPS_TENANT_BASE_URL', 'http://localhost:8001'), '/');
    $hostPublicUrl = rtrim(envOrDefault('XAPPS_HOST_PUBLIC_URL', 'http://localhost:8002'), '/');
    $bootstrapApiKey = envOrDefault('XAPPS_HOST_BOOTSTRAP_API_KEY', '');

    if ($bootstrapApiKey === '') {
        throw new RuntimeException('Missing XAPPS_HOST_BOOTSTRAP_API_KEY');
    }

    $payload = normalizeHostBootstrapPayload($input);
    $payload['origin'] = $hostPublicUrl;

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'X-API-Key: ' . $bootstrapApiKey,
            ]),
            'content' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'ignore_errors' => true,
        ],
    ]);

    $raw = file_get_contents($tenantBaseUrl . '/api/host-bootstrap', false, $context);
    $statusLine = is_array($http_response_header ?? null) ? (string) ($http_response_header[0] ?? '') : '';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);
    $status = isset($matches[1]) ? (int) $matches[1] : 500;
    $decoded = json_decode((string) $raw, true);
    $data = is_array($decoded) ? $decoded : ['message' => trim((string) $raw) !== '' ? trim((string) $raw) : 'host bootstrap failed'];

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException((string) ($data['message'] ?? 'host bootstrap failed'));
    }

    return $data;
}

$result = forwardHostBootstrap([
    'type' => 'business_member',
    'identifier' => [
        'idType' => 'tenant_member_id',
        'value' => 'acct-company-a-user-42',
        'hint' => 'Company A',
    ],
    'email' => 'alex@example.com',
    'name' => 'Alex Example',
    'metadata' => [
        'companyId' => 'company-a',
        'role' => 'member',
    ],
]);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

/*
Laravel mapping sketch:

  public function hostBootstrap(Request $request): JsonResponse
  {
      return response()->json(forwardHostBootstrap([
          'subjectId' => $request->input('subjectId'),
          'type' => $request->input('type'),
          'identifier' => $request->input('identifier'),
          'email' => $request->input('email'),
          'name' => $request->input('name'),
          'metadata' => $request->input('metadata'),
      ]));
  }

Expected local request body:

  {
    type?: string,
    identifier?: { idType: string, value: string, hint?: string },
    email?: string,
    name?: string,
    metadata?: array
  }

First bootstrap usually sends `identifier`, not `subjectId`.
If the integrator later stores the returned platform `subjectId`, it may include it too.

The local route forwards the same identity payload to:

  POST {XAPPS_HOST_PUBLIC_URL}/api/browser/host-bootstrap

The local browser-safe route forwards to:

  POST {XAPPS_TENANT_BASE_URL}/api/host-bootstrap

with:

  X-API-Key: {XAPPS_HOST_BOOTSTRAP_API_KEY}

and appends:

  { origin: XAPPS_HOST_PUBLIC_URL }
*/
