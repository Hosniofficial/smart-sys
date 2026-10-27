<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use PDO;
use RuntimeException;

/**
 * Reads and refreshes tenant exchange rates using the configured base currency.
 *
 * Rates are stored per tenant and base/quote pair; refreshes use Frankfurter USD quotes
 * to derive rates for each supported base currency.
 */
final class CurrencyRateService
{
    private const PROVIDER = 'frankfurter';
    private const SUPPORTED = ['EGP', 'USD', 'EUR', 'SAR', 'AED', 'KWD', 'QAR', 'BHD', 'OMR'];

    private PDO $db;
    private SettingsRepository $settings;
    private ?\Closure $providerFetch;

    public function __construct(PDO $db, ?callable $providerFetch = null)
    {
        $this->db = $db;
        $this->settings = new SettingsRepository($db);
        $this->providerFetch = $providerFetch === null ? null : \Closure::fromCallable($providerFetch);
    }

    /** Return the tenant's configured base currency, defaulting to EGP. */
    public function getBaseCurrency(int $tenantId): string
    {
        return strtoupper((string) ($this->settings->get($tenantId, 'company.currency', 'EGP') ?: 'EGP'));
    }

    /** Return the stored quote rate, expressed as base-currency units per one quote unit. */
    public function getRate(int $tenantId, string $quoteCurrency, ?string $baseCurrency = null): float
    {
        $baseCurrency = strtoupper($baseCurrency ?? $this->getBaseCurrency($tenantId));
        $quoteCurrency = strtoupper($quoteCurrency);
        if (!in_array($baseCurrency, self::SUPPORTED, true) || !in_array($quoteCurrency, self::SUPPORTED, true)) {
            throw new RuntimeException('Unsupported transaction currency.');
        }
        if ($baseCurrency === $quoteCurrency) {
            return 1.0;
        }

        $this->refreshIfStale($tenantId, $baseCurrency);
        $stmt = $this->db->prepare(
            'SELECT rate FROM currency_exchange_rates
             WHERE tenant_id = ? AND base_currency_code = ? AND quote_currency_code = ?
             ORDER BY rate_date DESC LIMIT 1'
        );
        $stmt->execute([$tenantId, $baseCurrency, $quoteCurrency]);
        $rate = $stmt->fetchColumn();
        if ($rate === false || !is_numeric($rate) || (float) $rate <= 0) {
            throw new RuntimeException('No exchange rate is available for this currency pair. Refresh exchange rates and retry.');
        }
        return (float) $rate;
    }

    /**
     * Return the latest stored rates for the tenant's base currency, refreshing stale data first.
     *
     * @return array{base_currency_code: string, supported_currencies: array, rates: array}
     */
    public function getRates(int $tenantId): array
    {
        $baseCurrency = $this->getBaseCurrency($tenantId);
        $this->refreshIfStale($tenantId, $baseCurrency);
        $stmt = $this->db->prepare(
            'SELECT quote_currency_code, rate, provider, rate_date, fetched_at
             FROM currency_exchange_rates
             WHERE tenant_id = ? AND base_currency_code = ?
               AND rate_date = (SELECT MAX(r2.rate_date) FROM currency_exchange_rates r2
                                WHERE r2.tenant_id = currency_exchange_rates.tenant_id
                                  AND r2.base_currency_code = currency_exchange_rates.base_currency_code)
             ORDER BY quote_currency_code'
        );
        $stmt->execute([$tenantId, $baseCurrency]);
        return [
            'base_currency_code' => $baseCurrency,
            'supported_currencies' => self::SUPPORTED,
            'rates' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ];
    }

