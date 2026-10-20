<?php

require_once __DIR__ . '/../MigrationDatabase.php';

/**
 * Migration: 011_add_owner_contribution_permissions
 *
 * Phase 8: Add permissions for owner contribution operations.
 *
 * Permissions added:
 * - accounting.owner_contribution.create - Record new owner contributions
 * - accounting.owner_contribution.view - View contribution records
 * - accounting.owner_contribution.void - Void existing contributions
 *
 * Category: accounting_management
 * Resource: owner_contributions
 *
 * @see api/v1/src/Handlers/OwnerContributionHandler.php
 * @see docs/AUDIT_TRAIL.md
 */
class AddOwnerContributionPermissions
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? MigrationDatabase::connect($_ENV);
    }

    public function up(): void
    {
        echo "📌 Migration: 011_add_owner_contribution_permissions\n\n";

        try {
            // ─────────────────────────────────────────────────────────────────────
            // 1. Verify permissions table exists
            // ─────────────────────────────────────────────────────────────────────
            echo "   • التحقق من وجود جدول permissions...\n";

            $stmtTableCheck = $this->pdo->prepare(
                "SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'permissions'
                 LIMIT 1"
            );
            $stmtTableCheck->execute();
            if (!$stmtTableCheck->fetch()) {
                throw new Exception('جدول permissions غير موجود. تأكد من تشغيل الميجريشن الأساسي أولاً.');
            }
            echo "      ✅ جدول permissions موجود\n\n";

            // ─────────────────────────────────────────────────────────────────────
            // 2. Define owner contribution permissions
            // ─────────────────────────────────────────────────────────────────────
            $permissions = [
                [
                    'name' => 'accounting.owner_contribution.create',
                    'description' => 'إنشاء وتسجيل مساهمة مالك (رأس مال، قرض مالك، رصيد افتتاحي)',
                    'category' => 'accounting_management',
                ],
                [
                    'name' => 'accounting.owner_contribution.view',
                    'description' => 'عرض وقراءة سجلات مساهمات المالك',
                    'category' => 'accounting_management',
                ],
                [
                    'name' => 'accounting.owner_contribution.void',
                    'description' => 'إلغاء مساهمة مالك موجودة (عكس القيد المحاسبي)',
                    'category' => 'accounting_management',
                ],
            ];

            // ─────────────────────────────────────────────────────────────────────
            // 3. Insert permissions (skip if already exist)
            // ─────────────────────────────────────────────────────────────────────
            echo "   • إضافة صلاحيات مساهمات المالك...\n\n";

            $stmtInsert = $this->pdo->prepare("
                INSERT IGNORE INTO permissions
                (name, description, category)
                VALUES (:name, :description, :category)
            ");

            $insertedCount = 0;
            $skippedCount = 0;

            foreach ($permissions as $perm) {
                $stmtCheck = $this->pdo->prepare(
                    "SELECT id FROM permissions WHERE name = ? LIMIT 1"
                );
                $stmtCheck->execute([$perm['name']]);
                $existing = $stmtCheck->fetch();

                if ($existing) {
                    echo "      ℹ️  [{$perm['name']}] موجودة بالفعل (id={$existing['id']})\n";
                    $skippedCount++;
                    continue;
                }

                $stmtInsert->execute([
                    ':name'        => $perm['name'],
                    ':description' => $perm['description'],
                    ':category'    => $perm['category'],
                ]);

                $permissionId = (int) $this->pdo->lastInsertId();
                echo "      ✅ [{$perm['name']}] تم الإضافة (id={$permissionId})\n";
                echo "         Category: {$perm['category']}\n\n";
                $insertedCount++;
            }

            echo "   • ملخص:\n";
            echo "      • تم الإضافة: {$insertedCount}\n";
            echo "      • تم التخطي: {$skippedCount}\n";
            echo "      • الإجمالي: " . count($permissions) . "\n\n";

            // ─────────────────────────────────────────────────────────────────────
            // 4. Verification
            // ─────────────────────────────────────────────────────────────────────
            echo "   • التحقق من الإضافة...\n";

            $stmtVerify = $this->pdo->prepare(
                "SELECT COUNT(*) as count FROM permissions
                 WHERE category = 'accounting_management'
                 AND name LIKE 'accounting.owner_contribution.%'"
            );
            $stmtVerify->execute();
            $actualCount = (int) $stmtVerify->fetch(PDO::FETCH_ASSOC)['count'];

            if ($actualCount < count($permissions)) {
                throw new Exception(
                    "فشل التحقق: متوقع " . count($permissions) . " صلاحية، تم العثور على {$actualCount}"
                );
            }

            echo "      ✅ التحقق ناجح: {$actualCount} صلاحية موجودة\n\n";

            // ─────────────────────────────────────────────────────────────────────
            // 5. Display created permissions
            // ─────────────────────────────────────────────────────────────────────
            $stmtList = $this->pdo->prepare(
                "SELECT id, name, description
                 FROM permissions
                 WHERE name LIKE 'accounting.owner_contribution.%'
                 ORDER BY name"
            );
            $stmtList->execute();
            $createdPerms = $stmtList->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($createdPerms)) {
                echo "   • الصلاحيات المضافة:\n";
                foreach ($createdPerms as $perm) {
                    echo sprintf("      • [%d] %s\n", $perm['id'], $perm['name']);
                }
                echo "\n";
            }

            echo "✨ Migration 011 تم بنجاح!\n\n";
            echo "📝 ملاحظة: يجب تعيين هذه الصلاحيات للأدوار المناسبة (مدير الحسابات، المدير المالي)\n";

        } catch (Exception $e) {
            echo "❌ خطأ: " . $e->getMessage() . "\n";
            echo "تتبع الخطأ: " . $e->getTraceAsString() . "\n";
            throw $e;
        }
    }

    public function down(): void
    {
        echo "🔄 Rollback: 011_add_owner_contribution_permissions\n\n";

        try {
            echo "   • حذف صلاحيات مساهمات المالك...\n";

            // Count before deletion
            $stmtCount = $this->pdo->prepare(
                "SELECT COUNT(*) as count FROM permissions
                 WHERE name LIKE 'accounting.owner_contribution.%'"
            );
            $stmtCount->execute();
            $beforeCount = (int) $stmtCount->fetch(PDO::FETCH_ASSOC)['count'];

            echo "      ℹ️  عدد الصلاحيات قبل الحذف: {$beforeCount}\n";

            $stmtList = $this->pdo->prepare(
                "SELECT id, name FROM permissions
                 WHERE name LIKE 'accounting.owner_contribution.%'"
            );
            $stmtList->execute();
            $toDelete = $stmtList->fetchAll(PDO::FETCH_ASSOC);

            foreach ($toDelete as $perm) {
                echo "      • سيتم حذف: [{$perm['id']}] {$perm['name']}\n";
            }

            $stmtDelete = $this->pdo->prepare(
                "DELETE FROM permissions
                 WHERE name LIKE 'accounting.owner_contribution.%'"
            );
            $stmtDelete->execute();
            $deletedCount = $stmtDelete->rowCount();

            echo "      ✅ تم حذف {$deletedCount} صلاحية\n";

            // Verify deletion
            $stmtVerify = $this->pdo->prepare(
                "SELECT COUNT(*) as count FROM permissions
                 WHERE name LIKE 'accounting.owner_contribution.%'"
            );
            $stmtVerify->execute();
            $afterCount = (int) $stmtVerify->fetch(PDO::FETCH_ASSOC)['count'];

            if ($afterCount > 0) {
                throw new Exception("فشل الحذف: لا يزال هناك {$afterCount} صلاحية موجودة");
            }

            echo "      ✅ التحقق ناجح: لا توجد صلاحيات متبقية\n\n";

            echo "⚠️  ملاحظة: إذا كانت الصلاحيات مُعَيَّنة لأدوار، قد تحتاج إلى تنظيف role_permissions يدوياً\n\n";

            echo "✨ Rollback 011 تم بنجاح!\n";

        } catch (Exception $e) {
            echo "❌ خطأ في Rollback: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
}
