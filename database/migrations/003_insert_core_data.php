<?php

require_once __DIR__ . '/../MigrationDatabase.php';

/**
 * Migration: 003_insert_core_data
 * 
 * إدراج البيانات الأساسية للنظام:
 * - الأدوار (roles)
 * - توزيع الصلاحيات على الأدوار (role_permissions)
 * - الوحدات (units)
 * - طرق الدفع (payment_methods)
 * - الخطط (plans)
 * 
 * المتطلبات:
 * - يجب تشغيل Migrations 001 و 002 أولاً
 */

class InsertCoreData
{
    private $pdo;

    /** Reuse the runner's PDO connection; direct construction uses the shared env resolver. */
    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? MigrationDatabase::connect($_ENV);
    }

    public function up()
    {
        echo "📌 Migration: 003_insert_core_data\n\n";
        
        try {
            // ──────────────────────────────────────────────────────────
            // 1. إدراج الأدوار الأساسية
            // ──────────────────────────────────────────────────────────
            
            echo "   • إدراج الأدوار الأساسية...\n";
            
            $this->insertRoles();
            
            echo "      ✅ تم إدراج الأدوار\n";
            
            // ──────────────────────────────────────────────────────────
            // 2. إدراج توزيع الصلاحيات على الأدوار
            // ──────────────────────────────────────────────────────────
            
            echo "   • تعيين الصلاحيات للأدوار...\n";
            
            $this->assignPermissionsToRoles();
            
            echo "      ✅ تم تعيين الصلاحيات\n";
            
            echo "\n✨ Migration 003 تم بنجاح!\n";
            
        } catch (Exception $e) {
            echo "❌ خطأ: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    public function down()
    {
        echo "🔄 Rollback: 003_insert_core_data\n";
        echo "⚠️  لا يتم حذف البيانات الأساسية - الرجاء حذفها يدويًا إذا لزم الأمر\n";
    }

    private function insertRoles()
    {
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
            INSERT IGNORE INTO roles 
            (id, name, display_name, description, is_system_role, tenant_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        
        foreach ($roles as $role) {
            $stmt->execute($role);
        }
    }

    private function assignPermissionsToRoles()
    {
        // Super Admin: جميع الصلاحيات
        $superAdminPerms = $this->pdo->query(
            "SELECT id FROM permissions WHERE is_active = 1"
        )->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
            VALUES (?, ?, NOW())
        ");
        
        foreach ($superAdminPerms as $perm) {
            $stmt->execute([1, $perm['id']]);
        }
        
        // Admin: جميع الصلاحيات ما عدا الأمان والنظام
        $adminPerms = $this->pdo->query(
            "SELECT id FROM permissions WHERE is_active = 1 AND category NOT IN ('system', 'security')"
        )->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($adminPerms as $perm) {
            $stmt->execute([2, $perm['id']]);
        }
        
        // Manager: إدارة العمليات
        $managerPerms = $this->pdo->query(
            "SELECT id FROM permissions 
            WHERE category IN ('sales_management', 'purchase_management', 'inventory_management', 'reports', 'payments')"
        )->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($managerPerms as $perm) {
            $stmt->execute([3, $perm['id']]);
        }
        
        // Cashier: المبيعات والدفع فقط
        $cashierPerms = $this->pdo->query(
            "SELECT id FROM permissions WHERE category IN ('sales_management', 'pos', 'payments')"
        )->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($cashierPerms as $perm) {
            $stmt->execute([4, $perm['id']]);
        }
        
        // Inventory Clerk: المخزون فقط
        $inventoryPerms = $this->pdo->query(
            "SELECT id FROM permissions WHERE category IN ('inventory_management')"
        )->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($inventoryPerms as $perm) {
            $stmt->execute([5, $perm['id']]);
        }
        
        // Finance Officer: الدفعات والتقارير
        $financePerms = $this->pdo->query(
            "SELECT id FROM permissions WHERE category IN ('payments', 'reports')"
        )->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($financePerms as $perm) {
            $stmt->execute([9, $perm['id']]);
        }
        
        // Branch Manager: إدارة الفرع
        $branchPerms = $this->pdo->query(
            "SELECT id FROM permissions 
            WHERE category IN ('sales_management', 'purchase_management', 'inventory_management', 'reports', 'payments', 'pos')"
        )->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($branchPerms as $perm) {
            $stmt->execute([10, $perm['id']]);
        }
    }
}
