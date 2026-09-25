<?php

require_once __DIR__ . '/../MigrationDatabase.php';

/**
 * Migration: 002_create_roles_and_permissions
 * 
 * إنشاء نظام الأدوار والصلاحيات (RBAC):
 * - roles (جدول الأدوار الأساسية)
 * - role_permissions (ربط الأدوار بالصلاحيات)
 * 
 * المتطلبات:
 * - يجب تشغيل Migration 001 أولاً (إنشاء جدول permissions)
 * 
 * البيانات المدرجة:
 * - 7 أدوار نظام أساسية (super_admin, admin, manager, cashier, etc.)
 * - توزيع الصلاحيات على كل دور حسب المسؤوليات
 */

class CreateRolesAndPermissions
{
    private $pdo;

    /** Reuse the runner's PDO connection; direct construction uses the shared env resolver. */
    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? MigrationDatabase::connect($_ENV);
    }

    public function up()
    {
        echo "📌 Migration: 002_create_roles_and_permissions\n\n";
        
        try {
            // ──────────────────────────────────────────────────────────
            // 1. إنشاء الأدوار (roles)
            // ──────────────────────────────────────────────────────────
            
            echo "   • إنشاء الأدوار الأساسية...\n";
            
            $roles = [
                [1, 'super_admin', 'Super Administrator', 'Super Administrator with full system access', 1, NULL],
                [2, 'admin', 'Administrator', 'System Administrator', 1, NULL],
                [3, 'manager', 'Store Manager', 'Store Manager', 1, NULL],
                [4, 'cashier', 'Cashier', 'Sales and Transactions Handler', 1, NULL],
                [5, 'inventory_clerk', 'Inventory Clerk', 'Inventory Management Staff', 1, NULL],
                [9, 'finance_officer', 'Finance Officer', 'Responsible for invoice approvals and cash handling', 0, NULL],
                [10, 'branch_manager', 'Branch Manager', 'Branch Manager', 1, NULL],
            ];
            
            $stmt = $this->pdo->prepare("
                INSERT INTO roles 
                (id, name, display_name, description, is_system_role, tenant_id, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE updated_at = NOW()
            ");
            
            foreach ($roles as $role) {
                $stmt->execute($role);
            }
            
            echo "      ✅ تم إنشاء " . count($roles) . " دور\n";
            
            // ──────────────────────────────────────────────────────────
            // 2. إنشاء ربط الأدوار بالصلاحيات (role_permissions)
            // ──────────────────────────────────────────────────────────
            
            echo "   • إعداد صلاحيات الأدوار...\n";
            
            // Super Admin: جميع الصلاحيات
            $superAdminPerms = $this->pdo->query(
                "SELECT id FROM permissions WHERE is_active = 1"
            )->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
                VALUES (?, ?, NOW())
            ");
            
            $superAdminCount = 0;
            foreach ($superAdminPerms as $perm) {
                $stmt->execute([1, $perm['id']]);
                $superAdminCount++;
            }
            
            echo "      ✅ تم إسناد {$superAdminCount} صلاحية لـ Super Admin\n";
            
            // Admin: صلاحيات إدارية (استثناء صلاحيات النظام)
            $adminPerms = $this->pdo->query(
                "SELECT id FROM permissions WHERE is_active = 1 AND category NOT IN ('system', 'security')"
            )->fetchAll(PDO::FETCH_ASSOC);
            
            $adminCount = 0;
            foreach ($adminPerms as $perm) {
                $stmt->execute([2, $perm['id']]);
                $adminCount++;
            }
            
            echo "      ✅ تم إسناد {$adminCount} صلاحية لـ Admin\n";
            
            // Manager: صلاحيات مدير المتجر
            $managerPerms = $this->pdo->query(
                "SELECT id FROM permissions WHERE category IN ('sales_management', 'purchase_management', 'inventory_management', 'reports')"
            )->fetchAll(PDO::FETCH_ASSOC);
            
            $managerCount = 0;
            foreach ($managerPerms as $perm) {
                $stmt->execute([3, $perm['id']]);
                $managerCount++;
            }
            
            echo "      ✅ تم إسناد {$managerCount} صلاحية لـ Manager\n";
            
            // Cashier: صلاحيات الكاشير
            $cashierPerms = $this->pdo->query(
                "SELECT id FROM permissions WHERE category IN ('sales_management', 'pos')"
            )->fetchAll(PDO::FETCH_ASSOC);
            
            $cashierCount = 0;
            foreach ($cashierPerms as $perm) {
                $stmt->execute([4, $perm['id']]);
                $cashierCount++;
            }
            
            echo "      ✅ تم إسناد {$cashierCount} صلاحية لـ Cashier\n";
            
            // Inventory Clerk: صلاحيات إدارة المخزون
            $inventoryPerms = $this->pdo->query(
                "SELECT id FROM permissions WHERE category IN ('inventory_management')"
            )->fetchAll(PDO::FETCH_ASSOC);
            
            $inventoryCount = 0;
            foreach ($inventoryPerms as $perm) {
                $stmt->execute([5, $perm['id']]);
                $inventoryCount++;
            }
            
            echo "      ✅ تم إسناد {$inventoryCount} صلاحية لـ Inventory Clerk\n";
            
            echo "\n✨ Migration 002 تم بنجاح!\n";
            
        } catch (Exception $e) {
            echo "❌ خطأ: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    public function down()
    {
        echo "🔄 Rollback: 002_create_roles_and_permissions\n";
        
        try {
            echo "⚠️  لا يتم حذف البيانات الأساسية - الرجاء حذف يدويًا إذا لزم الأمر\n";
        } catch (Exception $e) {
            echo "❌ خطأ: " . $e->getMessage() . "\n";
        }
    }
}
