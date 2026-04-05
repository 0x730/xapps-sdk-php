<?php

declare(strict_types=1);

use Xapps\XmsEvents;

return [
    [
        'name' => 'XmsEvents recognizes canonical lifecycle events',
        'run' => static function (): void {
            xappsPhpAssertTrue(XmsEvents::isXmsLifecycleEventType('xapps.xms.access.issued'));
            xappsPhpAssertTrue(!XmsEvents::isXmsLifecycleEventType('xapps.request.completed'));
        },
    ],
    [
        'name' => 'XmsEvents normalizes access-issued payloads',
        'run' => static function (): void {
            $normalized = XmsEvents::normalizeXmsLifecycleEvent([
                'eventId' => 'evt_123',
                'eventType' => 'xapps.xms.access.issued',
                'occurredAt' => '2026-04-04T09:15:23.000Z',
                'installationId' => null,
                'payload' => [
                    'client_id' => 'client_123',
                    'publisher_id' => 'publisher_123',
                    'subject_id' => 'subject_123',
                    'realm_ref' => 'workspace_demo',
                    'xapp_id' => 'xapp_123',
                    'xapp_version_id' => 'ver_123',
                    'purchase_intent_id' => 'intent_123',
                    'issuance_mode' => 'wallet_topup',
                    'payment_session_id' => 'pay_123',
                    'request_id' => 'req_123',
                    'entitlement_id' => 'ent_123',
                    'subscription_contract_id' => 'sub_123',
                    'wallet_account_id' => 'wallet_123',
                    'wallet_ledger_id' => 'ledger_123',
                    'snapshot_id' => 'snap_123',
                    'access_projection' => [
                        'tier' => 'creator_pro_membership_access',
                        'balance_state' => 'sufficient',
                        'entitlement_state' => 'active',
                    ],
                ],
            ]);

            xappsPhpAssertSame('client_123', (string) ($normalized['scope']['clientId'] ?? ''));
            xappsPhpAssertSame('intent_123', (string) ($normalized['correlation']['purchaseIntentId'] ?? ''));
            xappsPhpAssertSame('issue_access', (string) ($normalized['semantics']['phase'] ?? ''));
            xappsPhpAssertSame('creator_pro_membership_access', (string) ($normalized['semantics']['accessTier'] ?? ''));
        },
    ],
    [
        'name' => 'XmsEvents normalizes nested purchase-intent and transaction data',
        'run' => static function (): void {
            $normalized = XmsEvents::normalizeXmsLifecycleEvent([
                'eventId' => 'evt_456',
                'eventType' => 'xapps.xms.transaction.reconciled',
                'occurredAt' => '2026-04-04T09:15:23.000Z',
                'installationId' => 'inst_123',
                'payload' => [
                    'client_id' => 'client_123',
                    'xapp_id' => 'xapp_123',
                    'purchase_intent' => [
                        'purchase_intent_id' => 'intent_123',
                        'status' => 'prepared',
                        'package' => ['id' => 'pkg_123'],
                        'price' => ['id' => 'price_123'],
                        'offering' => ['id' => 'offer_123'],
                    ],
                    'transaction' => [
                        'id' => 'txn_123',
                        'status' => 'paid',
                        'payment_session_id' => 'pay_123',
                        'request_id' => 'req_123',
                    ],
                ],
            ]);

            xappsPhpAssertSame('inst_123', (string) ($normalized['scope']['installationId'] ?? ''));
            xappsPhpAssertSame('txn_123', (string) ($normalized['correlation']['transactionId'] ?? ''));
            xappsPhpAssertSame('pkg_123', (string) ($normalized['semantics']['packageId'] ?? ''));
            xappsPhpAssertSame('reconcile_transaction', (string) ($normalized['semantics']['phase'] ?? ''));
        },
    ],
];
