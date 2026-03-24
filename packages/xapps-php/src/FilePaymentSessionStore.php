<?php

declare(strict_types=1);

namespace Xapps;

/**
 * Simple JSON-file-backed payment session store.
 *
 * Suitable for single-instance tenant backends where sessions must survive
 * PHP request boundaries. This is not intended as a distributed store.
 */
final class FilePaymentSessionStore implements PaymentSessionStoreInterface
{
    private string $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        if (!is_file($filePath)) {
            file_put_contents($filePath, "{}\n");
        }
    }

    public function get(string $id): ?array
    {
        $sessions = $this->loadSessions();
        $session = $sessions[$id] ?? null;
        if (!is_array($session)) {
            return null;
        }
        if ($this->isExpired($session)) {
            unset($sessions[$id]);
            $this->saveSessions($sessions);
            return null;
        }
        return $session;
    }

    public function set(string $id, array $session): void
    {
        $sessions = $this->loadSessions();
        $sessions[$id] = $session;
        $this->saveSessions($sessions);
    }

    public function delete(string $id): void
    {
        $sessions = $this->loadSessions();
        unset($sessions[$id]);
        $this->saveSessions($sessions);
    }

    public function prune(): void
    {
        $sessions = $this->loadSessions();
        $filtered = [];
        foreach ($sessions as $id => $session) {
            if (!is_string($id) || !is_array($session) || $this->isExpired($session)) {
                continue;
            }
            $filtered[$id] = $session;
        }
        $this->saveSessions($filtered);
    }

    /** @return array<string,array<string,mixed>> */
    private function loadSessions(): array
    {
        $handle = fopen($this->filePath, 'c+');
        if ($handle === false) {
            return [];
        }
        try {
            if (!flock($handle, LOCK_SH)) {
                return [];
            }
            $raw = stream_get_contents($handle);
            flock($handle, LOCK_UN);
            $decoded = json_decode($raw !== false ? $raw : '{}', true);
            return is_array($decoded) ? $decoded : [];
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string,array<string,mixed>> $sessions */
    private function saveSessions(array $sessions): void
    {
        $handle = fopen($this->filePath, 'c+');
        if ($handle === false) {
            return;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($sessions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: "{}\n");
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string,mixed> $session */
    private function isExpired(array $session): bool
    {
        $nowMs = (int) (microtime(true) * 1000);
        if ((int) ($session['expires_at_ms'] ?? 0) <= $nowMs) {
            return true;
        }
        return ($session['status'] ?? '') === 'authorized'
            && ((int) ($session['authorized_at_ms'] ?? 0) + 60_000) <= $nowMs;
    }
}
