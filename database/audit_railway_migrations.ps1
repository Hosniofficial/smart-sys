param(
    [string] $PhpExe = 'php'
)

$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$migrationDirectory = Join-Path $PSScriptRoot 'migrations'
$migrationFiles = @(
    Get-ChildItem -LiteralPath $migrationDirectory -Filter '*.php' -File |
        Where-Object { $_.BaseName -match '^\d{3}_.+' } |
        Sort-Object Name
)
if ($migrationFiles.Count -eq 0) {
    throw "No numbered migration files found in '$migrationDirectory'."
}

$expectedMigrations = @($migrationFiles | ForEach-Object { $_.BaseName })
$databaseHost = Read-Host 'Railway TCP Proxy hostname (default: shortline.proxy.rlwy.net)'
if (-not $databaseHost) {
    $databaseHost = 'shortline.proxy.rlwy.net'
}
if ($databaseHost -notmatch '^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$') {
    throw 'Enter a hostname only; do not include a URL, port, or credentials.'
}

$databasePortText = Read-Host 'Railway external TCP Proxy port (default: 15839; do not use internal port 3306 here)'
if (-not $databasePortText) {
    $databasePortText = '15839'
}
if ($databasePortText -notmatch '^\d{1,5}$' -or [int] $databasePortText -lt 1 -or [int] $databasePortText -gt 65535) {
    throw 'Port must be an integer between 1 and 65535.'
}
$databasePort = [int] $databasePortText

$databaseName = Read-Host 'Railway database name'
if ($databaseName -notmatch '^[A-Za-z0-9_]{1,64}$') {
    throw 'Database name may contain only ASCII letters, digits, and underscores.'
}
$username = Read-Host 'Dedicated read-only database username'
if ($username -notmatch '^[A-Za-z0-9_.-]{1,80}$') {
    throw 'Username may contain only ASCII letters, digits, underscore, dot, and hyphen.'
}
$securePassword = Read-Host 'Read-only database password (hidden input)' -AsSecureString
if ($securePassword.Length -eq 0) {
    throw 'A non-empty read-only database password is required.'
}

$confirmation = Read-Host "This performs SELECT-only checks against '$databaseName' via $databaseHost`:$databasePort. Type CHECK $databaseName to continue"
if ($confirmation -cne "CHECK $databaseName") {
    $securePassword.Dispose()
    throw 'Audit cancelled.'
}

$phpCommand = Get-Command $PhpExe -ErrorAction SilentlyContinue
if (-not $phpCommand) {
    $securePassword.Dispose()
    throw 'PHP CLI was not found. Install PHP with pdo_mysql or pass -PhpExe.'
}
$phpPath = $phpCommand.Source

$environmentNames = @(
    'SMARTSYS_AUDIT_DB_HOST',
    'SMARTSYS_AUDIT_DB_PORT',
    'SMARTSYS_AUDIT_DB_NAME',
    'SMARTSYS_AUDIT_DB_USER',
    'SMARTSYS_AUDIT_DB_PASSWORD',
    'SMARTSYS_AUDIT_EXPECTED_MIGRATIONS'
)
$originalEnvironment = @{}
foreach ($name in $environmentNames) {
    $existing = Get-Item -Path "Env:$name" -ErrorAction SilentlyContinue
    $originalEnvironment[$name] = if ($existing) { $existing.Value } else { $null }
}

$passwordPointer = [IntPtr]::Zero
$password = $null
$phpHelper = Join-Path $env:TEMP ('smartsys-migration-audit-' + [Guid]::NewGuid().ToString('N') + '.php')

try {
    $passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
    $password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)

    $env:SMARTSYS_AUDIT_DB_HOST = $databaseHost
    $env:SMARTSYS_AUDIT_DB_PORT = [string] $databasePort
    $env:SMARTSYS_AUDIT_DB_NAME = $databaseName
    $env:SMARTSYS_AUDIT_DB_USER = $username
    $env:SMARTSYS_AUDIT_DB_PASSWORD = $password
    $migrationJson = ConvertTo-Json -InputObject $expectedMigrations -Compress
    $env:SMARTSYS_AUDIT_EXPECTED_MIGRATIONS = [Convert]::ToBase64String(
        [Text.Encoding]::UTF8.GetBytes($migrationJson)
    )

    $phpSource = @'
