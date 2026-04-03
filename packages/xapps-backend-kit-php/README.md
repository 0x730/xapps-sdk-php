# xapps-platform/xapps-backend-kit

Modular PHP backend kit for the current Xapps backend contract.

## Install

```bash
composer require xapps-platform/xapps-backend-kit
```

This package depends on `xapps-platform/xapps-php` and sits above it.

Use it when you want a higher-level packaged backend contract with default routes, mode assembly, payment runtime composition, and override seams.

Use `xapps-platform/xapps-php` directly only when you need lower-level PHP primitives that the backend kit intentionally does not own.

Current public surface:

- backend composition for the shipped backend contract

Direction:

- PHP and Node variants should converge on the same backend contract
- actor differences should live in adapters, rights/scope, config, and data access
- not in duplicated platform backend logic

This package sits above:

- `xapps-platform/xapps-php`

Use it when you want a working backend with default routes, default modes, and
override seams, while keeping the later shared tenant/publisher direction
open.

For request-capable publisher-rendered widgets, use the package layer to
verify browser widget context server-side before exposing private runtime
behavior:

```php
$originPolicy = BackendKit::evaluateWidgetBootstrapOriginPolicy([
    'hostOrigin' => $request['body']['hostOrigin'] ?? null,
    'allowedOrigins' => $config['widgetBootstrap']['allowedOrigins'] ?? [],
]);

if (!($originPolicy['ok'] ?? false)) {
    http_response_code(($originPolicy['code'] ?? '') === 'HOST_ORIGIN_REQUIRED' ? 400 : 403);
    echo json_encode([
        'ok' => false,
        'error' => [
            'code' => $originPolicy['code'] ?? 'HOST_ORIGIN_NOT_ALLOWED',
            'message' => $originPolicy['message'] ?? 'Widget bootstrap origin rejected',
        ],
    ]);
    return;
}

$verified = BackendKit::verifyBrowserWidgetContext($gatewayClient, [
    'hostOrigin' => $originPolicy['hostOrigin'],
    'installationId' => 'inst_123',
    'bindToolName' => 'submit_form',
    'subjectId' => 'sub_123',
    'bootstrapTicket' => $request['body']['bootstrapTicket'] ?? null,
]);
```

Recommended shared local config contract for publisher-rendered bootstrap
routes:

- `widgetBootstrap.allowedOrigins`
- optional app env:
  - `XAPPS_WIDGET_ALLOWED_ORIGINS=https://host.example.test,https://host-b.example.test`

This stays local/app-owned on purpose. The package helper standardizes the
policy behavior without forcing a framework-specific env contract.

Recommended request-widget posture:

- keep the widget asset URL as a public/bootstrap shell
- keep request-capable UI blocked until backend verification succeeds
- do not place secrets or durable bearer tokens in manifest widget URLs
- direct raw browser hits should stay blocked instead of exposing private runtime

Optional stronger bootstrap transport already supported:

- `widgets[].config.xapps.bootstrap_transport = "signed_ticket"`
- current first slice reuses the short-lived signed widget token as a bootstrap
  ticket and carries it in the iframe URL hash
- browser widget code can forward it to your backend as `bootstrapTicket`
- the PHP backend-kit passthrough accepts that field without changing the
  current default/public bootstrap contract

This is now a real package, not a placeholder or extraction stub. Keep the
public entry surface stable and split internal package code behind it.

## Start Here

Consumer rule:

- prefer Composer autoload plus the package file autoload
- use the package entry surface
- do not wire individual `src/...` files directly in consuming apps

Example:

```php
require_once __DIR__ . "/vendor/autoload.php";

use Xapps\BackendKit\BackendKit;
```

Packaged consumers should prefer Composer autoload plus the package file
autoload. Repo-local consumers can keep loading `src/functions.php` directly.

## What It Gives You

The current package surface provides:

- backend-kit option normalization
- backend-kit composition
- default route surface
- default mode tree
- payment runtime assembly
- higher-level XMS purchase workflow helpers
- host-proxy service assembly
- request-widget bootstrap verification passthrough
- subject-profile sourcing hooks

Internal package structure is intentionally modular:

- `src/BackendKit.php`
  thin public facade
- `src/Backend/Support.php`
  small shared value helpers
- `src/Backend/Options.php`
  option normalization and config shaping
- `src/Backend/Runtime.php`
  plain-PHP request/bootstrap helpers
- `src/Backend/PaymentRuntime.php`
  payment runtime assembly and payment-page API helpers
- `src/Backend/Xms.php`
  higher-level XMS purchase workflow helpers on top of the PHP gateway client
- `src/Backend/HostProxy.php`
  host-proxy service and plain-app creation
- `src/Backend/Modules.php`
  backend module composition
