# xapps-platform/xapps-php

PHP backend SDK for tenant, publisher, and host-proxy integrations.

## Install

```bash
composer require xapps-platform/xapps-php
```

Use `xapps-platform/xapps-php` when you want lower-level PHP primitives for gateway, callback, payment-return, publisher-admin, or host-proxy flows.

If you want a higher-level packaged backend contract with default routes and mode assembly, use `xapps-platform/xapps-backend-kit` instead.

For the current XMS system behavior and API reader path, read:

- [docs/specifications/xms/README.md](/home/dacrise/x/xapps/docs/specifications/xms/README.md)

## Browser + backend SDK split

For the full embed contract:

- browser SDK: `@xapps-platform/browser-host`
- low-level browser engine: `@xapps-platform/embed-sdk`
- PHP backend SDK: `xapps-platform/xapps-php`

Use this package when the browser already runs `@xapps-platform/browser-host`
or `@xapps-platform/embed-sdk` and the backend needs to proxy the host/session
contract to the gateway or tenant backend.

## Scope (MVP)

- Dispatch payload parsing (`Xapps\\Dispatch::parseRequest`)
- Request signature verification (`Xapps\\Signature::verifyXappsSignature`)
- Callback client for `/v1/requests/:id/events|complete` (`Xapps\\CallbackClient`)
- Payment-return parse/sign/verify helpers (`Xapps\\PaymentReturn`)
- Provider credential bundle helpers (`Xapps\\PaymentProviderCredentials`)
- Managed gateway session shaping helpers (`Xapps\\ManagedGatewayPaymentSession`)
- Hosted gateway payment bootstrap helper (`Xapps\\HostedGatewayPaymentSession`)
- Payment policy support helpers (`Xapps\\PaymentPolicySupport`)
- Gateway client for host backends (API key and/or bearer token), including payment-session helpers, low-level XMS monetization lifecycle helpers (catalog, access, subscription, wallet, purchase-intent, and subscription-contract routes), and request-widget bootstrap verification (`Xapps\\GatewayClient`)
- Publisher admin API client for publisher backends (`Xapps\\PublisherApiClient`), including `listClients()`, publisher linking helpers, and bridge-token exchange parity with `@xapps-platform/server-sdk`
- Typed SDK exceptions (`Xapps\\XappsSdkError`) for callback/gateway networking + argument validation
- Unified subject-proof verifier surface (`Xapps\\SubjectProof`) via injected verifier adapters

Current `GatewayClient` XMS helpers include:

- `getXappMonetizationCatalog(...)`
- `getXappMonetizationAccess(...)`
- `getXappCurrentSubscription(...)`
- `listXappEntitlements(...)`
- `listXappWalletAccounts(...)`
- `listXappWalletLedger(...)`
- `consumeXappWalletCredits(...)`
- purchase-intent / transaction / payment-session lifecycle helpers
- subscription-contract reconcile / cancel / refresh helpers
- current-user embed monetization lane:
  - `getEmbedMyXappMonetization(...)`
  - `getEmbedMyXappMonetizationHistory(...)`
  - `prepareEmbedMyXappPurchaseIntent(...)`
  - `createEmbedMyXappPurchasePaymentSession(...)`
  - `finalizeEmbedMyXappPurchasePaymentSession(...)`

Current XMS targeting-aware catalog helpers support:

- `getXappMonetizationCatalog($xappIdOrInput)` where the input may include:
  - `xappId`
  - `subjectId`
  - `installationId`
  - `realmRef`
  - `locale`
  - `country`
- `prepareXappPurchaseIntent(...)` with optional `locale` and `country`

Current enforced gateway policy on that lane:

- offering/paywall `targeting_rules`
- price `country_rules`
- price `trial_policy`
- price `intro_policy`

Current enforced subset:

- locale include/exclude
- country include/exclude
- scope requirements:
  - `require_subject`
  - `require_installation`
  - `require_realm`
- first-time-only free trials for `subscription_plan` / `hybrid_plan`
- first-time-only intro discounts for `subscription_plan` / `hybrid_plan`
- `trial_policy` wins over `intro_policy` when both qualify
- zero-cost qualified lanes can finalize without an external payment session

Current canonical `XMS` lifecycle event family exposed through the existing
hook system:

