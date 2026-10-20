param(
    [string] $DatabaseHost,
    [ValidateRange(1, 65535)]
    [int] $DatabasePort = 3306,
    [string] $Database = 'railway',
    [string] $MysqlExe,
    [string] $PhpExe = 'php'
)

$ErrorActionPreference = 'Stop'

function Get-MySqlArguments {
    param([string[]] $AdditionalArguments)

    return @(
        "--defaults-extra-file=$script:OptionFile",
        '--protocol=TCP',
        "--host=$script:DatabaseHost",
        "--port=$script:DatabasePort",
        "--database=$script:Database",
        '--default-character-set=utf8mb4'
    ) + $AdditionalArguments
}

function Invoke-MySqlQuery {
    param([Parameter(Mandatory = $true)][string] $Query)

    $arguments = Get-MySqlArguments -AdditionalArguments @(
        '--batch',
        '--skip-column-names',
        "--execute=$Query"
    )
    $result = & $script:MysqlExe @arguments
    if ($LASTEXITCODE -ne 0) {
        throw 'MySQL query failed. Check the host, port, credentials, and target database.'
    }
    return ($result -join '').Trim()
}

function Import-MySqlFile {
    param([Parameter(Mandatory = $true)][string] $SqlFile)

    $mysqlCommand = '"{0}" --defaults-extra-file="{1}" --protocol=TCP --host={2} --port={3} --database={4} --default-character-set=utf8mb4 < "{5}"' -f `
        $script:MysqlExe, $script:OptionFile, $script:DatabaseHost, $script:DatabasePort, $script:Database, $SqlFile
    & $env:ComSpec /d /s /c $mysqlCommand
    if ($LASTEXITCODE -ne 0) {
        throw "Import failed for $(Split-Path -Leaf $SqlFile). The database may now be partially imported; do not rerun until it has been reviewed."
    }
}

function Assert-ArtifactHash {
    param(
        [Parameter(Mandatory = $true)][string] $Path,
        [Parameter(Mandatory = $true)][long] $ExpectedBytes,
        [Parameter(Mandatory = $true)][string] $ExpectedHash
    )

    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "Required SQL artifact is missing: $Path"
    }

    $file = Get-Item -LiteralPath $Path
    $hash = (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash.ToLowerInvariant()
    if ($file.Length -ne $ExpectedBytes -or $hash -ne $ExpectedHash.ToLowerInvariant()) {
        throw "SQL artifact fingerprint mismatch: $($file.Name)"
    }
}

if ($Database -notmatch '^[A-Za-z0-9_]+$') {
    throw 'Database name may contain only ASCII letters, digits, and underscores.'
}
if (-not $DatabaseHost) {
    $DatabaseHost = Read-Host 'Railway TCP Proxy hostname'
}
if ($DatabaseHost -notmatch '^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$') {
    throw 'DatabaseHost must be a hostname or IPv4 address; do not provide a URL or credentials.'
}
$script:DatabaseHost = $DatabaseHost
$script:DatabasePort = $DatabasePort
$script:Database = $Database

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$manifestPath = Join-Path $PSScriptRoot 'production\smartsys_production_export_manifest_20261012.json'
$manifest = Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 | ConvertFrom-Json
if ($manifest.package_status -ne 'validated_for_test_import') {
    throw "Refusing import: the database package is marked '$($manifest.package_status)' and is not approved for import. Regenerate and validate the schema, seed, and migration plan in an isolated database first."
}
$schemaPath = Join-Path $PSScriptRoot ('production\' + $manifest.schema_file)
$seedPath = Join-Path $PSScriptRoot ('production\' + $manifest.seed_file)
Assert-ArtifactHash -Path $schemaPath -ExpectedBytes $manifest.schema_bytes -ExpectedHash $manifest.schema_sha256
Assert-ArtifactHash -Path $seedPath -ExpectedBytes $manifest.seed_bytes -ExpectedHash $manifest.seed_sha256

if (-not $MysqlExe) {
    $mysqlCommand = Get-Command mysql.exe -CommandType Application -ErrorAction SilentlyContinue
    if ($mysqlCommand) {
        $MysqlExe = $mysqlCommand.Source
    } else {
        $xamppMysql = 'C:\xampp\mysql\bin\mysql.exe'
        if (Test-Path -LiteralPath $xamppMysql -PathType Leaf) {
            $MysqlExe = $xamppMysql
        } else {
            throw 'mysql.exe was not found. Install a MySQL client or pass -MysqlExe.'
        }
    }
}
if (-not (Test-Path -LiteralPath $MysqlExe -PathType Leaf)) {
    throw "MySQL client not found: $MysqlExe"
}
$script:MysqlExe = (Resolve-Path -LiteralPath $MysqlExe).Path
$mysqlVersion = (& $script:MysqlExe --version 2>&1 | Out-String).Trim()
if ($LASTEXITCODE -ne 0) {
    throw 'Could not determine the MySQL client version. Pass a compatible MySQL client with -MysqlExe.'
}
if ($mysqlVersion -match 'MariaDB') {
    throw 'The selected client is MariaDB and cannot authenticate to Railway using caching_sha2_password. Install an Oracle MySQL 8.x client and pass its mysql.exe with -MysqlExe.'
}

$phpCommand = Get-Command $PhpExe -ErrorAction SilentlyContinue
if (-not $phpCommand) {
    throw 'PHP CLI was not found. Install PHP with pdo_mysql or pass -PhpExe.'
}
$script:PhpExe = $phpCommand.Source
$pdoDriver = & $script:PhpExe -r 'echo in_array($argv[1], PDO::getAvailableDrivers(), true) ? chr(121).chr(101).chr(115) : chr(110).chr(111);' mysql
$pdoDriver = ($pdoDriver | Out-String).Trim()
if ($LASTEXITCODE -ne 0 -or $pdoDriver -ne 'yes') {
    throw 'The selected PHP CLI must have the pdo_mysql extension enabled.'
}

$confirmation = Read-Host "This imports a schema-only snapshot and curated core seed into the EMPTY '$Database' database. Type IMPORT $Database to continue"
if ($confirmation -cne "IMPORT $Database") {
    throw 'Import cancelled.'
}

$username = Read-Host 'One-time MySQL import username (use a temporary database-scoped account if available; default root)'
if (-not $username) {
    $username = 'root'
}
if ($username -notmatch '^[A-Za-z0-9_.-]{1,80}$') {
    throw 'MySQL username may contain only ASCII letters, digits, underscore, dot, and hyphen.'
}
$securePassword = Read-Host 'One-time MySQL password (input is hidden; do not paste it into a command)' -AsSecureString
if ($securePassword.Length -eq 0) {
    throw 'A non-empty MySQL password is required.'
}

$passwordPointer = [IntPtr]::Zero
$password = $null
$phpHelper = $null
$originalEnvironment = @{}
$environmentNames = @(
    'DB_HOST',
    'DB_PORT',
    'DB_DATABASE',
    'DB_USERNAME',
    'DB_PASSWORD',
    'SMARTSYS_IMPORT_ROOT'
)
foreach ($name in $environmentNames) {
    $existing = Get-Item -Path "Env:$name" -ErrorAction SilentlyContinue
    $originalEnvironment[$name] = if ($existing) { $existing.Value } else { $null }
}

try {
    $passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
    $password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)

    $optionPath = Join-Path $env:TEMP ('smartsys-mysql-' + [Guid]::NewGuid().ToString('N') + '.cnf')
    $script:OptionFile = $optionPath
    $escapedPassword = $password.Replace('\', '\\').Replace('"', '\"')
    $optionContents = "[client]`r`nuser=$username`r`npassword=`"$escapedPassword`"`r`ndefault-character-set=utf8mb4`r`n"
    [IO.File]::WriteAllText($optionPath, $optionContents, [Text.UTF8Encoding]::new($false))

    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $acl = New-Object Security.AccessControl.FileSecurity
    $acl.SetAccessRuleProtection($true, $false)
    $acl.SetOwner($identity.User)
    $rule = [Security.AccessControl.FileSystemAccessRule]::new(
        $identity.User,
        [Security.AccessControl.FileSystemRights]::FullControl,
        [Security.AccessControl.AccessControlType]::Allow
    )
    $acl.AddAccessRule($rule)
    Set-Acl -LiteralPath $optionPath -AclObject $acl

    $emptyState = Invoke-MySqlQuery -Query @'
SELECT CONCAT(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()), ':',
    (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = DATABASE()), ':',
    (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE()), ':',
    (SELECT COUNT(*) FROM information_schema.events WHERE event_schema = DATABASE())
);
'@
    if ($emptyState -ne '0:0:0:0') {
        throw "Refusing import: target database '$Database' is not empty (tables/views:routines:triggers:events = $emptyState)."
    }

    Write-Output "Target '$Database' is empty. Importing verified schema and approved core seed..."
    Import-MySqlFile -SqlFile $schemaPath
    Import-MySqlFile -SqlFile $seedPath

    $objectState = Invoke-MySqlQuery -Query @'
