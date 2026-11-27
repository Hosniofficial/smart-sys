# MySQL integration-test schema

`mysql_schema.sql` is a schema-only historical fixture imported by migration-focused integration tests into uniquely named temporary databases. Tests create their own records; this file is intentionally not the current database baseline.

The local full-database snapshot previously kept beside this fixture has been removed. Do not store or commit full database copies here. Keep this schema fixture data-free and retain it while the migration tests depend on its historical starting state.
