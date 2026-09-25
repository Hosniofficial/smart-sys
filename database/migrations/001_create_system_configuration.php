<?php

require_once __DIR__ . '/../MigrationDatabase.php';

/**
 * Migration: 001_create_system_configuration
 * 
 * إنشاء الجداول الأساسية والصلاحيات الأساسية للنظام
 * - permissions (الصلاحيات الكاملة)
 */

class CreateSystemConfiguration
{
    private $pdo;

    /** Reuse the runner's PDO connection; direct construction uses the shared env resolver. */
    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? MigrationDatabase::connect($_ENV);
    }

    public function up()
    {
        echo "📌 Migration: 001_create_system_configuration\n\n";
        
        try {
            // ──────────────────────────────────────────────────────────
            // إنشاء جدول permissions إذا لم يكن موجوداً
            // ──────────────────────────────────────────────────────────
            
            echo "   • التحقق من جدول permissions...\n";
            
            $tableExistsStmt = $this->pdo->prepare(
                "SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
            );
            $tableExistsStmt->execute(['permissions']);
            $tableExists = $tableExistsStmt->fetch();
            
            if (!$tableExists) {
                echo "   • إنشاء جدول permissions...\n";
                
                $this->pdo->exec("
                    CREATE TABLE permissions (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(255) NOT NULL UNIQUE,
                        display_name VARCHAR(255),
                        description TEXT,
                        category VARCHAR(100),
                        resource VARCHAR(100),
                        action VARCHAR(100),
                        is_active TINYINT DEFAULT 1,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_category (category),
                        INDEX idx_resource (resource)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
                
                echo "      ✅ تم إنشاء جدول permissions\n";
            } else {
                echo "      ℹ️  جدول permissions موجود بالفعل\n";
            }
            
            // ──────────────────────────────────────────────────────────
            // إدراج الصلاحيات الأساسية
            // ──────────────────────────────────────────────────────────
            
            echo "   • إدراج الصلاحيات الأساسية...\n";
            
            $existingCount = $this->pdo->query("SELECT COUNT(*) as count FROM permissions")->fetch()['count'];
            
            if ($existingCount == 0) {
                $this->insertDefaultPermissions();
                echo "      ✅ تم إدراج الصلاحيات الأساسية\n";
            } else {
                echo "      ℹ️  الصلاحيات موجودة بالفعل ({$existingCount} صلاحية)\n";
            }
            
            echo "\n✨ Migration 001 تم بنجاح!\n";
            
        } catch (Exception $e) {
            echo "❌ خطأ: " . $e->getMessage() . "\n";
            throw $e;
        }
    }

    public function down()
    {
        echo "🔄 Rollback: 001_create_system_configuration\n";
        echo "⚠️  لا يتم حذف جدول permissions - الرجاء حذفه يدويًا إذا لزم الأمر\n";
    }

    private function insertDefaultPermissions()
    {
        $permissions = [
            // System & Settings
            ['system.manage_settings', 'Manage System Settings', 'إدارة إعدادات النظام', 'system', 'settings', 'manage', 1],
            ['system.view_logs', 'View System Logs', 'عرض سجلات النظام', 'system', 'logs', 'view', 1],
            ['system.manage_users', 'Manage Users', 'إدارة المستخدمين', 'system', 'users', 'manage', 1],
            ['system.manage_roles', 'Manage Roles', 'إدارة الأدوار', 'system', 'roles', 'manage', 1],
            ['system.view_analytics', 'View Analytics', 'عرض التحليلات', 'system', 'analytics', 'view', 1],
            
            // Sales Management
            ['sales.create', 'Create Sale', 'إنشاء مبيعة', 'sales_management', 'sales', 'create', 1],
            ['sales.view', 'View Sales', 'عرض المبيعات', 'sales_management', 'sales', 'view', 1],
            ['sales.edit', 'Edit Sale', 'تعديل مبيعة', 'sales_management', 'sales', 'edit', 1],
            ['sales.delete', 'Delete Sale', 'حذف مبيعة', 'sales_management', 'sales', 'delete', 1],
            ['sales.approve', 'Approve Sale', 'الموافقة على مبيعة', 'sales_management', 'sales', 'approve', 1],
            
            // Purchase Management
            ['purchase.create', 'Create Purchase', 'إنشاء شراء', 'purchase_management', 'purchases', 'create', 1],
            ['purchase.view', 'View Purchases', 'عرض المشتريات', 'purchase_management', 'purchases', 'view', 1],
            ['purchase.edit', 'Edit Purchase', 'تعديل شراء', 'purchase_management', 'purchases', 'edit', 1],
            ['purchase.delete', 'Delete Purchase', 'حذف شراء', 'purchase_management', 'purchases', 'delete', 1],
            ['purchase.approve', 'Approve Purchase', 'الموافقة على شراء', 'purchase_management', 'purchases', 'approve', 1],
            
            // Inventory Management
            ['inventory.create', 'Create Inventory', 'إنشاء مخزون', 'inventory_management', 'inventory', 'create', 1],
            ['inventory.view', 'View Inventory', 'عرض المخزون', 'inventory_management', 'inventory', 'view', 1],
            ['inventory.edit', 'Edit Inventory', 'تعديل مخزون', 'inventory_management', 'inventory', 'edit', 1],
            ['inventory.delete', 'Delete Inventory', 'حذف مخزون', 'inventory_management', 'inventory', 'delete', 1],
            ['inventory.transfer', 'Transfer Stock', 'نقل مخزون', 'inventory_management', 'inventory', 'transfer', 1],
            
            // POS
            ['pos.checkout', 'POS Checkout', 'دفع من نقطة البيع', 'pos', 'checkout', 'process', 1],
            ['pos.refund', 'POS Refund', 'استرجاع من نقطة البيع', 'pos', 'refund', 'process', 1],
            
            // Payments
            ['payment.create', 'Create Payment', 'إنشاء دفعة', 'payments', 'payments', 'create', 1],
            ['payment.view', 'View Payments', 'عرض الدفعات', 'payments', 'payments', 'view', 1],
            ['payment.reconcile', 'Reconcile Payment', 'تسوية دفعة', 'payments', 'payments', 'reconcile', 1],
            
            // Reports
            ['report.view', 'View Reports', 'عرض التقارير', 'reports', 'reports', 'view', 1],
            ['report.export', 'Export Reports', 'تصدير التقارير', 'reports', 'reports', 'export', 1],
            
            // Security
            ['security.manage_ip_blacklist', 'Manage IP Blacklist', 'إدارة قائمة IP المحظورة', 'security', 'blacklist', 'manage', 1],
            ['security.view_security_events', 'View Security Events', 'عرض أحداث الأمان', 'security', 'events', 'view', 1],
        ];

        $stmt = $this->pdo->prepare("
            INSERT IGNORE INTO permissions 
            (name, display_name, description, category, resource, action, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($permissions as $perm) {
            $stmt->execute($perm);
        }
    }
}