SELECT CONCAT(
    (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'), ':',
    (SELECT COUNT(*) FROM information_schema.views WHERE table_schema = DATABASE()), ':',
    (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE()), ':',
    (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = DATABASE())
);
'@
    if ($objectState -ne "$($manifest.base_table_count):$($manifest.view_count):$($manifest.trigger_count):$($manifest.routine_count)") {
        throw "Imported schema object counts did not match the manifest: $objectState"
    }

    $env:DB_HOST = $DatabaseHost
    $env:DB_PORT = [string] $DatabasePort
    $env:DB_DATABASE = $Database
    $env:DB_USERNAME = $username
    $env:DB_PASSWORD = $password
    $env:SMARTSYS_IMPORT_ROOT = $projectRoot

    $phpHelper = Join-Path $env:TEMP ('smartsys-migrate-' + [Guid]::NewGuid().ToString('N') + '.php')
    $phpSource = @'
<?php
declare(strict_types=1);

$root = getenv('SMARTSYS_IMPORT_ROOT');
if (!$root || !is_dir($root)) {
    fwrite(STDERR, "Project root is not available.\n");
    exit(1);
}
chdir($root);

foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
    $value = getenv($key);
    if ($value === false) {
        fwrite(STDERR, "Missing required database environment: {$key}\n");
        exit(1);
    }
    $_ENV[$key] = $value;
    putenv($key . '=' . $value);
}

