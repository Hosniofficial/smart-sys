<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class UserSessionService
{
    public function __construct(private PDO $db)
    {
    }

    /** Change a user's password and revoke every access and refresh session atomically. */
    public function changePassword(int $userId, int $tenantId, string $newPassword): void
    {
        $updatedAt = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'CURRENT_TIMESTAMP'
            : 'NOW()';
        $tenantCondition = $tenantId > 0
            ? 'tenant_id = ?'
            : '(tenant_id IS NULL OR tenant_id = 0)';
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $stmt = $this->db->prepare(
                "UPDATE users
                 SET password = ?, token_version = token_version + 1, updated_at = {$updatedAt}
                 WHERE id = ? AND {$tenantCondition}"
            );
            $params = [
                password_hash($newPassword, PASSWORD_DEFAULT),
                $userId,
            ];
            if ($tenantId > 0) {
                $params[] = $tenantId;
            }
            $stmt->execute($params);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Could not update password for the requested tenant user.');
            }

            $this->revokeRefreshTokens($userId);

            if ($startedTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Revoke outstanding refresh tokens and invalidate access tokens by version. */
    public function revokeAllSessions(int $userId, int $tenantId): void
    {
        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $tenantCondition = $tenantId > 0
                ? 'tenant_id = ?'
                : '(tenant_id IS NULL OR tenant_id = 0)';
            $stmt = $this->db->prepare(
                'UPDATE users
                 SET token_version = token_version + 1
                 WHERE id = ? AND ' . $tenantCondition
            );
            $params = [$userId];
            if ($tenantId > 0) {
                $params[] = $tenantId;
            }
            $stmt->execute($params);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Could not revoke sessions for the requested tenant user.');
            }

            $this->revokeRefreshTokens($userId);

            if ($startedTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Check that a signed access token carries the user's current session version. */
    public function isCurrentTokenVersion(int $userId, int $tenantId, int $tokenVersion): bool
    {
        if ($tenantId > 0) {
            $stmt = $this->db->prepare(
                'SELECT token_version FROM users WHERE id = ? AND tenant_id = ? LIMIT 1'
            );
            $stmt->execute([$userId, $tenantId]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT token_version FROM users
                 WHERE id = ? AND (tenant_id IS NULL OR tenant_id = 0)
                 LIMIT 1'
            );
            $stmt->execute([$userId]);
        }

        $currentVersion = $stmt->fetchColumn();

        return $currentVersion !== false && (int) $currentVersion === $tokenVersion;
    }

    private function revokeRefreshTokens(int $userId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE refresh_tokens SET is_revoked = 1 WHERE user_id = ? AND is_revoked = 0'
        );
        $stmt->execute([$userId]);
    }
}
