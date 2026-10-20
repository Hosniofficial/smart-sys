<?php

namespace App\Repositories;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;

/**
 * Stores and queries security audit events using the application PDO connection.
 */
class SecurityEventRepository
{
    private PDO $db;
    private ?LoggerInterface $logger;

    public function __construct(PDO $db, ?LoggerInterface $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /** Insert an event and return its ID, or null when a PDO error occurs. */
    public function logEvent(
        string $eventType,
        ?int $tenantId = null,
        ?int $userId = null,
        ?int $targetUserId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $status = null,
        string $severity = 'info',
        ?array $details = null,
        ?string $createdAt = null
    ): ?int {
        try {
            $sql = 'INSERT INTO security_events (
                event_type, tenant_id, user_id, target_user_id, 
                ip_address, user_agent, status, event_severity, 
                details, created_at
            ) VALUES (
                :event_type, :tenant_id, :user_id, :target_user_id, 
                :ip_address, :user_agent, :status, :severity, 
                :details, :created_at
            )';

            $stmt = $this->db->prepare($sql);

            $stmt->bindValue(':event_type', $eventType, PDO::PARAM_STR);
            $stmt->bindValue(':tenant_id', $tenantId, $tenantId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':user_id', $userId, $userId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':target_user_id', $targetUserId, $targetUserId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':ip_address', $ipAddress, $ipAddress !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':user_agent', $userAgent, $userAgent !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':status', $status, $status !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':severity', $severity, PDO::PARAM_STR);

            $stmt->bindValue(':details', $details !== null ? json_encode($details) : null, PDO::PARAM_STR);
            $stmt->bindValue(':created_at', $createdAt ?? date('Y-m-d H:i:s'), PDO::PARAM_STR);

            $stmt->execute();

            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            $this->logError('Failed to log security event: ' . $e->getMessage(), [
                'event_type' => $eventType,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'exception' => $e,
            ]);
            return null;
        }
    }

    /** Fetch filtered events and pagination metadata. */
    public function getEvents(
        ?int $tenantId = null,
        ?int $userId = null,
        ?string $eventType = null,
        ?string $severity = null,
        ?string $status = null,
        ?string $ipAddress = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $page = 1,
        int $perPage = 50,
        string $orderBy = 'created_at',
        string $orderDir = 'DESC'
    ): array {
        try {
            // Validate order direction
            $orderDir = strtoupper($orderDir) === 'ASC' ? 'ASC' : 'DESC';

            // Validate order by field
            $allowedOrderBy = [
                'id', 'event_type', 'tenant_id', 'user_id', 'target_user_id',
                'ip_address', 'status', 'severity', 'created_at'
            ];
            $orderBy = in_array($orderBy, $allowedOrderBy) ? $orderBy : 'created_at';

            // Build the base query
            $sql = 'SELECT * FROM security_events WHERE 1=1';
            $params = [];
            $types = [];

            // Add filters
            if ($tenantId !== null) {
                $sql .= ' AND tenant_id = :tenant_id';
                $params[':tenant_id'] = $tenantId;
                $types[':tenant_id'] = PDO::PARAM_INT;
            }

            if ($userId !== null) {
                $sql .= ' AND user_id = :user_id';
                $params[':user_id'] = $userId;
                $types[':user_id'] = PDO::PARAM_INT;
            }

            if ($eventType !== null) {
                $sql .= ' AND event_type = :event_type';
                $params[':event_type'] = $eventType;
                $types[':event_type'] = PDO::PARAM_STR;
            }

            if ($severity !== null) {
                $sql .= ' AND event_severity = :severity';
                $params[':severity'] = $severity;
                $types[':severity'] = PDO::PARAM_STR;
            }

            if ($status !== null) {
                $sql .= ' AND status = :status';
                $params[':status'] = $status;
                $types[':status'] = PDO::PARAM_STR;
            }

            if ($ipAddress !== null) {
                $sql .= ' AND ip_address = :ip_address';
                $params[':ip_address'] = $ipAddress;
                $types[':ip_address'] = PDO::PARAM_STR;
            }

            if ($dateFrom !== null) {
                $sql .= ' AND created_at >= :date_from';
                $params[':date_from'] = $dateFrom;
                $types[':date_from'] = PDO::PARAM_STR;
            }

            if ($dateTo !== null) {
                $sql .= ' AND created_at <= :date_to';
                $params[':date_to'] = $dateTo;
                $types[':date_to'] = PDO::PARAM_STR;
            }

            // Get total count for pagination
            $countStmt = $this->db->prepare(str_replace('*', 'COUNT(*) as total', $sql));
            $this->bindParams($countStmt, $params, $types);
            $countStmt->execute();
            $total = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Add sorting and pagination
            $sql .= " ORDER BY {$orderBy} {$orderDir}";
            $sql .= ' LIMIT :offset, :limit';

            // Prepare and execute the query
            $stmt = $this->db->prepare($sql);

            // Bind all the parameters
            $this->bindParams($stmt, $params, $types);

            // Bind pagination parameters
            $offset = ($page - 1) * $perPage;
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);

            $stmt->execute();

            // Process results
            $events = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $row['details'] = $row['details'] ? json_decode($row['details'], true) : null;
                $events[] = $row;
            }

