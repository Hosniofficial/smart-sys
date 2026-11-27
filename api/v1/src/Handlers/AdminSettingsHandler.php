<?php

declare(strict_types=1);

namespace App\Handlers;

use PDO;
use Exception;
use Throwable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Services\MonologHandler;
use App\Services\CurrencyRateService;
use App\Utils\SuperAdminHelper;

/**
 * Handles tenant settings, company-logo uploads, supported currencies, and exchange-rate endpoints.
 *
 * Delegates settings persistence to SettingsHandler, authorization checks to RBACHandler,
 * and exchange-rate reads/refreshes to CurrencyRateService.
 */
class AdminSettingsHandler extends BaseHandler
{
    private SettingsHandler $settingsHandler;

    public function __construct(PDO $db)
    {
        parent::__construct($db);
        $this->logger = MonologHandler::getInstance('admin_settings');
        $this->settingsHandler = new SettingsHandler($db);
    }

    /**
     * Return user data when the requested settings permission is granted; otherwise return null.
     *
     * Tenant owners may read settings directly. Other requests use RBAC permissions,
     * with a super-admin fallback.
     */
    private function checkSettingsAccess(Request $request, bool $requireManage = false): ?array
    {
        $userData = $request->getAttribute('user');

        if (is_object($userData)) {
            $userData = (array) $userData;
        } elseif (!is_array($userData)) {
            $userData = [];
        }

        $userId = isset($userData['id']) ? (int) $userData['id'] : null;
        $roleId = isset($userData['role_id']) ? (int) $userData['role_id'] : null;
        $role = strtolower((string) ($userData['role'] ?? ''));
        $isOwner = (bool) ($userData['is_owner'] ?? false);

        if (!$userId) {
            return null;
        }

        // Tenant ownership bypasses the read permission check, but not settings management.
        if ($isOwner && !$requireManage) {
            return $userData;
        }

        try {
            $rbac = new RBACHandler($this->db);

            if ($requireManage) {
                $hasPermission = $rbac->hasPermission($userId, 'settings.manage');
            } else {
                $hasPermission =
                    $rbac->hasPermission($userId, 'settings.view') ||
                    $rbac->hasPermission($userId, 'settings.manage');
            }

            if (!$hasPermission) {
                $hasPermission = SuperAdminHelper::isAdminOrAbove($userData);
            }

            return $hasPermission ? $userData : null;
        } catch (Throwable $e) {
            $this->logger->error('Error checking settings permissions', [
                'message' => $e->getMessage(),
                'user_id' => $userId
            ]);

            return null;
        }
    }

