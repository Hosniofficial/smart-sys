# MySQL integration-test schema

`mysql_schema.sql` contains the local MySQL schema only; it has no table data. The MySQL integration tests import it into a uniquely named temporary database and create their own fixtures.

`smartsys-local-test.sql` is a local full database copy used only as the source for this fixture. It is ignored by Git because it contains database records. Do not stage or commit it. When refreshing the test schema, export structure only (`mysqldump --no-data`) and verify the result contains no `INSERT` statements.
