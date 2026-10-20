param(
    [string] $PhpExe = 'php',
    [switch] $WhatIf
)

$ErrorActionPreference = 'Stop'

$migrationDirectory = Join-Path $PSScriptRoot 'migrations'
$migrationFiles = @(
    Get-ChildItem -LiteralPath $migrationDirectory -Filter '*.php' -File |
        Where-Object { $_.BaseName -match '^\d{3}_.+' } |
        Sort-Object Name
)
if ($migrationFiles.Count -ne 27 -or $migrationFiles[-1].BaseName -ne '027_track_opening_balance_reversals') {
    throw 'Expected the reviewed 001-027 migration sequence. Review source migrations before applying.'
}
$expectedMigrations = @($migrationFiles | ForEach-Object { $_.BaseName })

$databaseHost = Read-Host 'Railway TCP Proxy hostname (default: shortline.proxy.rlwy.net)'
if (-not $databaseHost) {
    $databaseHost = 'shortline.proxy.rlwy.net'
}
if ($databaseHost -notmatch '^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$') {
    throw 'Enter a hostname only; do not include a URL, port, or credentials.'
}

$databasePortText = Read-Host 'Railway external TCP Proxy port (default: 15839)'
if (-not $databasePortText) {
    $databasePortText = '15839'
}
if ($databasePortText -notmatch '^\d{1,5}$' -or [int] $databasePortText -lt 1 -or [int] $databasePortText -gt 65535) {
    throw 'Port must be an integer between 1 and 65535.'
}
$databasePort = [int] $databasePortText

$databaseName = Read-Host 'Railway database name (expected: railway)'
if ($databaseName -notmatch '^[A-Za-z0-9_]{1,64}$') {
    throw 'Database name may contain only ASCII letters, digits, and underscores.'
}
$usernamePrompt = if ($WhatIf) {
    'Railway database username (read-only access is sufficient for preflight)'
} else {
    'Railway database migration username (requires CREATE/ALTER/UPDATE/INSERT privileges)'
}
$username = Read-Host $usernamePrompt
if ($username -notmatch '^[A-Za-z0-9_.-]{1,80}$') {
    throw 'Username may contain only ASCII letters, digits, underscore, dot, and hyphen.'
}
$securePassword = Read-Host 'Migration database password (hidden input)' -AsSecureString
if ($securePassword.Length -eq 0) {
    throw 'A non-empty migration database password is required.'
}

if (-not $WhatIf) {
    $confirmation = Read-Host "Target '$databaseName' is a disposable non-production database. Type MIGRATE $databaseName 024-027 to continue"
    if ($confirmation -cne "MIGRATE $databaseName 024-027") {
        $securePassword.Dispose()
        throw 'Migration cancelled.'
    }
}

$phpCommand = Get-Command $PhpExe -ErrorAction SilentlyContinue
if (-not $phpCommand) {
    $securePassword.Dispose()
    throw 'PHP CLI was not found. Install PHP with pdo_mysql or pass -PhpExe.'
}
$phpPath = $phpCommand.Source

$environmentNames = @(
    'SMARTSYS_MIGRATION_DB_HOST',
    'SMARTSYS_MIGRATION_DB_PORT',
    'SMARTSYS_MIGRATION_DB_NAME',
    'SMARTSYS_MIGRATION_DB_USER',
    'SMARTSYS_MIGRATION_DB_PASSWORD',
    'SMARTSYS_MIGRATION_EXPECTED',
    'SMARTSYS_MIGRATION_PROJECT_ROOT'
)
$originalEnvironment = @{}
foreach ($name in $environmentNames) {
    $existing = Get-Item -Path "Env:$name" -ErrorAction SilentlyContinue
    $originalEnvironment[$name] = if ($existing) { $existing.Value } else { $null }
}

$passwordPointer = [IntPtr]::Zero
$password = $null
$phpHelper = Join-Path $env:TEMP ('smartsys-railway-migrate-' + [Guid]::NewGuid().ToString('N') + '.php')

