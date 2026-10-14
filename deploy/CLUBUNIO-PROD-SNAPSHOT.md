# ClubUnio: initial PROD snapshot

11.10.2026. User explicitly selected PROD, not DEV, as the source of the new DB.

## Connection and scope

- Target: pg6001.site4now.net:6432 / db_acdf8d_dclubora, PostgreSQL 18.4.
- Source: SQL Server db_acdf8d_mysvs via application/.env.prod.local.
- Target initially had no user tables. Import refuses a non-empty target.
- Credentials in laravel/.env.postgresql.local, confirmed ignored by Git.
  Password copied programmatically from the configured www2 PostgreSQL URI,
  as requested. No secret printed or committed; website not modified.
- TLS verify-full with the local CA bundle. PHP pdo_pgsql loaded only for CLI
  invocations with -d extension=pdo_pgsql. Running PHP configuration unchanged.
- No Laravel/PHPMaker bootstrap, application events, external API requests,
  emails, pushes or background jobs during import.

## Result

84 physical tables and 49,946 rows copied into legacy_prod.
Source transaction used SNAPSHOT isolation (already enabled on PROD); no source
DDL/DML. The read transaction was rolled back after reading. Target schema,
data and verification manifest committed together in a single transaction.

Time according to target server, normalized to UTC:
2026-10-11 00:40:42 through 00:41:26. Subsequent independent connection reread
every table and verified counts plus SHA-256 of canonical column values against
the source manifest. All passed. Comparison preserves decimal values, nulls,
binary values and Unicode; normalizes equivalent boolean/date representations.

Examples: Mitglieder 2,435; Users 70; Rechnung 6,791; Positionen 6,742;
Spesenbelege 447; SpesenPositionen 3,703; TeamList 6,766.

## Deliberate staging limitations

This is a source snapshot, NOT the completed multi-tenant application schema.

- Original dbo table names retained as quoted names in legacy_prod. DataSync
  tables prefixed DataSync__. _migration_row records the verification order.
- Native PostgreSQL types where lossless. datetime2/time with scale above 6
  and datetimeoffset retained as text to preserve SQL Server precision/offset.
  SQL Server rowversion stored as bytea, never interpreted as a date.
- Original views, procedures, functions and triggers are archived as definitions
  in migration_audit.catalog, NOT executable PostgreSQL routines.
- Original columns/defaults, checks, foreign keys and index metadata archived.
  Source identity/default/FK/index semantics are NOT yet implemented in staging.
  _migration_row is the only active staging primary key. No business triggers.
- DataSync/diagram objects retained as evidence, not to become new business code.
- Original ini values, password hashes and device tokens are confidential source
  data. Schemas revoke PUBLIC access. Do not grant the future web runtime access
  to these staging schemas or expose them through an API.
- No application is connected to the snapshot. Production settings must not be
  activated in the new development app. Use separate secrets, disable outbound
  actions, and encrypt imported secrets in the new settings model.
- Upload binaries/Azure blobs were not copied; only DB references were copied.
  Do not give the new development app write/delete access to production blobs.
- Existing DEV/PROD, www2 and Laravel pilot connections remain unchanged.

## Tooling

From laravel/: `php -d extension=pdo_pgsql tools/clubunio-import.php inspect`.
`verify` independently checks the persisted snapshot (read-only).
`copy-prod --new-empty-target` refuses this now-populated target; never clear it
automatically. Failed initial attempts rolled back completely before the
successful import (rowversion conversion and pooled prepared statements fixed).

Next: versioned tenant-aware target migrations, global/user/tenant separation,
settings classification/encryption, tenant-bound relationships, safe IDs and
file namespaces. Port needed views/business rules; test data integrity and
authorization before switching Laravel. Existing mobile API contract retained.
