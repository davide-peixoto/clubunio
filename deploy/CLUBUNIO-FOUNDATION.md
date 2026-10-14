# ClubUnio foundation - 11.10.2026

First implementation slice, not a completed migration of all business modules.

Current extended delivery and remaining work:
[CLUBUNIO-MIGRATION.md](CLUBUNIO-MIGRATION.md). The sections below retain the
original foundation context; member/family/team functionality has since expanded.

## Running locally

- URL: http://127.0.0.1:8093/clubunio/login
- Separate panel and clubora authentication guard. Existing /admin unchanged.
- Start from laravel/public:
  `php -d extension=pdo_pgsql -S 127.0.0.1:8093 -t . ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`
- Existing active/unlocked PROD accounts from the snapshot can log in if their
  imported User_Level is -1 (admin) or 1 (office). Other levels are NOT granted
  backoffice access automatically. No passwords changed; no remember tokens.
- Also deployed to Pi staging on 11.10.2026:
  https://dev-my2.svsissach.ch/clubunio/login (see PI.md).
  SmarterASP DEV and PROD unchanged. Local and Pi share the ClubUnio database.

## New database schema

Versioned migration database/clubunio creates schema-local tenants, accounts,
tenant_accounts, members, tenant_settings, setting_changes and imports.
The legacy_prod source snapshot and migration_audit are unchanged.

Commands: clubunio:foundation, clubunio:import-foundation, clubunio:status.
Foundation importer is transactional and guarded by an advisory lock and import
marker; reruns do not overwrite edits. Dedicated migration path avoids running
Laravel's unrelated default migrations. Destructive down migration is blocked.

Imported: 1 tenant (SV Sissach), 70 accounts, 2,435 non-soft-deleted members,
184 settings. Member projection currently contains legacy ID, names, company,
email and city, now extended with birthday, member status and member type.
Other data remains in the source snapshot awaiting modules.

## Club objects and table-backed lookups

Second local slice (11.10.2026), migration
`2026_10_11_000002_add_club_objects.php`, command `clubunio:import-objects`:

- 45 teams and 37 seasons, editable through tenant-scoped catalog forms.
- 422 team-season assignments, currently read-only with team/season filters.
- 69 registrations, currently read-only with status/team-season filters and
  slide-over details. No acceptance, member creation, emails or push yet.
- Member statuses (6), member types (4) and registration statuses (6) are
  editable database catalogs, not hardcoded dropdown arrays. Names, ordering
  and active state can be maintained; technical codes cannot change after creation.
- Members have status/type filters and slide-over details. Filters apply
  immediately and persist alongside search. All lookup options are tenant-scoped.

The importer reads only the existing legacy_prod snapshot, validates references,
and runs once transactionally with a lock and marker. A rerun does not replace
user edits. Soft-deleted catalog source rows are represented as inactive.
CatalogService owns validation, authorization and transactional audit writes in
object_changes; Filament policies provide a second authorization layer. Composite
tenant foreign keys prohibit cross-tenant references. Deletion is disabled.
These services can be reused by future APIs; no new API contract is exposed yet.

## UI and security

- Members: searchable/paginated list, tenant-scoped by Filament, with detail and
  edit slide-overs for admin/office accounts (see member editing below).
- Settings: consolidated table with area tabs, search and replace-value action.
  Legacy keys retained temporarily for traceable mapping; not yet fully curated
  domain-specific forms. Unknown keys treated as confidential by default.
- All setting values encrypted with Laravel APP_KEY. Back up this key securely;
  do not rotate it without a decrypt/re-encrypt migration. Secrets never prefilled
  in the form or exposed through model serialization; only nonsecret general
  values can be displayed. Submitted value excluded from session error flashing.
- SettingsService checks actor membership, active state and role, and scopes the
  target record to the tenant. Each update logs actor, time and setting ID in one
  transaction (no plaintext values). Historical value restoration not implemented.
- Composite foreign keys protect audit references across tenants. Other modules
  must apply equivalent constraints as they are implemented.
- No external side effects implemented. outbound_enabled=false is fixed; all
  imported settings initially disabled. No activation switch exposed in UI.
- No App API routes changed. Mobile app continues to use the existing backend.

## Test coverage and remaining work

### Member editing (11.10.2026)

Editable projection: first/last name, company, email, city, birthday, member status
and member type. A company makes first/last name optional; otherwise both are
required, including server-side whitespace normalization. Email/date validation
rejects invalid values and future birthdays. Status/type options are read from
tenant tables. Inactive values may remain on existing records but cannot be newly
assigned. Legacy ID and tenant identity are never writable.

MemberService owns authorization, validation and transactional updates, reusable
for a future API. MemberPolicy permits admin/office only, never foreign tenants.
Row locking plus a form version fingerprint rejects stale edits. Real changes
write actor, tenant and before/after values to object_changes, not application
logs. These audit records contain personal data: keep DB access restricted;
there is no audit browser or retention policy yet. Creating/deleting members,
address details, phone numbers, pass numbers, files, exit/reactivation workflows
and external synchronization remain outside this slice. No schema migration
needed; no changes to source snapshot, old DEV/PROD or mobile APIs.

Verification: full suite 27 tests / 144 assertions, two legacy DEV-read tests
skipped. Includes real Filament edit action, company rule, stale edits, audit,
lookup tenant/active validation and role/foreign-record rejection. Synthetic DB
fixtures are rolled back. Local login responds HTTP 200; real-user browser
acceptance of the edit slide-over is still pending.

CLUBUNIO_TEST_DB=1 opts into integration tests against the new ClubUnio DB only.
Synthetic fixtures and edits run in rolled-back transactions. Checks cover tenant
page isolation, foreign setting IDs, inactive/member roles, encryption/masking,
audit writes, typed validation and DB composite FK enforcement. Existing pilot
tests also run; DEV-read tests need their separate explicit opt-in.

Login visually checked in browser; authenticated pages checked via Laravel HTTP
tests. Real-user browser login and mobile layout still need user acceptance.

Not production-ready: dedicated least-privilege runtime DB role remains to be
provisioned (current local connection is migration owner); no RLS policy yet.
No second-tenant onboarding UI, account/member identity linking, domain-specific
full member workflows, registration workflows, invoices, expenses, planning or external
adapters have been ported. Team-season assignment editing is also pending.
Legacy security must be ported/tested per module, not inferred
from the coarse foundation roles. Sensitive snapshot remains owner-accessible,
must never be granted to a future runtime role. Further settings classification
must separate facility state from configuration and split historical iniWhatsApp
keys by their actual integration. Production blob write access remains forbidden.