try {
    $passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
    $password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)

    $env:SMARTSYS_MIGRATION_DB_HOST = $databaseHost
    $env:SMARTSYS_MIGRATION_DB_PORT = [string] $databasePort
    $env:SMARTSYS_MIGRATION_DB_NAME = $databaseName
    $env:SMARTSYS_MIGRATION_DB_USER = $username
    $env:SMARTSYS_MIGRATION_DB_PASSWORD = $password
    $env:SMARTSYS_MIGRATION_PROJECT_ROOT = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
    $migrationJson = ConvertTo-Json -InputObject $expectedMigrations -Compress
    $env:SMARTSYS_MIGRATION_EXPECTED = [Convert]::ToBase64String(
        [Text.Encoding]::UTF8.GetBytes($migrationJson)
    )

    $phpSource = @'
<?php
declare(strict_types=1);

$requiredEnvironment = [
    'SMARTSYS_MIGRATION_DB_HOST',
    'SMARTSYS_MIGRATION_DB_PORT',
    'SMARTSYS_MIGRATION_DB_NAME',
    'SMARTSYS_MIGRATION_DB_USER',
    'SMARTSYS_MIGRATION_DB_PASSWORD',
    'SMARTSYS_MIGRATION_EXPECTED',
    'SMARTSYS_MIGRATION_PROJECT_ROOT',
];
foreach ($requiredEnvironment as $name) {
    if (getenv($name) === false || getenv($name) === '') {
        fwrite(STDERR, "Missing migration configuration: {$name}\n");
        exit(2);
    }
}
if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "The selected PHP CLI does not have pdo_mysql enabled.\n");
    exit(2);
}

