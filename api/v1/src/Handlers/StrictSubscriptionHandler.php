<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Exception;
use Throwable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;
use App\Utils\RequestHelper;

class StrictSubscriptionHandler extends BaseHandler
{
    private array $config;
    private array $fraudDetection;

    public function __construct(PDO $db)
    {
        parent::__construct($db);

        $this->logger = MonologHandler::getInstance('strict_subscription');

        $config = [];

        $this->config = array_merge([
            'trial_duration_days' => 14,
            'max_trial_extensions' => 0,
            'require_email_verification' => true,
            'require_payment_verification' => true,
            'fraud_detection_enabled' => true,
            'max_accounts_per_ip' => 3,
            'max_accounts_per_device' => 2,
            'suspicious_activity_threshold' => 3,
            'auto_block_threshold' => 5
        ], $config);

        $this->fraudDetection = [
            'email_domain_blacklist' => [
                'tempmail.org',
                '10minutemail.com',
                'guerrillamail.com'
            ],
            'suspicious_patterns' => [
                '/^\d+@/',
                '/test@/i',
                '/demo@/i'
            ],
            'risk_factors' => [
                'multiple_trials_same_ip' => 3,
                'multiple_emails_same_device' => 2,
                'rapid_signup' => 2,
                'suspicious_domain' => 2,
                'no_device_fingerprint' => 1
            ]
        ];
    }

    public function createSecureTrial(Request $request, Response $response): Response
    {
        $ip = $this->getClientIp($request);
        $userAgent = $request->getHeaderLine('User-Agent');
        $fingerprint = trim($request->getHeaderLine('X-Device-Fingerprint'));
        $data = [];
        $fraudScore = 0;

        $this->logger->info('Secure trial creation request', [
            'ip' => $ip,
            'user_agent' => $userAgent,
            'fingerprint' => $fingerprint !== '' ? $fingerprint : 'missing'
        ]);

        try {
            $this->db->beginTransaction();

            $data = $this->extractAndValidateRequestData(
                $request,
                ['username', 'email', 'password', 'full_name']
            );

            $validation = $this->performEnhancedValidation($data, $ip, $fingerprint);
            if (!$validation['valid']) {
                $this->logSecurityEvent(
                    'trial_validation_failed',
                    'medium',
                    'Trial validation failed: ' . $validation['message'],
                    [
                        'ip' => $ip,
                        'email' => $data['email'],
                        'reason' => $validation['message']
                    ]
                );

                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return $this->errorResponse($response, $validation['message'], 400);
            }

            $fraudScore = 0;
            if ($this->config['fraud_detection_enabled']) {
                $fraudScore = $this->calculateFraudScore($data, $ip, $fingerprint);

                if ($fraudScore >= 7) {
                    $this->blockSuspiciousActivity($ip, $fingerprint, 'High fraud score: ' . $fraudScore);

                    $this->logSecurityEvent('high_fraud_score', 'high', 'High fraud score detected', [
                        'ip' => $ip,
                        'email' => $data['email'],
                        'fraud_score' => $fraudScore
                    ]);

                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }

                    return $this->errorResponse($response, 'Suspicious activity detected', 403);
                }
            }

            $tenantId = $this->createSecureTenant($data, $ip, $fingerprint);
            \App\Services\TenantRoleBootstrapper::ensureDefaults($this->db, $tenantId);

            $userData = $this->createSecureUser($tenantId, $data, $ip, $fingerprint);
            $userId = (int) $userData['user_id'];
            $verificationToken = $userData['verification_token'] ?? null;

            $subscriptionId = $this->createSecureTrialSubscription($tenantId, $data, $ip, $fingerprint, $fraudScore);

            $this->initializeSecureAccounting($tenantId, $userId);

            if ($this->config['require_email_verification'] && $verificationToken) {
                $this->sendVerificationEmail($data['email'], $userId, $verificationToken, $ip, $request);

                $this->logger->info('Verification email sent during trial signup', [
                    'user_id' => $userId,
                    'email' => $data['email'],
                    'token_length' => strlen($verificationToken),
                ]);
            }

            $this->recordSubscriptionAttempt($ip, $data['email'], 'trial_signup', 'success', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'fraud_score' => $fraudScore,
                'device_fingerprint' => $fingerprint !== '' ? $fingerprint : null
            ]);

