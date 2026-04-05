<?php

declare(strict_types=1);

namespace Xapps;

final class XmsEvents
{
    /** @var list<string> */
    public const LIFECYCLE_EVENT_TYPES = [
        'xapps.xms.purchase_intent.prepared',
        'xapps.xms.transaction.reconciled',
        'xapps.xms.access.issued',
        'xapps.xms.access_snapshot.refreshed',
    ];

    public static function isXmsLifecycleEventType(string $eventType): bool
    {
        return in_array(trim($eventType), self::LIFECYCLE_EVENT_TYPES, true);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>|null
     */
    public static function normalizeXmsLifecycleEvent(array $input): ?array
    {
        $eventType = self::readString($input['eventType'] ?? null);
        if ($eventType === null || !self::isXmsLifecycleEventType($eventType)) {
            return null;
        }

        $payload = self::readRecord($input['payload'] ?? null);
        $purchaseIntent = self::readRecord($payload['purchase_intent'] ?? null);
        $transaction = self::readRecord($payload['transaction'] ?? null);
        $accessProjection = self::readRecord($payload['access_projection'] ?? null);

        $phase = 'prepare_intent';
        if ($eventType === 'xapps.xms.transaction.reconciled') {
            $phase = 'reconcile_transaction';
        } elseif ($eventType === 'xapps.xms.access.issued') {
            $phase = 'issue_access';
        } elseif ($eventType === 'xapps.xms.access_snapshot.refreshed') {
            $phase = 'refresh_access';
        }

        return [
            'eventId' => self::readString($input['eventId'] ?? null),
            'eventType' => $eventType,
            'occurredAt' => self::readString($input['occurredAt'] ?? null),
            'payload' => $payload,
            'scope' => [
                'clientId' => self::readString($payload['client_id'] ?? null),
                'publisherId' => self::readString($payload['publisher_id'] ?? null),
                'subjectId' => self::readString($payload['subject_id'] ?? null),
                'installationId' => self::readString($payload['installation_id'] ?? null) ?? self::readString($input['installationId'] ?? null),
                'realmRef' => self::readString($payload['realm_ref'] ?? null),
                'xappId' => self::readString($payload['xapp_id'] ?? null),
                'xappVersionId' => self::readString($payload['xapp_version_id'] ?? null),
            ],
            'correlation' => [
                'purchaseIntentId' => self::readString($payload['purchase_intent_id'] ?? null)
                    ?? self::readString($purchaseIntent['purchase_intent_id'] ?? null),
                'paymentSessionId' => self::readString($payload['payment_session_id'] ?? null)
                    ?? self::readString($transaction['payment_session_id'] ?? null)
                    ?? self::readString($purchaseIntent['payment_session_id'] ?? null),
                'requestId' => self::readString($payload['request_id'] ?? null)
                    ?? self::readString($transaction['request_id'] ?? null)
                    ?? self::readString($purchaseIntent['request_id'] ?? null),
                'snapshotId' => self::readString($payload['snapshot_id'] ?? null),
                'entitlementId' => self::readString($payload['entitlement_id'] ?? null),
                'subscriptionContractId' => self::readString($payload['subscription_contract_id'] ?? null),
                'walletAccountId' => self::readString($payload['wallet_account_id'] ?? null),
                'walletLedgerId' => self::readString($payload['wallet_ledger_id'] ?? null),
                'transactionId' => self::readString($transaction['id'] ?? null),
            ],
            'semantics' => [
                'phase' => $phase,
                'reason' => self::readString($payload['reason'] ?? null),
                'issuanceMode' => self::readString($payload['issuance_mode'] ?? null),
                'accessTier' => self::readString($accessProjection['tier'] ?? null),
                'accessState' => self::readString($accessProjection['entitlement_state'] ?? null),
                'balanceState' => self::readString($accessProjection['balance_state'] ?? null),
                'purchaseIntentStatus' => self::readString($purchaseIntent['status'] ?? null)
                    ?? self::readString($payload['intent_status'] ?? null),
                'transactionStatus' => self::readString($transaction['status'] ?? null),
                'packageId' => self::readString($purchaseIntent['package_id'] ?? null)
                    ?? self::readString(self::readRecord($purchaseIntent['package'] ?? null)['id'] ?? null),
                'priceId' => self::readString($purchaseIntent['price_id'] ?? null)
                    ?? self::readString(self::readRecord($purchaseIntent['price'] ?? null)['id'] ?? null),
                'offeringId' => self::readString($purchaseIntent['offering_id'] ?? null)
                    ?? self::readString(self::readRecord($purchaseIntent['offering'] ?? null)['id'] ?? null),
            ],
        ];
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>
     */
    private static function readRecord(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function readString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }
}
