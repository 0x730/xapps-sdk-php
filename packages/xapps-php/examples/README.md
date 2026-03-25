# `xapps-platform/xapps-php` examples

Use these examples by intent.

## Host proxy starter

Minimal server-side host proxy reference:

- [host-proxy/minimal.php](/home/dacrise/x/xapps/packages/xapps-php/examples/host-proxy/minimal.php)

Use this when you want:

- the smallest secure backend boundary for marketplace embedding
- subject resolution
- catalog/widget session minting
- a PHP reference to mirror the Node host proxy starter

This maps to the `minimal embed` backend profile:

- `POST /api/resolve-subject`
- `POST /api/create-catalog-session`
- `POST /api/create-widget-session`

## Managed gateway session examples

Payment-lane examples:

- [managed-gateway-session/tenant.php](/home/dacrise/x/xapps/packages/xapps-php/examples/managed-gateway-session/tenant.php)
- [managed-gateway-session/publisher.php](/home/dacrise/x/xapps/packages/xapps-php/examples/managed-gateway-session/publisher.php)

## Smoke examples

- [smoke/smoke.php](/home/dacrise/x/xapps/packages/xapps-php/examples/smoke/smoke.php)
- [smoke/live.php](/home/dacrise/x/xapps/packages/xapps-php/examples/smoke/live.php)

## Payment-return examples

- [payment-return/parity.php](/home/dacrise/x/xapps/packages/xapps-php/examples/payment-return/parity.php)
- [payment-return/xpo-core-fixtures.php](/home/dacrise/x/xapps/packages/xapps-php/examples/payment-return/xpo-core-fixtures.php)

Use the PHP host proxy starter as the backend pair for the browser starter in `packages/xapps-embed-sdk/examples/marketplace-host-starter`.