            $this->db->commit();

            return $this->successResponse($response, [
                'message' => 'Trial created successfully',
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'subscription_id' => $subscriptionId,
                'trial_ends_at' => date(
                    'Y-m-d H:i:s',
                    strtotime('+' . $this->config['trial_duration_days'] . ' days')
                ),
                'email_verification_required' => $this->config['require_email_verification']
            ], 201);
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Secure trial creation failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip' => $ip,
                'email' => $data['email'] ?? 'unknown',
                'user_agent' => $userAgent,
                'fingerprint' => $fingerprint
            ]);

            $this->logSecurityEvent('trial_creation_failed', 'high', 'Trial creation failed', [
                'ip' => $ip,
                'email' => $data['email'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            $this->recordSubscriptionAttempt($ip, $data['email'] ?? 'unknown', 'trial_signup', 'failed', [
                'error' => $e->getMessage(),
                'device_fingerprint' => $fingerprint !== '' ? $fingerprint : null,
                'fraud_score' => $fraudScore
            ]);

            // Log technical details internally, return generic message to user
            $this->logger->error('Trial creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse($response, 'فشل إنشاء الاشتراك التجريبي. يرجى المحاولة مرة أخرى أو التواصل مع الدعم.', 500);
        }
    }

    public function upgradeSubscription(Request $request, Response $response): Response
    {
        $ip = $this->getClientIp($request);
        $user = $request->getAttribute('user');

        $tenantId = is_array($user) ? ($user['tenant_id'] ?? null) : null;
        $userId = is_array($user) ? ($user['id'] ?? null) : null;
        $userEmail = is_array($user) ? ($user['email'] ?? null) : null;
        $planCode = '';

        try {
            if (!$tenantId || !$userId) {
                return $this->errorResponse($response, 'Unauthorized', 403);
            }

            $data = $request->getParsedBody();
            if (!is_array($data)) {
                $data = [];
            }

            $planCode = isset($data['plan']) ? trim((string) $data['plan']) : '';
            if ($planCode === '') {
                return $this->errorResponse($response, 'Plan is required', 400);
            }

            $this->db->beginTransaction();

            $plan = $this->validateAndGetPlan($planCode);
            if (!$plan) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return $this->errorResponse($response, 'Invalid or inactive plan', 400);
            }

            $currentSubscription = $this->getCurrentSubscription($tenantId);
            if (!$currentSubscription) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return $this->errorResponse($response, 'No active subscription found', 400);
            }

            $upgradeValidation = $this->validateUpgradeRules($currentSubscription, $plan);
            if (!$upgradeValidation['valid']) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return $this->errorResponse($response, $upgradeValidation['message'], 400);
            }

            $paymentResult = $this->processSecurePayment($tenantId, $userId, $plan, $ip, $data);
            if (!$paymentResult['success']) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return $this->errorResponse(
                    $response,
                    $paymentResult['message'] ?? 'Payment processing failed',
                    400
                );
            }

            $this->updateSubscription(
                (int) $currentSubscription['id'],
                $plan,
                (string) $paymentResult['transaction_id']
            );

            $this->recordSubscriptionAttempt($ip, $userEmail ?? 'unknown', 'plan_upgrade', 'success', [
                'tenant_id' => $tenantId,
                'plan_from' => $currentSubscription['plan_code'] ?? null,
                'plan_to' => $planCode,
                'transaction_id' => $paymentResult['transaction_id']
            ]);

            $this->db->commit();

            return $this->successResponse($response, [
                'message' => 'Subscription upgraded successfully',
                'plan' => $plan['code'],
                'next_billing_date' => $this->calculateNextBillingDate($plan),
                'transaction_id' => $paymentResult['transaction_id']
            ], 200);
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logger->error('Subscription upgrade failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip' => $ip,
                'tenant_id' => $tenantId ?? 'unknown',
                'user_id' => $userId ?? 'unknown',
                'plan_code' => $planCode ?: 'unknown'
            ]);

            $this->logSecurityEvent('upgrade_failed', 'medium', 'Subscription upgrade failed', [
                'ip' => $ip,
                'tenant_id' => $tenantId ?? 'unknown',
                'error' => 'Upgrade failed'
            ]);

            return $this->errorResponse($response, 'فشل ترقية الاشتراك. يرجى المحاولة مرة أخرى أو التواصل مع الدعم.', 500);
        }
    }

    protected function extractAndValidateRequestData(Request $request, array $requiredFields = []): array
    {
        $data = $request->getParsedBody();
        if (!is_array($data)) {
            $data = [];
        }

        $required = empty($requiredFields)
            ? ['username', 'email', 'password', 'full_name']
            : $requiredFields;

        foreach ($required as $field) {
            if (!isset($data[$field]) || trim((string) $data[$field]) === '') {
                throw new Exception("Field '{$field}' is required");
            }
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format');
        }

        if (strlen((string) $data['password']) < 8) {
            throw new Exception('Password must be at least 8 characters');
        }

        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', (string) $data['username'])) {
            throw new Exception('Username must be 3-20 characters, alphanumeric and underscore only');
        }

        return [
            'username' => trim((string) $data['username']),
            'email' => strtolower(trim((string) $data['email'])),
            'password' => (string) $data['password'],
            'full_name' => trim((string) $data['full_name']),
            'company_name' => trim((string) ($data['company_name'] ?? $data['full_name']))
        ];
    }

    private function performEnhancedValidation(array $data, string $ip, string $fingerprint): array
    {
        $domain = substr(strrchr($data['email'], '@'), 1);
        if (in_array($domain, $this->fraudDetection['email_domain_blacklist'], true)) {
            return ['valid' => false, 'message' => 'Email domain not allowed'];
        }

        foreach ($this->fraudDetection['suspicious_patterns'] as $pattern) {
            if (preg_match($pattern, $data['email'])) {
                return ['valid' => false, 'message' => 'Suspicious email pattern detected'];
            }
        }

        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$data['email']]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            return ['valid' => false, 'message' => 'Email already registered'];
        }

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS count
             FROM tenants t
             JOIN users u ON u.tenant_id = t.id
             WHERE t.signup_ip = ?
               AND t.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $stmt->execute([$ip]);
        $ipCount = (int) (($stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0));

        if ($ipCount >= (int) $this->config['max_accounts_per_ip']) {
            return ['valid' => false, 'message' => 'Too many accounts from this IP'];
        }

        if ($fingerprint !== '') {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) AS count
                 FROM device_fingerprints df
                 JOIN tenants t ON t.device_fingerprint = df.fingerprint_hash
                 WHERE df.fingerprint_hash = ?
                   AND df.last_seen_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
            $stmt->execute([$fingerprint]);
            $deviceCount = (int) (($stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0));

            if ($deviceCount >= (int) $this->config['max_accounts_per_device']) {
                return ['valid' => false, 'message' => 'Too many accounts from this device'];
            }
        }

        return ['valid' => true, 'message' => ''];
    }

    private function calculateFraudScore(array $data, string $ip, string $fingerprint): int
    {
        $score = 0;

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS count
             FROM subscription_attempts
             WHERE ip_address = ?
               AND attempt_type = 'trial_signup'
               AND status = 'success'
               AND attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $stmt->execute([$ip]);
        $ipTrials = (int) (($stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0));
        if ($ipTrials > 1) {
            $score += (int) $this->fraudDetection['risk_factors']['multiple_trials_same_ip'];
        }

        if ($fingerprint !== '') {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) AS count
                 FROM device_fingerprints
                 WHERE fingerprint_hash = ?
                   AND last_seen_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
            $stmt->execute([$fingerprint]);
            $deviceUsage = (int) (($stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0));

            if ($deviceUsage > 1) {
                $score += (int) $this->fraudDetection['risk_factors']['multiple_emails_same_device'];
            }
        }

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS count
             FROM subscription_attempts
             WHERE ip_address = ?
               AND attempted_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
        );
        $stmt->execute([$ip]);
        $recentAttempts = (int) (($stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0));
        if ($recentAttempts > 3) {
            $score += (int) $this->fraudDetection['risk_factors']['rapid_signup'];
        }

        $domain = substr(strrchr($data['email'], '@'), 1);
        if (in_array($domain, $this->fraudDetection['email_domain_blacklist'], true)) {
            $score += (int) $this->fraudDetection['risk_factors']['suspicious_domain'];
        }

        if ($fingerprint === '') {
            $score += (int) $this->fraudDetection['risk_factors']['no_device_fingerprint'];
        }

        return $score;
    }

    private function createSecureTenant(array $data, string $ip, string $fingerprint): int
    {
        $trialPlan = $this->getTrialPlan();
        $planId = (int) $trialPlan['id'];

        $stmt = $this->db->prepare(
            "INSERT INTO tenants (
                company_name, plan_id, status, signup_ip, device_fingerprint, created_at
            ) VALUES (?, ?, 'trial', ?, ?, NOW())"
        );
        $stmt->execute([
            $data['company_name'],
            $planId,
            $ip,
            $fingerprint !== '' ? $fingerprint : null
        ]);

        $tenantId = (int) $this->db->lastInsertId();

        $this->logSecurityEvent('tenant_created', 'low', 'New tenant created', [
            'tenant_id' => $tenantId,
            'ip' => $ip,
            'company_name' => $data['company_name']
        ]);

        return $tenantId;
    }

    private function createSecureUser(int $tenantId, array $data, string $ip, string $fingerprint): array
    {
        $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);

        $verificationToken = $this->config['require_email_verification']
            ? bin2hex(random_bytes(32))
            : null;

        $tokenExpires = $this->config['require_email_verification']
            ? date('Y-m-d H:i:s', time() + 3600)
            : null;

        $stmt = $this->db->prepare(
            "INSERT INTO users (
                tenant_id, username, email, password, name,
                role_id, status, is_owner,
                email_verified_at, verification_token, verification_token_expires,
                created_at
            ) VALUES (
                ?, ?, ?, ?, ?, 2, 'active', 1, ?, ?, ?, NOW()
            )"
        );
        $stmt->execute([
            $tenantId,
            $data['username'],
            $data['email'],
            $hashedPassword,
            $data['full_name'],
            $this->config['require_email_verification'] ? null : date('Y-m-d H:i:s'),
            $verificationToken,
            $tokenExpires
        ]);

        $userId = (int) $this->db->lastInsertId();

        // ربط المستخدم بالـ role في users_role (مطلوب لـ PermissionMiddleware)
        $this->db->prepare(
            "INSERT IGNORE INTO users_role (user_id, role_id, tenant_id, created_by)
             VALUES (?, 2, ?, ?)"
        )->execute([$userId, $tenantId, $userId]);

        $this->logSecurityEvent('user_created', 'low', 'New user created', [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'username' => $data['username'],
            'email' => $data['email'],
            'role_id' => 2,
            'is_owner' => 1
        ]);

        return [
            'user_id' => $userId,
            'verification_token' => $verificationToken,
            'verification_token_expires' => $tokenExpires
        ];
    }

    private function createSecureTrialSubscription(int $tenantId, array $data, string $ip, string $fingerprint, int $fraudScore): int
    {
        $trialPlan = $this->getTrialPlan();
        $endDate = date('Y-m-d H:i:s', strtotime('+' . $this->config['trial_duration_days'] . ' days'));
        $riskLevel = $fraudScore >= 5 ? 'high' : ($fraudScore >= 3 ? 'medium' : 'low');

        $stmt = $this->db->prepare(
            "INSERT INTO subscriptions (
                tenant_id, plan_id, status, start_date, end_date,
                security_flags, last_security_check, risk_score,
                created_at, updated_at
            ) VALUES (
                ?, ?, 'trial', NOW(), ?, ?, NOW(), ?, NOW(), NOW()
            )"
        );
        $stmt->execute([
            $tenantId,
            (int) $trialPlan['id'],
            $endDate,
            json_encode([
                'email_verified' => $this->config['require_email_verification'] ? false : true
            ]),
            $fraudScore
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function getTrialPlan(): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, code, name, price, currency, billing_cycle_days
             FROM plans
             WHERE code = 'trial' AND is_active = 1
             LIMIT 1"
        );
        $stmt->execute();
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plan) {
            throw new Exception('Trial plan not found or inactive');
        }

        return $plan;
    }

    private function initializeSecureAccounting(int $tenantId, int $userId): void
    {
        $check = $this->db->prepare(
            "SELECT COUNT(*)
             FROM accounting_periods
             WHERE tenant_id = ?"
        );
        $check->execute([$tenantId]);

        if ((int) $check->fetchColumn() > 0) {
            $this->logSecurityEvent('accounting_already_initialized', 'low', 'Accounting system already exists', [
                'tenant_id' => $tenantId,
                'user_id' => $userId
            ]);
            return;
        }

        $inTransaction = $this->db->inTransaction();
        if (!$inTransaction) {
            $this->db->beginTransaction();
        }

        try {
            // إنشاء الفترة المحاسبية الأولى
            $stmt = $this->db->prepare(
                "INSERT INTO accounting_periods (
                    tenant_id, period_name, start_date, end_date, status, created_by, created_at
                ) VALUES (
                    ?, 'Initial Period', NOW(), DATE_ADD(NOW(), INTERVAL 1 YEAR), 'open', ?, NOW()
                )"
            );
            $stmt->execute([$tenantId, $userId]);

            // إنشاء الحسابات الافتراضية
            $createdIds = $this->createDefaultAccounts($tenantId);

            // إنشاء الإعدادات الافتراضية
            $this->createDefaultSettings($tenantId, $createdIds);

            // إنشاء طرق الدفع الافتراضية
            $this->createDefaultPaymentMethods($tenantId, $createdIds);

            if (!$inTransaction) {
                $this->db->commit();
            }

            $this->logSecurityEvent('accounting_initialized', 'low', 'Accounting system initialized', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'accounts_created' => count($createdIds)
            ]);
        } catch (Throwable $e) {
            if (!$inTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $this->logSecurityEvent('accounting_initialization_failed', 'high', 'Accounting system initialization failed', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    private function createDefaultAccounts(int $tenantId): array
    {
        $accounts = [
            ['code' => '1001', 'name' => 'الصندوق', 'type' => 'asset'],
            ['code' => '1002', 'name' => 'البنك', 'type' => 'asset'],
            ['code' => '1003', 'name' => 'محفظة إلكترونية', 'type' => 'asset'],
            ['code' => '1101', 'name' => 'العملاء', 'type' => 'asset'],
            ['code' => '1301', 'name' => 'المخزون', 'type' => 'asset'],
            ['code' => '2001', 'name' => 'الأرصدة الافتتاحية', 'type' => 'equity'],
            ['code' => '2101', 'name' => 'الموردين', 'type' => 'liability'],
            ['code' => '2201', 'name' => 'ضريبة مدخلات', 'type' => 'liability'],
            ['code' => '2202', 'name' => 'ضريبة مخرجات', 'type' => 'liability'],
            ['code' => '2102', 'name' => 'ضريبة القيمة المضافة', 'type' => 'liability'],
            ['code' => '3001', 'name' => 'رأس المال', 'type' => 'equity'],
            ['code' => '4001', 'name' => 'المبيعات', 'type' => 'revenue'],
            ['code' => '4002', 'name' => 'مردودات المبيعات', 'type' => 'revenue'],
            ['code' => '5001', 'name' => 'المشتريات', 'type' => 'expense'],
            ['code' => '5002', 'name' => 'مردودات المشتريات', 'type' => 'expense'],
            ['code' => '5101', 'name' => 'المصروفات العمومية', 'type' => 'expense'],
            ['code' => '5102', 'name' => 'مصروف كهرباء', 'type' => 'expense'],
            ['code' => '5103', 'name' => 'تكلفة البضاعة المباعة', 'type' => 'expense'],
            ['code' => '5104', 'name' => 'تسويات المخزون', 'type' => 'expense'],
            ['code' => '5201', 'name' => 'خسارة تخفيض قيمة المخزون (NRV)', 'type' => 'expense'],
            ['code' => '1202', 'name' => 'مخصص الديون المشكوك فيها', 'type' => 'asset'],
            ['code' => '5301', 'name' => 'مصروف الديون المشكوك فيها (ECL)', 'type' => 'expense']
        ];

        $getAccountIdByCode = function (string $code) use ($tenantId): ?int {
            $q = $this->db->prepare(
                "SELECT id FROM accounts WHERE code = ? AND tenant_id = ? LIMIT 1"
            );
            $q->execute([$code, $tenantId]);
            $id = $q->fetchColumn();
            return $id ? (int) $id : null;
        };

        $insertAccount = $this->db->prepare(
            "INSERT INTO accounts (
                tenant_id, parent_id, code, name, type,
                debit_balance, credit_balance, is_active, notes, created_at
            ) VALUES (?, ?, ?, ?, ?, 0, 0, 1, NULL, NOW())"
        );

        $createdIds = [];

        foreach ($accounts as $acc) {
            $existing = $getAccountIdByCode($acc['code']);
            if ($existing) {
                $createdIds[$acc['code']] = $existing;
                continue;
            }

            $insertAccount->execute([
                $tenantId,
                null,
                $acc['code'],
                $acc['name'],
                $acc['type']
            ]);

            $createdIds[$acc['code']] = (int) $this->db->lastInsertId();
        }

        return $createdIds;
    }

    private function createDefaultSettings(int $tenantId, array $createdIds): void
    {
        $getAccountIdByCode = function (string $code) use ($tenantId): ?int {
            $q = $this->db->prepare(
                "SELECT id FROM accounts WHERE code = ? AND tenant_id = ? LIMIT 1"
            );
            $q->execute([$code, $tenantId]);
            $id = $q->fetchColumn();
            return $id ? (int) $id : null;
        };

        $settings = new SettingsHandler($this->db);

        $settings->set('opening_balance_equity_account_id', (int) ($createdIds['2001'] ?? 0), 'integer', $tenantId);
        $settings->set('accounts_payable_account', (int) ($createdIds['2101'] ?? 0), 'integer', $tenantId);
        $settings->set('cash_and_banks_account', (int) ($createdIds['1001'] ?? 0), 'integer', $tenantId);
        $settings->set('purchase_returns_account', (int) ($createdIds['5002'] ?? 0), 'integer', $tenantId);
        $settings->set('purchases_account', (int) ($createdIds['5001'] ?? 0), 'integer', $tenantId);
        $settings->set('sales_account', (int) ($createdIds['4001'] ?? 0), 'integer', $tenantId);
        $settings->set('sales_returns_account', (int) ($createdIds['4002'] ?? 0), 'integer', $tenantId);

        $vatInputId = (int) ($createdIds['2201'] ?? 0);
        $vatOutputId = (int) ($createdIds['2202'] ?? 0);

        if ($vatInputId === 0) {
            $vatInputId = (int) ($getAccountIdByCode('2201') ?? 0);
        }
        if ($vatOutputId === 0) {
            $vatOutputId = (int) ($getAccountIdByCode('2202') ?? 0);
        }

        $settings->set('vat.input_account_id', $vatInputId, 'integer', $tenantId);
        $settings->set('vat.output_account_id', $vatOutputId, 'integer', $tenantId);

        // vat_payable يشير إلى الحساب الرئيسي للضريبة (2102) وليس حساب المخرجات
        $vatPayableId = (int) ($createdIds['2102'] ?? 0);
        if ($vatPayableId === 0) {
            $vatPayableId = (int) ($getAccountIdByCode('2102') ?? 0);
        }

        // حساب 2102 يجب أن يكون موجوداً دائماً - لو مش موجود نسجل خطأ
        if ($vatPayableId === 0) {
            $this->logger->error('VAT payable account (2102) not found during tenant setup', [
                'tenant_id' => $tenantId,
                'created_accounts' => array_keys($createdIds)
            ]);
            throw new \RuntimeException('فشل إنشاء الحسابات الافتراضية: حساب الضريبة الرئيسي (2102) غير موجود');
        }

        $settings->set('vat_payable', $vatPayableId, 'integer', $tenantId);
        $settings->set('inventory_account_id', (int) ($createdIds['1301'] ?? 0), 'integer', $tenantId);
        $settings->set('inventory_adjustment_account_id', (int) ($createdIds['5104'] ?? 0), 'integer', $tenantId);
        $settings->set('cogs_account_id', (int) ($createdIds['5103'] ?? 0), 'integer', $tenantId);
        $settings->set('ar_account', (int) ($createdIds['1101'] ?? 0), 'integer', $tenantId);
    }

    private function createDefaultPaymentMethods(int $tenantId, array $createdIds): void
    {
        $cashAccountId   = (int) ($createdIds['1001'] ?? 0);
        $bankAccountId   = (int) ($createdIds['1002'] ?? 0);
        $walletAccountId = (int) ($createdIds['1003'] ?? 0);

        $paymentMethods = [
            ['name' => 'نقدي',                'kind' => 'cash',   'account_id' => $cashAccountId ?: null],
            ['name' => 'تحويل بنكي',          'kind' => 'bank',   'account_id' => $bankAccountId ?: null],
            ['name' => 'بطاقة ائتمان',        'kind' => 'card',   'account_id' => $bankAccountId ?: null],
            ['name' => 'محفظة إلكترونية',     'kind' => 'wallet', 'account_id' => $walletAccountId ?: null],
            ['name' => 'آجل / ذمم',           'kind' => 'credit', 'account_id' => null],
        ];

        $pmInsert = $this->db->prepare(
            "INSERT INTO payment_methods (tenant_id, name, kind, account_id, is_active, created_at)
             VALUES (?, ?, ?, ?, 1, NOW())"
        );

        $pmExists = $this->db->prepare(
            "SELECT id FROM payment_methods WHERE tenant_id = ? AND kind = ? AND name = ? LIMIT 1"
        );

        foreach ($paymentMethods as $pm) {
            $pmExists->execute([$tenantId, $pm['kind'], $pm['name']]);
            if (!$pmExists->fetchColumn()) {
                $pmInsert->execute([$tenantId, $pm['name'], $pm['kind'], $pm['account_id']]);
            }
        }
    }

    private function sendVerificationEmail(string $email, int $userId, string $token, string $clientIp, Request $request): void
    {
        try {
            // Use EmailVerificationService with proper method signature
            $verificationService = new \App\Services\EmailVerificationService($this->db);

            // Call the correct method: sendVerification(email, purpose, request)
            // Pass the real Request object which contains all necessary data
            $verificationService->sendVerification($email, 'registration', $request);

            $this->logger->info('Verification email sent successfully', [
                'email' => $email,
                'user_id' => $userId
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('Failed to send verification email', [
                'email' => $email,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            throw new \Exception('فشل في إرسال بريد التحقق. يرجى المحاولة لاحقاً.');
        }
    }

    private function getUserById(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, username, email, name, tenant_id, role_id
             FROM users WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$userId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function validateAndGetPlan(string $planCode): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, code, name, price, currency, billing_cycle_days
             FROM plans
             WHERE code = ? AND is_active = 1
             LIMIT 1"
        );
        $stmt->execute([$planCode]);

        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        return $plan ?: null;
    }

    private function getCurrentSubscription(int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, p.code AS plan_code, p.name AS plan_name
             FROM subscriptions s
             JOIN plans p ON p.id = s.plan_id
             WHERE s.tenant_id = ?
               AND s.status IN ('trial', 'active')
             ORDER BY s.created_at DESC
             LIMIT 1"
        );
        $stmt->execute([$tenantId]);

        $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
        return $subscription ?: null;
    }

    private function validateUpgradeRules(array $currentSubscription, array $plan): array
    {
        if (($currentSubscription['status'] ?? null) === 'trial') {
            return ['valid' => true, 'message' => ''];
        }

        if ((int) ($currentSubscription['plan_id'] ?? 0) === (int) ($plan['id'] ?? 0)) {
            return ['valid' => false, 'message' => 'You are already on this plan'];
        }

        return ['valid' => true, 'message' => ''];
    }

    private function processSecurePayment(int $tenantId, int $userId, array $plan, string $ip, array $data): array
    {
        $transactionId = 'txn_' . uniqid('', true);

        $stmt = $this->db->prepare(
            "INSERT INTO payment_transactions (
                transaction_id, tenant_id, user_id, amount, currency,
                payment_method, payment_gateway, status, ip_address, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())"
        );
        $stmt->execute([
            $transactionId,
            $tenantId,
            $userId,
            $plan['price'] ?? 0,
            $plan['currency'] ?? 'USD',
            $data['payment_method'] ?? 'card',
            $data['payment_gateway'] ?? 'stripe',
            $ip
        ]);

        return [
            'success' => true,
            'transaction_id' => $transactionId
        ];
    }

    private function updateSubscription(int $subscriptionId, array $plan, string $transactionId): void
    {
        $days = (int) ($plan['billing_cycle_days'] ?? 30);
        $nextEndDate = date('Y-m-d H:i:s', strtotime('+' . $days . ' days'));

        $stmt = $this->db->prepare(
            "UPDATE subscriptions
             SET plan_id = ?,
                 status = 'active',
                 payment_status = 'paid',
                 start_date = NOW(),
                 end_date = ?,
                 updated_at = NOW()
             WHERE id = ?"
        );
        $stmt->execute([
            (int) $plan['id'],
            $nextEndDate,
            $subscriptionId
        ]);

        $this->logSecurityEvent('subscription_updated', 'low', 'Subscription updated after payment', [
            'subscription_id' => $subscriptionId,
            'plan_id' => (int) $plan['id'],
            'transaction_id' => $transactionId,
            'end_date' => $nextEndDate
        ]);
    }

    private function calculateNextBillingDate(array $plan): string
    {
        $interval = (int) ($plan['billing_cycle_days'] ?? 30);
        return date('Y-m-d H:i:s', strtotime('+' . $interval . ' days'));
    }

    private function blockSuspiciousActivity(string $ip, string $fingerprint, string $reason): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO blocked_ips (
                ip_address, reason, blocked_until, is_permanent
            ) VALUES (
                ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), 0
            )
            ON DUPLICATE KEY UPDATE reason = VALUES(reason)"
        );
        $stmt->execute([$ip, $reason]);

        if ($fingerprint !== '') {
            $stmt = $this->db->prepare(
                "UPDATE device_fingerprints
                 SET is_suspicious = 1,
                     suspicion_reason = ?
                 WHERE fingerprint_hash = ?"
            );
            $stmt->execute([$reason, $fingerprint]);
        }
    }

    private function recordSubscriptionAttempt(string $ip, string $email, string $type, string $status, array $details): void
    {
        $deviceFingerprint = $details['device_fingerprint'] ?? null;
        $riskScore = (int) ($details['fraud_score'] ?? 0);

        $stmt = $this->db->prepare(
            "INSERT INTO subscription_attempts (
                ip_address, email, attempt_type, status,
                attempt_data, device_fingerprint, risk_score, attempted_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([
            $ip,
            $email,
            $type,
            $status,
            json_encode($details, JSON_UNESCAPED_UNICODE),
            $deviceFingerprint,
            $riskScore
        ]);
    }

    private function getClientIp(Request $request): string
    {
        return RequestHelper::getClientIp($request);
    }

    protected function logSecurityEvent(
        string $eventType,
        string $riskLevel,
        string $message,
        array $meta = []
    ): void {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO subscription_events (
                    subscription_id, event_type, event_date, meta_json
                ) VALUES (?, ?, NOW(), ?)"
            );

            $stmt->execute([
                $meta['subscription_id'] ?? null,
                $eventType,
                json_encode([
                    'risk_level' => $riskLevel,
                    'message' => $message,
                    'meta' => $meta
                ], JSON_UNESCAPED_UNICODE)
            ]);

        } catch (Throwable $e) {
            $this->logger->warning('Failed to log security event', [
                'event_type' => $eventType,
                'message' => $e->getMessage()
            ]);
        }
    }
}
