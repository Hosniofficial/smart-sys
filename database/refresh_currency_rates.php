<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (is_file(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

require_once __DIR__ . '/MigrationDatabase.php';

try {
    $db = MigrationDatabase::connect($_ENV);
    $tenantIds = $db->query('SELECT id FROM tenants ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $service = new App\Services\CurrencyRateService($db);
    $failures = [];

    foreach ($tenantIds as $tenantId) {
        try {
            $result = $service->refresh((int) $tenantId);
            fwrite(STDOUT, sprintf(
                "Tenant %d: refreshed %d rates for %s (%s).\n",
                $tenantId,
                $result['rate_count'],
                $result['base_currency_code'],
                $result['rate_date']
            ));
        } catch (Throwable $e) {
            $failures[] = sprintf('Tenant %d: %s', $tenantId, $e->getMessage());
        }
    }

    if ($failures !== []) {
        fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
        exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Exchange-rate refresh failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
