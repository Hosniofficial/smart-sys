<?php

/**
 * Database Migration Runner
 * 
 * يقوم بتشغيل جميع ملفات migrations بالترتيب الصحيح
 * 
 * الاستخدام:
 * php database/migrate.php up      - تشغيل جميع الـ migrations
 * php database/migrate.php down    - التراجع عن آخر migration
 * php database/migrate.php status  - عرض حالة الـ migrations (قراءة فقط)
 *
 * Safety: `up` refuses to bootstrap an absent/empty ledger beside existing tables.
 * Reconcile an existing installation's migration history explicitly before running it.
 */

// تحميل متغيرات البيئة بصيغة dotenv المستخدمة في التطبيق
require_once __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();
}

// ──────────────────────────────────────────────────────────
// إعداد الاتصال بقاعدة البيانات باستخدام أسماء إعدادات التطبيق القياسية
// ──────────────────────────────────────────────────────────

require_once __DIR__ . '/MigrationDatabase.php';
require_once __DIR__ . '/MigrationHistoryGuard.php';

try {
    $pdo = MigrationDatabase::connect($_ENV);
} catch (PDOException | RuntimeException $e) {
    die("❌ فشل الاتصال بقاعدة البيانات: " . $e->getMessage() . "\n");
}

// Migration history is initialized only by `up` after the fresh-database guard passes.
// `status` remains read-only; an existing schema without a ledger requires reviewed reconciliation.

// ──────────────────────────────────────────────────────────
// قائمة الـ migrations بالترتيب
// ──────────────────────────────────────────────────────────

$migrations = [
    '001_create_system_configuration',
    '002_create_roles_and_permissions',
    '003_insert_core_data',
    '004_add_accounting_period_close_override',
    '005_allow_optional_opening_snapshot_mapping',
    '006_tenant_scoped_operational_roles',
    '007_add_purchase_request_idempotency',
    '008_add_liquidity_policy_columns',
    '009_create_owner_contributions_table',
    '010_add_cash_negative_setting',
    '011_add_owner_contribution_permissions',
    '012_harden_payment_method_liquidity_config',
    '013_reconcile_legacy_sale_cancellation_stock',
    '014_add_reversed_journal_entry_status',
    '015_normalize_sale_approval_status',
    '016_add_cash_voucher_status',
    '017_add_cash_voucher_edit_permission',
    '018_grant_cash_voucher_edit_permission',
    '019_consolidate_shared_operational_roles',
    '020_mark_finance_officer_as_system_role',
    '021_add_cash_voucher_idempotency',
    '022_add_supplier_tax_number',
    '023_allocate_customer_payments_fifo',
    '024_reconcile_legacy_stock_adjustment_costs',
    '025_create_request_rate_limits',
    '026_add_multi_currency_foundation',
    '027_track_opening_balance_reversals',
    '028_add_purchase_invoice_number_uniqueness',
    '029_add_return_number_uniqueness',
    '030_grant_owner_contribution_permissions_to_admin_roles',
    '031_revoke_sessions_after_password_change',
    '032_create_repair_orders',
];

// ──────────────────────────────────────────────────────────
// معالجة الأوامر
// ──────────────────────────────────────────────────────────

$command = $argv[1] ?? 'status';

switch ($command) {
    case 'up':
        runMigrationsUp($pdo, $migrations);
        break;
        
    case 'down':
        rollbackLastMigration($pdo, $migrations);
        break;
        
    case 'status':
        showMigrationsStatus($pdo, $migrations);
        break;

    case 'baseline':
        adoptLegacyBaseline(
            $pdo,
            $migrations,
            $argv[2] ?? '',
            $argv[3] ?? ''
        );
        break;
        
    default:
        showHelp();
}

// ──────────────────────────────────────────────────────────
// الدوال
// ──────────────────────────────────────────────────────────

