<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use App\Utils\SuperAdminHelper;
use App\Utils\ApiResponseEnvelope;
use App\Services\MonologHandler;

/**
 * Gates tenant requests on subscription status and end date.
 *
 * It may create an automatic trial, mark an overdue subscription expired,
 * record reminder/expiry events, or return a 402 subscription-required response.
 */
class SubscriptionMiddleware implements MiddlewareInterface
{
    private \PDO $db;
    private $logger;

    public function __construct($db)
    {
        $this->db = $db instanceof \Database ? $db->pdo : $db;
        $this->logger = MonologHandler::getInstance('subscription');
    }

    /** Resolve the tenant subscription before forwarding the request. */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $tenantId = $request->getAttribute('tenant_id');

        // Bypass for super_admin
        try {
            $user = $request->getAttribute('user');
            if (SuperAdminHelper::is(is_array($user) ? $user : null)) {
                return $handler->handle($request);
            }
        } catch (\Throwable $e) { /* ignore user extraction errors */
        }

        if (!$tenantId) {
            $user = $request->getAttribute('user');
            if ($user && isset($user['tenant_id'])) {
                $tenantId = $user['tenant_id'];
            } else {
                return $this->upgradeResponse('Tenant ID missing');
            }
        }

        try {
            // Find active or trial subscription
            $sub = null;
            try {
                $stmt = $this->db->prepare("SELECT s.*, p.name AS plan_name, p.billing_cycle_days, p.code AS plan_code
                    FROM subscriptions s
                    JOIN plans p ON p.id = s.plan_id
                    WHERE s.tenant_id = ? AND s.status IN ('trial','active')
                    ORDER BY s.id DESC LIMIT 1");
                $stmt->execute([$tenantId]);
                $sub = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            } catch (\Throwable $e) {
                // Fallback if plans table schema differs or join fails
                $stmt2 = $this->db->prepare("SELECT * FROM subscriptions WHERE tenant_id = ? AND status IN ('trial','active') ORDER BY id DESC LIMIT 1");
                $stmt2->execute([$tenantId]);
                $sub = $stmt2->fetch(\PDO::FETCH_ASSOC) ?: null;
            }

            // Check if there's an expired subscription first
            $expiredSub = null;
            try {
                $stmt = $this->db->prepare("SELECT s.*, p.name AS plan_name, p.billing_cycle_days, p.code AS plan_code
                    FROM subscriptions s
                    JOIN plans p ON p.id = s.plan_id
                    WHERE s.tenant_id = ? AND s.status = 'expired'
                    ORDER BY s.id DESC LIMIT 1");
                $stmt->execute([$tenantId]);
                $expiredSub = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            } catch (\Throwable $e) {
                // Fallback
                $stmt2 = $this->db->prepare("SELECT * FROM subscriptions WHERE tenant_id = ? AND status = 'expired' ORDER BY id DESC LIMIT 1");
                $stmt2->execute([$tenantId]);
                $expiredSub = $stmt2->fetch(\PDO::FETCH_ASSOC) ?: null;
            }

            // If there's an expired subscription, block access
            if ($expiredSub) {
                $this->markExpiredIfNeeded((int)$expiredSub['id']);
                return $this->upgradeResponse('Subscription expired', [
                    'plan_id' => $expiredSub['plan_id'] ?? null,
                    'expired_at' => $expiredSub['end_date'] ?? null,
                ]);
            }

            $now = new \DateTimeImmutable('now');

            if (!$sub) {
                // Only create trial if no expired subscription exists
                $trialPlanId = $this->getPlanIdByCode('trial');
                if ($trialPlanId === 0) {
                    return $this->upgradeResponse('No plans available');
                }
                $start = $now;
                $end = $now->modify('+14 days');
                $ins = $this->db->prepare("INSERT INTO subscriptions (tenant_id, plan_id, start_date, end_date, status, payment_status, auto_renew) VALUES (?, ?, ?, ?, 'trial', 'n/a', 0)");
                $ins->execute([$tenantId, $trialPlanId, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
                $subId = (int)$this->db->lastInsertId();
                $this->insertEvent($subId, 'created', ['auto_trial' => true]);
                $sub = [
                    'id' => $subId,
                    'plan_id' => $trialPlanId,
                    'end_date' => $end->format('Y-m-d H:i:s'),
                ];
            }

            $endAt = new \DateTimeImmutable($sub['end_date']);
            if ($endAt < $now) {
                // Expired -> block
                $this->markExpiredIfNeeded((int)$sub['id']);
                return $this->upgradeResponse('Subscription expired', [
                    'plan_id' => $sub['plan_id'] ?? null,
                    'expired_at' => $sub['end_date'] ?? null,
                ]);
            }

            // Optionally send reminder if 3 days left (best-effort, once per day)
            $diffDays = (int)$now->diff($endAt)->format('%a');
            if ($diffDays === 3) {
                $this->maybeSendReminder((int)$sub['id']);
            }

        } catch (\Throwable $e) {
            $this->logger->error('Subscription verification failed; request blocked.', [
                'tenant_id' => (int) $tenantId,
                'exception' => $e,
            ]);
            return $this->serviceUnavailableResponse();
        }

        return $handler->handle($request);
    }

    private function serviceUnavailableResponse(): ResponseInterface
    {
        $response = new Response(503);
        $response->getBody()->write(json_encode(
            ApiResponseEnvelope::normalize([
                'status' => 'error',
                'message' => 'Subscription verification is temporarily unavailable.',
            ], 503),
            JSON_UNESCAPED_UNICODE
        ));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function upgradeResponse(string $message, array $extra = []): ResponseInterface
    {
        $res = new Response(402);
        $payload = array_merge([
            'status' => 'subscription_required',
            'code' => 'SUBSCRIPTION_REQUIRED',
            'message' => $message
        ], $extra);
        $res->getBody()->write(json_encode(
            ApiResponseEnvelope::normalize($payload, 402),
            JSON_UNESCAPED_UNICODE
        ));
        return $res->withHeader('Content-Type', 'application/json');
    }

    private function getPlanIdByCode(string $code): int
    {
        $stmt = $this->db->prepare("SELECT id FROM plans WHERE code = ? LIMIT 1");
        $stmt->execute([$code]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    private function insertEvent(int $subscriptionId, string $type, array $meta = []): void
    {
        try {
            $stmt = $this->db->prepare("INSERT INTO subscription_events (subscription_id, event_type, event_date, meta_json) VALUES (?, ?, NOW(), ?)");
            $stmt->execute([$subscriptionId, $type, json_encode($meta, JSON_UNESCAPED_UNICODE)]);
        } catch (\Throwable $e) { /* ignore */
        }
    }

    private function seedPlans(): void
    {
        // Idempotent upserts (prices may be updated elsewhere; keep defaults as guard)
        $this->db->exec("INSERT INTO plans (code, name, price, currency, billing_cycle_days, features_json, is_active)
            VALUES ('trial','Trial 14 days',0,'SAR',14, JSON_OBJECT('all_features', true),1)
            ON DUPLICATE KEY UPDATE name=VALUES(name), is_active=VALUES(is_active)");
        $this->db->exec("INSERT INTO plans (code, name, price, currency, billing_cycle_days, features_json, is_active)
            VALUES ('monthly','Monthly Plan',300,'SAR',30, JSON_OBJECT('all_features', true),1)
            ON DUPLICATE KEY UPDATE name=VALUES(name), is_active=VALUES(is_active)");
        $this->db->exec("INSERT INTO plans (code, name, price, currency, billing_cycle_days, features_json, is_active)
            VALUES ('yearly','Yearly Plan',2500,'SAR',365, JSON_OBJECT('all_features', true),1)
            ON DUPLICATE KEY UPDATE name=VALUES(name), is_active=VALUES(is_active)");
    }

    private function alignWithAssignedPlan(int $tenantId): void
    {
        try {
            // Try to read assigned plan from companies or tenants table
            $assigned = null;
            // companies
            try {
                $st = $this->db->prepare("SELECT plan_id, status FROM companies WHERE id = ? LIMIT 1");
                if ($st->execute([$tenantId])) {
                    $row = $st->fetch(\PDO::FETCH_ASSOC);
                    if ($row && !empty($row['plan_id'])) {
                        $assigned = ['plan_id' => (int)$row['plan_id'], 'status' => strtolower((string)$row['status'])];
                    }
                }
            } catch (\Throwable $e) { /* table may not exist */
            }
            // tenants
            if (!$assigned) {
                try {
                    $st = $this->db->prepare("SELECT plan_id, status FROM tenants WHERE id = ? LIMIT 1");
                    if ($st->execute([$tenantId])) {
                        $row = $st->fetch(\PDO::FETCH_ASSOC);
                        if ($row && !empty($row['plan_id'])) {
                            $assigned = ['plan_id' => (int)$row['plan_id'], 'status' => strtolower((string)$row['status'])];
                        }
                    }
                } catch (\Throwable $e) { /* ignore */
                }
            }

            if (!$assigned || !in_array($assigned['status'] ?? 'active', ['active','trial'], true)) {
                return; // nothing to align
            }

            // Get current latest subscription
            $stmt = $this->db->prepare("SELECT * FROM subscriptions WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$tenantId]);
            $current = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

            // If already matches and active/trial, keep
            if ($current && (int)$current['plan_id'] === (int)$assigned['plan_id'] && in_array($current['status'], ['active','trial'], true)) {
                return;
            }

            // Resolve plan cycle
            $plSt = $this->db->prepare("SELECT id, billing_cycle_days FROM plans WHERE id = ? LIMIT 1");
            $plSt->execute([$assigned['plan_id']]);
            $pl = $plSt->fetch(\PDO::FETCH_ASSOC);
            if (!$pl) {
                return;
            }
            $cycle = max(1, (int)$pl['billing_cycle_days']);

            $now = new \DateTimeImmutable('now');
            $start = $now->format('Y-m-d H:i:s');
            $end = $now->modify('+' . $cycle . ' days')->format('Y-m-d H:i:s');

            if ($current) {
                // Update existing to align
                $up = $this->db->prepare("UPDATE subscriptions SET plan_id = ?, start_date = ?, end_date = ?, status = 'active', payment_status = IF(payment_status='n/a','paid',payment_status), updated_at = NOW() WHERE id = ?");
                $up->execute([(int)$assigned['plan_id'], $start, $end, (int)$current['id']]);
                $this->insertEvent((int)$current['id'], 'activated', ['aligned_with_assigned_plan' => true, 'plan_id' => (int)$assigned['plan_id']]);
            } else {
                // Create a new active subscription
                $ins = $this->db->prepare("INSERT INTO subscriptions (tenant_id, plan_id, start_date, end_date, status, payment_status, auto_renew) VALUES (?, ?, ?, ?, 'active', 'paid', 0)");
                $ins->execute([$tenantId, (int)$assigned['plan_id'], $start, $end]);
                $this->insertEvent((int)$this->db->lastInsertId(), 'activated', ['aligned_with_assigned_plan' => true, 'plan_id' => (int)$assigned['plan_id']]);
            }
        } catch (\Throwable $e) {
        }
    }

    private function markExpiredIfNeeded(int $subscriptionId): void
    {
        try {
            $stmt = $this->db->prepare("UPDATE subscriptions SET status = 'expired' WHERE id = ? AND status <> 'expired'");
            $stmt->execute([$subscriptionId]);
            $this->insertEvent($subscriptionId, 'expired');
        } catch (\Throwable $e) { /* ignore */
        }
    }

    private function maybeSendReminder(int $subscriptionId): void
    {
        try {
            // Avoid duplicate reminder on same day
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM subscription_events WHERE subscription_id = ? AND event_type = 'reminder_sent' AND DATE(event_date) = CURDATE()");
            $stmt->execute([$subscriptionId]);
            $count = (int)$stmt->fetchColumn();
            if ($count === 0) {
                $this->insertEvent($subscriptionId, 'reminder_sent');
            }
        } catch (\Throwable $e) { /* ignore */
        }
    }
}
