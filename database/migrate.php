<?php

/**
 * Database Migration Runner
 * 
 * يقوم بتشغيل جميع ملفات migrations بالترتيب الصحيح
 * 
 * الاستخدام:
 * php database/migrate.php up      - تشغيل جميع الـ migrations
 * php database/migrate.php down    - التراجع عن آخر migration
 * php database/migrate.php status  - عرض حالة الـ migrations
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

try {
    $pdo = MigrationDatabase::connect($_ENV);
} catch (PDOException | RuntimeException $e) {
    die("❌ فشل الاتصال بقاعدة البيانات: " . $e->getMessage() . "\n");
}

// ──────────────────────────────────────────────────────────
// إنشاء جدول migrations إذا لم يكن موجود
// ──────────────────────────────────────────────────────────

$pdo->exec("
    CREATE TABLE IF NOT EXISTS migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        migration VARCHAR(255) NOT NULL UNIQUE,
        batch INT NOT NULL,
        executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

// ──────────────────────────────────────────────────────────
// قائمة الـ migrations بالترتيب
// ──────────────────────────────────────────────────────────

$migrations = [
    '001_create_system_configuration',
    '002_create_roles_and_permissions',
    '004_add_accounting_period_close_override',
    // يمكن إضافة migrations أخرى هنا
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
        rollbackLastMigration($pdo);
        break;
        
    case 'status':
        showMigrationsStatus($pdo, $migrations);
        break;
        
    default:
        showHelp();
}

// ──────────────────────────────────────────────────────────
// الدوال
// ──────────────────────────────────────────────────────────

function runMigrationsUp($pdo, $migrations)
{
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "🚀 تشغيل Migrations\n";
    echo str_repeat("=", 60) . "\n\n";
    
    $batch = ($pdo->query("SELECT MAX(batch) as batch FROM migrations")->fetch()['batch'] ?? 0) + 1;
    
    foreach ($migrations as $migration) {
        // التحقق من أن الـ migration لم تكن مشغلة من قبل
        $exists = $pdo->query("SELECT id FROM migrations WHERE migration = '{$migration}'")->fetch();
        
        if ($exists) {
            echo "⏭️  تخطي: {$migration} (مشغل بالفعل)\n";
            continue;
        }
        
        echo "\n▶️  تشغيل: {$migration}\n";
        echo str_repeat("-", 60) . "\n";
        
        try {
            $filePath = __DIR__ . "/migrations/{$migration}.php";
            
            if (!file_exists($filePath)) {
                echo "⚠️  تحذير: الملف غير موجود: {$filePath}\n";
                continue;
            }
            
            require $filePath;
            
            // الحصول على اسم الكلاس من اسم الملف (تحويل snake_case إلى PascalCase)
            $className = implode('', array_map('ucfirst', explode('_', preg_replace('/^\d+_/', '', $migration))));
            
            if (!class_exists($className)) {
                echo "❌ خطأ: الكلاس {$className} غير موجود في {$filePath}\n";
                continue;
            }
            
            $migrator = new $className($pdo);
            
            if (method_exists($migrator, 'up')) {
                $migrator->up();
                
                // تسجيل الـ migration
                $stmt = $pdo->prepare("INSERT INTO migrations (migration, batch) VALUES (?, ?)");
                $stmt->execute([$migration, $batch]);
                
                echo "\n✅ تم: {$migration}\n";
            }
            
        } catch (Exception $e) {
            echo "\n❌ خطأ: " . $e->getMessage() . "\n";
            echo "🔄 إيقاف التشغيل\n";
            exit(1);
        }
    }
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "✨ تم إكمال جميع الـ migrations بنجاح!\n";
    echo str_repeat("=", 60) . "\n\n";
}

function rollbackLastMigration($pdo)
{
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "🔄 التراجع عن آخر migration\n";
    echo str_repeat("=", 60) . "\n\n";
    
    $lastMigration = $pdo->query("
        SELECT migration FROM migrations 
        ORDER BY id DESC LIMIT 1
    ")->fetch();
    
    if (!$lastMigration) {
        echo "ℹ️  لا توجد migrations مشغلة\n";
        return;
    }
    
    $migration = $lastMigration['migration'];
    
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
                $pdo->query("DELETE FROM migrations WHERE migration = '{$migration}'");
                
                echo "\n✅ تم: {$migration}\n";
            }
        }
        
    } catch (Exception $e) {
        echo "\n❌ خطأ: " . $e->getMessage() . "\n";
    }
}

function showMigrationsStatus($pdo, $migrations)
{
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "📊 حالة الـ Migrations\n";
    echo str_repeat("=", 60) . "\n\n";
    
    $executed = $pdo->query("SELECT migration FROM migrations")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Migration Name                        | Status\n";
    echo str_repeat("-", 60) . "\n";
    
    foreach ($migrations as $migration) {
        $status = in_array($migration, $executed) ? "✅ مشغل" : "⏭️  لم يشغل";
        printf("%-35s | %s\n", $migration, $status);
    }
    
    echo "\n" . str_repeat("=", 60) . "\n";
}

function showHelp()
{
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "Database Migration Runner\n";
    echo str_repeat("=", 60) . "\n\n";
    echo "الاستخدام:\n";
    echo "  php database/migrate.php up      - تشغيل جميع الـ migrations\n";
    echo "  php database/migrate.php down    - التراجع عن آخر migration\n";
    echo "  php database/migrate.php status  - عرض حالة الـ migrations\n\n";
}