    /**
     * Fetch USD quotes, derive base-to-quote rates, and persist the dated rates atomically.
     *
     * @return array{base_currency_code: string, rate_date: string, rate_count: int}
     */
    public function refresh(int $tenantId): array
    {
        $baseCurrency = $this->getBaseCurrency($tenantId);
        if (!in_array($baseCurrency, self::SUPPORTED, true)) {
            throw new RuntimeException('The tenant base currency is not supported by the configured exchange-rate provider.');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 12,
                'header' => "Accept: application/json\r\nUser-Agent: SmartSys-ERP/1.0\r\n",
                'ignore_errors' => true,
            ],
        ]);
        $quotes = array_values(array_filter(self::SUPPORTED, static fn(string $currency): bool => $currency !== 'USD'));
        $url = 'https://api.frankfurter.dev/v2/rates?base=USD&quotes=' . implode(',', $quotes);
        $body = $this->providerFetch !== null
            ? ($this->providerFetch)($url)
            : @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('Exchange-rate provider is unavailable.');
        }

        $payload = json_decode($body, true);
        if (!is_array($payload) || $payload === []) {
            throw new RuntimeException('Exchange-rate provider returned an invalid response.');
        }

        $usdRates = ['USD' => 1.0];
        $rateDate = null;
        foreach ($payload as $quote) {
            if (!is_array($quote) || strtoupper((string) ($quote['base'] ?? '')) !== 'USD') {
                throw new RuntimeException('Exchange-rate provider returned an invalid rate record.');
            }
            $quoteCurrency = strtoupper((string) ($quote['quote'] ?? ''));
            if (!in_array($quoteCurrency, self::SUPPORTED, true) || $quoteCurrency === 'USD') {
                continue;
            }
            $usdRates[$quoteCurrency] = $this->positiveRate($quote['rate'] ?? null, $quoteCurrency);
            $quoteDate = (string) ($quote['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $quoteDate)) {
                throw new RuntimeException('Exchange-rate provider returned an invalid rate date.');
            }
            $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $quoteDate, new \DateTimeZone('UTC'));
            $dateErrors = \DateTimeImmutable::getLastErrors();
            if (
                $parsedDate === false
                || $parsedDate->format('Y-m-d') !== $quoteDate
                || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            ) {
                throw new RuntimeException('Exchange-rate provider returned an invalid rate date.');
            }
            if ($parsedDate > new \DateTimeImmutable('today', new \DateTimeZone('UTC'))) {
                throw new RuntimeException('Exchange-rate provider returned a future rate date.');
            }
            if ($rateDate !== null && $rateDate !== $quoteDate) {
                throw new RuntimeException('Exchange-rate provider returned rates from different dates.');
            }
            $rateDate = $quoteDate;
        }
        if ($rateDate === null) {
            throw new RuntimeException('Exchange-rate provider returned no supported currencies.');
        }
        $usdToBase = $this->positiveRate($usdRates[$baseCurrency] ?? null, $baseCurrency);
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite'
            ? 'INSERT INTO currency_exchange_rates
                 (tenant_id, base_currency_code, quote_currency_code, rate, provider, rate_date, fetched_at)
               VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
               ON CONFLICT(tenant_id, base_currency_code, quote_currency_code, rate_date)
               DO UPDATE SET rate = excluded.rate, provider = excluded.provider, fetched_at = CURRENT_TIMESTAMP'
            : 'INSERT INTO currency_exchange_rates
                 (tenant_id, base_currency_code, quote_currency_code, rate, provider, rate_date, fetched_at)
               VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
               ON DUPLICATE KEY UPDATE rate = VALUES(rate), provider = VALUES(provider), fetched_at = CURRENT_TIMESTAMP';

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare($sql);
            $count = 0;
            foreach (self::SUPPORTED as $quoteCurrency) {
                $usdToQuote = $this->positiveRate($usdRates[$quoteCurrency] ?? null, $quoteCurrency);
                $rate = $usdToBase / $usdToQuote;
                $stmt->execute([$tenantId, $baseCurrency, $quoteCurrency, $rate, self::PROVIDER, $rateDate]);
                $count++;
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return ['base_currency_code' => $baseCurrency, 'rate_date' => $rateDate, 'rate_count' => $count];
    }

    private function positiveRate($value, string $currency): float
    {
        if (!is_numeric($value) || (float) $value <= 0) {
            throw new RuntimeException("The provider did not return a valid {$currency} exchange rate.");
        }
        return (float) $value;
    }

    private function refreshIfStale(int $tenantId, string $baseCurrency): void
    {
        $stmt = $this->db->prepare(
            'SELECT MAX(fetched_at) FROM currency_exchange_rates
             WHERE tenant_id = ? AND base_currency_code = ?'
        );
        $stmt->execute([$tenantId, $baseCurrency]);
        $fetchedAt = $stmt->fetchColumn();
        if ($fetchedAt === false || $fetchedAt === null || strtotime((string) $fetchedAt) < time() - 86400) {
            $this->refresh($tenantId);
        }
    }
}
