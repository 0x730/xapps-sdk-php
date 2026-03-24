# xapps/xapps-backend-kit

Modular PHP backend kit for the current Xapps backend contract.

Current public surface:

- backend composition for the shipped backend contract

Direction:

- PHP and Node variants should converge on the same backend contract
- actor differences should live in adapters, rights/scope, config, and data access
- not in duplicated platform backend logic

This package sits above:

- `xapps/xapps-php`

Use it when you want a working backend with default routes, default modes, and
override seams, while keeping the later shared tenant/publisher direction
open.

This is now a real package, not a placeholder or extraction stub. Keep the
public entry surface stable and split internal package code behind it.

## Start Here

Consumer rule:

- load `packages/xapps-backend-kit-php/src/functions.php`
- use the package entry surface
- do not wire individual `src/...` files directly in consuming apps

Example:

```php
require_once $repoRoot . '/packages/xapps-php/src/index.php';
require_once $repoRoot . '/packages/xapps-backend-kit-php/src/functions.php';

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
- host-proxy service assembly
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

Use `xapps/xapps-php` directly only when the consumer needs a lower-level PHP
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