$argv = [$root . '/database/migrate.php', 'status'];
require $root . '/database/migrate.php';

foreach ([
    '022_add_supplier_tax_number',
    '023_allocate_customer_payments_fifo',
    '024_reconcile_legacy_stock_adjustment_costs',
] as $target) {
    $index = array_search($target, $migrations, true);
    if ($index === false) {
        fwrite(STDERR, "Required migration is missing: {$target}\n");
        exit(1);
    }
    runMigrationsUp($pdo, array_slice($migrations, 0, $index + 1));
}

showMigrationsStatus($pdo, $migrations);
$latest = $pdo->query('SELECT migration FROM migrations ORDER BY id DESC LIMIT 1')->fetchColumn();
$pending025 = $pdo->prepare('SELECT COUNT(*) FROM migrations WHERE migration = ?');
$pending025->execute(['025_create_request_rate_limits']);
$pending026 = $pdo->prepare('SELECT COUNT(*) FROM migrations WHERE migration = ?');
$pending026->execute(['026_add_multi_currency_foundation']);
if (
    $latest !== '024_reconcile_legacy_stock_adjustment_costs' ||
    (int) $pending025->fetchColumn() !== 0 ||
    (int) $pending026->fetchColumn() !== 0
) {
    fwrite(STDERR, "Migration ledger does not match the approved local version.\n");
    exit(1);
}
'@
    [IO.File]::WriteAllText($phpHelper, $phpSource, [Text.UTF8Encoding]::new($false))
    & $script:PhpExe $phpHelper
    if ($LASTEXITCODE -ne 0) {
        throw 'Migrations 022-024 did not complete or the final ledger check failed. Do not rerun against this partially imported database.'
    }

    $emptyOperationalState = Invoke-MySqlQuery -Query @'
SELECT CONCAT(
    (SELECT COUNT(*) FROM tenants), ':',
    (SELECT COUNT(*) FROM users), ':',
    (SELECT COUNT(*) FROM sales), ':',
    (SELECT COUNT(*) FROM purchases)
);
'@
    if ($emptyOperationalState -ne '0:0:0:0') {
        throw "Unexpected operational rows found after import; review target database '$Database'."
    }

    Write-Output 'Import verified: schema DDL installed, approved core seed loaded, migrations applied through 024, and 025-026 left pending; no tenant or operational rows imported.'
    Write-Output 'Disable the Railway TCP Proxy and revoke the temporary MySQL import account after the transfer.'
} finally {
    foreach ($name in $environmentNames) {
        $value = $originalEnvironment[$name]
        if ($null -eq $value) {
            Remove-Item -Path "Env:$name" -ErrorAction SilentlyContinue
        } else {
            Set-Item -Path "Env:$name" -Value $value
        }
    }
    if ($optionPath -and (Test-Path -LiteralPath $optionPath)) {
        Remove-Item -LiteralPath $optionPath -Force
    }
    if ($phpHelper -and (Test-Path -LiteralPath $phpHelper)) {
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
