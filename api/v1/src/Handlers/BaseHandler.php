<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Throwable;
use App\Services\AccountingService;
use App\Services\MonologHandler;
use App\Services\Transaction\TransactionManager;
use App\Services\ServiceFactory;
use App\Traits\AuthorizesRequests;
use App\Utils\RequestHelper;
use App\Repositories\SettingsRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class BaseHandler
{
    use AuthorizesRequests;
    protected ?PDO $db;
    protected ?int $tenantId = null;
    protected ?array $currentUser = null;  // ← Store current user context for audit logging
    private ?TransactionManager $txManager = null;
    protected $logger;
    private ?AccountingService $accounting = null;
    private ?SettingsRepository $settingsRepo = null;
    private ?AuditHandler $audit = null;
    private ?ServiceFactory $services = null;

    public function __construct(?PDO $db = null)
    {
        $this->db     = $db;
        $this->logger = MonologHandler::getInstance('handler');
        // All other properties are lazy-initialized on first access
        // to avoid memory exhaustion when many handlers are registered in the DI container.
    }

    // ── Lazy accessors ────────────────────────────────────────────────────────
    // These are called internally AND by subclasses via $this->accounting etc.
    // The properties are declared above; they are populated on first use only.

    protected function getAccounting(): AccountingService
    {
        return $this->accounting ??= new AccountingService($this->db);
    }

    protected function getSettingsRepo(): SettingsRepository
    {
        return $this->settingsRepo ??= new SettingsRepository($this->db);
    }

    protected function getAudit(): AuditHandler
    {
        if ($this->audit === null) {
            $this->audit = new AuditHandler($this->db);
        }
        // Always sync currentUser so logAction() can access it even if set after first getAudit() call
        if ($this->currentUser !== null) {
            $this->audit->currentUser = $this->currentUser;
        }
        return $this->audit;
    }

    protected function getServices(): ServiceFactory
    {
        return $this->services ??= new ServiceFactory($this->db);
    }

    protected function getTxManager(): ?TransactionManager
    {
        return $this->txManager ??= ($this->db ? new TransactionManager($this->db) : null);
    }

    /**
     * Magic getter — allows subclasses to use $this->accounting, $this->audit,
     * $this->services, $this->settingsRepo, $this->txManager as if they were
     * eagerly initialized, while actually lazy-loading them on first access.
     *
     * This avoids the memory exhaustion caused by constructing all services
     * upfront for every handler registered in the DI container.
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            'accounting'   => $this->getAccounting(),
            'settingsRepo' => $this->getSettingsRepo(),
            'audit'        => $this->getAudit(),
            'services'     => $this->getServices(),
            'txManager'    => $this->getTxManager(),
            default        => throw new \RuntimeException("Undefined property: {$name}"),
        };
    }

    protected function throwUnauthorizedBranch(): void
    {
        throw new \Exception('branch_id not permitted for your account', 403);
    }

    /**
     * تعيين قيمة tenantId باستخدام extractTenantId الموحد
     */
    protected function setTenantId(Request $request): void
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            throw new \Exception('مطلوب معرف المستأجر (Tenant ID).');
        }

        $this->tenantId = (int) $tenantId;
    }

    /**
     * تعيين قيمة tenantId باستخدام extractTenantId الموحد
     */
    protected function requireTenantContext(Request $request): array
    {
        $this->validateAuth();

        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            throw new \Exception('مطلوب معرف المستأجر (Tenant ID).');
        }

        $this->tenantId = (int) $tenantId;
        $userId = $this->extractUserId($request);

        return [
            'tenant_id' => (int) $tenantId,
            'user_id' => $userId,
        ];
    }

    /**
     * Simple tenant ID extraction and validation
     * Returns tenant ID or throws exception
     *
     * @return int Validated tenant ID
     * @throws \Exception If tenant ID is missing
     */
    protected function requireTenantId(Request $request, Response $response): ?int
    {
        $tenantId = $this->extractTenantId($request);
        if (!$tenantId) {
            throw new \Exception('مطلوب معرف المستأجر (Tenant ID).', 403);
        }
        return (int) $tenantId;
    }

    /**
     * Build filtered SQL WHERE clause and bindings
     * Automatically adds tenant_id filter
     *
     * Usage:
     * $filters = $this->buildFilteredQuery($tenantId, [
     *     'event_type' => 'event_type',      // filter_key => db_column
     *     'level' => 'level',
     *     'date_from' => ['timestamp', '>='],  // with operator
     *     'date_to' => ['timestamp', '<=']
     * ], $params);
     *
     * $sql = "SELECT * FROM audit_log WHERE {$filters['where']}";
     * $stmt->execute($filters['bindings']);
     *
     * @param int $tenantId Tenant ID for isolation
     * @param array $allowedFilters Map of filter_key => column_name or [column, operator]
     * @param array $params Query parameters from request
     * @return array ['where' => string, 'bindings' => array]
     */
    protected function buildFilteredQuery(int $tenantId, array $allowedFilters, array $params): array
    {
        $where = ['tenant_id = ?'];
        $bindings = [$tenantId];

        foreach ($allowedFilters as $key => $config) {
            if (empty($params[$key])) {
                continue;
            }

            if (is_array($config)) {
                // [$column, $operator]
                [$column, $operator] = $config;
                $where[] = "$column $operator ?";
                $bindings[] = $params[$key];
            } else {
                // Simple equality
                $where[] = "$config = ?";
                $bindings[] = $params[$key];
            }
        }

        return [
            'where' => implode(' AND ', $where),
            'bindings' => $bindings
        ];
    }

    /**
     * قراءة قيمة إعداد واحدة من جدول settings
     */
    protected function getSetting(int $tenantId, string $key): ?string
    {
        if ($this->db) {
            return $this->getSettingsRepo()->get($tenantId, $key);
        }
        return null;
    }

    /**
     * قراءة إعداد منطقي (boolean) بقيمة افتراضية
     */
    protected function getBoolSetting(int $tenantId, string $key, bool $default = false): bool
    {
        if ($this->db) {
            return $this->getSettingsRepo()->getBool($tenantId, $key, $default);
        }
        return $default;
    }

    /**
     * هل نظام جلسات الكاشير مفعّل؟
    * المفتاح: pos.sessions.enabled (افتراضي: false — الجلسات اختيارية وتُفعّل صراحةً)
     */
    protected function isSessionsEnabled(int $tenantId): bool
    {
        return $this->getBoolSetting($tenantId, 'pos.sessions.enabled', false);
    }

    /**
     * تحديد ما إذا كانت طريقة الدفع نقدية بالاعتماد على payment_methods.kind فقط.
     */
    protected function isCashMethod(int $paymentMethodId, ?int $tenantId = null): bool
    {
        if (!$this->db) {
            return false;
        }

        try {
            $tid = $tenantId ?? $this->tenantId;

            $stmt = $this->db->prepare("
                    SELECT kind
                    FROM payment_methods
                    WHERE id = ? AND tenant_id = ?
                    LIMIT 1
                ");
            $stmt->execute([$paymentMethodId, $tid]);

            $kind = $stmt->fetchColumn();
            $kind = $kind ? strtolower((string) $kind) : null;

            return in_array($kind, ['cash', 'check', 'bank_transfer'], true);
        } catch (Throwable $e) {
            $this->logger->warning('isCashMethod error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Returns a JSON response using PSR-7
     */
    protected function jsonResponse(
        Response $response,
        $data,
        int $status = 200,
        bool $autoLog = true
    ): Response {
        if ($autoLog && is_array($data) && ($data['status'] ?? null) === 'error') {
            $message = $data['message'] ?? 'Unknown error';
            $eventType = 'api_error';

            if (in_array($status, [401, 403], true)) {
                $eventType = 'unauthorized_access';
            } elseif ($status === 404) {
                $eventType = 'not_found';
            } elseif ($status >= 500) {
                $eventType = 'server_error';
            }

            $this->logger->warning($eventType, [
                'status'  => $status,
                'message' => $message,
                'data'    => $data,
            ]);
        }

        $response->getBody()->write(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }



    protected function errorResponse(
        Response $response,
        string $message,
        int $status = 400,
        array $extra = []
    ): Response {
        $data = array_merge([
            'status' => 'error',
            'message' => $message,
        ], $extra);

        return $this->jsonResponse($response, $data, $status, true);
    }

    protected function successResponse(
        Response $response,
        array|null $data = [],
        int $status = 200
    ): Response {
        return $this->jsonResponse($response, [
            'status' => 'success',
            'data' => $data,
        ], $status, false);
    }




    protected function validateAuth(): bool
    {
        if (!$this->db) {
            throw new \Exception('Database connection required for authentication validation');
        }

        return true;
    }

    /**
     * Extract tenant ID from JWT only (security measure)
     */
    protected function extractTenantId(Request $request): ?int
    {
        $tenantId = $request->getAttribute('tenant_id');
        return is_numeric($tenantId) ? (int) $tenantId : null;
    }

    /**
     * Centralized validation with JSON parsing and error collection
     */
    protected function extractAndValidateRequestData(Request $request, array $requiredFields = []): array
    {
        $data = $request->getParsedBody();
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid JSON data');
        }

        $errors = [];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode(', ', $errors));
        }

        return $data;
    }

    /**
     * Extract and set current user context from request
     * This is used by audit logging to capture the user_id
     */
    protected function setUserContext(Request $request): void
    {
        $user = $request->getAttribute('user');
        if (is_array($user) && isset($user['id'])) {
            // Store user context for audit logging
            if (!isset($this->currentUser)) {
                $this->currentUser = $user;
            }
        }
    }

    /**
     * استخراج معرف المستخدم (الكاشير) من الـ JWT المرفق في الطلب
     * وتخزين سياق المستخدم لـ audit logging
     */
    protected function extractUserId(Request $request): ?int
    {
        $user = $request->getAttribute('user');

        if (is_array($user)) {
            // Store user context for audit logging (if not already set)
            if (!isset($this->currentUser)) {
                $this->currentUser = $user;
            }
            return isset($user['id']) ? (int) $user['id'] : null;
        }

        return null;
    }

    /**
     * إيجاد جلسة كاشير مفتوحة حسب المستأجر والمخزن والكاشير.
     */
    protected function findOpenCashierSession(int $tenantId, int $branchId, ?int $cashierId = null): ?int
    {
        if (!$this->db) {
            return null;
        }

        if ($cashierId) {
            $sql = "
                SELECT id
                FROM cashier_sessions
                WHERE tenant_id = ?
                  AND branch_id = ?
                  AND cashier_id = ?
                  AND status = 'open'
                ORDER BY id DESC
                LIMIT 1
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$tenantId, $branchId, $cashierId]);
            $sid = $stmt->fetchColumn();

            if ($sid) {
                return (int) $sid;
            }
        }

        $sql = "
            SELECT id
            FROM cashier_sessions
            WHERE tenant_id = ?
              AND branch_id = ?
              AND status = 'open'
            ORDER BY id DESC
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$tenantId, $branchId]);
        $sid = $stmt->fetchColumn();

        return $sid ? (int) $sid : null;
    }

    /**
     * فرض وجود جلسة كاشير مفتوحة
     */
    protected function requireOpenCashierSession(int $tenantId, int $branchId, ?int $cashierId = null): int
    {
        $sid = $this->findOpenCashierSession($tenantId, $branchId, $cashierId);

        if (!$sid) {
            throw new \Exception('لا توجد جلسة كاشير مفتوحة لهذا المخزن. يرجى فتح جلسة قبل تسجيل الحركة النقدية.');
        }

        return $sid;
    }

    /**
     * تحقق مما إذا كان المستخدم الحالي مُعفى من شرط جلسة الكاشير المفتوحة.
     */
    protected function isCashierSessionExempt(Request $request): bool
    {
        try {
            $user = $request->getAttribute('user');
            $tenantId = $this->extractTenantId($request);
            $userId = null;
            $roles = [];
            $roleId = null;

            if (is_array($user)) {
                $userId = isset($user['id']) ? (int) $user['id'] : null;

                if (isset($user['roles']) && is_array($user['roles'])) {
                    $roles = $user['roles'];
                } elseif (isset($user['role']) && is_string($user['role'])) {
                    $roles = [$user['role']];
                }

                if (isset($user['role_id'])) {
                    $roleId = (int) $user['role_id'];
                }
            }

            if (!$tenantId) {
                return false;
            }

            $allowManagerOverride = $this->getBoolSetting($tenantId, 'pos.sessions.allow_manager_override', true);

            foreach ($roles as $r) {
                $r = strtolower((string) $r);
                if ($allowManagerOverride && in_array($r, ['admin', 'administrator', 'manager', 'owner', 'superadmin'], true)) {
                    return true;
                }
            }

            if ($allowManagerOverride && $roleId === 1) {
                return true;
            }

            if (!$this->db) {
                return false;
            }

            $stmt = $this->db->prepare("
                SELECT key_name, value
                FROM settings
                WHERE tenant_id = :tenant_id
                  AND key_name IN (
                    'cashier_session_exempt_users',
                    'cashier_session_exempt_roles',
                    'pos.sessions.exempt_users',
                    'pos.sessions.exempt_roles'
                  )
            ");
            $stmt->execute([':tenant_id' => $tenantId]);
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

            $usersCsvValue = $settings['cashier_session_exempt_users']
                ?? $settings['pos.sessions.exempt_users']
                ?? null;

            if (!empty($usersCsvValue) && $userId) {
                $ids = array_filter(array_map('trim', explode(',', (string) $usersCsvValue)), 'strlen');
                foreach ($ids as $idStr) {
                    if ((int) $idStr === $userId) {
                        return true;
                    }
                }
            }

            $rolesCsvValue = $settings['cashier_session_exempt_roles']
                ?? $settings['pos.sessions.exempt_roles']
                ?? null;

            if (!empty($rolesCsvValue) && !empty($roles)) {
                $allowed = array_map(
                    fn ($x) => strtolower(trim((string) $x)),
                    explode(',', (string) $rolesCsvValue)
                );

                foreach ($roles as $r) {
                    if (in_array(strtolower((string) $r), $allowed, true)) {
                        return true;
                    }
                }
            }

            return false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Resolve cost_center_id automatically
     */
    protected function resolveCostCenterId(Request $request): ?int
    {
        try {
            if (!$this->db) {
                return null;
            }

            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return null;
            }

            $user = $request->getAttribute('user');
            $userId = is_array($user) && isset($user['id'])
                ? (int) $user['id']
                : $this->extractUserId($request);

            $branchId = null;
            $parsedBody = $request->getParsedBody();

            if (is_array($parsedBody) && !empty($parsedBody['branch_id'])) {
                $branchId = (int) $parsedBody['branch_id'];
            }

            if (!$branchId) {
                $qp = $request->getQueryParams();
                if (!empty($qp['branch_id'])) {
                    $branchId = (int) $qp['branch_id'];
                }
            }

            if (!$branchId) {
                $hdr = $request->getHeaderLine('X-branch-Id');
                if ($hdr !== '') {
                    $branchId = (int) $hdr;
                }
            }

            if ($branchId) {
                $assignedBranchId = null;
                $roleId = null;

                if ($userId) {
                    $s = $this->db->prepare("
                        SELECT branch_id, role_id
                        FROM users
                        WHERE id = :id AND tenant_id = :tenant_id
                        LIMIT 1
                    ");
                    $s->execute([
                        ':id' => $userId,
                        ':tenant_id' => $tenantId
                    ]);

                    $row = $s->fetch(PDO::FETCH_ASSOC) ?: [];
                    $assignedBranchId = !empty($row['branch_id']) ? (int) $row['branch_id'] : null;
                    $roleId = isset($row['role_id']) ? (int) $row['role_id'] : null;
                }

                if ($assignedBranchId !== null && $assignedBranchId !== $branchId) {
                    $this->throwUnauthorizedBranch();
                }

                if ($assignedBranchId === null && $userId) {
                    try {
                        $check = $this->db->prepare("
                            SELECT 1
                            FROM branch_user_permissions
                            WHERE tenant_id = :tenant_id
                              AND user_id = :user_id
                              AND branch_id = :branch_id
                            LIMIT 1
                        ");
                        $check->execute([
                            ':tenant_id' => $tenantId,
                            ':user_id' => $userId,
                            ':branch_id' => $branchId
                        ]);

                        $ok = $check->fetchColumn();
                        if (!$ok && $roleId !== 1) {
                            $this->throwUnauthorizedBranch();
                        }
                    } catch (\PDOException $e) {
                        // Only PDOException from missing table is expected; ignore
                        $this->logger->debug('ACL table may not exist yet', [
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            // 🎯 توحيد معماري: كانت هذه الدالة تحتوي من هنا نسخة رابعة كاملة ومستقلة من
            // خوارزمية حل مركز التكلفة (branch → cost_center، ثم user's branch → cost_center،
            // ثم إعداد افتراضي) — منفصلة تماماً عن App\Services\CostCenter\CostCenterService
            // وعن AccountingService::resolveCostCenterForService. وبما أن applyDefaultCostCenter
            // (التي تستدعي هذه الدالة) تُستدعى فعلياً من SalesHandler وPurchasesHandler و
            // ReturnsHandler وCashVouchersHandler وJournalEntriesHandler *قبل* وصول البيانات
            // لأي من تلك الخدمتين، كانت هذه هي الخوارزمية الفعلية الحاكمة عملياً في معظم
            // النظام — بينما التوحيد السابق بين CostCenterService وAccountingService كان
            // يعالج طبقة ثانوية نادراً ما تُستدعى فعلياً (لأن القيمة تصل إليها مملوءة مسبقاً).
            //
            // فحص التفويض (authorization) أعلاه يبقى هنا لأنه فعلاً مسؤولية طبقة HTTP/الصلاحيات
            // ولا علاقة له بمنطق "أي مركز تكلفة يخص هذا الفرع" — لكن الحل الفعلي أصبح يُفوَّض
            // بالكامل لنفس المحرك الموحّد بدل إعادة كتابته بـ SQL منفصل هنا.
            //
            // ⚠️ فرق سلوك مقصود: النسخة القديمة هنا لم يكن بها fallback "أول مركز تكلفة متاح"
            // (كانت تُرجع null مباشرة إذا فشل الفرع + فرع المستخدم + الإعداد الافتراضي).
            // التفويض لـ CostCenterService يضيف هذا الـ fallback تلقائياً (نفس سلوك كل باقي
            // النظام الآن) — تحسين في المرونة، وليس مجرد توحيد شكلي.
            try {
                $costCenterService = new \App\Services\CostCenter\CostCenterService($this->db, 'base_handler');
                return $costCenterService->resolve($tenantId, $userId, null, $branchId);
            } catch (Throwable $e) {
                // نفس السلوك التاريخي لهذه الدالة تحديداً: إرجاع null بدل رفع استثناء عند
                // تعذّر الحل الكامل (خلافاً لـ CostCenterService وحدها التي تفضّل الاستثناء).
                $this->logger->debug('resolveCostCenterId: CostCenterService resolution failed, returning NULL', [
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'error'     => $e->getMessage(),
                ]);
                return null;
            }
        } catch (Throwable $e) {
            if ((int) $e->getCode() === 403) {
                throw $e;
            }

            $this->logger->warning('resolveCostCenterId error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Apply default cost_center_id into payload if not provided
     */
    protected function applyDefaultCostCenter(array &$payload, Request $request): void
    {
        if (isset($payload['cost_center_id']) && $payload['cost_center_id'] !== null && $payload['cost_center_id'] !== '') {
            return;
        }

        $cc = $this->resolveCostCenterId($request);
        if ($cc !== null) {
            $payload['cost_center_id'] = $cc;
        }
    }

    /**
     * Execute an atomic database operation
     */
    protected function executeAtomicOperation(callable $operation, string $operationName = 'Database Operation'): mixed
    {
        if (!$this->db) {
            throw new \Exception('Database connection not initialized');
        }

        $this->db->beginTransaction();

        try {
            $result = $operation();
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
                $this->logger->error('Transaction rolled back', ['operation' => $operationName, 'error' => $e->getMessage()]);
            }
            throw $e;
        }
    }

    /**
     * Execute operation with validation checks before transaction
     */
    protected function executeWithValidation(
        callable $operation,
        array $validators = [],
        string $operationName = 'Operation'
    ): mixed {
        foreach ($validators as $validator) {
            $validator();
        }

        return $this->executeAtomicOperation($operation, $operationName);
    }

    /**
     * Log critical security events
     */
    protected function logSecurityEvent(string $eventType, string $severity, string $message, array $context = []): void
    {
        $allowedSeverities = ['high', 'critical'];
        if (!in_array(strtolower($severity), $allowedSeverities, true)) {
            return;
        }

        try {
            $tenantId = $context['tenant_id'] ?? $this->tenantId ?? null;
            $userId = $context['user_id'] ?? null;
            $targetUserId = $context['target_user_id'] ?? null;
            $status = $context['status'] ?? 'success';

            if (!$this->db || !$tenantId) {
                $this->logger->warning('logSecurityEvent: tenant_id missing', [
                    'event_type' => $eventType,
                    'message'    => $message,
                ]);
                return;
            }

            $stmt = $this->db->prepare("
                INSERT INTO security_events (
                    event_type,
                    event_severity,
                    description,
                    tenant_id,
                    user_id,
                    target_user_id,
                    ip_address,
                    user_agent,
                    status,
                    details,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $eventType,
                strtolower($severity),
                $message,
                $tenantId,
                $userId,
                $targetUserId,
                RequestHelper::getClientIpFromServer(),
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $status,
                json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]);
        } catch (Throwable $e) {
            $this->logger->error('logSecurityEvent: failed to insert', ['error' => $e->getMessage()]);
        }
    }

    /**
     * دالة helper آمنة للـ transactions
     */
    protected function executeTransaction(callable $callback, string $operationName = '', array $context = []): mixed
    {
        if (!$this->db) {
            throw new \Exception('Transaction manager not initialized');
        }

        return $this->getTxManager()->execute($callback, $operationName, $context);
    }
}
