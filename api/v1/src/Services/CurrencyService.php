<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use App\Repositories\SettingsRepository;

/**
 * Resolves a tenant's company currency from the shared settings repository.
 */
class CurrencyService
{
    private SettingsRepository $settingsRepo;

    public function __construct(PDO $db)
    {
        $this->settingsRepo = new SettingsRepository($db);
    }

    /** Return the configured currency, defaulting to EGP when unset. */
    public function getCompanyCurrency(int $tenantId): string
    {
        return $this->settingsRepo->get($tenantId, 'company.currency', 'EGP') ?: 'EGP';
    }
}