<?php
declare(strict_types=1);

$requiredEnvironment = [
    'SMARTSYS_AUDIT_DB_HOST',
    'SMARTSYS_AUDIT_DB_PORT',
    'SMARTSYS_AUDIT_DB_NAME',
    'SMARTSYS_AUDIT_DB_USER',
    'SMARTSYS_AUDIT_DB_PASSWORD',
    'SMARTSYS_AUDIT_EXPECTED_MIGRATIONS',
];
foreach ($requiredEnvironment as $name) {
    if (getenv($name) === false || getenv($name) === '') {
        fwrite(STDERR, "Missing local audit configuration: {$name}\n");
        exit(2);
    }
}
if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "The selected PHP CLI does not have pdo_mysql enabled.\n");
    exit(2);
}

try {
    $expectedJson = base64_decode((string) getenv('SMARTSYS_AUDIT_EXPECTED_MIGRATIONS'), true);
    $expected = is_string($expectedJson) ? json_decode($expectedJson, true, 512, JSON_THROW_ON_ERROR) : null;
    if (!is_array($expected) || $expected === []) {
        throw new RuntimeException('The source migration list is invalid.');
    }

    $host = (string) getenv('SMARTSYS_AUDIT_DB_HOST');
    $port = (int) getenv('SMARTSYS_AUDIT_DB_PORT');
    $database = (string) getenv('SMARTSYS_AUDIT_DB_NAME');
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        (string) getenv('SMARTSYS_AUDIT_DB_USER'),
        (string) getenv('SMARTSYS_AUDIT_DB_PASSWORD'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION READ ONLY',
        ]
    );

    $server = $pdo->query('SELECT VERSION() AS server_version, DATABASE() AS database_name')->fetch();
    $ledgerCheck = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'migrations'"
    );
    $ledgerCheck->execute();
    if ((int) $ledgerCheck->fetchColumn() !== 1) {
        echo json_encode([
            'connection' => 'success',
            'server_version' => $server['server_version'],
            'database' => $server['database_name'],
            'migration_ledger' => 'missing',
            'migration_check_passed' => false,
            'expected_count' => count($expected),
            'applied_count' => 0,
            'missing' => $expected,
            'unexpected' => [],
            'duplicates' => [],
            'last_applied' => null,
            'writes_performed' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
        exit(3);
    }

    $applied = array_map(
        static fn(array $row): string => (string) $row['migration'],
        $pdo->query('SELECT migration FROM migrations ORDER BY id')->fetchAll()
    );
    $counts = array_count_values($applied);
    $duplicates = array_values(array_filter(
        array_keys($counts),
        static fn(string $name): bool => $counts[$name] > 1
    ));
    $missing = array_values(array_diff($expected, $applied));
    $unexpected = array_values(array_diff($applied, $expected));
    $appliedKnownInLedgerOrder = array_values(array_intersect($applied, $expected));
    $ordered = $appliedKnownInLedgerOrder === $expected;
    $passed = $missing === [] && $unexpected === [] && $duplicates === [] && $ordered;

    echo json_encode([
        'connection' => 'success',
        'server_version' => $server['server_version'],
        'database' => $server['database_name'],
        'migration_ledger' => 'present',
        'migration_check_passed' => $passed,
        'ledger_order_matches_source' => $ordered,
        'expected_count' => count($expected),
        'applied_count' => count($applied),
        'missing' => $missing,
        'unexpected' => $unexpected,
        'duplicates' => $duplicates,
        'last_applied' => $applied === [] ? null : end($applied),
        'writes_performed' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
    exit($passed ? 0 : 3);
} catch (Throwable $error) {
    fwrite(
        STDERR,
        'Read-only migration audit failed. Verify the host, external port, database name, read-only account, and network access. Details: '
        . $error->getMessage()
        . PHP_EOL
    );
    exit(2);
}
'@
    [IO.File]::WriteAllText($phpHelper, $phpSource, [Text.UTF8Encoding]::new($false))
    & $phpPath $phpHelper
    if ($LASTEXITCODE -ne 0) {
        throw "Read-only migration audit returned exit code $LASTEXITCODE. Review the report above; no migration was run."
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

Write-Output 'The migration ledger matches the current local migration files. This does not certify schema drift, application behavior, backups, deployment, or production readiness.'