    /** Return settings for the current tenant to users with view or manage access. */
    public function getSettings(Request $request, Response $response): Response
    {
        $userData = $this->checkSettingsAccess($request, false);

        if (!$userData) {
            $this->logger->warning('Settings access denied', [
                'user' => $request->getAttribute('user')
            ]);
            return $this->errorResponse($response, 'ليس لديك إذن للوصول إلى الإعدادات', 403);
        }

        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $this->logger->info('Fetching settings', [
                'tenant_id' => $tenantId,
                'user_id' => $userData['id'] ?? null,
                'is_owner' => $userData['is_owner'] ?? false
            ]);

            $settings = $this->settingsHandler->getSettings((int) $tenantId);

            return $this->successResponse($response, $settings, 200);
        } catch (Throwable $e) {
            $this->logger->error('Failed to retrieve settings', [
                'message' => $e->getMessage(),
                'tenant_id' => $this->extractTenantId($request)
            ]);

            return $this->errorResponse($response, 'فشل في جلب الإعدادات', 500);
        }
    }

    /** Return effective print settings for an authenticated user's tenant branch. */
    public function getBranchPrintSettings(Request $request, Response $response): Response
    {
        $userData = $request->getAttribute('user');
        $userData = is_object($userData) ? (array) $userData : (is_array($userData) ? $userData : []);
        if (empty($userData['id'])) {
            return $this->errorResponse($response, 'يجب تسجيل الدخول لعرض إعدادات الطباعة.', 401);
        }

        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }
            $branchId = $this->resolvePrintSettingsBranch($request, $userData, $tenantId);
            return $this->successResponse(
                $response,
                ['branch_id' => $branchId] + $this->settingsHandler->getBranchPrintSettings($tenantId, $branchId),
                200
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, $e->getMessage(), 400);
        } catch (\DomainException $e) {
            return $this->errorResponse($response, $e->getMessage(), 403);
        } catch (Throwable $e) {
            $this->logger->error('Failed to retrieve branch print settings', [
                'message' => $e->getMessage(),
                'tenant_id' => $this->extractTenantId($request),
                'user_id' => $userData['id'],
            ]);
            return $this->errorResponse($response, 'فشل في جلب إعدادات طباعة الفرع', 500);
        }
    }

    /** Save print overrides for one authorized branch. */
    public function updateBranchPrintSettings(Request $request, Response $response): Response
    {
        $userData = $this->checkSettingsAccess($request, true);
        if (!$userData) {
            return $this->errorResponse($response, 'ليس لديك إذن لتحديث إعدادات الطباعة', 403);
        }

        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }
            $data = $request->getParsedBody();
            $data = is_array($data) ? $data : [];
            $branchId = $this->resolvePrintSettingsBranch($request, $userData, $tenantId, $data['branch_id'] ?? null);
            $settings = $data['settings'] ?? null;
            if (!is_array($settings)) {
                return $this->errorResponse($response, 'بيانات إعدادات الطباعة غير صالحة', 400);
            }

            $this->settingsHandler->updateBranchPrintSettings(
                $tenantId,
                $branchId,
                (int) $userData['id'],
                $settings
            );
            return $this->successResponse(
                $response,
                ['updated' => true, 'branch_id' => $branchId]
                    + $this->settingsHandler->getBranchPrintSettings($tenantId, $branchId),
                200
            );
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, $e->getMessage(), 400);
        } catch (\DomainException $e) {
            return $this->errorResponse($response, $e->getMessage(), 403);
        } catch (Throwable $e) {
            $this->logger->error('Failed to update branch print settings', [
                'message' => $e->getMessage(),
                'tenant_id' => $this->extractTenantId($request),
                'user_id' => $userData['id'] ?? null,
            ]);
            return $this->errorResponse($response, 'فشل في تحديث إعدادات طباعة الفرع', 500);
        }
    }

    /** Remove branch print overrides and restore inheritance from the tenant. */
    public function resetBranchPrintSettings(Request $request, Response $response): Response
    {
        $userData = $this->checkSettingsAccess($request, true);
        if (!$userData) {
            return $this->errorResponse($response, 'ليس لديك إذن لتحديث إعدادات الطباعة', 403);
        }

        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }
            $branchId = $this->resolvePrintSettingsBranch($request, $userData, $tenantId);
            $this->settingsHandler->resetBranchPrintSettings($tenantId, $branchId, (int) $userData['id']);
            return $this->successResponse($response, ['reset' => true, 'branch_id' => $branchId], 200);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, $e->getMessage(), 400);
        } catch (\DomainException $e) {
            return $this->errorResponse($response, $e->getMessage(), 403);
        } catch (Throwable $e) {
            $this->logger->error('Failed to reset branch print settings', [
                'message' => $e->getMessage(),
                'tenant_id' => $this->extractTenantId($request),
                'user_id' => $userData['id'] ?? null,
            ]);
            return $this->errorResponse($response, 'فشل في استعادة إعدادات الطباعة الافتراضية', 500);
        }
    }

    /** Validate tenant ownership and the authenticated user's branch assignment. */
    private function resolvePrintSettingsBranch(
        Request $request,
        array $userData,
        int $tenantId,
        $branchIdParam = null
    ): int {
        if ($branchIdParam === null) {
            $branchIdParam = $request->getQueryParams()['branch_id'] ?? null;
        }
        $branchId = filter_var($branchIdParam, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($branchId === false) {
            throw new \InvalidArgumentException('معرف الفرع غير صالح.');
        }

        $branch = $this->db->prepare('SELECT id FROM branches WHERE id = ? AND tenant_id = ? LIMIT 1');
        $branch->execute([$branchId, $tenantId]);
        if (!$branch->fetchColumn()) {
            throw new \InvalidArgumentException('الفرع غير موجود ضمن هذه المنشأة.');
        }

        if (!SuperAdminHelper::is($userData)) {
            $assignment = $this->db->prepare(
                'SELECT u.branch_id, u.is_owner, LOWER(r.name) AS role_name
                 FROM users u
                 LEFT JOIN roles r ON r.id = u.role_id
                 WHERE u.id = ? AND u.tenant_id = ?
                 LIMIT 1'
            );
            $assignment->execute([(int) $userData['id'], $tenantId]);
            $assignmentData = $assignment->fetch(PDO::FETCH_ASSOC);
            if (!$assignmentData) {
                throw new \DomainException('لا يمكنك الوصول إلى إعدادات هذا الفرع.');
            }
            $canManageAllBranches = !empty($assignmentData['is_owner'])
                || $assignmentData['role_name'] === 'admin';
            $assignedBranchId = $assignmentData['branch_id'];
            if (!$canManageAllBranches
                && ($assignedBranchId === null || (int) $assignedBranchId !== (int) $branchId)) {
                throw new \DomainException('لا يمكنك الوصول إلى إعدادات فرع غير مخصص لحسابك.');
            }
        }

        return (int) $branchId;
    }

    /** Update the current tenant's settings for users with manage access. */
    public function updateSettings(Request $request, Response $response): Response
    {
        $userData = $this->checkSettingsAccess($request, true);

        if (!$userData) {
            return $this->errorResponse($response, 'ليس لديك إذن لتحديث الإعدادات', 403);
        }

        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $parsed = $request->getParsedBody();
            $parsed = is_array($parsed) ? $parsed : [];
            $settings = $parsed['settings'] ?? [];

            if (!is_array($settings)) {
                return $this->errorResponse($response, 'بيانات الإعدادات غير صالحة', 400);
            }

            $userId = isset($userData['id']) ? (int) $userData['id'] : null;
            $this->settingsHandler->updateSettings($settings, (int) $tenantId, $userId);

            return $this->successResponse($response, [
                'updated' => true
            ], 200);
        } catch (Throwable $e) {
            $this->logger->error('Failed to update settings', [
                'message' => $e->getMessage(),
                'tenant_id' => $this->extractTenantId($request),
                'user_id' => $userData['id'] ?? null
            ]);

            return $this->errorResponse($response, 'فشل في تحديث الإعدادات', 500);
        }
    }

    /** Apply submitted settings and optionally upload and persist a company logo. */
    public function uploadLogo(Request $request, Response $response): Response
    {
        $userData = $this->checkSettingsAccess($request, true);

        if (!$userData) {
            return $this->errorResponse($response, 'ليس لديك إذن لتحديث الإعدادات', 403);
        }

        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $directory = __DIR__ . '/../../../../public/uploads/logos/';
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new Exception('تعذر إنشاء مجلد رفع الشعارات');
            }

            $uploadedFiles = $request->getUploadedFiles();
            $parsedBody = $request->getParsedBody();
            $parsedBody = is_array($parsedBody) ? $parsedBody : [];
            $userId = isset($userData['id']) ? (int) $userData['id'] : null;

            $settingsJson = $parsedBody['settings'] ?? '[]';
            $settings = json_decode((string) $settingsJson, true);

            if (!empty($settings)) {
                if (!is_array($settings)) {
                    return $this->errorResponse($response, 'تنسيق settings غير صالح', 400);
                }

                if (isset($settings['company.currency'])) {
                    $supportedCurrencies = ['EGP', 'USD', 'EUR', 'SAR', 'AED', 'KWD', 'QAR', 'BHD', 'OMR'];
                    $currency = strtoupper((string) $settings['company.currency']);

                    if (!in_array($currency, $supportedCurrencies, true)) {
                        return $this->errorResponse($response, 'العملة غير مدعومة: ' . $currency, 400);
                    }

                    $settings['company.currency'] = $currency;
                    $settings['company.currency_code'] = $currency;

                    $currencySymbols = [
                        'EGP' => 'ج.م',
                        'USD' => '$',
                        'EUR' => '€',
                        'SAR' => 'ر.س',
                        'AED' => 'د.إ',
                        'KWD' => 'د.ك',
                        'QAR' => 'ر.ق',
                        'BHD' => 'د.ب',
                        'OMR' => 'ر.ع'
                    ];
                    $settings['company.currency_symbol'] = $currencySymbols[$currency] ?? '';
                }

                $this->settingsHandler->updateSettings($settings, (int) $tenantId, $userId);
            }

            if (isset($uploadedFiles['logo'])) {
                $logo = $uploadedFiles['logo'];

                if ($logo->getError() !== UPLOAD_ERR_OK) {
                    return $this->errorResponse($response, 'خطأ في رفع ملف الشعار', 400);
                }

                $clientFilename = (string) $logo->getClientFilename();
                $safeOriginal = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $clientFilename);
                $extension = strtolower(pathinfo($safeOriginal, PATHINFO_EXTENSION));

                $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
                if (!in_array($extension, $allowedExtensions, true)) {
                    return $this->errorResponse($response, 'نوع ملف الشعار غير مدعوم', 400);
                }

                $filename = uniqid('logo_', true) . '.' . $extension;
                $fullPath = $directory . $filename;

                $logo->moveTo($fullPath);

                $relativePath = 'uploads/logos/' . $filename;
                $this->settingsHandler->set('company.logo', $relativePath, null, (int) $tenantId, $userId);

                return $this->successResponse($response, [
                    'logo_path' => $relativePath
                ], 200);
            }

            return $this->successResponse($response, [
                'updated' => true
            ], 200);
        } catch (Throwable $e) {
            $this->logger->error('Failed to upload logo or update settings', [
                'message' => $e->getMessage(),
                'tenant_id' => $this->extractTenantId($request),
                'user_id' => $userData['id'] ?? null
            ]);

            return $this->errorResponse($response, 'فشل في رفع الشعار', 500);
        }
    }

    /** Return the tenant's configured exchange rates. */
    public function getExchangeRatesAPI(Request $request, Response $response): Response
    {
        if (!$this->checkSettingsAccess($request, false)) {
            return $this->errorResponse($response, 'ليس لديك إذن للوصول إلى أسعار الصرف', 403);
        }

        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $rates = (new CurrencyRateService($this->db))->getRates((int) $tenantId);
            return $this->successResponse($response, $rates, 200);
        } catch (Throwable $e) {
            $this->logger->error('Failed to retrieve currency exchange rates', [
                'message' => $e->getMessage(),
                'tenant_id' => $this->extractTenantId($request),
            ]);
            return $this->errorResponse($response, 'تعذر تحديث أو جلب أسعار الصرف: ' . $e->getMessage(), 503);
        }
    }

    /** Refresh exchange rates for the tenant; requires settings management access. */
    public function refreshExchangeRatesAPI(Request $request, Response $response): Response
    {
        $userData = $this->checkSettingsAccess($request, true);
        if (!$userData) {
            return $this->errorResponse($response, 'ليس لديك إذن لإدارة أسعار الصرف', 403);
        }

        try {
            $tenantId = $this->extractTenantId($request);
            if (!$tenantId) {
                return $this->errorResponse($response, 'مطلوب معرف المستأجر (Tenant ID).', 403);
            }

            $result = (new CurrencyRateService($this->db))->refresh((int) $tenantId);
            return $this->successResponse($response, $result, 200);
        } catch (Throwable $e) {
            $this->logger->error('Failed to refresh currency exchange rates', [
                'message' => $e->getMessage(),
                'tenant_id' => $this->extractTenantId($request),
                'user_id' => $userData['id'] ?? null,
            ]);
            return $this->errorResponse($response, 'فشل تحديث أسعار الصرف: ' . $e->getMessage(), 503);
        }
    }
}
