<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Handlers\BaseHandler;
use PDO;

/**
 * Builds aggregated tenant data payloads for POS, payment, session, and management screens.
 *
 * Uses PDO for operational lookups and SettingsRepository for canonical setting keys.
 * Routes are registered in app/routes/bootstrap.php.
 */
class BootstrapHandler extends BaseHandler
{
    /** Return active branches for a tenant in creation order. */
    protected function getActiveBranches($tenantId)
    {
        $query = "SELECT id, name FROM branches WHERE tenant_id = :tenant_id AND active = 1 ORDER BY created_at ASC, id ASC";
        $stmt = $this->db->prepare($query);
        $stmt->execute(['tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Return active tenant payment methods with their payment classifications. */
    protected function getActivePaymentMethods($tenantId)
    {
        $query = "SELECT id, name, kind FROM payment_methods WHERE tenant_id = :tenant_id AND is_active = 1 ORDER BY name";
        $stmt = $this->db->prepare($query);
        $stmt->execute(['tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Return active tenant users with the requested fixed column list. */
    protected function getActiveUsers($tenantId, $fieldsArray = ['id', 'name'])
    {
        $fields = implode(', ', $fieldsArray);
        $query = "SELECT {$fields} FROM users WHERE tenant_id = :tenant_id AND status = 'active' ORDER BY name";
        $stmt = $this->db->prepare($query);
        $stmt->execute(['tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Aggregate branches, categories, payment methods, cashier session, and POS settings. */
    public function getPosData($request, $response)
    {
        try {
            $user = $request->getAttribute('user');
            $userId = is_array($user) && isset($user['id']) ? (int) $user['id'] : null;
            $userBranchId = is_array($user) && isset($user['branch_id']) ? (int) $user['branch_id'] : null;
            $tenantId = $this->extractTenantId($request);

            if (!$tenantId) {
                return $this->errorResponse($response, 'معرف المستأجر مطلوب', 400);
            }

            $branches = $this->getActiveBranches($tenantId);

            $categoriesQuery = "SELECT id, name FROM categories WHERE tenant_id = :tenant_id AND active = 1 ORDER BY name";
            $categoriesStmt = $this->db->prepare($categoriesQuery);
            $categoriesStmt->execute(['tenant_id' => $tenantId]);
            $categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

            $paymentMethods = $this->getActivePaymentMethods($tenantId);

            $currentSession = null;
            if ($userBranchId) {
                $sessionQuery = "SELECT * FROM cashier_sessions 
                                WHERE branch_id = :branch_id 
                                AND user_id = :user_id 
                                AND status = 'open' 
                                ORDER BY start_time DESC 
                                LIMIT 1";
                $sessionStmt = $this->db->prepare($sessionQuery);
                $sessionStmt->execute([
                    'branch_id' => $userBranchId,
                    'user_id' => $userId
                ]);
                $currentSession = $sessionStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            $settingsRepo = $this->getSettingsRepo();
            $settings = [
                'sales.tax_rate'              => $settingsRepo->get($tenantId, 'sales.tax_rate',              '0'),
                'sales.require_approval'      => $settingsRepo->get($tenantId, 'sales.require_approval',      'false'),
                'pos.sessions.enabled'        => $settingsRepo->get($tenantId, 'pos.sessions.enabled',        'false'),
                'pos.sessions.mode'           => $settingsRepo->get($tenantId, 'pos.sessions.mode',           'optional'),
            ];

            return $this->jsonResponse($response, [
                'status' => 'success',
                'data' => [
                    'branches' => $branches,
                    'categories' => $categories,
                    'paymentMethods' => $paymentMethods,
                    'currentSession' => $currentSession,
                    'settings' => $settings,
                    'userBranch' => $userBranchId
                ],
                'meta' => [
                    'cached_at' => date('Y-m-d H:i:s'),
                    'ttl' => 300 // 5 minutes
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Bootstrap POS Data Error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return $this->jsonResponse($response, [
                'status' => 'error',
                'message' => 'فشل في تحميل بيانات نقطة البيع',
            ], 500);
        }
    }

    /** Aggregate payment methods, up to 500 customers/suppliers, active users, and currency settings. */
    public function getPaymentsPageData($request, $response)
    {
        try {
            $tenantId = $this->extractTenantId($request);

            if (!$tenantId) {
                return $this->errorResponse($response, 'معرف المستأجر مطلوب', 400);
            }

            $paymentMethods = $this->getActivePaymentMethods($tenantId);

            $customersQuery = "SELECT id, name, phone, email FROM customers 
                              WHERE tenant_id = :tenant_id 
                              AND active = 1 
                              ORDER BY name 
                              LIMIT 500";
            $customersStmt = $this->db->prepare($customersQuery);
            $customersStmt->execute(['tenant_id' => $tenantId]);
            $customers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

            $suppliersQuery = "SELECT id, name, phone, email FROM suppliers 
                              WHERE tenant_id = :tenant_id 
                              AND active = 1 
                              ORDER BY name 
                              LIMIT 500";
            $suppliersStmt = $this->db->prepare($suppliersQuery);
            $suppliersStmt->execute(['tenant_id' => $tenantId]);
            $suppliers = $suppliersStmt->fetchAll(PDO::FETCH_ASSOC);

            $users = $this->getActiveUsers($tenantId, ['id', 'name', 'email', 'role_id']);

            $settingsRepo = $this->getSettingsRepo();
            $settings = [
                'company.currency'        => $settingsRepo->get($tenantId, 'company.currency',        'EGP'),
                'company.currency_code'   => $settingsRepo->get($tenantId, 'company.currency_code',   'EGP'),
                'company.currency_symbol' => $settingsRepo->get($tenantId, 'company.currency_symbol', 'ج.م'),
                'sales.tax_rate'          => $settingsRepo->get($tenantId, 'sales.tax_rate',          '0'),
            ];

            return $this->jsonResponse($response, [
                'status' => 'success',
                'data' => [
                    'paymentMethods' => $paymentMethods,
                    'customers' => $customers,
                    'suppliers' => $suppliers,
                    'users' => $users,
                    'settings' => $settings
                ],
                'meta' => [
                    'cached_at' => date('Y-m-d H:i:s'),
                    'ttl' => 300
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Bootstrap Payments Data Error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return $this->jsonResponse($response, [
                'status' => 'error',
                'message' => 'فشل في تحميل بيانات الدفعات',
            ], 500);
        }
    }

    /** Aggregate active branches and users for session management screens. */
    public function getSessionsPageData($request, $response)
    {
        try {
            $tenantId = $this->extractTenantId($request);

            if (!$tenantId) {
                return $this->errorResponse($response, 'معرف المستأجر مطلوب', 400);
            }

            $branches = $this->getActiveBranches($tenantId);

            $users = $this->getActiveUsers($tenantId);

            return $this->jsonResponse($response, [
                'status' => 'success',
                'data' => [
                    'branches' => $branches,
                    'users' => $users
                ],
                'meta' => [
                    'cached_at' => date('Y-m-d H:i:s'),
                    'ttl' => 300
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Bootstrap Sessions Data Error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return $this->jsonResponse($response, [
                'status' => 'error',
                'message' => 'فشل في تحميل بيانات الجلسات',
            ], 500);
        }
    }

    /** Aggregate shared branch/payment data and purchase- or sale-specific contacts. */
    public function getManagementData($request, $response, $args)
    {
        try {
            $type = $args['type'] ?? 'purchase';
            $tenantId = $this->extractTenantId($request);

            if (!$tenantId) {
                return $this->errorResponse($response, 'معرف المستأجر مطلوب', 400);
            }

            $branches = $this->getActiveBranches($tenantId);
            $paymentMethods = $this->getActivePaymentMethods($tenantId);

            $data = [
                'branches' => $branches,
                'paymentMethods' => $paymentMethods
            ];

            if ($type === 'purchase') {
                $suppliersQuery = "SELECT id, name, phone, email FROM suppliers WHERE tenant_id = :tenant_id AND active = 1 ORDER BY name LIMIT 500";
                $suppliersStmt = $this->db->prepare($suppliersQuery);
                $suppliersStmt->execute(['tenant_id' => $tenantId]);
                $data['suppliers'] = $suppliersStmt->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($type === 'sale') {
                $customersQuery = "SELECT id, name, phone, email FROM customers WHERE tenant_id = :tenant_id AND active = 1 ORDER BY name LIMIT 500";
                $customersStmt = $this->db->prepare($customersQuery);
                $customersStmt->execute(['tenant_id' => $tenantId]);
                $data['customers'] = $customersStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            return $this->jsonResponse($response, [
                'status' => 'success',
                'data' => $data,
                'meta' => [
                    'type' => $type,
                    'cached_at' => date('Y-m-d H:i:s'),
                    'ttl' => 300
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Bootstrap Management Data Error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return $this->jsonResponse($response, [
                'status' => 'error',
                'message' => 'فشل في تحميل بيانات الإدارة',
            ], 500);
        }
    }
}