try {
    $expectedJson = base64_decode((string) getenv('SMARTSYS_MIGRATION_EXPECTED'), true);
    $expected = is_string($expectedJson) ? json_decode($expectedJson, true, 512, JSON_THROW_ON_ERROR) : null;
    if (!is_array($expected) || count($expected) !== 27) {
        throw new RuntimeException('The source migration list is invalid.');
    }

    $config = [
        'DB_HOST' => (string) getenv('SMARTSYS_MIGRATION_DB_HOST'),
        'DB_PORT' => (string) getenv('SMARTSYS_MIGRATION_DB_PORT'),
        'DB_DATABASE' => (string) getenv('SMARTSYS_MIGRATION_DB_NAME'),
        'DB_USERNAME' => (string) getenv('SMARTSYS_MIGRATION_DB_USER'),
        'DB_PASSWORD' => (string) getenv('SMARTSYS_MIGRATION_DB_PASSWORD'),
    ];
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $config['DB_HOST'],
        $config['DB_PORT'],
        $config['DB_DATABASE']
    );
    $pdo = new PDO($dsn, $config['DB_USERNAME'], $config['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
    ]);

    $identity = $pdo->query('SELECT VERSION() AS server_version, DATABASE() AS database_name')->fetch();
    if ((string) $identity['database_name'] !== $config['DB_DATABASE']) {
        throw new RuntimeException('Connected database name does not match the requested target.');
    }
    if (stripos((string) $identity['server_version'], '8.4.11') === false) {
        throw new RuntimeException(
            'Connected server version differs from the reviewed 8.4.11 target; stop and review compatibility.'
        );
    }

    $tableCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ? AND table_type = ?'
    );
    $tableCheck->execute(['migrations', 'BASE TABLE']);
    if ((int) $tableCheck->fetchColumn() !== 1) {
        throw new RuntimeException('Migration ledger is missing; no migrations were applied.');
    }

    $applied = array_map(
        static fn(array $row): string => (string) $row['migration'],
        $pdo->query('SELECT migration FROM migrations ORDER BY id')->fetchAll()
    );
    $expectedPrefix = array_slice($expected, 0, 24);
    $appliedCounts = array_count_values($applied);
    $duplicates = array_values(array_filter(
        array_keys($appliedCounts),
        static fn(string $name): bool => $appliedCounts[$name] > 1
    ));
    if ($applied !== $expectedPrefix || $duplicates !== []) {
        throw new RuntimeException(
            'Migration ledger is not exactly 001-024 in source order. No migration was applied.'
        );
    }

    $requiredTables = ['tenants', 'users', 'products', 'sales', 'purchases', 'returns',
        'payments', 'cash_vouchers', 'opening_balances', 'inventory_transactions'];
    $tableCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ? AND table_type = ?'
    );
    $missingTables = [];
    foreach ($requiredTables as $tableName) {
        $tableCheck->execute([$tableName, 'BASE TABLE']);
        if ((int) $tableCheck->fetchColumn() !== 1) {
            $missingTables[] = $tableName;
        }
    }
    if ($missingTables !== []) {
        throw new RuntimeException(
            'Required source-schema tables are missing: ' . implode(', ', $missingTables)
        );
    }

    $requiredColumns = [
        'opening_balances' => ['product_id', 'branch_id', 'quantity', 'unit_cost'],
        'inventory_transactions' => [
            'product_id', 'branch_to', 'movement_type', 'quantity', 'unit_cost',
        ],
    ];
    $columnCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $missingColumns = [];
    foreach ($requiredColumns as $tableName => $columns) {
        foreach ($columns as $columnName) {
            $columnCheck->execute([$tableName, $columnName]);
            if ((int) $columnCheck->fetchColumn() !== 1) {
                $missingColumns[] = "{$tableName}.{$columnName}";
            }
        }
    }
    if ($missingColumns !== []) {
        throw new RuntimeException(
            'Required migration input columns are missing: ' . implode(', ', $missingColumns)
        );
    }

    $emptyTables = ['tenants', 'users', 'products', 'sales', 'purchases', 'returns',
        'opening_balances', 'inventory_transactions'];
    $nonEmptyTables = [];
    foreach ($emptyTables as $tableName) {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$tableName}`")->fetchColumn();
        if ($count !== 0) {
            $nonEmptyTables[] = $tableName;
        }
    }
    if ($nonEmptyTables !== []) {
        throw new RuntimeException(
            'The selected target is not empty of operational data: ' . implode(', ', $nonEmptyTables)
        );
    }

    echo json_encode([
        'preflight' => 'passed',
        'server_version' => $identity['server_version'],
        'database' => $identity['database_name'],
        'last_applied' => end($applied),
        'pending' => array_slice($expected, 24),
        'operational_tables_checked_empty' => $emptyTables,
        'writes_performed' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;

    if (in_array('--preflight-only', $argv, true)) {
        exit(0);
    }

    foreach ($config as $key => $value) {
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
    $projectRoot = (string) getenv('SMARTSYS_MIGRATION_PROJECT_ROOT');
    if (!is_dir($projectRoot)) {
        throw new RuntimeException('The local project root is not available to the migration runner.');
    }
    $migrationRunner = $projectRoot . '/database/migrate.php';
    if (!is_file($migrationRunner)) {
        throw new RuntimeException('The project migration runner is missing.');
    }
    chdir($projectRoot);
    $argv = [$migrationRunner, 'status'];
    require $migrationRunner;

    runMigrationsUp($pdo, $migrations);
    showMigrationsStatus($pdo, $migrations);

    $finalApplied = array_map(
        static fn(array $row): string => (string) $row['migration'],
        $pdo->query('SELECT migration FROM migrations ORDER BY id')->fetchAll()
    );
    if ($finalApplied !== $expected) {
        throw new RuntimeException(
            'Migrations returned without a complete 001-027 ledger. Stop and inspect the reported database status.'
        );
    }
    echo "Verified: migration ledger is complete through 027. Review schema and smoke tests before release.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration step stopped: ' . $error->getMessage() . PHP_EOL);
    exit(2);
}
'@
    [IO.File]::WriteAllText($phpHelper, $phpSource, [Text.UTF8Encoding]::new($false))

    if ($WhatIf) {
        & $phpPath $phpHelper '--preflight-only'
    } else {
        & $phpPath $phpHelper
    }
    if ($LASTEXITCODE -ne 0) {
        throw "Migration step returned exit code $LASTEXITCODE. Review the output; do not rerun until the ledger and schema are inspected."
    }
} finally {
    foreach ($name in $environmentNames) {
        $value = $originalEnvironment[$name]
        if ($null -eq $value) {
            Remove-Item -Path "Env:$name" -ErrorAction SilentlyContinue
        } else {
            Set-Item -Path "Env:$name" -Value $value
        }
    }
    if (Test-Path -LiteralPath $phpHelper) {
        Remove-Item -LiteralPath $phpHelper -Force
    }
    if ($passwordPointer -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    }
    $password = $null
    if ($securePassword) {
        $securePassword.Dispose()
    }
}
