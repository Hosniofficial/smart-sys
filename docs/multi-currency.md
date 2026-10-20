# Multi-currency foundation

The tenant's `company.currency` remains the accounting/base currency. Exchange rates are stored per tenant and base currency in `currency_exchange_rates`; each quote rate means **base-currency units for one unit of the quote currency**. Rates come from Frankfurter's free daily USD-based API and are cross-calculated using its USD rates.

## Refreshing rates

`GET /api/v1/settings/currencies/rates` returns the tenant's current rates and refreshes them when the stored feed is older than 24 hours. `POST /api/v1/settings/currencies/rates/refresh` forces an update and requires `settings.manage`. Frankfurter requires no API key, publishes daily rates from central banks and other official sources, and permits free commercial use.

For a scheduled daily update independent of page traffic, run:

```text
php database/refresh_currency_rates.php
```

Configure this command as a daily Railway cron job or Windows Task Scheduler job using the application's environment variables. It processes all tenants and exits non-zero if any tenant cannot be refreshed.

## Transaction snapshots

Migration 026 adds nullable transaction/base-currency, exchange-rate, and amount snapshot columns to sales, purchases, returns, payments, and cash vouchers. Existing records are intentionally left unchanged: their historical currency cannot be reliably inferred and their values must not be recalculated using today's rate.

The rate service rejects missing or unsupported pairs instead of silently assuming parity. Transaction entry and accounting flows must use the server-resolved rate, persist the rate and original/base amounts, and continue posting ledger and inventory values in the tenant's base currency before multi-currency entry is enabled operationally.
