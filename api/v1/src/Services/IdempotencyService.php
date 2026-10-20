<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;

/**
 * Reads and stores tenant-scoped cached responses for idempotency keys.
 *
 * Best-effort methods suppress lookup/write failures; strict methods propagate them.
 * Callers coordinate request fingerprints and transaction boundaries.
 */
class IdempotencyService
{
    private PDO $db;

    /** Cache lifetime in seconds. */
    private int $ttl;
    private MonologHandler $logger;

    public function __construct(PDO $db, int $ttl = 86400)
    {
        $this->db  = $db;
        $this->ttl = $ttl;
        $this->logger = MonologHandler::getInstance('idempotency');
    }

    /** Best-effort lookup; returns null for missing, expired, malformed, or unreadable records. */
    public function check(int $tenantId, string $key): ?array
    {
        if ($key === '') {
            return null;
        }

        try {
            $stmt = $this->db->prepare("
                SELECT response_json
                FROM   payment_idempotency_keys
                WHERE  tenant_id       = ?
                  AND  idempotency_key = ?
                  AND  expires_at      > NOW()
                LIMIT 1
            ");
            $stmt->execute([$tenantId, $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row || empty($row['response_json'])) {
                return null;
            }

            $decoded = json_decode((string) $row['response_json'], true);
            return is_array($decoded) ? $decoded : null;

        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Lookup a cached response and propagate database or decoding failures. */
    public function checkStrict(int $tenantId, string $key): ?array
    {
        if ($key === '') {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT response_json
            FROM payment_idempotency_keys
            WHERE tenant_id = ? AND idempotency_key = ? AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$tenantId, $key]);
        $responseJson = $stmt->fetchColumn();
        if ($responseJson === false || $responseJson === null || $responseJson === '') {
            return null;
        }

        $decoded = json_decode((string) $responseJson, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('تعذر قراءة نتيجة طلب الدفعة السابق.');
        }

        return $decoded;
    }

    /**
     * Atomically claim a key inside the caller's transaction.
     *
     * Returns null when this transaction inserted the reservation; otherwise returns
     * the response already stored for the key after acquiring its row lock.
     */
    public function claimStrict(int $tenantId, string $key, string $requestFingerprint): ?array
    {
        if ($key === '') {
            return null;
        }
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Idempotency keys must be claimed inside the operation transaction.');
        }

        $now = date('Y-m-d H:i:s');
        $existingExpiry = $this->db->prepare(
            'SELECT expires_at
             FROM payment_idempotency_keys
             WHERE tenant_id = ? AND idempotency_key = ?
             LIMIT 1'
        );
        $existingExpiry->execute([$tenantId, $key]);
        $expiresAt = $existingExpiry->fetchColumn();
        if ($expiresAt !== false && (string) $expiresAt <= $now) {
            $this->db->prepare(
                'DELETE FROM payment_idempotency_keys
                 WHERE tenant_id = ? AND idempotency_key = ? AND expires_at <= ?'
            )->execute([$tenantId, $key, $now]);
        }

        $reservation = json_encode([
            '_request_fingerprint' => $requestFingerprint,
            '_processing' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($reservation === false) {
            throw new \RuntimeException('تعذر حجز مفتاح منع التكرار.');
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $stmt = $this->db->prepare(
                    'INSERT INTO payment_idempotency_keys
                        (tenant_id, idempotency_key, payment_id, response_json, expires_at)
                     VALUES (?, ?, 0, ?, ?)'
                );
                $stmt->execute([
                    $tenantId,
                    $key,
                    $reservation,
                    date('Y-m-d H:i:s', time() + $this->ttl),
                ]);
                return null;
            } catch (PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }

                $forUpdate = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                    ? ' FOR UPDATE'
                    : '';
                $stmt = $this->db->prepare(
                    "SELECT response_json, expires_at
                     FROM payment_idempotency_keys
                     WHERE tenant_id = ? AND idempotency_key = ?
                     LIMIT 1{$forUpdate}"
                );
                $stmt->execute([$tenantId, $key]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$existing) {
                    throw $e;
                }

                if ((string) $existing['expires_at'] <= $now) {
                    $this->db->prepare(
                        'DELETE FROM payment_idempotency_keys
                         WHERE tenant_id = ? AND idempotency_key = ? AND expires_at <= ?'
                    )->execute([$tenantId, $key, $now]);
                    continue;
                }

                $decoded = json_decode((string) $existing['response_json'], true);
                if (!is_array($decoded)) {
                    throw new \RuntimeException('تعذر قراءة نتيجة طلب الدفعة السابق.');
                }

                return $decoded;
            }
        }

        throw new \RuntimeException('تعذر حجز مفتاح منع التكرار بعد انتهاء السجل السابق.');
    }

    /** Best-effort response storage; logs and suppresses persistence failures. */
    public function store(int $tenantId, string $key, int $paymentId, array $response): void
    {
        if ($key === '') {
            return;
        }

        try {
            $this->storeStrict($tenantId, $key, $paymentId, $response);
        } catch (\Throwable $e) {
            $this->logger->error('[IdempotencyService] Failed to store key', [
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Persist a cached response and propagate encoding or database errors.
     *
     * The caller must include this write in the transaction for the financial operation.
     */
    public function storeStrict(int $tenantId, string $key, int $paymentId, array $response): void
    {
        if ($key === '') {
            return;
        }

        $responseJson = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($responseJson === false) {
            throw new \RuntimeException('تعذر حفظ نتيجة طلب الدفعة لإعادة المحاولة بأمان.');
        }

        $expiresAt = date('Y-m-d H:i:s', time() + $this->ttl);
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $upsert = $driver === 'sqlite'
            ? "ON CONFLICT (tenant_id, idempotency_key) DO UPDATE SET
                   payment_id = excluded.payment_id,
                   response_json = excluded.response_json,
                   expires_at = excluded.expires_at"
            : "ON DUPLICATE KEY UPDATE
                   payment_id = VALUES(payment_id),
                   response_json = VALUES(response_json),
                   expires_at = VALUES(expires_at)";

        $stmt = $this->db->prepare("
            INSERT INTO payment_idempotency_keys
                (tenant_id, idempotency_key, payment_id, response_json, expires_at)
            VALUES (?, ?, ?, ?, ?)
            {$upsert}
        ");
        $stmt->execute([$tenantId, $key, $paymentId, $responseJson, $expiresAt]);
    }

    /** Delete expired records; returns zero if the database operation fails. */
    public function purgeExpired(): int
    {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM payment_idempotency_keys
                WHERE expires_at < NOW()
            ");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
