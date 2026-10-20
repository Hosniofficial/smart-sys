<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;
use DateTimeImmutable;
use App\Services\MonologHandler;
use App\Repositories\SettingsRepository;

/**
 * Thrown by CashierSessionService on business-rule violations.
 * Carries HTTP code + specific audit event name/context for the handler to log.
 */
class SessionDeniedException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int    $httpCode,
        public readonly string $auditEvent,
        public readonly array  $auditContext = []
    ) {
        parent::__construct($message, $httpCode);
    }
}

/**
 * Manages cashier-session lifecycle and summaries.
 *
 * Cash flows come from cash_transactions; expected cash is opening cash plus cash-in minus cash-out.
 */
class CashierSessionService
{
    private const ADMIN_SESSION_TIMEOUT_HOURS = 12;

    private PDO $db;
    private SettingsRepository $settings;
    private $logger;

    public function __construct(PDO $db)
    {
        $this->db       = $db;
        $this->settings = new SettingsRepository($db);
        $this->logger   = MonologHandler::getInstance('sessions');
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Open a new cashier session.
     *
     * @return array ['session_id', 'session_type', 'session_type_label', 'shift_id', 'auto_closed']
     * @throws SessionDeniedException on business-rule violation (handler logs audit with full context)
     * @throws \Exception on unexpected DB error
     */
    public function openSession(
        int $tenantId,
        array $data,
        ?int $jwtUserId,
        ?int $jwtRoleId,
        bool $canManageTenantSessions = false
    ): array
    {
        $lockAcquired         = false;
        $terminalLockAcquired = false;
        $lockName             = null;
        $terminalLockName     = null;

        try {
            $branchId    = $data['branch_id']          ?? null;
            $notes       = $data['notes']              ?? null;
            $deviceId    = $data['device_id']          ?? null;
            $deviceName  = $data['device_name']        ?? null;
            $terminalId  = $data['terminal_id']        ?? null;
            $shiftId     = $data['shift_id']           ?? null;
            $openingCash = isset($data['opening_cash_amount']) ? (float) $data['opening_cash_amount'] : 0.0;

            $this->logger->debug('Session open attempt', [
                'tenant_id' => $tenantId, 'time' => date('Y-m-d H:i:s'),
            ]);

            $allowOverride = (int) $this->settings->get($tenantId, 'pos.sessions.allow_manager_override', '0') === 1;
            $isAdmin       = $canManageTenantSessions || (int) $jwtRoleId === 2;
            if ($isAdmin) {
                $allowOverride = true;
            }

            $cashierId = $data['cashier_id'] ?? $jwtUserId;
            if ($cashierId && $jwtUserId && $cashierId != $jwtUserId && !$allowOverride) {
                $cashierId = $jwtUserId;
            }

            $this->logger->debug('Session user info', [
                'tenant_id' => $tenantId, 'user_id' => $jwtUserId, 'role_id' => $jwtRoleId,
            ]);

            if (!$cashierId) {
                throw new SessionDeniedException(
                    'مطلوب معرف الكاشير (cashier_id).',
                    400,
                    'pos_session_open_denied_missing_cashier',
                    ['user_id' => $jwtUserId, 'reason' => 'missing_cashier_id', 'branch_id' => $branchId, 'role_id' => $jwtRoleId]
                );
            }

            // Parse enforced roles
            $rawEnforce   = (string) ($this->settings->get($tenantId, 'pos.sessions.enforce_for_roles', '') ?? '');
            $enforceRoles = [];
            if ($rawEnforce !== '') {
                $trim = trim($rawEnforce);
                if (str_starts_with($trim, '[')) {
                    $decoded = json_decode($trim, true);
                    if (is_array($decoded)) {
                        $enforceRoles = array_map('intval', $decoded);
                    }
                } else {
                    $enforceRoles = array_map('intval', array_filter(array_map('trim', explode(',', $trim))));
                }
            }
            $isEnforced = in_array((int) $jwtRoleId, $enforceRoles, true);

            $this->logger->debug('Session enforcement settings', [
                'tenant_id'      => $tenantId,
                'raw_setting'    => $rawEnforce,
                'enforced_roles' => $enforceRoles,
                'is_enforced'    => $isEnforced,
                'user_role_id'   => $jwtRoleId,
            ]);

            if (!$branchId && $isEnforced) {
                throw new SessionDeniedException(
                    'مطلوب معرف المخزن (branch_id) لهذا الدور وفق الإعدادات.',
                    400,
                    'pos_session_open_denied_missing_branch',
                    ['user_id' => $jwtUserId, 'reason' => 'missing_branch_id',
                     'cashier_id' => $cashierId, 'role_id' => $jwtRoleId, 'enforced' => true]
                );
            }

            if (!$terminalId) {
                throw new SessionDeniedException(
                    'مطلوب اختيار جهاز نقطة البيع (terminal_id) لفتح الجلسة.',
                    400,
                    'pos_session_open_denied_missing_terminal',
                    ['user_id' => $jwtUserId, 'cashier_id' => $cashierId]
                );
            }

            // Validate terminal
            $terminalStmt = $this->db->prepare(
                "SELECT id, tenant_id, branch_id, status FROM terminals WHERE id = ? AND tenant_id = ? LIMIT 1"
            );
            $terminalStmt->execute([(int) $terminalId, $tenantId]);
            $terminal = $terminalStmt->fetch(PDO::FETCH_ASSOC);

            if (!$terminal) {
                throw new SessionDeniedException(
                    'الترمينال المحدد غير موجود أو لا يتبع هذا المستأجر.',
                    400,
                    'pos_session_open_denied_invalid_terminal',
                    ['terminal_id' => $terminalId]
                );
            }
            if (($terminal['status'] ?? 'inactive') !== 'active') {
                throw new SessionDeniedException(
                    'لا يمكن فتح جلسة على جهاز نقطة بيع غير نشط.',
                    400,
                    'pos_session_open_denied_inactive_terminal',
                    ['terminal_id' => $terminalId, 'status' => $terminal['status'] ?? 'unknown']
                );
            }

            $terminalBranchId = $terminal['branch_id'] !== null ? (int) $terminal['branch_id'] : null;
            if ($branchId === null && $terminalBranchId !== null) {
                $branchId = $terminalBranchId;
            } elseif ($branchId !== null && $terminalBranchId !== null && (int) $branchId !== $terminalBranchId) {
                throw new SessionDeniedException(
                    'المخزن المحدد لا يطابق المخزن المرتبط بجهاز نقطة البيع.',
                    400,
                    'pos_session_open_denied_branch_mismatch',
                    ['branch_id' => $branchId, 'terminal_branch_id' => $terminalBranchId]
                );
            }

            $sessionType = $this->resolveSessionType($tenantId, $data['session_type'] ?? null);

            // Acquire distributed lock (cashier+branch+day)
            $lockName = sprintf(
                'tenant:%d:wh:%s:cashier:%d:date:%s',
                $tenantId,
                $branchId === null ? 'null' : (string)(int)$branchId,
                (int)$cashierId,
                (new DateTimeImmutable('now'))->format('Y-m-d')
            );
            $lockResult = $this->db->query("SELECT GET_LOCK(" . $this->db->quote($lockName) . ", 5)");
            $lockAcquired = ((int) $lockResult->fetchColumn() === 1);
            if (!$lockAcquired) {
                throw new SessionDeniedException(
                    'النظام مشغول بمعالجة جلسة أخرى لنفس الكاشير/المخزن الآن. حاول مرة أخرى بعد لحظات.',
                    403,
                    'pos_session_open_lock_busy',
                    ['reason' => 'lock_busy', 'lock' => $lockName, 'cashier_id' => $cashierId, 'branch_id' => $branchId]
                );
            }

            // Acquire terminal lock
            $terminalLockName = sprintf(
                'tenant:%d:terminal:%d:date:%s',
                $tenantId,
                (int)$terminalId,
                (new DateTimeImmutable('now'))->format('Y-m-d')
            );
            $termLockResult = $this->db->query("SELECT GET_LOCK(" . $this->db->quote($terminalLockName) . ", 5)");
            $terminalLockAcquired = ((int) $termLockResult->fetchColumn() === 1);
            if (!$terminalLockAcquired) {
                throw new SessionDeniedException(
                    'لا يمكن فتح جلسة أخرى على نفس جهاز نقطة البيع في هذه اللحظة. حاول مرة أخرى بعد لحظات.',
                    403,
                    'pos_session_open_terminal_lock_busy',
                    ['reason' => 'terminal_lock_busy', 'terminal_id' => $terminalId,
                     'lock' => $terminalLockName, 'cashier_id' => $cashierId, 'branch_id' => $branchId]
                );
            }

            // Daily limit check
            $mode       = strtolower((string) ($this->settings->get($tenantId, 'pos.sessions.mode', '') ?? ''));
            $dailyLimit = match ($mode) {
                'one_per_day'   => 1,
                'two_per_day'   => 2,
                'three_per_day' => 3,
                default         => null,
            };

            if ($dailyLimit !== null && $branchId !== null) {
                $countStmt = $this->db->prepare(
                    "SELECT COUNT(*) FROM cashier_sessions
                     WHERE tenant_id = ? AND branch_id = ? AND cashier_id = ?
                       AND DATE(start_time) = DATE(NOW())"
                );
                $countStmt->execute([$tenantId, $branchId, $cashierId]);
                $todayCount = (int) $countStmt->fetchColumn();
                if ($todayCount >= $dailyLimit && !$allowOverride) {
                    throw new SessionDeniedException(
                        'تم الوصول إلى الحد الأقصى لعدد جلسات الكاشير المسموح بها لهذا المخزن اليوم.',
                        403,
                        'pos_session_open_denied_limit',
                        ['user_id' => $jwtUserId, 'reason' => 'daily_limit_reached',
                         'mode' => $mode, 'daily_limit' => $dailyLimit, 'today_count' => $todayCount,
                         'cashier_id' => $cashierId, 'branch_id' => $branchId, 'role_id' => $jwtRoleId]
                    );
                }
            }

            // Check no open session for same cashier+branch
            if ($branchId !== null) {
                $chk = $this->db->prepare(
                    "SELECT id FROM cashier_sessions WHERE tenant_id = ? AND branch_id = ? AND cashier_id = ? AND status = 'open' LIMIT 1"
                );
                $chk->execute([$tenantId, $branchId, $cashierId]);
            } else {
                $chk = $this->db->prepare(
                    "SELECT id FROM cashier_sessions WHERE tenant_id = ? AND branch_id IS NULL AND cashier_id = ? AND status = 'open' LIMIT 1"
                );
                $chk->execute([$tenantId, $cashierId]);
            }
            if ($chk->fetch()) {
                throw new SessionDeniedException(
                    'توجد جلسة مفتوحة مسبقًا لهذا الكاشير في هذا المخزن.',
                    403,
                    'pos_session_open_denied_already_open',
                    ['user_id' => $jwtUserId, 'reason' => 'already_open_session',
                         'cashier_id' => $cashierId, 'branch_id' => $branchId, 'role_id' => $jwtRoleId]
                );
            }

            // Auto-create shift if needed
            if ($branchId !== null && $terminalId !== null) {
                $shiftStmt = $this->db->prepare(
                    "SELECT id FROM cashier_shifts
                     WHERE tenant_id = ? AND branch_id = ? AND terminal_id = ? AND status = 'open'
                     ORDER BY start_time DESC LIMIT 1"
                );
                $shiftStmt->execute([(int)$tenantId, (int)$branchId, (int)$terminalId]);
                $openShift = $shiftStmt->fetch(PDO::FETCH_ASSOC);

                if ($openShift && isset($openShift['id'])) {
                    $shiftId = (int)$openShift['id'];
                } else {
                    $this->db->prepare("
                        INSERT INTO cashier_shifts
                            (tenant_id, branch_id, terminal_id, cashier_id,
                             start_time, opening_cash_amount, status, notes,
                             created_by, created_at, updated_at)
                        VALUES (?, ?, ?, ?, NOW(), ?, 'open', 'auto', ?, NOW(), NOW())
                    ")->execute([
                        (int)$tenantId, (int)$branchId, (int)$terminalId, $cashierId,
                        $openingCash, $jwtUserId ?: $cashierId,
                    ]);
                    $shiftId = (int)$this->db->lastInsertId();
                    $this->logger->info('Auto-created shift for session', [
                        'tenant_id' => $tenantId, 'shift_id' => $shiftId,
                        'terminal_id' => $terminalId, 'branch_id' => $branchId,
                    ]);
                }
            }

            // Check no open session on same terminal
            if ($terminalId !== null) {
                $termChk = $this->db->prepare(
                    "SELECT id FROM cashier_sessions WHERE tenant_id = ? AND terminal_id = ? AND status = 'open' LIMIT 1"
                );
                $termChk->execute([$tenantId, $terminalId]);
                if ($termChk->fetch()) {
                    throw new SessionDeniedException(
                        'يوجد بالفعل جلسة مفتوحة على جهاز نقطة البيع المحدد. يجب إغلاقها قبل فتح جلسة جديدة.',
                        403,
                        'pos_session_open_denied_terminal_in_use',
                        ['reason' => 'terminal_already_in_use', 'terminal_id' => $terminalId,
                         'cashier_id' => $cashierId, 'branch_id' => $branchId]
                    );
                }
            }

            // INSERT session
            $createdBy = $jwtUserId ?: $cashierId;
            $this->db->prepare("
                INSERT INTO cashier_sessions (
                    tenant_id, cashier_id, device_id, device_name, session_type,
                    start_time, opening_cash_amount, status, created_by,
                    branch_id, terminal_id, shift_id, notes, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, NOW(), ?, 'open', ?, ?, ?, ?, ?, NOW(), NOW())
            ")->execute([
                $tenantId, $cashierId, $deviceId, $deviceName, $sessionType,
                $openingCash, $createdBy, $branchId, $terminalId, $shiftId, $notes,
            ]);
            $sessionId       = (int)$this->db->lastInsertId();
            $finalSessionType = $sessionType;

            // Upgrade to admin session for admin users
            if ($isAdmin && $sessionType !== 'admin') {
                $this->db->prepare(
                    "UPDATE cashier_sessions SET session_type = 'admin' WHERE id = ? AND tenant_id = ?"
                )->execute([$sessionId, $tenantId]);
                $finalSessionType = 'admin';
                $this->logger->info('Session converted to admin session', [
                    'tenant_id' => $tenantId, 'session_id' => $sessionId, 'user_id' => $jwtUserId,
                ]);
            }

            $this->logger->info('Session created successfully', [
                'tenant_id' => $tenantId, 'session_id' => $sessionId,
                'cashier_id' => $cashierId, 'session_type' => $finalSessionType, 'created_by' => $createdBy,
            ]);

            return [
                'session_id'         => $sessionId,
                'session_type'       => $finalSessionType,
                'session_type_label' => $this->getSessionTypeLabel($finalSessionType),
                'shift_id'           => $shiftId,
                'auto_closed'        => $isAdmin,
            ];
        } finally {
            if ($lockAcquired && $lockName !== null) {
                try {
                    $this->db->query("SELECT RELEASE_LOCK(" . $this->db->quote($lockName) . ")");
                } catch (Throwable $e) {
                }
            }
            if ($terminalLockAcquired && $terminalLockName !== null) {
                try {
                    $this->db->query("SELECT RELEASE_LOCK(" . $this->db->quote($terminalLockName) . ")");
                } catch (Throwable $e) {
                }
            }
        }
    }

    /**
     * Close an open session and return its final cash reconciliation.
     *
     * @throws SessionDeniedException when the amount or session state is invalid
     */
    public function closeSession(
        int    $tenantId,
        int    $sessionId,
        float  $closingCash,
        ?int   $closedBy,
        ?string $varianceReason
    ): array {
        // Guard against non-finite floats (NaN / INF) that PHP can produce when the
        // caller casts a non-numeric string with (float). The Handler already rejects
        // null and negative values, but is_finite() covers the remaining edge cases.
        if (!is_finite($closingCash)) {
            throw new SessionDeniedException(
                'مبلغ النقد المدخل غير صحيح.',
                400,
                'pos_session_close_invalid_cash_nan',
                ['closing_cash' => 'non-finite']
            );
        }

        // Normalize to 2 decimal places (fils/cents) before arithmetic to prevent
        // IEEE 754 floating-point artifacts in the variance calculation below.
        $closingCash = round((float) $closingCash, 2);

        $stmt = $this->db->prepare(
            "SELECT id FROM cashier_sessions WHERE id = ? AND tenant_id = ? AND status = 'open'"
        );
        $stmt->execute([$sessionId, $tenantId]);
        if (!$stmt->fetch()) {
            throw new SessionDeniedException(
                'لم يتم العثور على جلسة مفتوحة.',
                403,
                'pos_session_close_not_found',
                ['session_id' => $sessionId]
            );
        }

        $summaryBefore = $this->buildSessionSummary($tenantId, $sessionId);
        $expected      = (float) ($summaryBefore['calculated']['expected_cash'] ?? 0);
        // Use round() with 4-decimal precision to prevent IEEE 754 floating-point artifacts
        // This matches the tolerance used in LiquidityPolicyService (0.001 tolerance)
        $variance      = round($closingCash - $expected, 4);

        // Normalise variance reason
        if (
            $varianceReason === '-- اختر سبب الفرق --' ||
            $varianceReason === '' ||
            (is_string($varianceReason) && trim($varianceReason) === '')
        ) {
            $varianceReason = null;
        } elseif (is_string($varianceReason)) {
            $varianceReason = trim($varianceReason) ?: null;
        }

        $this->db->prepare("
            UPDATE cashier_sessions
            SET status = 'closed', end_time = NOW(),
                closing_cash_amount = ?, closed_by = ?,
                variance_reason = ?, updated_at = NOW()
            WHERE id = ? AND tenant_id = ?
        ")->execute([$closingCash, $closedBy, $varianceReason, $sessionId, $tenantId]);

        $summary = $this->buildSessionSummary($tenantId, $sessionId);

        // حقول الإغلاق مُضافة بشكل صريح (لأغراض الـ audit وسهولة الاستعلام)
        return array_merge($summary, [
            'closing' => [
                'closing_cash_amount' => $closingCash,
                'expected_cash'       => $expected,
                'variance'            => $variance,
                'variance_reason'     => $varianceReason,
                'closed_at'           => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'closed_by'           => $closedBy,
            ],
        ]);
    }

    // =========================================================================
    // Shared Read Helpers (used by Handler for current, summary, listSessions)
    // =========================================================================

    /** Build a session summary from its cash transactions and stored session details. */
    public function buildSessionSummary(int $tenantId, int $sessionId): array
    {
        $transactions = $this->getSessionTransactions($tenantId, $sessionId);

        $cashIn  = 0.0;
        $cashOut = 0.0;
        foreach ($transactions as $tx) {
            if (($tx['status'] ?? '') === 'cancelled') {
                continue;
            }
            if (in_array($tx['type'], ['income', 'return_receipt', 'sale', 'deposit'], true)) {
                $cashIn += (float)$tx['amount'];
            } elseif (in_array($tx['type'], ['expense', 'return_payment', 'purchase', 'withdrawal'], true)) {
                $cashOut += (float)$tx['amount'];
            }
        }

        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(s.net_total_amount + COALESCE(s.tax_amount, 0)), 0) AS total_sales
             FROM sales s WHERE s.tenant_id = ? AND s.session_id = ? AND LOWER(COALESCE(s.status, '')) NOT IN ('canceled', 'cancelled')"
        );
        $stmt->execute([$tenantId, $sessionId]);
        $totalSales = (float)$stmt->fetchColumn();

        $stmt = $this->db->prepare(
            "SELECT opening_cash_amount, closing_cash_amount, variance_reason,
                    branch_id, cashier_id, status, start_time, end_time,
                    session_type, device_id, device_name, terminal_id, shift_id, closed_by
             FROM cashier_sessions WHERE id = ? AND tenant_id = ?"
        );
        $stmt->execute([$sessionId, $tenantId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($session) {
            $session['session_type_label'] = $this->getSessionTypeLabel($session['session_type'] ?? 'manual');
            if (!empty($session['closed_by'])) {
                $u = $this->db->prepare("SELECT name FROM users WHERE id = ? AND tenant_id = ?");
                $u->execute([$session['closed_by'], $tenantId]);
                $session['closed_by_name'] = $u->fetchColumn() ?: 'مستخدم غير معروف';
            }
        }

        $openingBalance = $session ? (float)$session['opening_cash_amount'] : 0.0;
        $closingBalance = ($session && isset($session['closing_cash_amount']))
            ? (float)$session['closing_cash_amount'] : null;

        // ─────────────────────────────────────────────────────────────────────
        // NOTE: cash_out is ALWAYS derived strictly from cash_transactions above.
        // We intentionally do NOT back-derive cash_out from closing_cash_amount
        // when a variance_reason is present — doing so silently masked the real
        // variance (forcing variance_amount to 0) and caused this endpoint to
        // disagree with listSessions()/closeSession(), which both
        // compute variance directly from opening + cash_in - cash_out.
        // variance_reason is purely descriptive metadata and must never affect
        // the calculation itself.
        // ─────────────────────────────────────────────────────────────────────

        $expectedCash   = $openingBalance + $cashIn - $cashOut;
        $varianceAmount = $closingBalance !== null ? $closingBalance - $expectedCash : null;

        return [
            'session'     => $session ?: null,
            'totals'      => ['payments' => $totalSales, 'cash_in' => $cashIn, 'cash_out' => $cashOut, 'total_sales' => $totalSales],
            'calculated'  => ['expected_cash' => $expectedCash, 'variance_amount' => $varianceAmount, 'opening_balance' => $openingBalance, 'closing_balance' => $closingBalance],
            'transactions' => $transactions,
        ];
    }

    /** Return a tenant-scoped session transaction history with creator names. */
    public function getSessionTransactions(int $tenantId, int $sessionId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, type, amount, reference_type, reference_id, notes, created_at, created_by, status
             FROM cash_transactions
             WHERE tenant_id = ? AND session_id = ? ORDER BY created_at ASC"
        );
        $stmt->execute([$tenantId, $sessionId]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $userIds = array_filter(array_unique(array_column($transactions, 'created_by')));
        $users   = [];
        if (!empty($userIds)) {
            $ph       = implode(',', array_fill(0, count($userIds), '?'));
            $uStmt    = $this->db->prepare("SELECT id, name FROM users WHERE id IN ($ph) AND tenant_id = ?");
            $uStmt->execute(array_merge($userIds, [$tenantId]));
            $users = array_column($uStmt->fetchAll(PDO::FETCH_ASSOC), 'name', 'id');
        }
        foreach ($transactions as &$tx) {
            $tx['created_by_name'] = $users[$tx['created_by']] ?? 'System';
            $tx['amount']          = (float)$tx['amount'];
        }
        unset($tx);
        return $transactions;
    }

    /** Return the localized label for a stored session type. */
    public function getSessionTypeLabel(?string $type): string
    {
        return match (strtolower((string)$type)) {
            'daily'   => 'جلسة يومية',
            'morning' => 'جلسة صباحية',
            'evening' => 'جلسة مسائية',
            'admin'   => 'جلسة إدارية',
            default   => 'جلسة يدوية',
        };
    }

    /**
     * Build summaries for multiple sessions efficiently (single bulk query approach).
     * Reduces database round-trips from N×4 queries to ~4 queries total.
     *
     * @param int   $tenantId
     * @param array $sessionIds  Array of session IDs
     * @return array  Map of session_id => summary data
     */
    public function buildBulkSessionSummaries(int $tenantId, array $sessionIds): array
    {
        if (empty($sessionIds)) {
            return [];
        }

        // Cast and deduplicate
        $sessionIds = array_unique(array_map('intval', $sessionIds));
        $sessionIdCount = count($sessionIds);
        $placeholders = implode(',', array_fill(0, $sessionIdCount, '?'));

        // ─── Query 1: Fetch all sessions data ────────────────────────────────
        $sessionsStmt = $this->db->prepare("
            SELECT id, opening_cash_amount, closing_cash_amount, variance_reason,
                   branch_id, cashier_id, status, start_time, end_time,
                   session_type, device_id, device_name, terminal_id, shift_id, closed_by
            FROM cashier_sessions
            WHERE id IN ($placeholders) AND tenant_id = ?
        ");
        $params = array_merge($sessionIds, [$tenantId]);
        $sessionsStmt->execute($params);
        $sessionsData = $sessionsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Index by session_id
        $sessions = [];
        $closedByIds = [];
        foreach ($sessionsData as $session) {
            $sessionId = (int)$session['id'];
            $session['session_type_label'] = $this->getSessionTypeLabel($session['session_type'] ?? 'manual');
            $sessions[$sessionId] = $session;

            if (!empty($session['closed_by'])) {
                $closedByIds[] = (int)$session['closed_by'];
            }
        }

        // Fetch closed_by user names
        $closedByNames = [];
        if (!empty($closedByIds)) {
            $closedByCount = count(array_unique($closedByIds));
            $closedByPlaceholders = implode(',', array_fill(0, $closedByCount, '?'));
            $closedByStmt = $this->db->prepare("
                SELECT id, name FROM users WHERE id IN ($closedByPlaceholders) AND tenant_id = ?
            ");
            $closedByParams = array_merge(array_unique($closedByIds), [$tenantId]);
            $closedByStmt->execute($closedByParams);
            $closedByNames = array_column($closedByStmt->fetchAll(PDO::FETCH_ASSOC), 'name', 'id');
        }

        foreach ($sessions as &$session) {
            if (!empty($session['closed_by'])) {
                $session['closed_by_name'] = $closedByNames[$session['closed_by']] ?? 'مستخدم غير معروف';
            }
        }
        unset($session);

        // ─── Query 2: Fetch all cash transactions ────────────────────────────
        $transactionsStmt = $this->db->prepare("
            SELECT id, session_id, type, amount, reference_type, reference_id, notes, created_at, created_by, status
            FROM cash_transactions
            WHERE tenant_id = ? AND session_id IN ($placeholders)
            ORDER BY session_id, created_at ASC
        ");
        $txParams = array_merge([$tenantId], $sessionIds);
        $transactionsStmt->execute($txParams);
        $allTransactions = $transactionsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by session_id
        $transactionsBySession = [];
        $createdByIds = [];
        foreach ($allTransactions as $tx) {
            $sessionId = (int)$tx['session_id'];
            $transactionsBySession[$sessionId][] = $tx;
            if (!empty($tx['created_by'])) {
                $createdByIds[] = (int)$tx['created_by'];
            }
        }

        // Fetch created_by user names
        $userNames = [];
        if (!empty($createdByIds)) {
            $uniqueCreatedByIds = array_unique($createdByIds);
            $userCount = count($uniqueCreatedByIds);
            $userPlaceholders = implode(',', array_fill(0, $userCount, '?'));
            $userStmt = $this->db->prepare("
                SELECT id, name FROM users WHERE id IN ($userPlaceholders) AND tenant_id = ?
            ");
            $userParams = array_merge($uniqueCreatedByIds, [$tenantId]);
            $userStmt->execute($userParams);
            $userNames = array_column($userStmt->fetchAll(PDO::FETCH_ASSOC), 'name', 'id');
        }

        // Enhance transactions with user names
        foreach ($transactionsBySession as &$transactions) {
            foreach ($transactions as &$tx) {
                $tx['created_by_name'] = $userNames[$tx['created_by']] ?? 'System';
                $tx['amount'] = (float)$tx['amount'];
            }
            unset($tx);
        }
        unset($transactions);

        // ─── Query 3: Fetch total sales per session ──────────────────────────
        $salesStmt = $this->db->prepare("
            SELECT s.session_id, COALESCE(SUM(s.net_total_amount + COALESCE(s.tax_amount, 0)), 0) AS total_sales
            FROM sales s
            WHERE s.tenant_id = ? AND s.session_id IN ($placeholders) AND LOWER(COALESCE(s.status, '')) NOT IN ('canceled', 'cancelled')
            GROUP BY s.session_id
        ");
        $salesParams = array_merge([$tenantId], $sessionIds);
        $salesStmt->execute($salesParams);
        $salesBySession = array_column($salesStmt->fetchAll(PDO::FETCH_ASSOC), 'total_sales', 'session_id');

        // ─── Build summaries ───────────────────────────────────────────────────
        $summaries = [];
        foreach ($sessionIds as $sessionId) {
            $session = $sessions[$sessionId] ?? null;
            if (!$session) {
                continue; // Session not found for this tenant
            }

            $transactions = $transactionsBySession[$sessionId] ?? [];
            $totalSales = (float)($salesBySession[$sessionId] ?? 0);

            // Calculate cash_in and cash_out
            $cashIn = 0.0;
            $cashOut = 0.0;
            foreach ($transactions as $tx) {
                if (($tx['status'] ?? '') === 'cancelled') {
                    continue;
                }
                if (in_array($tx['type'], ['income', 'return_receipt', 'sale', 'deposit'], true)) {
                    $cashIn += (float)$tx['amount'];
                } elseif (in_array($tx['type'], ['expense', 'return_payment', 'purchase', 'withdrawal'], true)) {
                    $cashOut += (float)$tx['amount'];
                }
            }

            $openingBalance = (float)$session['opening_cash_amount'];
            $closingBalance = isset($session['closing_cash_amount']) ? (float)$session['closing_cash_amount'] : null;
            $expectedCash = $openingBalance + $cashIn - $cashOut;
            $varianceAmount = $closingBalance !== null ? $closingBalance - $expectedCash : null;

            $summaries[$sessionId] = [
                'session' => $session,
                'totals' => [
                    'payments' => $totalSales,
                    'cash_in' => $cashIn,
                    'cash_out' => $cashOut,
                    'total_sales' => $totalSales,
                ],
                'calculated' => [
                    'expected_cash' => $expectedCash,
                    'variance_amount' => $varianceAmount,
                    'opening_balance' => $openingBalance,
                    'closing_balance' => $closingBalance,
                ],
                'transactions' => $transactions,
            ];
        }

        return $summaries;
    }

    /** Close admin sessions whose last update is older than the configured timeout. */
    public function checkAndCloseInactiveAdminSessions(int $tenantId): void
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE cashier_sessions
                SET status = 'closed', end_time = NOW(),
                    closing_cash_amount = opening_cash_amount,
                    closed_by = created_by, updated_at = NOW()
                WHERE tenant_id = ? AND session_type = 'admin' AND status = 'open'
                  AND TIMESTAMPDIFF(HOUR, updated_at, NOW()) >= ?
            ");
            $stmt->execute([$tenantId, self::ADMIN_SESSION_TIMEOUT_HOURS]);

            if ($stmt->rowCount() > 0) {
                $this->logger->info('Inactive admin sessions closed', [
                    'tenant_id' => $tenantId,
                    'count'     => $stmt->rowCount(),
                ]);
            }
        } catch (Throwable $e) {
            $this->logger->error('Error closing inactive admin sessions', ['message' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    private function resolveSessionType(int $tenantId, ?string $requestedType = null): string
    {
        $mode   = strtolower((string) ($this->settings->get($tenantId, 'pos.sessions.mode', 'manual') ?? 'manual'));
        $cutoff = (string) ($this->settings->get($tenantId, 'pos.sessions.period_cutoff', '15:00') ?? '15:00');
        $cutoff = preg_match('/^\d{2}:\d{2}$/', $cutoff) ? $cutoff : '15:00';

        if ($mode === 'daily') {
            return 'daily';
        }

        if ($mode === 'morning' || $mode === 'evening') {
            $now             = new DateTimeImmutable('now');
            [$hour, $minute] = array_map('intval', explode(':', $cutoff));
            $cutoffTime      = $now->setTime($hour, $minute, 0);
            return $now < $cutoffTime ? 'morning' : 'evening';
        }

        $req = strtolower((string)$requestedType);
        return in_array($req, ['manual', 'morning', 'evening', 'daily'], true) ? $req : 'manual';
    }
}