- `xapps.xms.purchase_intent.prepared`
- `xapps.xms.transaction.reconciled`
- `xapps.xms.access.issued`
- `xapps.xms.access_snapshot.refreshed`

Xapps can subscribe through manifest `event_subscriptions`, and publisher-wide
integrations can subscribe through Publisher `Events & Webhooks` on the same
delivery rail.

## Local path install during monorepo development

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../../../../packages/xapps-php",
      "options": { "symlink": true }
    }
  ],
  "require": {
    "xapps-platform/xapps-php": "*@dev"
  }
}
```

Then:

```bash
composer update xapps-platform/xapps-php
```

## Supported distribution modes

Current supported ways to consume `xapps-platform/xapps-php`:

1. Local/path package during monorepo development
2. Packagist-facing split package mirror and/or VCS package from the public package mirror, pinned to an approved tag for integrator environments

Current release model:

- `0x730/xapps-sdk-php` is the public PHP source/control-plane repo
- package distribution is intended to happen through split package mirrors:
  - `0x730/xapps-php`
  - `0x730/xapps-backend-kit-php`
- Packagist should point to those split package mirrors, not the raw multi-package source repo

Example VCS package install:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "<xapps-repository-url>"
    }
  ],
  "require": {
    "xapps-platform/xapps-php": "dev-xpo#<approved-commit-or-tag>"
  }
}
```

Practical rule for integrators:

- pin to an approved tag or commit, not a floating branch
- treat monorepo tags/commits as the source of truth for release provenance
- run `composer test` or `php packages/xapps-php/test/run.php` against the pinned version during integration sign-off

## Verify locally

Smoke check:

```bash
php packages/xapps-php/examples/smoke/smoke.php
```

Direct local package verification:

```bash
php packages/xapps-php/test/run.php
```

Or via Composer inside `packages/xapps-php`:

```bash
composer test
composer smoke
```

Payment-return parity regression (golden vector vs Node SDK contract):

```bash
php packages/xapps-php/examples/payment-return/parity.php
```

Managed gateway session examples:

```bash
php packages/xapps-php/examples/managed-gateway-session/tenant.php
php packages/xapps-php/examples/managed-gateway-session/publisher.php
```

Minimal host proxy example:

```bash
php packages/xapps-php/examples/host-proxy/minimal.php
```

Host plans / current-user monetization example:

```bash
php packages/xapps-php/examples/host-proxy/plans.php
```

Current `EmbedHostProxyService` host-plan helpers include:

- `getMyXappMonetization(...)`
- `getMyXappMonetizationHistory(...)`
- `prepareMyXappPurchaseIntent(...)`
- `createMyXappPurchasePaymentSession(...)`
- `finalizeMyXappPurchasePaymentSession(...)`
- `runWidgetToolRequest(...)`

Request-widget bootstrap verification helper:

```php
$verified = $gateway->verifyBrowserWidgetContext([
    'hostOrigin' => 'https://tenant.example.test',
    'installationId' => 'inst_123',
    'bindToolName' => 'submit_form',
    'subjectId' => 'sub_123',
    'bootstrapTicket' => 'bst_123',
]);
```

Recommended request-widget posture:

- keep the publisher widget asset URL as a public/bootstrap shell
- block request-capable runtime until the short-lived widget token and context
  are verified server-side
- do not put secrets or durable tokens in the manifest URL
- direct raw browser hits should stay blocked instead of unlocking private
  request/runtime behavior

Optional stronger bootstrap transport already supported:

- `widgets[].config.xapps.bootstrap_transport = "signed_ticket"`
- current first slice reuses the short-lived signed widget token as a bootstrap
  ticket and carries it in the iframe URL hash
- browser widget code can forward it to the backend as `bootstrapTicket`
- `GatewayClient::verifyBrowserWidgetContext(...)` accepts both:
  - `bootstrapTicket`
  - `bootstrap_ticket`

Publisher linking + bridge helpers:

```php
$publisher = new PublisherApiClient('http://localhost:3000', 'publisher-api-key');
$publisher->completeLink([
    'subjectId' => 'sub_123',
    'xappId' => 'xapp_123',
    'publisherUserId' => 'publisher-user-123',
    'metadata' => ['email' => 'user@example.test'],
]);

$status = $publisher->getLinkStatus();
$bridge = $publisher->exchangeBridgeToken([
    'publisher_id' => 'pub_123',
    'scopes' => ['publisher.api:read'],
]);
```

