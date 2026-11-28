<?php

declare(strict_types=1);

namespace App\Services;

use App\Utils\RequestHelper;
use InvalidArgumentException;
use PDO;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;

final class PlatformAuditLogger
{
    public function __construct(private PDO $db)
    {
    }

    public function record(Request $request, string $action, ?int $tenantId, array $details): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,49}$/', $action)) {
            throw new InvalidArgumentException('Invalid platform audit action.');
        }

        $user = $request->getAttribute('user');
        $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        if ($userId <= 0) {
            throw new RuntimeException('Authenticated platform actor is required for audit logging.');
        }

        $encodedDetails = json_encode(
            $details,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $statement = $this->db->prepare("
            INSERT INTO audit_log
                (user_id, action, entity, entity_id, details, tenant_id, ip_address, created_at)
            VALUES
                (?, ?, 'platform_admin', ?, ?, NULL, ?, CURRENT_TIMESTAMP)
        ");
        $statement->execute([
            $userId,
            $action,
            $tenantId,
            $encodedDetails,
            RequestHelper::getClientIp($request),
        ]);
    }
}
