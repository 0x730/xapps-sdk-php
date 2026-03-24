<?php

declare(strict_types=1);

namespace Xapps;

interface PaymentSessionStoreInterface
{
    /**
     * Retrieve a payment session by ID.
     *
     * @return array<string,mixed>|null Session data, or null if not found/expired.
     */
    public function get(string $id): ?array;

    /**
     * Store or update a payment session.
     *
     * @param array<string,mixed> $session
     */
    public function set(string $id, array $session): void;

    /**
     * Delete a payment session by ID.
     */
    public function delete(string $id): void;

    /**
     * Remove expired or stale sessions.
     */
    public function prune(): void;
}