Higher-level tenant/publisher backend kits are intentionally not part of the
current supported PHP SDK surface. The next redesign for that layer will start
from backend `lib/` and `modes/`, not from route-wrapper aliases.

XPO-Core fixture conformance check (`P1`-`P5`, `N1`-`N7` payment-return vectors):

```bash
php packages/xapps-php/examples/payment-return/xpo-core-fixtures.php
```

Note: replay fixture handling (`N3`) is evaluated at the runner layer to model
gateway/runtime replay protection semantics above pure signature verification.

Live local smoke (gateway + optional callback roundtrip):

```bash
XAPPS_SMOKE_BASE_URL=http://localhost:3000 \
XAPPS_SMOKE_API_KEY=xapps_test_tenant_b_key_123456789 \
php packages/xapps-php/examples/smoke/live.php
```

Optional callback leg:

```bash
XAPPS_SMOKE_BASE_URL=http://localhost:3000 \
XAPPS_SMOKE_API_KEY=xapps_test_tenant_b_key_123456789 \
XAPPS_SMOKE_CALLBACK_TOKEN='<callback-token>' \
XAPPS_SMOKE_REQUEST_ID='<request-id>' \
php packages/xapps-php/examples/smoke/live.php
```

Minimal event-delivery verification example:

```php
$result = Signature::verifyXappsSignature([
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'POST',
    'pathWithQuery' => $_SERVER['REQUEST_URI'] ?? '/webhooks/events',
    'body' => $rawBody,
    'timestamp' => $_SERVER['HTTP_X_XAPPS_TS'] ?? '',
    'signature' => $_SERVER['HTTP_X_XAPPS_SIGNATURE'] ?? '',
    'nonce' => $_SERVER['HTTP_X_XAPPS_NONCE'] ?? '',
    'source' => 'event_delivery',
    'requireSourceInSignature' => true,
    'allowLegacyWithoutSource' => true,
    'secret' => getenv('XAPPS_ENDPOINT_SECRET') ?: '',
]);

if (!($result['ok'] ?? false)) {
    throw new RuntimeException('Invalid event delivery signature');
}
```

## Usage