- `src/Backend/Modes/*`
  explicit default mode tree
- `src/Backend/Routes/*`
  explicit default route tree

Current route surface includes:

- health
- reference
- host core
- lifecycle
- bridge
- payment
- guard
- subject profiles

Current workflow helpers also include:

- `BackendKit::normalizeXappMonetizationScopeKind(...)`
  normalizes subject / installation / realm scope selection
- `BackendKit::resolveXappMonetizationScope(...)`
  resolves scope fields from runtime context plus optional realm reference
- `BackendKit::resolveXappHostedPaymentDefinition(...)`
  resolves a manifest payment definition into hosted session config, including delegated signing metadata
- `BackendKit::listXappHostedPaymentPresets(...)`
  shapes manifest payment definitions into generic hosted-lane preset options for UI selectors
- `BackendKit::findXappHostedPaymentPreset(...)`
  looks up one hosted-lane preset by `paymentGuardRef`
- `BackendKit::readXappMonetizationSnapshot(...)`
  reads the common app-facing XMS state bundle: access, current subscription, and wallet accounts
- `BackendKit::consumeXappWalletCredits(...)`
  consumes credits from one wallet account through the XMS API and returns the updated wallet, ledger entry, and refreshed access projection
- `BackendKit::startXappHostedPurchase(...)`
  prepares a purchase intent and creates the lane-bootstrapped gateway payment session
- `BackendKit::finalizeXappHostedPurchase(...)`
  finalizes a hosted purchase through the platform finalize endpoint, returning reconciliation and issued access state
- `BackendKit::activateXappPurchaseReference(...)`
  prepares a purchase intent, creates a verified reference transaction, and issues access

## Minimal usage

```php
<?php

require_once __DIR__ . "/vendor/autoload.php";

use Xapps\BackendKit\BackendKit;
```

Current default mode tree includes:

- `gateway_managed`
- `tenant_delegated`
- `publisher_delegated`
- `owner_managed`

For `owner_managed`, the packaged default can run tenant-owned or
publisher-owned. Use `payments.ownerIssuer` when the backend should default the
owner-managed lane to `publisher` instead of `tenant` when the guard config
does not narrow the issuer explicitly.

For hosted-integrator mode, the PHP kit mirrors the same secure bootstrap shape
as the Node variant:

- `host.allowedOrigins`
- `host.bootstrap.apiKeys`
- `host.bootstrap.signingSecret`
- optional `host.bootstrap.ttlSeconds`

The tenant backend resolves subject through the gateway/host proxy and signs
the short-lived browser bootstrap token locally. Raw platform API keys stay on
the integrator backend or tenant backend, never in browser code.

That `host.allowedOrigins` allowlist covers the browser-facing host API
surface, including:

- `/api/host-config`
- `/api/resolve-subject`
- `/api/create-catalog-session`
- `/api/create-widget-session`
- lifecycle routes under `/api/install*`
- bridge routes under `/api/bridge/*`

Hosted-integrator session expectations are the same as in the Node backend kit:

- browser hosts use a short-lived `bootstrapToken`
- widget sessions renew through `/api/bridge/token-refresh`
- bootstrap renewal should re-run bootstrap instead of treating `subjectId` alone as durable proof
- terminal widget-session failure should surface at the host shell layer, not as a raw iframe error

The PHP runtime should therefore be documented and reasoned about as an
implementation variant of the same hosted-integrator/session contract, not as a
separate feature line.

## What Should Stay Local

A consuming app should still keep these local when they are actor-specific:

- startup and env/config mapping
- branding and host pages/assets
- actor-specific subject-profile catalogs or resolver hooks
- explicit mode or route overrides

## Recommended Consumer Structure

Keep the local PHP adapter thin and predictable.

```text
tenant-backend/
  bootstrap.php
  public/index.php
  lib/
    config.php
    appSurfaceModule.php
    subjectProfiles/defaultProfiles.php
  routes/
    host/
      pages.php
      shared.php
  modes/
    README.md
    */README.md
```

Recommended override order:

1. backend-kit options
2. local branding/assets and subject-profile data
3. injected services or resolver hooks
4. explicit route or mode overrides

Do not wire individual package route files directly in the app just to recreate
the package defaults locally.

## When To Drop Lower

Use `xapps-platform/xapps-php` directly only when the consumer needs a lower-level PHP
primitive that the backend kit intentionally does not own.

## Rule

Do not add route-level wrapper aliases here.

Keep the public surface:

- module oriented
- config driven
- hook based

Keep internals:

- explicit
- modular
- safe to refactor behind the stable entry surface

Node and PHP should keep the same backend behavior in the end. Differences
should be runtime-adapter concerns, not separate platform feature lines.

## Verify locally

```bash
composer smoke
```