            return [
                'data' => $events,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => ceil($total / $perPage),
                    'from' => $total > 0 ? $offset + 1 : 0,
                    'to' => min($offset + $perPage, $total),
                ]
            ];
        } catch (PDOException $e) {
            $this->logError('Failed to fetch security events: ' . $e->getMessage(), [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'exception' => $e,
            ]);
            return ['data' => [], 'pagination' => []];
        }
    }

    /** Fetch an event by ID, optionally scoped to a tenant. */
    public function getEventById(int $id, ?int $tenantId = null): ?array
    {
        try {
            $sql = 'SELECT * FROM security_events WHERE id = :id';
            $params = [':id' => $id];
            $types = [':id' => PDO::PARAM_INT];

            if ($tenantId !== null) {
                $sql .= ' AND (tenant_id IS NULL OR tenant_id = :tenant_id)';
                $params[':tenant_id'] = $tenantId;
                $types[':tenant_id'] = PDO::PARAM_INT;
            }

            $stmt = $this->db->prepare($sql);
            $this->bindParams($stmt, $params, $types);
            $stmt->execute();

            $event = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($event) {
                $event['details'] = $event['details'] ? json_decode($event['details'], true) : null;
                return $event;
            }

            return null;
        } catch (PDOException $e) {
            $this->logError('Failed to fetch security event: ' . $e->getMessage(), [
                'id' => $id,
                'tenant_id' => $tenantId,
                'exception' => $e,
            ]);
            return null;
        }
    }

    /** Aggregate event totals and the most common event types. */
    public function getStatistics(
        ?int $tenantId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        try {
            $sql = 'SELECT 
                COUNT(*) as total_events,
                COUNT(DISTINCT user_id) as unique_users,
                COUNT(DISTINCT ip_address) as unique_ips,
                SUM(CASE WHEN event_severity = "high" OR event_severity = "critical" THEN 1 ELSE 0 END) as critical_events
            FROM security_events
            WHERE 1=1';

            $params = [];
            $types = [];

            if ($tenantId !== null) {
                $sql .= ' AND tenant_id = :tenant_id';
                $params[':tenant_id'] = $tenantId;
                $types[':tenant_id'] = PDO::PARAM_INT;
            }

            if ($dateFrom !== null) {
                $sql .= ' AND created_at >= :date_from';
                $params[':date_from'] = $dateFrom;
                $types[':date_from'] = PDO::PARAM_STR;
            }

            if ($dateTo !== null) {
                $sql .= ' AND created_at <= :date_to';
                $params[':date_to'] = $dateTo;
                $types[':date_to'] = PDO::PARAM_STR;
            }

            $stmt = $this->db->prepare($sql);
            $this->bindParams($stmt, $params, $types);
            $stmt->execute();

            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get event counts by type
            $sql = 'SELECT 
                event_type, 
                COUNT(*) as count 
            FROM security_events 
            WHERE 1=1';

            if ($tenantId !== null) {
                $sql .= ' AND tenant_id = :tenant_id';
            }

            if ($dateFrom !== null) {
                $sql .= ' AND created_at >= :date_from';
            }

            if ($dateTo !== null) {
                $sql .= ' AND created_at <= :date_to';
            }

            $sql .= ' GROUP BY event_type ORDER BY count DESC LIMIT 10';

            $stmt = $this->db->prepare($sql);
            $this->bindParams($stmt, $params, $types);
            $stmt->execute();

            $eventTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'total_events' => (int)($result['total_events'] ?? 0),
                'unique_users' => (int)($result['unique_users'] ?? 0),
                'unique_ips' => (int)($result['unique_ips'] ?? 0),
                'critical_events' => (int)($result['critical_events'] ?? 0),
                'top_event_types' => $eventTypes,
            ];
        } catch (PDOException $e) {
            $this->logError('Failed to fetch security event statistics: ' . $e->getMessage(), [
                'tenant_id' => $tenantId,
                'exception' => $e,
            ]);
            return [
                'total_events' => 0,
                'unique_users' => 0,
                'unique_ips' => 0,
                'critical_events' => 0,
                'top_event_types' => [],
            ];
        }
    }

    /** Bind named query parameters using their declared PDO types. */
    private function bindParams(\PDOStatement $stmt, array $params, array $types): void
    {
        foreach ($params as $key => $value) {
            $type = $types[$key] ?? PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $type);
        }
    }

    /** Log repository errors when a logger was provided. */
    private function logError(string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->error($message, $context);
        }
    }
}