```php
<?php

use Xapps\GatewayClient;
use Xapps\PublisherApiClient;
use Xapps\ManagedGatewayPaymentSession;
use Xapps\PaymentReturn;
use Xapps\PaymentProviderCredentials;
use Xapps\CallbackClient;
use Xapps\SubjectProof;
use Xapps\XappsSdkError;

$gateway = new GatewayClient('http://localhost:3000', 'xapps_test_tenant_b_key_123456789');
// Or bearer token auth:
// $gateway = new GatewayClient('http://localhost:3000', '', 20, ['token' => 'publisher.jwt.or.token']);
$guardConfig = [
    'payment_scheme' => 'stripe',
    'accepts' => [
        ['scheme' => 'mock_manual', 'label' => 'Mock Hosted Redirect'],
    ],
    'payment_ui' => [
        'brand' => ['name' => 'Tenant A', 'accent' => '#635bff'],
        'schemes' => [
            ['scheme' => 'stripe', 'title' => 'Pay with Stripe'],
        ],
    ],
];
$payment = $gateway->createPaymentSession(ManagedGatewayPaymentSession::buildManagedGatewayPaymentSessionInput([
    'source' => 'tenant-backend',
    'guardSlug' => 'tenant-payment-policy',
    'guardConfig' => $guardConfig,
    'xappId' => '01ABC...',
    'toolName' => 'submit_form',
    'amount' => '3.00',
    'currency' => 'USD',
    'paymentIssuer' => 'gateway',
    'paymentScheme' => 'stripe',
    'returnUrl' => 'https://tenant.example.test/payment/return',
]));
// See also:
// - packages/xapps-php/examples/managed-gateway-session/tenant.php
// - packages/xapps-php/examples/managed-gateway-session/publisher.php
// Hosted gateway payment-page methods (canonical xpo-core shape):
$hostedSession = $gateway->getGatewayPaymentSession([
    'paymentSessionId' => (string) ($payment['session']['payment_session_id'] ?? ''),
    'returnUrl' => 'https://tenant.example.test/payment/return',
]);
$hostedComplete = $gateway->completeGatewayPayment([
    'paymentSessionId' => (string) ($hostedSession['session']['payment_session_id'] ?? ''),
]);
if (($hostedComplete['flow'] ?? null) === 'client_collect' && !empty($hostedComplete['clientSettleUrl'])) {
    $gateway->clientSettleGatewayPayment([
        'paymentSessionId' => (string) ($hostedComplete['paymentSessionId'] ?? ''),
        'status' => 'paid',
        'clientToken' => (string) (($hostedComplete['metadata']['client_token'] ?? '')),
    ]);
}
$response = $gateway->post('/v1/subjects/resolve', [
    'type' => 'user',
    'identifier' => ['idType' => 'email', 'value' => 'user@example.com'],
    'email' => 'user@example.com',
]);

$guardCredentialRefs = PaymentProviderCredentials::buildRefsByProvider([
    'stripe' => [
        'STRIPE_SECRET_KEY' => 'platform://stripe-secret?scope=client&scope_id=CL001',
        'STRIPE_WEBHOOK_SECRET' => 'platform://stripe-webhook?scope=client&scope_id=CL001',
    ],
    'paypal' => [
        'bundle_ref' => 'platform://payment:gateway:paypal:bundle',
        'refs' => [
            'PAYPAL_CLIENT_ID' => 'env:PAYPAL_CLIENT_ID',
            'PAYPAL_CLIENT_SECRET' => 'env:PAYPAL_CLIENT_SECRET',
            'PAYPAL_WEBHOOK_ID' => 'env:PAYPAL_WEBHOOK_ID',
            // Optional:
            'PAYPAL_API_BASE_URL' => 'env:PAYPAL_API_BASE_URL',
        ],
    ],
]);
// => assign to payment_guard_definition.payment_provider_credentials_refs

$sessionCredentialBundle = PaymentProviderCredentials::buildBundle([
    'refs' => [
        'COMMON_PROVIDER_TIMEOUT_MS' => 'env:COMMON_PROVIDER_TIMEOUT_MS',
    ],
    'bundle_ref' => 'platform://payment:gateway:common:bundle',
    'providers' => $guardCredentialRefs,
]);
// => assign to payment session metadata.payment_provider_credentials

$publisherApi = new PublisherApiClient('http://localhost:3000', '', 20, [
    'token' => 'publisher.jwt.or.token',
]);
// $xapps = $publisherApi->listXapps();
// $clients = $publisherApi->listClients();
// $endpoints = $publisherApi->listEndpoints('xapp_version_...');
// $credentials = $publisherApi->listEndpointCredentials('endpoint_...');
// if (count($credentials['items']) === 0) {
//     $publisherApi->createEndpointCredential('endpoint_...', [
//         'authType' => 'api-key',
//         'config' => ['headerName' => 'x-xplace-api-key'],
//         'initialKey' => [
//             'secret' => getenv('XPLACE_XAPP_INGEST_API_KEY') ?: '',
//             'status' => 'active',
//             'algorithm' => 'hmac-sha256',
//         ],
//     ]);
// }

$evidence = PaymentReturn::parsePaymentReturnEvidenceFromSearch($_SERVER['QUERY_STRING'] ?? '');
if ($evidence !== null) {
    $result = PaymentReturn::verifyPaymentReturnEvidence([
        'evidence' => $evidence,
        'secret' => 'tenant-return-secret',
        'expected' => [
            'issuer' => 'tenant',
            'xapp_id' => '01ABC...',
            'tool_name' => 'submit_form',
        ],
    ]);
}

$callbacks = new CallbackClient(
    'http://localhost:3000',
    'callback.jwt.token',
    [
        'retry' => [
            'maxAttempts' => 3,
            'baseDelayMs' => 150,
            'maxDelayMs' => 1200,
            'retryOnStatus' => [408, 425, 429, 500, 502, 503, 504],
        ],
        'idempotencyKeyFactory' => static function (array $input): string {
            return 'xapps:' . $input['operation'] . ':' . $input['requestId'];
        },
    ]
);

try {
    $callbacks->sendEvent('req_123', ['type' => 'request.updated']);
} catch (XappsSdkError $err) {
    // Machine-readable error metadata for retries/logging.
    error_log(json_encode([
        'code' => $err->errorCode,
        'status' => $err->status,
        'retryable' => $err->retryable,
        'message' => $err->getMessage(),
    ]));
    throw $err;
}

// CallbackClient response shape parity:
// - xapps-php returns ['status' => <http-status>, 'body' => <decoded-json-or-text>]

$subjectResult = SubjectProof::verifySubjectProofEnvelope(
    [
        'subjectActionPayload' => '{"action":"approve"}',
        'subjectProof' => ['kind' => 'jws', 'jws' => '...'],
    ],
    [
        'verifySubjectProofEnvelope' => static function (array $input): array {
            // Plug your verifier implementation here.
            return ['ok' => true];
        },
        'verifyJwsSubjectProof' => static fn(array $input): array => ['ok' => true],
        'verifyWebauthnSubjectProof' => static fn(array $input): array => ['ok' => true],
    ]
);
```

