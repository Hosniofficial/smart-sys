<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Exception;
use Throwable;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;

/**
 * Manages tenant subscriptions and plans for the administration API.
 *
 * Reads and writes subscription, plan, and event records with PDO; protected routes
 * are registered through SuperAdminMiddleware in app/routes/admin.php.
 */
class AdminSubscriptionHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('admin_subscription');
    }

    /** List up to 200 subscriptions, optionally filtered by plan, status, or tenant. */
    public function listSubscriptions(Request $request, Response $response): Response
    {
        try {
            $qp = $request->getQueryParams();
            $conds = [];
            $params = [];

            if (!empty($qp['plan'])) {
                $conds[] = 'p.code = ?';
                $params[] = trim((string) $qp['plan']);
            }

            if (!empty($qp['status'])) {
                $conds[] = 's.status = ?';
                $params[] = trim((string) $qp['status']);
            }

            if (!empty($qp['tenant_id'])) {
                $conds[] = 's.tenant_id = ?';
                $params[] = (int) $qp['tenant_id'];
            }

            $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';

            $sql = "
                SELECT
                    s.*,
                    u.name AS tenant_name,
                    u.email AS tenant_email,
                    u.email_verified_at IS NOT NULL AS email_verified,
                    p.code AS plan_code,
                    p.name AS plan_name,
                    p.price,
                    p.currency
                FROM subscriptions s
                JOIN plans p ON p.id = s.plan_id
                LEFT JOIN users u ON u.id = s.tenant_id
                {$where}
                ORDER BY s.id DESC
                LIMIT 200
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $processedRows = array_map(function($row) {
                if (!empty($row['security_flags'])) {
                    $flags = json_decode($row['security_flags'], true);
                    $row['security_flags'] = is_array($flags) ? $flags : [];
                } else {
                    $row['security_flags'] = [];
                }

                $row['email_verified'] = (int) $row['email_verified'];
                $row['auto_renew'] = (int) $row['auto_renew'];

                return $row;
            }, $rows);

            return $this->successResponse($response, [
                'items' => $processedRows
            ], 200);
        } catch (Throwable $e) {
            $this->logger->error('Failed to list subscriptions', [
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'Failed to list subscriptions', 500);
        }
    }

    /** Activate a subscription on its current or requested active plan and record an event. */
    public function activateSubscription(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $payload = $request->getParsedBody();
        $payload = is_array($payload) ? $payload : [];
        $planCode = isset($payload['plan']) ? trim((string) $payload['plan']) : '';

        if ($id <= 0) {
            return $this->errorResponse($response, 'Invalid subscription id', 400);
        }

        try {
            $stmt = $this->db->prepare("
                SELECT s.*, p.code AS plan_code, p.billing_cycle_days
                FROM subscriptions s
                JOIN plans p ON p.id = s.plan_id
                WHERE s.id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $sub = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sub) {
                return $this->errorResponse($response, 'الاشتراك غير موجود', 404);
            }

            $planId = (int) $sub['plan_id'];
            $cycle = (int) $sub['billing_cycle_days'];

            if ($planCode !== '') {
                $st = $this->db->prepare("
                    SELECT id, billing_cycle_days, is_active
                    FROM plans
                    WHERE code = ?
                    LIMIT 1
                ");
                $st->execute([$planCode]);
                $pl = $st->fetch(PDO::FETCH_ASSOC);

                if (!$pl) {
                    return $this->errorResponse($response, 'الخطة غير موجودة', 400);
                }

                if ((int) ($pl['is_active'] ?? 0) !== 1) {
                    return $this->errorResponse($response, 'لا يمكن التفعيل بخطة غير مفعلة', 400);
                }

                $planId = (int) $pl['id'];
                $cycle = (int) $pl['billing_cycle_days'];
            }

            $start = new DateTimeImmutable('now');
            $end = $start->modify('+' . max(1, $cycle) . ' days');

            $this->db->beginTransaction();

            $up = $this->db->prepare("
                UPDATE subscriptions
                SET plan_id = ?,
                    start_date = ?,
                    end_date = ?,
                    status = 'active',
                    payment_status = 'paid',
                    updated_at = NOW()
                WHERE id = ?
            ");
            $up->execute([
                $planId,
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
                $id
            ]);

            $ev = $this->db->prepare("
                INSERT INTO subscription_events (subscription_id, event_type, event_date, meta_json)
                VALUES (?, 'activated', NOW(), ?)
            ");
            $ev->execute([
                $id,
                json_encode([
                    'by' => 'admin',
                    'plan_id' => $planId
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]);

            $this->db->commit();

            return $this->successResponse($response, [
                'subscription_id' => $id,
                'plan_id' => $planId,
                'start_date' => $start->format('Y-m-d H:i:s'),
                'end_date' => $end->format('Y-m-d H:i:s')
            ], 200);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Failed to activate subscription', [
                'subscription_id' => $id,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'Failed to activate subscription', 500);
        }
    }

    /** Change the plan and optionally extend the subscription period; record the change. */
    public function changePlan(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $payload = $request->getParsedBody();
        $payload = is_array($payload) ? $payload : [];

        $newPlanCode = trim((string) ($payload['new_plan'] ?? ''));
        $prorate = !empty($payload['prorate']);
        $extendPeriod = !empty($payload['extend_period']);

        if ($id <= 0) {
            return $this->errorResponse($response, 'Invalid subscription id', 400);
        }

        if ($newPlanCode === '') {
            return $this->errorResponse($response, 'الخطة الجديدة مطلوبة', 400);
        }

        try {
            $stmt = $this->db->prepare("
                SELECT s.*, p.code AS current_plan_code, p.billing_cycle_days
                FROM subscriptions s
                JOIN plans p ON p.id = s.plan_id
                WHERE s.id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $sub = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sub) {
                return $this->errorResponse($response, 'الاشتراك غير موجود', 404);
            }

            $st = $this->db->prepare("
                SELECT id, billing_cycle_days, price, is_active
                FROM plans
                WHERE code = ?
                LIMIT 1
            ");
            $st->execute([$newPlanCode]);
            $newPlan = $st->fetch(PDO::FETCH_ASSOC);

            if (!$newPlan) {
                return $this->errorResponse($response, 'الخطة الجديدة غير موجودة', 400);
            }

            if ((int) ($newPlan['is_active'] ?? 0) !== 1) {
                return $this->errorResponse($response, 'الخطة الجديدة غير مفعلة', 400);
            }

            if ($sub['current_plan_code'] === $newPlanCode) {
                return $this->errorResponse($response, 'نفس الخطة محددة', 400);
            }

            $end = !empty($sub['end_date'])
                ? new DateTimeImmutable($sub['end_date'])
                : new DateTimeImmutable('now');

            if ($extendPeriod) {
                $end = $end->modify('+' . max(1, (int) $newPlan['billing_cycle_days']) . ' days');
            }

            $this->db->beginTransaction();

            $up = $this->db->prepare("
                UPDATE subscriptions
                SET plan_id = ?,
                    end_date = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $up->execute([
                (int) $newPlan['id'],
                $end->format('Y-m-d H:i:s'),
                $id
            ]);

            $ev = $this->db->prepare("
                INSERT INTO subscription_events (subscription_id, event_type, event_date, meta_json)
                VALUES (?, 'plan_changed', NOW(), ?)
            ");
            $ev->execute([
                $id,
                json_encode([
                    'by' => 'admin',
                    'old_plan' => $sub['current_plan_code'],
                    'new_plan' => $newPlanCode,
                    'prorate' => $prorate,
                    'extend_period' => $extendPeriod
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]);

            $this->db->commit();

            return $this->successResponse($response, [
                'old_plan' => $sub['current_plan_code'],
                'new_plan' => $newPlanCode,
                'new_end_date' => $end->format('Y-m-d H:i:s')
            ], 200);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Failed to change subscription plan', [
                'subscription_id' => $id,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'Failed to change plan', 500);
        }
    }

    /** Recalculate and persist the subscription risk score from tenant security signals. */
    public function securityCheck(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);

        if ($id <= 0) {
            return $this->errorResponse($response, 'Invalid subscription id', 400);
        }

        try {
            $stmt = $this->db->prepare("
                SELECT
                    s.*,
                    t.signup_ip,
                    t.device_fingerprint,
                    t.created_at AS tenant_created,
                    (
                        SELECT COUNT(*)
                        FROM security_events
                        WHERE tenant_id = s.tenant_id
                          AND event_severity = 'high'
                    ) AS high_risk_events,
                    (
                        SELECT COUNT(*)
                        FROM security_events
                        WHERE tenant_id = s.tenant_id
                          AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                    ) AS recent_events
                FROM subscriptions s
                JOIN tenants t ON t.id = s.tenant_id
                WHERE s.id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $sub = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sub) {
                return $this->errorResponse($response, 'الاشتراك غير موجود', 404);
            }

            $riskScore = 0;
            $securityFlags = [];

            if ((int) $sub['high_risk_events'] > 0) {
                $riskScore += 3;
                $securityFlags[] = "High risk events: {$sub['high_risk_events']}";
            }

            if ((int) $sub['recent_events'] > 5) {
                $riskScore += 2;
                $securityFlags[] = "Recent activity: {$sub['recent_events']} events";
            }

            if (empty($sub['device_fingerprint'])) {
                $riskScore += 1;
                $securityFlags[] = 'No device fingerprint';
            }

            $flagsText = implode('; ', $securityFlags);

            $up = $this->db->prepare("
                UPDATE subscriptions
                SET risk_score = ?,
                    security_flags = ?,
                    last_security_check = NOW()
                WHERE id = ?
            ");
            $up->execute([$riskScore, $flagsText, $id]);

            return $this->successResponse($response, [
                'risk_score' => $riskScore,
                'security_flags' => $flagsText,
                'last_security_check' => date('Y-m-d H:i:s'),
                'high_risk_events' => (int) $sub['high_risk_events'],
                'recent_events' => (int) $sub['recent_events']
            ], 200);
        } catch (Throwable $e) {
            $this->logger->error('Failed to run subscription security check', [
                'subscription_id' => $id,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'Failed to run security check', 500);
        }
    }

    /** Block the subscription and tenant, then record subscription and security events. */
    public function blockSubscription(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);

        if ($id <= 0) {
            return $this->errorResponse($response, 'Invalid subscription id', 400);
        }

        try {
            $stmt = $this->db->prepare("
                SELECT *
                FROM subscriptions
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $sub = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sub) {
                return $this->errorResponse($response, 'الاشتراك غير موجود', 404);
            }

            $this->db->beginTransaction();

            $up = $this->db->prepare("
                UPDATE subscriptions
                SET status = 'blocked',
                    updated_at = NOW()
                WHERE id = ?
            ");
            $up->execute([$id]);

            $tenantUp = $this->db->prepare("
                UPDATE tenants
                SET status = 'blocked'
                WHERE id = ?
            ");
            $tenantUp->execute([(int) $sub['tenant_id']]);

            $ev = $this->db->prepare("
                INSERT INTO subscription_events (subscription_id, event_type, event_date, meta_json)
                VALUES (?, 'blocked', NOW(), ?)
            ");
            $ev->execute([
                $id,
                json_encode(['by' => 'admin'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]);

            $this->logSecurityEvent(
                'subscription_blocked',
                'high',
                'Subscription blocked by admin',
                [
                    'subscription_id' => $id,
                    'blocked_by' => 'admin',
                    'tenant_id' => (int) $sub['tenant_id']
                ]
            );

            $this->db->commit();

            return $this->successResponse($response, [
                'subscription_id' => $id,
                'tenant_id' => (int) $sub['tenant_id'],
                'status' => 'blocked'
            ], 200);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Failed to block subscription', [
                'subscription_id' => $id,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'Failed to block subscription', 500);
        }
    }

    /** Mark the subscription expired and set its end date to the current time. */
    public function expireSubscription(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);

        if ($id <= 0) {
            return $this->errorResponse($response, 'Invalid subscription id', 400);
        }

        try {
            $stmt = $this->db->prepare("
                SELECT id
                FROM subscriptions
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);

            if (!$stmt->fetch()) {
                return $this->errorResponse($response, 'الاشتراك غير موجود', 404);
            }

            $this->db->beginTransaction();

            $up = $this->db->prepare("
                UPDATE subscriptions
                SET status = 'expired',
                    end_date = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $up->execute([$id]);

            $ev = $this->db->prepare("
                INSERT INTO subscription_events (subscription_id, event_type, event_date, meta_json)
                VALUES (?, 'expired', NOW(), ?)
            ");
            $ev->execute([
                $id,
                json_encode([
                    'by' => 'admin',
                    'manual' => true
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]);

            $this->db->commit();

            return $this->successResponse($response, [
                'subscription_id' => $id,
                'status' => 'expired'
            ], 200);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Failed to expire subscription', [
                'subscription_id' => $id,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'Failed to expire subscription', 500);
        }
    }

    /** Extend the current end date by the requested positive number of days. */
    public function extendSubscription(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $payload = $request->getParsedBody();
        $payload = is_array($payload) ? $payload : [];
        $days = (int) ($payload['days'] ?? 0);

        if ($id <= 0) {
            return $this->errorResponse($response, 'Invalid subscription id', 400);
        }

        if ($days <= 0) {
            return $this->errorResponse($response, 'Invalid days', 400);
        }

        try {
            $stmt = $this->db->prepare("
                SELECT end_date
                FROM subscriptions
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $end = $stmt->fetchColumn();

            if (!$end) {
                return $this->errorResponse($response, 'Subscription not found', 404);
            }

            $base = new DateTimeImmutable((string) $end);
            $newEnd = $base->modify('+' . $days . ' days');

            $this->db->beginTransaction();

            $up = $this->db->prepare("
                UPDATE subscriptions
                SET end_date = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $up->execute([$newEnd->format('Y-m-d H:i:s'), $id]);

            $ev = $this->db->prepare("
                INSERT INTO subscription_events (subscription_id, event_type, event_date, meta_json)
                VALUES (?, 'extended', NOW(), ?)
            ");
            $ev->execute([
                $id,
                json_encode([
                    'by' => 'admin',
                    'days' => $days
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]);

            $this->db->commit();

            return $this->successResponse($response, [
                'subscription_id' => $id,
                'new_end_date' => $newEnd->format('Y-m-d H:i:s')
            ], 200);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Failed to extend subscription', [
                'subscription_id' => $id,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'Failed to extend subscription', 500);
        }
    }

    /** List available subscription plan definitions. */
    public function listPlans(Request $request, Response $response): Response
    {
        try {
            $stmt = $this->db->query("
                SELECT
                    code,
                    name,
                    price,
                    currency,
                    billing_cycle_days,
                    is_active
                FROM plans
                ORDER BY FIELD(code, 'trial', 'promo_3months', 'monthly', 'yearly'), name
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return $this->successResponse($response, [
                'items' => $rows
            ], 200);
        } catch (Throwable $e) {
            $this->logger->error('Failed to list plans', [
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'Failed to list plans', 500);
        }
    }

    /** Create a plan after validating its code, name, price, and billing cycle. */
    public function createPlan(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $data = is_array($data) ? $data : [];

        $code = strtolower(trim((string) ($data['code'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
        $price = (float) ($data['price'] ?? 0);
        $currency = strtoupper(trim((string) ($data['currency'] ?? 'USD')));
        $billingCycleDays = (int) ($data['billing_cycle_days'] ?? 30);
        $isActive = (int) ($data['is_active'] ?? 1);

        if ($code === '' || $name === '') {
            return $this->errorResponse($response, 'Code and name are required', 400);
        }

        if (!preg_match('/^[a-z0-9_]+$/', $code)) {
            return $this->errorResponse($response, 'Invalid code format', 400);
        }

        if ($price < 0) {
            return $this->errorResponse($response, 'Price must be positive', 400);
        }

        if ($billingCycleDays < 1) {
            return $this->errorResponse($response, 'Billing cycle days must be positive', 400);
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO plans (
                    code,
                    name,
                    price,
                    currency,
                    billing_cycle_days,
                    is_active,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $code,
                $name,
                $price,
                $currency,
                $billingCycleDays,
                $isActive
            ]);

            return $this->successResponse($response, [
                'code' => $code
            ], 201);
        } catch (Throwable $e) {
            $this->logger->error('Failed to create plan', [
                'code' => $code,
                'message' => $e->getMessage()
            ]);

            if (stripos($e->getMessage(), 'duplicate') !== false) {
                return $this->errorResponse($response, 'Plan code already exists', 409);
            }

            return $this->errorResponse($response, 'Failed to create plan', 500);
        }
    }

    /** Update allowed fields on the monthly or yearly plan. */
    public function updatePlan(Request $request, Response $response, array $args): Response
    {
        $code = strtolower(trim((string) ($args['code'] ?? '')));

        if (!in_array($code, ['monthly', 'yearly'], true)) {
            return $this->errorResponse($response, 'Only monthly/yearly can be updated', 400);
        }

        $data = $request->getParsedBody();
        $data = is_array($data) ? $data : [];

        $updates = [];
        $params = [];

        if (isset($data['price'])) {
            $price = (float) $data['price'];
            if ($price < 0) {
                return $this->errorResponse($response, 'Price must be positive', 400);
            }
            $updates[] = 'price = ?';
            $params[] = $price;
        }

        if (isset($data['currency'])) {
            $currency = strtoupper(trim((string) $data['currency']));
            if ($currency === '') {
                return $this->errorResponse($response, 'Invalid currency', 400);
            }
            $updates[] = 'currency = ?';
            $params[] = $currency;
        }

        if (isset($data['billing_cycle_days'])) {
            $days = (int) $data['billing_cycle_days'];
            if ($days < 1) {
                return $this->errorResponse($response, 'Billing cycle days must be positive', 400);
            }
            $updates[] = 'billing_cycle_days = ?';
            $params[] = $days;
        }

        if (isset($data['is_active'])) {
            $updates[] = 'is_active = ?';
            $params[] = (int) !!$data['is_active'];
        }

        if (empty($updates)) {
            return $this->errorResponse($response, 'No fields to update', 400);
        }

        try {
            $params[] = $code;

            $stmt = $this->db->prepare(
                'UPDATE plans SET ' . implode(', ', $updates) . ', updated_at = NOW() WHERE code = ?'
            );
            $stmt->execute($params);

            if ($stmt->rowCount() === 0) {
                return $this->errorResponse($response, 'Plan not found or no changes applied', 404);
            }

            return $this->successResponse($response, [
                'code' => $code
            ], 200);
        } catch (Throwable $e) {
            $this->logger->error('Failed to update plan', [
                'code' => $code,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'Failed to update plan', 500);
        }
    }

    /** Delete a non-trial plan unless it still has trial or active subscriptions. */
    public function deletePlan(Request $request, Response $response, array $args): Response
    {
        $code = strtolower(trim((string) ($args['code'] ?? '')));

        if ($code === 'trial') {
            return $this->errorResponse($response, 'Cannot delete trial plan', 400);
        }

        try {
            $stmt = $this->db->prepare("SELECT id FROM plans WHERE code = ?");
            $stmt->execute([$code]);
            $plan = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$plan) {
                return $this->errorResponse($response, 'Plan not found', 404);
            }

            $stmt = $this->db->prepare("
                SELECT COUNT(*) AS count
                FROM subscriptions
                WHERE plan_id = ?
                  AND status IN ('trial', 'active')
            ");
            $stmt->execute([(int) $plan['id']]);
            $subscriptionCount = (int) (($stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0));

            if ($subscriptionCount > 0) {
                return $this->errorResponse(
                    $response,
                    "Cannot delete plan with {$subscriptionCount} active subscriptions",
                    400
                );
            }

            $stmt = $this->db->prepare("DELETE FROM plans WHERE id = ?");
            $stmt->execute([(int) $plan['id']]);

            return $this->successResponse($response, [
                'code' => $code
            ], 200);
        } catch (Throwable $e) {
            $this->logger->error('Failed to delete plan', [
                'code' => $code,
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'Failed to delete plan', 500);
        }
    }
}
