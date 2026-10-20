<?php

require_once __DIR__ . '/../MigrationDatabase.php';

/**
 * Migration: 010_add_cash_negative_setting
 *
 * Phase 6: Add pos.cash_session.allow_negative_intraday setting
 *
 * This setting controls whether cash accounts can go negative during an open cashier session.
 * When enabled (true), LiquidityPolicyService allows negative cash balance ONLY when:
 * 1. Account type is 'asset' (Cash)
 * 2. Cashier session is currently open
 * 3. This setting is enabled
 *
 * Default: false (strict liquidity control)
 *
 * @see api/v1/src/Services/LiquidityPolicyService.php
 * @see docs/LIQUIDITY_VALIDATION_MAPPING.md
 */
class AddCashNegativeSetting
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? MigrationDatabase::connect($_ENV);
    }

    public function up(): void
    {
        echo "📌 Migration: 010_add_cash_negative_setting\n\n";

        try {
            // ─────────────────────────────────────────────────────────────────────
            // 1. Verify settings table exists
            // ─────────────────────────────────────────────────────────────────────
            echo "   • التحقق من وجود جدول settings...\n";

            $stmtTableCheck = $this->pdo->prepare(
                "SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings'
                 LIMIT 1"
            );
            $stmtTableCheck->execute();
            if (!$stmtTableCheck->fetch()) {
                throw new Exception('جدول settings غير موجود. تأكد من تشغيل الميجريشن الأساسي أولاً.');
            }
            echo "      ✅ جدول settings موجود\n\n";

            // ─────────────────────────────────────────────────────────────────────
            // 2. Verify tenants table exists (FK constraint)
            // ─────────────────────────────────────────────────────────────────────
            echo "   • التحقق من وجود جدول tenants...\n";

            $stmtTenantsCheck = $this->pdo->prepare(
                "SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'
                 LIMIT 1"
            );
            $stmtTenantsCheck->execute();
            if (!$stmtTenantsCheck->fetch()) {
                throw new Exception('جدول tenants غير موجود. تأكد من تشغيل الميجريشن الأساسي أولاً.');
            }
            echo "      ✅ جدول tenants موجود\n\n";

            // ─────────────────────────────────────────────────────────────────────
            // 3. Get all active tenants
            // ─────────────────────────────────────────────────────────────────────
            echo "   • جلب قائمة جميع المستأجرين...\n";

            $stmtTenants = $this->pdo->query(
                "SELECT id, company_name FROM tenants ORDER BY id"
            );
            $tenants = $stmtTenants->fetchAll(PDO::FETCH_ASSOC);
            $tenantCount = count($tenants);

            echo "      ✅ تم العثور على {$tenantCount} مستأجر\n\n";

            if ($tenantCount === 0) {
                echo "      ⚠️  لا يوجد مستأجرون - سيتم تخطي إضافة الإعدادات\n";
                echo "\n✨ Migration 010 تم بنجاح (لا توجد إعدادات للإضافة)\n";
                return;
            }

            // ─────────────────────────────────────────────────────────────────────
            // 4. Insert setting for each tenant
            // ─────────────────────────────────────────────────────────────────────
            echo "   • إضافة إعداد pos.cash_session.allow_negative_intraday لكل مستأجر...\n\n";

            $stmtInsert = $this->pdo->prepare("
                INSERT INTO settings (tenant_id, key_name, value, type, updated_at)
                VALUES (:tenant_id, :key_name, :value, :type, NOW())
                ON DUPLICATE KEY UPDATE
                    value = IF(value IS NULL, VALUES(value), value),
                    type = VALUES(type),
                    updated_at = NOW()
            ");

            $settingKey = 'pos.cash_session.allow_negative_intraday';
            $settingValue = '0';  // Default: false (strict control)
            $settingType = 'boolean';

            $insertedCount = 0;
            $skippedCount = 0;

            foreach ($tenants as $tenant) {
                $tenantId = (int) $tenant['id'];
                $businessName = $tenant['company_name'] ?? "Tenant {$tenantId}";

                // Check if setting already exists
                $stmtCheck = $this->pdo->prepare(
                    "SELECT value FROM settings WHERE tenant_id = ? AND key_name = ? LIMIT 1"
                );
                $stmtCheck->execute([$tenantId, $settingKey]);
                $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                if ($existing !== false && $existing['value'] !== null) {
                    echo "      ℹ️  [{$tenantId}] {$businessName}: الإعداد موجود بالفعل (value={$existing['value']})\n";
                    $skippedCount++;
                    continue;
                }

                // Insert setting
                $stmtInsert->execute([
                    ':tenant_id' => $tenantId,
                    ':key_name' => $settingKey,
                    ':value' => $settingValue,
                    ':type' => $settingType,
                ]);

                echo "      ✅ [{$tenantId}] {$businessName}: تم إضافة الإعداد (value=0/false)\n";
                $insertedCount++;
            }

            echo "\n   • ملخص:\n";
            echo "      • تم الإضافة: {$insertedCount}\n";
            echo "      • تم التخطي: {$skippedCount}\n";
            echo "      • الإجمالي: {$tenantCount}\n\n";

            // ─────────────────────────────────────────────────────────────────────
            // 5. Verification
            // ─────────────────────────────────────────────────────────────────────
            echo "   • التحقق من الإضافة...\n";

            $stmtVerify = $this->pdo->prepare(
                "SELECT COUNT(*) as count FROM settings WHERE key_name = ?"
            );
            $stmtVerify->execute([$settingKey]);
            $verifyResult = $stmtVerify->fetch(PDO::FETCH_ASSOC);
            $actualCount = (int) $verifyResult['count'];

            if ($actualCount !== $tenantCount) {
                throw new Exception(
                    "فشل التحقق: متوقع {$tenantCount} إعداد، تم العثور على {$actualCount}"
                );
            }

            echo "      ✅ التحقق ناجح: {$actualCount} إعداد موجود\n\n";

            // ─────────────────────────────────────────────────────────────────────
            // 6. Display sample setting
            // ─────────────────────────────────────────────────────────────────────
            $stmtSample = $this->pdo->prepare(
                "SELECT s.tenant_id, t.company_name, s.value, s.type, s.updated_at
                 FROM settings s
                 INNER JOIN tenants t ON t.id = s.tenant_id
                 WHERE s.key_name = ?
                 ORDER BY s.tenant_id
                 LIMIT 3"
            );
            $stmtSample->execute([$settingKey]);
            $samples = $stmtSample->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($samples)) {
                echo "   • عينة من الإعدادات المضافة:\n";
                foreach ($samples as $sample) {
                    echo sprintf(
                        "      • Tenant %d (%s): value=%s, type=%s, updated=%s\n",
                        $sample['tenant_id'],
                        $sample['company_name'],
                        $sample['value'],
                        $sample['type'],
                        $sample['updated_at']
                    );
                }
                echo "\n";
            }

            echo "✨ Migration 010 تم بنجاح!\n\n";

            echo "📝 ملاحظات:\n";
            echo "   • الإعداد الافتراضي: false (0) - التحكم الصارم بالسيولة\n";
            echo "   • لتفعيل السماح بالرصيد السالب أثناء الجلسة:\n";
            echo "     UPDATE settings SET value = '1' WHERE key_name = '{$settingKey}' AND tenant_id = <tenant_id>;\n";
            echo "   • الإعداد يُقرأ من LiquidityPolicyService::validateLiquidityBalance()\n";
            echo "   • الشرط: يُطبق فقط على الحسابات النقدية (accounts.type='asset') مع جلسة كاشير مفتوحة\n";

        } catch (Exception $e) {
            echo "❌ خطأ: " . $e->getMessage() . "\n";
            echo "تتبع الخطأ: " . $e->getTraceAsString() . "\n";
            throw $e;
        }
    }

    public function down(): void
    {
        echo "🔄 Rollback: 010_add_cash_negative_setting\n\n";

        try {
            echo "   • حذف إعداد pos.cash_session.allow_negative_intraday...\n";

            $settingKey = 'pos.cash_session.allow_negative_intraday';

            // Count before deletion
            $stmtCount = $this->pdo->prepare(
                "SELECT COUNT(*) as count FROM settings WHERE key_name = ?"
            );
            $stmtCount->execute([$settingKey]);
            $beforeCount = (int) $stmtCount->fetch(PDO::FETCH_ASSOC)['count'];

            echo "      ℹ️  عدد الإعدادات قبل الحذف: {$beforeCount}\n";

            // Delete setting
            $stmtDelete = $this->pdo->prepare(
                "DELETE FROM settings WHERE key_name = ?"
            );
            $stmtDelete->execute([$settingKey]);
            $deletedCount = $stmtDelete->rowCount();

            echo "      ✅ تم حذف {$deletedCount} إعداد\n";

            // Verify deletion
            $stmtVerify = $this->pdo->prepare(
                "SELECT COUNT(*) as count FROM settings WHERE key_name = ?"
            );
            $stmtVerify->execute([$settingKey]);
            $afterCount = (int) $stmtVerify->fetch(PDO::FETCH_ASSOC)['count'];

            if ($afterCount > 0) {
                throw new Exception("فشل الحذف: لا يزال هناك {$afterCount} إعداد موجود");
            }

            echo "      ✅ التحقق ناجح: لا توجد إعدادات متبقية\n\n";
            echo "✨ Rollback 010 تم بنجاح!\n";

        } catch (Exception $e) {
            echo "❌ خطأ في Rollback: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
}