## Secret ref resolution

`PaymentReturn::resolveSecretFromRef()` resolves scheme-prefixed secret
references to raw secret strings, avoiding hardcoded secrets in config.

| Scheme                 | Description                                                                |
| ---------------------- | -------------------------------------------------------------------------- |
| `env:VAR_NAME`         | Read from `getenv()`                                                       |
| `file:/path/to/secret` | Read from filesystem (`realpath()` validated, 8 KB limit)                  |
| `vault://...`          | Supported via resolver callback options (gateway-core adapter recommended) |
| `awssm://...`          | Supported via resolver callback options (gateway-core adapter recommended) |
| `platform://...`       | Supported via resolver callback options (gateway-core adapter recommended) |

```php
use Xapps\PaymentReturn;

$secret = PaymentReturn::resolveSecretFromRef('env:TENANT_PAYMENT_SECRET');
```

External schemes can be resolved by injecting callbacks:

```php
$secret = PaymentReturn::resolveSecretFromRef(
    'platform://tenant-payment?scope=client&scope_id=CL001',
    [
        'resolveSecretRef' => function (string $ref, string $scheme): ?string {
            // Delegate to gateway-core secret resolver endpoint/client here.
            return getenv('PAYMENT_SECRET') ?: null;
        },
    ],
);
```

## Formalization status

- direct local package tests now exist under `packages/xapps-php/test/`
- Composer verification scripts now exist for `test`, `smoke`, and `parity`
- `@xapps-platform/server-sdk` and `xapps-platform/xapps-php` are expected to stay functionally aligned on shipped backend integrator capabilities
- supported integrator distribution is now explicit:
  - path package for local monorepo work
  - split package mirror tag / Packagist release for public integrator environments

`PaymentHandler` accepts `secretRef` in its config. When both `secret` and
`secretRef` are provided, `secret` takes precedence.

```php
use Xapps\PaymentHandler;

$handler = new PaymentHandler([
    'secret'    => getenv('PAYMENT_SECRET') ?: null,
    'secretRef' => getenv('PAYMENT_SECRET_REF') ?: null,
    'secretRefResolver' => function (string $ref, string $scheme): ?string {
        // Optional: resolve vault://, awssm://, platform:// via gateway-core adapter.
        return null;
    },
    'issuer'    => 'tenant',
]);
```

## Compatibility Policy

- Current track follows additive compatibility for public SDK entry points.
- `XappsSdkError::errorCode` values are machine-readable contract fields and should be treated as stable.
- Breaking API behavior should ship only with an explicit major version and migration notes.
- Payment return helpers are aligned with Node `@xapps-platform/server-sdk` contract semantics (`xapps_payment_orchestration_v1`, plain `xapp_id`/`tool_name` return params, canonical HMAC signing format).
- Gateway and publisher API error code names in PHP align with Node-style machine-readable names (`GATEWAY_API_*`, `PUBLISHER_API_*`), with backward-compatible aliases retained for legacy PHP code.

## Guard blocked forward-compatibility notes

When handling gateway `GUARD_BLOCKED` responses, keep `reason` handling additive and preserve
`details` fields.

Payment-governance reasons currently include:

- `payment_guard_override_not_allowed`
- `payment_guard_pricing_floor_violation`

Payment guard composition provenance may be present in:

- `details.payment_guard_ref_resolution` (`source`: `consumer_manifest` | `owner_manifest`)

## Current follow-on gap

- Subject-proof integration is adapter-injected in this cycle.
- Native verifier package/distribution strategy remains a follow-on decision.
