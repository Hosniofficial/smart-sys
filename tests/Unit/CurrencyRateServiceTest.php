<?php

declare(strict_types=1);

use App\Services\CurrencyRateService;
use PHPUnit\Framework\TestCase;

final class CurrencyRateServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available.');
        }

        $this->db = new PDO('sqlite::memory:');
        $this->db->exec(
            'CREATE TABLE settings (
                tenant_id INTEGER NULL,
                key_name TEXT NOT NULL,
                value TEXT NULL,
                updated_at TEXT NULL
            )'
        );
        $this->db->exec(
            "INSERT INTO settings (tenant_id, key_name, value, updated_at)
             VALUES (7, 'company.currency', 'EGP', CURRENT_TIMESTAMP)"
        );
        $this->db->exec(
            'CREATE TABLE currency_exchange_rates (
                tenant_id INTEGER NOT NULL,
                base_currency_code TEXT NOT NULL,
                quote_currency_code TEXT NOT NULL,
                rate NUMERIC NOT NULL,
                provider TEXT NOT NULL,
                rate_date TEXT NOT NULL,
                fetched_at TEXT NOT NULL,
                UNIQUE (tenant_id, base_currency_code, quote_currency_code, rate_date)
            )'
        );
    }

    public function testReturnsStoredRateInBaseUnitsPerQuoteUnit(): void
    {
        $this->db->exec(
            "INSERT INTO currency_exchange_rates
             (tenant_id, base_currency_code, quote_currency_code, rate, provider, rate_date, fetched_at)
             VALUES (7, 'EGP', 'USD', 49.25, 'test', date('now'), CURRENT_TIMESTAMP)"
        );

        $service = new CurrencyRateService($this->db);
        self::assertSame(49.25, $service->getRate(7, 'USD'));
        self::assertSame(1.0, $service->getRate(7, 'EGP'));
    }

    public function testRejectsMissingRatesInsteadOfAssumingParity(): void
    {
        $this->expectException(RuntimeException::class);
        (new CurrencyRateService($this->db, static fn() => false))->getRate(7, 'USD');
    }

    public function testRejectsUnsupportedCurrencies(): void
    {
        $this->expectException(RuntimeException::class);
        (new CurrencyRateService($this->db))->getRate(7, 'XYZ');
    }

    public function testRefreshesAndCrossCalculatesFrankfurterRates(): void
    {
        $provider = static function (string $url): string {
            self::assertStringContainsString('api.frankfurter.dev/v2/rates', $url);
            return json_encode([
                ['date' => '2026-10-14', 'base' => 'USD', 'quote' => 'EGP', 'rate' => 52.804],
                ['date' => '2026-10-14', 'base' => 'USD', 'quote' => 'EUR', 'rate' => 0.893421],
                ['date' => '2026-10-14', 'base' => 'USD', 'quote' => 'SAR', 'rate' => 3.75],
                ['date' => '2026-10-14', 'base' => 'USD', 'quote' => 'AED', 'rate' => 3.6725],
                ['date' => '2026-10-14', 'base' => 'USD', 'quote' => 'KWD', 'rate' => 0.30963],
                ['date' => '2026-10-14', 'base' => 'USD', 'quote' => 'QAR', 'rate' => 3.64],
                ['date' => '2026-10-14', 'base' => 'USD', 'quote' => 'BHD', 'rate' => 0.376],
                ['date' => '2026-10-14', 'base' => 'USD', 'quote' => 'OMR', 'rate' => 0.3845],
            ], JSON_THROW_ON_ERROR);
        };

        $service = new CurrencyRateService($this->db, $provider);
        $result = $service->refresh(7);

        self::assertSame('2026-10-14', $result['rate_date']);
        self::assertSame(9, $result['rate_count']);
        self::assertEqualsWithDelta(52.804 / 0.893421, $service->getRate(7, 'EUR'), 0.0000001);
        self::assertSame('frankfurter', $this->db->query(
            "SELECT provider FROM currency_exchange_rates WHERE quote_currency_code = 'EUR'"
        )->fetchColumn());
    }

    public function testRejectsFutureRateDatesBeforePersistingAnyRates(): void
    {
        $futureDate = (new DateTimeImmutable('tomorrow', new DateTimeZone('UTC')))->format('Y-m-d');
        $provider = static function (string $url) use ($futureDate): string {
            return json_encode([
                ['date' => $futureDate, 'base' => 'USD', 'quote' => 'EGP', 'rate' => 52.804],
            ], JSON_THROW_ON_ERROR);
        };

        try {
            (new CurrencyRateService($this->db, $provider))->refresh(7);
            self::fail('Expected a future-dated rate to be rejected.');
        } catch (RuntimeException $e) {
            self::assertSame('Exchange-rate provider returned a future rate date.', $e->getMessage());
        }

        self::assertSame(
            0,
            (int) $this->db->query('SELECT COUNT(*) FROM currency_exchange_rates')->fetchColumn()
        );
    }
}
