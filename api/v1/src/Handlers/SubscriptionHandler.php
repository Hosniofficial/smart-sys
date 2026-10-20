<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;

/**
 * Provides tenant subscription summaries, history, and plan listings.
 *
 * Subscription and plan data is read through PDO; tenant identity comes from
 * BaseHandler, and request failures are logged through the subscription logger.
 * No route registration for this handler was found in the current route files.
 */
class SubscriptionHandler extends BaseHandler
{
    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('subscription');
    }

    /** Return the tenant's preferred subscription, or an inactive default. */
    public function getMySubscription(Request $request, Response $response): Response
    {
        try {
            $tenantId = $this->extractTenantId($request);

            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $stmt = $this->db->prepare(
                "SELECT
                    s.*,
                    p.code AS plan_code,
                    p.name AS plan_name,
                    p.price,
                    p.currency,
                    p.billing_cycle_days
                 FROM subscriptions s
                 JOIN plans p ON p.id = s.plan_id
                 WHERE s.tenant_id = ?
                 ORDER BY (s.status IN ('trial','active')) DESC, s.end_date DESC, s.id DESC
                 LIMIT 1"
            );
            $stmt->execute([$tenantId]);
            $sub = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if (!$sub) {
                $result = [
                    'current' => [
                        'status' => 'inactive',
                        'plan_code' => null,
                        'plan_name' => null,
                        'price' => 0,
                        'currency' => 'USD',
                        'billing_cycle_days' => 30,
                        'end_date' => null,
                        'days_left' => 0
                    ]
                ];

                return $this->successResponse($response, $result);
            }

            $now = new DateTimeImmutable('now');
            $end = new DateTimeImmutable($sub['end_date']);
            $daysLeft = max(0, (int) $now->diff($end)->format('%a'));

            $result = [
                'current' => array_merge($sub, [
                    'days_left' => $daysLeft
                ])
            ];

            return $this->successResponse($response, $result);
        } catch (\Exception $e) {
            $this->logger->error('SubscriptionHandler::getMySubscription error', [
                'message' => $e->getMessage()
            ]);

            $result = [
                'current' => [
                    'status' => 'inactive',
                    'plan_code' => null,
                    'plan_name' => null,
                    'price' => 0,
                    'currency' => 'USD',
                    'billing_cycle_days' => 30,
                    'end_date' => null,
                    'days_left' => 0
                ]
            ];

            return $this->successResponse($response, $result);
        }
    }

    /** Return active plans without requiring a tenant identifier. */
    public function listPublicPlans(Request $request, Response $response): Response
    {
        try {
            $stmt = $this->db->query(
                "SELECT code, name, price, currency, billing_cycle_days, is_active
                 FROM plans
                 WHERE is_active = 1
                 ORDER BY FIELD(code, 'trial', 'monthly', 'yearly'), name"
            );

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return $this->successResponse($response, $rows, 200);
        } catch (\Exception $e) {
            $this->logger->error('SubscriptionHandler::listPublicPlans error', [
                'message' => $e->getMessage()
            ]);

            return $this->errorResponse($response, 'Failed to fetch plans', 500);
        }
    }

}