function runMigrationsUp(PDO $pdo, array $migrations): void
{
    prepareMigrationLedger($pdo, $migrations);

    echo "\n" . str_repeat("=", 60) . "\n";
    echo "🚀 تشغيل Migrations\n";
    echo str_repeat("=", 60) . "\n\n";
    
    $batch = ($pdo->query("SELECT MAX(batch) as batch FROM migrations")->fetch()['batch'] ?? 0) + 1;
    
    foreach ($migrations as $migration) {
        // التحقق من أن الـ migration لم تكن مشغلة من قبل
        $existsStmt = $pdo->prepare('SELECT id FROM migrations WHERE migration = ?');
        $existsStmt->execute([$migration]);
        $exists = $existsStmt->fetch();
        
        if ($exists) {
            echo "⏭️  تخطي: {$migration} (مشغل بالفعل)\n";
            continue;
        }
        
        echo "\n▶️  تشغيل: {$migration}\n";
        echo str_repeat("-", 60) . "\n";
        
        try {
            $filePath = __DIR__ . "/migrations/{$migration}.php";
            
            if (!file_exists($filePath)) {
                throw new RuntimeException("Migration file is missing: {$filePath}");
            }
            
            require $filePath;
            
            // الحصول على اسم الكلاس من اسم الملف (تحويل snake_case إلى PascalCase)
            $className = implode('', array_map('ucfirst', explode('_', preg_replace('/^\d+_/', '', $migration))));
            
            if (!class_exists($className)) {
                throw new RuntimeException("Migration class {$className} is missing from {$filePath}.");
            }
            
            $migrator = new $className($pdo);
            
            if (!method_exists($migrator, 'up')) {
                throw new RuntimeException("Migration {$migration} does not implement up().");
            }

            $migrator->up();

            // Record only after the migration's up() completes successfully.
            $stmt = $pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (?, ?)');
            $stmt->execute([$migration, $batch]);

            echo "\n✅ تم: {$migration}\n";
            
        } catch (Throwable $e) {
            echo "\n❌ خطأ: " . $e->getMessage() . "\n";
            echo "🔄 إيقاف التشغيل\n";
            exit(1);
        }
    }
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "✨ تم إكمال جميع الـ migrations بنجاح!\n";
    echo str_repeat("=", 60) . "\n\n";
}

function rollbackLastMigration(PDO $pdo, array $migrations): void
{
    if (!migrationLedgerExists($pdo)) {
        echo "لا يوجد سجل migrations؛ لم يتم تنفيذ أي rollback.\n";
        return;
    }

    MigrationHistoryGuard::assertContiguousHistory($migrations, executedMigrations($pdo));

    echo "\n" . str_repeat("=", 60) . "\n";
    echo "🔄 التراجع عن آخر migration\n";
    echo str_repeat("=", 60) . "\n\n";
    
    $lastMigration = $pdo->query("
        SELECT migration, batch FROM migrations
        ORDER BY id DESC LIMIT 1
    ")->fetch();
    
    if (!$lastMigration) {
        echo "ℹ️  لا توجد migrations مشغلة\n";
        return;
    }
    
    $migration = $lastMigration['migration'];
    if ((int) $lastMigration['batch'] === 0) {
        echo "رفض التراجع: {$migration} جزء من baseline قديم معتمد، وليس تشغيلًا نفذته أداة migrations.\n";
        return;
    }
    
    echo "▶️  التراجع: {$migration}\n";
    echo str_repeat("-", 60) . "\n";
    
    try {
        $filePath = __DIR__ . "/migrations/{$migration}.php";
        require $filePath;
        
        $className = implode('', array_map('ucfirst', explode('_', preg_replace('/^\d+_/', '', $migration))));
        
        if (class_exists($className)) {
            $migrator = new $className($pdo);
            
            if (method_exists($migrator, 'down')) {
                $migrator->down();
                
                // حذف تسجيل الـ migration
                $delete = $pdo->prepare('DELETE FROM migrations WHERE migration = ?');
                $delete->execute([$migration]);
                
                echo "\n✅ تم: {$migration}\n";
            }
        }
        
    } catch (Throwable $e) {
        echo "\n❌ خطأ: " . $e->getMessage() . "\n";
        exit(1);
    }
}

function showMigrationsStatus(PDO $pdo, array $migrations): void
{
    $ledgerExists = migrationLedgerExists($pdo);
    $existingTables = databaseTableNames($pdo);

    echo "\n" . str_repeat("=", 60) . "\n";
    echo "📊 حالة الـ Migrations\n";
    echo str_repeat("=", 60) . "\n\n";
    
    if (!$ledgerExists) {
        echo "⚠️  سجل migrations غير موجود؛ الحالة غير معروفة ولا يجوز تشغيل up على قاعدة قائمة قبل تسوية السجل.\n";
        if ($existingTables !== []) {
            echo 'الجداول الموجودة: ' . implode(', ', $existingTables) . "\n";
        }
        $executed = [];
        $statusLabel = '❔ غير معروف (لا يوجد سجل)';
    } else {
        $executed = executedMigrations($pdo);
        $baseline = baselineMigrations($pdo);
        try {
            MigrationHistoryGuard::assertContiguousHistory($migrations, $executed);
            if ($executed === []) {
                $applicationTables = array_values(array_filter(
                    $existingTables,
                    static fn (string $table): bool => strtolower($table) !== 'migrations'
                ));
                MigrationHistoryGuard::assertFreshDatabase($applicationTables);
            }
        } catch (RuntimeException $e) {
            echo '⚠️  تحذير سجل migrations: ' . $e->getMessage() . "\n";
        }
        $statusLabel = null;
    }

    echo "Migration Name                        | Status\n";
    echo str_repeat("-", 60) . "\n";
    
    foreach ($migrations as $migration) {
        $status = $statusLabel ?? (
            in_array($migration, $baseline, true)
                ? '✅ baseline معتمد (batch 0)'
                : (in_array($migration, $executed, true) ? '✅ مشغل' : '⏭️  لم يشغل')
        );
        printf("%-35s | %s\n", $migration, $status);
    }
    
    echo "\n" . str_repeat("=", 60) . "\n";
}

function migrationLedgerExists(PDO $pdo): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute(['migrations']);
    return (int) $stmt->fetchColumn() === 1;
}

