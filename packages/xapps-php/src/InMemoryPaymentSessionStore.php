<?php

declare(strict_types=1);

namespace Xapps;

/**
 * In-memory payment session store for local development and testing.
 *
 * WARNING: Not suitable for production. Sessions are lost on process restart
 * and not shared across multiple workers. Use a persistent store (Redis, database)
 * for production deployments.
 */
final class InMemoryPaymentSessionStore implements PaymentSessionStoreInterface
{
    /** @var array<string,array<string,mixed>> */
    private array $sessions = [];
    private int $ttlMs;

    public function __construct(int $ttlMs = 1_800_000)
    {
        $this->ttlMs = $ttlMs;
    }

    public function get(string $id): ?array
    {
        $this->prune();
        $session = $this->sessions[$id] ?? null;
        if ($session === null) {
            return null;
        }
        $nowMs = (int) (microtime(true) * 1000);
        if (($session['expires_at_ms'] ?? 0) <= $nowMs) {
            unset($this->sessions[$id]);
            return null;
        }
        return $session;
    }

    public function set(string $id, array $session): void
    {
        $this->sessions[$id] = $session;
    }

    public function delete(string $id): void
    {
        unset($this->sessions[$id]);
    }

    public function prune(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        foreach ($this->sessions as $id => $session) {
            if (!is_array($session)) {
                unset($this->sessions[$id]);
                continue;
            }
            if (($session['expires_at_ms'] ?? 0) <= $nowMs) {
                unset($this->sessions[$id]);
                continue;
            }
            if (
                ($session['status'] ?? '') === 'authorized'
                && (($session['authorized_at_ms'] ?? 0) + 60_000) <= $nowMs
            ) {
                unset($this->sessions[$id]);
            }
        }
    }
}
