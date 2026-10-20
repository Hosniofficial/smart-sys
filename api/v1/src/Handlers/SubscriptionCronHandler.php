<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Services\Mailer;
use App\Services\MonologHandler;

/**
 * Processes subscription-expiry reminders and expiration transitions.
 *
 * The cron endpoint authenticates with the X-Cron-Token header against
 * CRON_SECRET. Reminder and expiration queries use PDO; Mailer sends notices
 * to tenant administrators, and subscription_events records processing results.
 * The endpoint is registered at /api/v1/admin/subscriptions/cron in config/routes.php.
 */
class SubscriptionCronHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('subscription_cron');
    }

    /** Authenticate the scheduled request and run reminder and expiration passes. */
    public function processCron(Request $request, Response $response): Response
    {
        try {
            $secret = $request->getHeaderLine('X-Cron-Token');
            $expectedSecret = $_ENV['CRON_SECRET'] ?? '';

            if (empty($secret) || !hash_equals($secret, $expectedSecret)) {
                return $this->errorResponse($response, 'Forbidden', 403);
            }

            $mailer = new Mailer();
            $results = [
                'reminders_sent' => 0,
                'expired_processed' => 0
            ];

            $results['reminders_sent'] = $this->processReminders($mailer);
            $results['expired_processed'] = $this->processExpirations($mailer);

            $this->logger->info('Subscription cron completed', $results);

            return $this->successResponse($response, $results);
        } catch (\Throwable $e) {
            $this->logger->error('Subscription cron failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse($response, 'Cron processing failed', 500);
        }
    }

    /** Send at most one recorded reminder event per subscription per calendar day. */
    private function processReminders(Mailer $mailer): int
    {
        $count = 0;

        $sqlRem = "
            SELECT
                s.id,
                s.tenant_id,
                s.end_date,
                p.name AS plan_name,
                p.code AS plan_code
            FROM subscriptions s
            JOIN plans p ON p.id = s.plan_id
            WHERE s.status IN ('trial', 'active')
              AND DATEDIFF(s.end_date, NOW()) = 3
        ";

        $reminders = $this->db->query($sqlRem)->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($reminders as $reminder) {
            $chk = $this->db->prepare(
                "SELECT COUNT(*)
                 FROM subscription_events
                 WHERE subscription_id = ?
                   AND event_type = 'reminder_sent'
                   AND DATE(event_date) = CURDATE()"
            );
            $chk->execute([(int) $reminder['id']]);

            if ((int) $chk->fetchColumn() > 0) {
                continue;
            }

            $emails = $this->getTenantAdminEmails((int) $reminder['tenant_id']);

            if (!empty($emails) && $mailer->isEnabled()) {
                $subject = 'تذكير: اقترب انتهاء الاشتراك';
                $body = Mailer::renderReminderTemplate(
                    (string) $reminder['plan_name'],
                    (string) $reminder['end_date'],
                    '/upgrade'
                );

                $sent = $mailer->send($emails, $subject, $body);
                if ($sent) {
                    $count++;
                }
            }

            $ev = $this->db->prepare(
                "INSERT INTO subscription_events (
                    subscription_id, event_type, event_date, meta_json
                ) VALUES (
                    ?, 'reminder_sent', NOW(), ?
                )"
            );
            $ev->execute([
                (int) $reminder['id'],
                json_encode(['emails' => $emails], JSON_UNESCAPED_UNICODE)
            ]);
        }

        return $count;
    }

    /** Mark overdue subscriptions expired and send a post-commit notice. */
    private function processExpirations(Mailer $mailer): int
    {
        $count = 0;

        $sqlExp = "
            SELECT
                s.id,
                s.tenant_id,
                s.end_date,
                p.name AS plan_name,
                p.code AS plan_code
            FROM subscriptions s
            JOIN plans p ON p.id = s.plan_id
            WHERE s.status IN ('trial', 'active')
              AND s.end_date < NOW()
        ";

        $toExpire = $this->db->query($sqlExp)->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($toExpire as $expired) {
            $this->db->beginTransaction();

            try {
                $up = $this->db->prepare(
                    "UPDATE subscriptions
                     SET status = 'expired', updated_at = NOW()
                     WHERE id = ?"
                );
                $up->execute([(int) $expired['id']]);

                $emails = $this->getTenantAdminEmails((int) $expired['tenant_id']);

                $ev = $this->db->prepare(
                    "INSERT INTO subscription_events (
                        subscription_id, event_type, event_date, meta_json
                    ) VALUES (
                        ?, 'expired', NOW(), ?
                    )"
                );
                $ev->execute([
                    (int) $expired['id'],
                    json_encode([
                        'by' => 'cron',
                        'emails' => $emails
                    ], JSON_UNESCAPED_UNICODE)
                ]);

                $this->db->commit();
                $count++;

                if (!empty($emails) && $mailer->isEnabled()) {
                    $subject = 'تم انتهاء الاشتراك';
                    $body = Mailer::renderExpiredTemplate(
                        (string) $expired['plan_name'],
                        (string) $expired['end_date'],
                        '/upgrade'
                    );

                    $mailer->send($emails, $subject, $body);
                }
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                $this->logger->error('Failed to expire subscription', [
                    'subscription_id' => $expired['id'] ?? null,
                    'tenant_id' => $expired['tenant_id'] ?? null,
                    'message' => $e->getMessage()
                ]);
            }
        }

        return $count;
    }

    /** Return unique non-empty administrator email addresses for one tenant. */
    private function getTenantAdminEmails(int $tenantId): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT email
                 FROM users
                 WHERE tenant_id = ?
                   AND email IS NOT NULL
                   AND email <> ''
                   AND (role = 'admin' OR role = 'super_admin')"
            );
            $stmt->execute([$tenantId]);

            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            return array_values(array_unique(array_filter($rows)));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch admin emails', [
                'tenant_id' => $tenantId,
                'message' => $e->getMessage()
            ]);

            return [];
        }
    }
}