/** @return list<string> */
function databaseTableNames(PDO $pdo): array
{
    return $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
    )->fetchAll(PDO::FETCH_COLUMN);
}

/** @return list<string> */
function executedMigrations(PDO $pdo): array
{
    return $pdo->query('SELECT migration FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
}

/** @return list<string> */
function baselineMigrations(PDO $pdo): array
{
    return $pdo->query('SELECT migration FROM migrations WHERE batch = 0 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Record the operator-confirmed legacy prefix without replaying its migrations.
 * Batch zero is reserved for these adopted entries; runner-executed batches start at one.
 */
function adoptLegacyBaseline(PDO $pdo, array $migrations, string $throughMigration, string $confirmation): void
{
    $prefix = MigrationHistoryGuard::assertLegacyBaselineAdoption(
        $migrations,
        $throughMigration,
        $confirmation,
        migrationLedgerExists($pdo),
        databaseTableNames($pdo)
    );

    createMigrationLedger($pdo);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (?, 0)');
        foreach ($prefix as $migration) {
            $stmt->execute([$migration]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('DROP TABLE IF EXISTS migrations');
        throw $e;
    }

    echo "تم تسجيل baseline 001-003 بناءً على التأكيد الصريح؛ لم تتم إعادة تشغيلها.\n";
    echo "راجع status ثم شغّل up لتطبيق migrations اللاحقة عبر runner.\n";
}

/**
 * Create the ledger only for a truly empty schema; never infer or fabricate a baseline.
 * Existing installations with absent/empty history must be reconciled by an operator.
 *
 * @param list<string> $migrations
 */
function prepareMigrationLedger(PDO $pdo, array $migrations): void
{
    $ledgerExists = migrationLedgerExists($pdo);
    $tables = databaseTableNames($pdo);
    $applicationTables = array_values(array_filter(
        $tables,
        static fn (string $table): bool => strtolower($table) !== 'migrations'
    ));

    if (!$ledgerExists) {
        MigrationHistoryGuard::assertFreshDatabase($applicationTables);
        createMigrationLedger($pdo);
        return;
    }

    $executed = executedMigrations($pdo);
    if ($executed === []) {
        MigrationHistoryGuard::assertFreshDatabase($applicationTables);
        return;
    }

    MigrationHistoryGuard::assertContiguousHistory($migrations, $executed);
}

function createMigrationLedger(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL UNIQUE,
            batch INT NOT NULL COMMENT '0 = operator-adopted legacy baseline; runner batches start at 1',
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function showHelp()
{
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "Database Migration Runner\n";
    echo str_repeat("=", 60) . "\n\n";
    echo "الاستخدام:\n";
    echo "  php database/migrate.php up      - تشغيل جميع الـ migrations\n";
    echo "  php database/migrate.php down    - التراجع عن آخر migration\n";
    echo "  php database/migrate.php status  - عرض حالة الـ migrations\n";
    echo "  php database/migrate.php baseline 003_insert_core_data --confirm-applied-001-003\n";
    echo "      تسجيل baseline قديم مؤكد صراحةً؛ لا يعيد تشغيل migrations 001-003\n\n";
}
