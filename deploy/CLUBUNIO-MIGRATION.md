# ClubUnio migration work packages

26.10.2026 provider actions: additive migration79 creates encrypted API work and
append-only event evidence; no legacy import, profile grant or configuration
change. Staged requests never call providers in the API idempotency transaction.
Bexio execution requires a real-admin per-item web approval, never a technical
role upgrade. Preserve original token revocations, intent/result encryption keys
and uncertain work on rollback; never reset running work to pending. No new
scheduler. Exact scope and release evidence: CLUBUNIO-API-PROVIDERS.md.
Migration79 applied via the shared ClubUnio journal; seven release gates and
isolated HTTPS staging/replay/status tests passed. Source90dc47f; final Swagger
example follow-up does not require another migration. Preserve the prior
20261026-clubunio-api-providers release and its durable evidence on rollback.

25.10.2026 new independently versioned API: apply additive migration76 only to
ClubUnio (api_only account flag, dedicated integrations/tokens/audit/idempotency).
No default permissions or client tokens are seeded. Sanctum provider discovery
and a fresh route/config/view cache are required for the new release. Preserve
current encryption keys and all revocation/idempotency/audit evidence on rollback;
the web/portal API-only login restriction must remain while service accounts
exist. The old PHPMaker API and all existing app/website consumers stay in place;
future cutovers require explicit client contract tests and their own approval.
API authorization is now in scope; SaaS, operations and app rebuild are not.
Details and exact exposed capabilities: CLUBUNIO-API.md.
26.10.2026: reviewed composite integration/profile FK78 explicitly applied via
the migration journal; no repair/import. Final API119 tests/26,554 assertions
passed. Preserve the exact-domain/token API middleware on every v1 route when
rebuilding route caches. Remaining specialized adapters are explicitly listed in
CLUBUNIO-API-COVERAGE.md; existing clients are not automatically migrated.
Deployed API release and isolated HTTPS QA passed; synthetic tokens revoked and
accounts/profile disabled. Final UI/no-store follow-up needs no schema change.
Keep API-only web login exclusion and composite profile FK on rollback; do not
drop tables or reactivate QA credentials. Existing PHPMaker DEV/PROD unchanged.

25.10.2026 sponsor CHF web boundary: all web monetary fields use amount_chf and
cap_chf; server validates and converts to existing integer amount_cents/cap_cents.
Do NOT multiply or rewrite persisted amounts. Domain/Bexio snapshots unchanged,
no migration, import, profile grant or API rollout. Reopen stale web forms after
deployment. Rollback to sponsor-visual restores cents UI without DB changes.
Exact precision/bounds and web tests: CLUBUNIO-SPONSOR-RUN.md.

25.10.2026 visual follow-up: authenticated document-series/sponsor-run/billing
desktop and390px checks through ClubUnio proxy. Blade-only layout fixes deployed
as20261025-clubunio-sponsor-visual; no schema/import/config/permission changes.
Keep QA evidence and previous release on rollback. No provider writes, resends or
bank payments. Manual PDF inspection by user; browser viewer automation blocked.
See CLUBUNIO-SPONSOR-DOCUMENT-ACCEPTANCE.md for exact coverage and limits.

25.10.2026 live acceptance: additive synthetic QA rows only, no schema change or
legacy import. Private Azure series41/document388 and sandbox RE-00017 remain
traceable; reference QA season/team assignments deactivated and prior sponsor
billing configuration restored. Never migrate QA fixture rows as club business
data or discard immutable evidence in a rollback. Country adapter corrected to
the actual iso_3166_alpha2 provider field. Repeatable CLI opt-in and real-provider
webkernel read tests documented in CLUBUNIO-SPONSOR-DOCUMENT-ACCEPTANCE.md.
Manual authenticated desktop/mobile acceptance subsequently completed as above.

25.10.2026: native document-series and sponsor-run web modules. Additive
ClubUnio-only migrations73/74/75 create immutable template/snapshot evidence,
recipient processing/return provenance, season-based runs/pledges/helper/public
consent history, and individually referenced Bexio billing/audit records.
Apply explicit paths in order; journal protects the shared local/Pi database.
No legacy database refresh/import, default-role grants or existing document moves.
Future source import must map seasons/teams/members first, preserve sponsor
consents/financial references and issued evidence, and separately reconcile remote
IDs before any provider write. Never silently regenerate issued documents.
Code rollback preserves new tables and encryption key; no destructive down.
App API, SaaS, operations and existing PHPMaker DEV/PROD remain out of scope.
See CLUBUNIO-DOCUMENT-SERIES.md, CLUBUNIO-SPONSOR-RUN.md and
CLUBUNIO-SPONSOR-BILLING.md for workflows, tests and live acceptance limits.

23.10.2026: season-planning acceptance completed with synthetic isolated source
and followup seasons, including real browser completion and all25 result links.
Source rows and SVS baseline unchanged; temporary fixture/access cleaned up.
Delta20261023-clubunio-season-planning-acceptance: Page/Blade only, explicit staff
function in drag payload, member ID search and inline final confirmation.
No schema/import/config/permission migration. Preserve existing drafts, encryption
key, revision guards and result history. Do not migrate synthetic QA seasons.
Historical open visual/overall checks are superseded by CLUBUNIO-SEASON-ACCEPTANCE.md.
Real planning still requires user business approval. API/SaaS/operations and
PHPMaker DEV/PROD untouched.

23.10.2026 follow-up: authenticated real drag/drop and filled training-slot
browser checks completed in isolated QA draft136 (now discarded, history kept).
Fix autosave overwriting pending browser fields by retaining the editor document;
storage still validates/normalizes/encrypts and enforces revisions/permissions.
New-plan/discard confirmations are inline instead of native JS. Reopened full slot
verified on desktop/390px. Domain-table counts unchanged; existing draft112 remains
revision22. Delta20261023-clubunio-season-planning-interactions deployed, no schema,
imports, rights changes or binding season creation. Regression tests use rollback.
Legacy DEV/PROD, API/SaaS/operations untouched. CLUBUNIO-SEASON-PLANNING.md.

23.10.2026: Wizard visual QA via authenticated Browser plugin, desktop/390px.
Place the existing authorized page in Sport navigation, load company for member
label/search fallback and constrain pool height. No member exclusion or altered
assignment/age/permission rules, no schema or data import. Existing review tokens
must be refreshed because the facts now include company names. Deploy navigation,
page/service/Blade/CSS together. Post-deploy desktop/mobile display verified after
recovering the browser connection in a fresh tab. Filled-slot and real drag/drop
browser checks remain open; no new draft or season was created during visual QA.
API/SaaS/operations and legacy DEV/PROD unchanged. CLUBUNIO-SEASON-PLANNING.md.

22.10.2026: Correct historical seed71 test expectations to its frozen read-only
grant list, not today's module catalog. Additional regression checks repeated
seeding preserves the complete existing profile and membership, with write,
download/export and transfer still denied. No migration, permissions, runtime
code or deployment change. Browser-driven visual wizard acceptance retried but
blocked by URL-verification safety guard; remains open, not marked passed.
See CLUBUNIO-SEASON-PLANNING.md. API/SaaS/operations and legacy DEV/PROD unchanged.

21.10.2026: Season-planning wizard. Migration72 adds encrypted revisioned plans
and per-item completion mappings only to ClubUnio PostgreSQL. Preserve APP_KEY,
drafts, result mappings and audit history. Do not refresh/import legacy data.
Draft edits never write domain tables. Explicit finish runs existing catalog,
contribution/training and assignment services in one transaction; replay returns
the existing season. No invoices, notifications, provider calls or current-season
setting change. Deploy page/view/CSS/provider/service/catalog changes together.
Retain additive tables on code rollback. Automated transaction/Livewire checks;
authenticated visual/drag-drop and real full-season acceptance remain open.
PHPMaker DEV/PROD, API, SaaS and operations unchanged.
See CLUBUNIO-SEASON-PLANNING.md.

19.10.2026: Read-only finance acceptance checker for known sandbox fixtures;
GS-00005 already allocated in package72. No migration or repeated business
write. Two-target allocation remains a separate manual sandbox acceptance.
See CLUBUNIO-FINANCE-ACCEPTANCE.md.

19.10.2026: Repeated application-lifecycle performance acceptance, plus
synthetic rolled-back save/PDF/history scenarios. CLI tooling/documentation
only; no business behavior, permissions, indexes, schema or data import changed.
Keep record IDs, audit history, error references and fresh operation guards.
Browser end-to-end validation remains explicitly incomplete. Legacy DEV/PROD,
API/SaaS/operations unchanged. See CLUBUNIO-PERFORMANCE-ACCEPTANCE.md.

19.10.2026: Narrow caretaker grants to field_availability.view/update. Deploy
catalog, permissions, facility service and form together. No schema migration.
Old broad facilities grants remain compatible; never re-expand caretaker grants
on rollback. See CLUBUNIO-CARETAKER-SCOPE.md.

18.10.2026: Synchronisation page reads history only for the selected item,
20 entries per separate history page; preserve every historical event/note.
Metadata projection and conditional context payload reads only. Fresh admin
authorization and tenant-scoped parent lookup; no client-supplied selection.
No schema/index/import/settings/retention or sync execution changes. Deploy
page and Blade together; rollback via prior release. API/SaaS/operations and
legacy DEV/PROD unchanged. See CLUBUNIO-SYNC-VIEW-PERFORMANCE.md.

18.10.2026: Seven SV Sissach access profiles created without account assignments.
Tenant-specific setup, not a schema migration or legacy import. Explicit rights,
audited creation, existing profiles never overwritten. Future database moves
must recreate deliberately without automatic account assignment or copied IDs.
See CLUBUNIO-SVS-ACCESS-PROFILES.md.

18.10.2026: Training list metadata projection, scoped read optimization and
one aggregate quota query. No schema/import/configuration/provider contract
change. Preserve owner/shared/team scope, fresh download/edit permissions,
request idempotency/limits/locks, legacy quality gate and audit. PDF content,
SVG safety, disabled remote/PHP/JavaScript and no-store headers unchanged.
Rollback via previous ClubUnio release. API/SaaS/operations and legacy DEV/PROD
unchanged. See CLUBUNIO-TRAINING-PERFORMANCE.md.

18.10.2026: Dossier UI fixes require no database migration or audit rewriting.
Deploy history service/view, relation actions, tab loading attributes and CSS
together. Existing permissions/assignments remain untouched. Rollback via
previous release. See CLUBUNIO-DOSSIER-UI-FIXES.md.

18.10.2026: Series/absence reads optimized without schema or data import.
Preserve unique participation keys, encrypted original participation/comment,
trainer decisions, deadlines and fresh write authorization. No persistent
permission/absence cache. Deploy both planning services together; rollback
via previous release, no database rollback. API/SaaS/operations and legacy
DEV/PROD unchanged. See CLUBUNIO-SERIES-ABSENCE-PERFORMANCE.md.

17.10.2026: Contribution batch preview shares bounded calculation reads within
one run. Single/create previews remain fresh; same results/fingerprints,
locks, rights, idempotency and audit. Preserve historical invoice/player IDs.
No schema/import/Bexio send/payment/API/SaaS/operations or PHPMaker DEV/PROD
change. See CLUBUNIO-CONTRIBUTION-PERFORMANCE.md.

17.10.2026: Named profile acceptance completed with transactional synthetic
accounts and domain records. Read-only office and finance editing tested;
existing rights fingerprints unchanged after rollback. No migration, deployment
or real assignments. See CLUBUNIO-PROFILE-ACCEPTANCE.md for scope and limits.

Manual deployed-browser acceptance followed using two separate synthetic
accounts: rights boundaries passed; invoice/line/expense edits and PDF checked.
Fixtures removed, original rights unchanged. No schema/import changes.
Before final UI sign-off fix nested invoice audit decoding, stale parent/tab
refresh after nested dialogs and the remaining "Edit line" label. No PHPMaker
DEV/PROD changes, no application deployment in this acceptance-only package.

17.10.2026: Season transfer indexes existing assignment collections and batches
member hydration. Bulk confirmation rows locked/read together; per-record
write authorization, validation and audit remain fresh. Preserve HMAC shape,
IDs and inactive records. No schema/import/API/SaaS/operations or PHPMaker
DEV/PROD change. See CLUBUNIO-SEASON-BULK-PERFORMANCE.md.

16.10.2026: Announcement choices now bounded server searches plus selected-ID
labels; status catalog reused per table build only. Existing recipient IDs,
snapshots, token/hash checks and transactional delivery unchanged. No schema,
API/SaaS/operations or legacy DEV/PROD change. Deployment/rollback documented
in CLUBUNIO-ANNOUNCEMENT-PERFORMANCE.md.

16.10.2026: Dynamic access profiles integrated across domain operations and
web actions. Migrations69/70 and activation71 applied on ClubUnio, with71
activated only after the guarded release was live. The
new profile role never inherits office rights; rollback to old code denies
profile access. Seed read-only definitions without assigning existing users.
Permission cache is render-only; mutations always recheck current rights.
No PHPMaker DEV/PROD changes, API/SaaS/operations remain excluded. Details and
verification: CLUBUNIO-DYNAMIC-ROLES.md and PI.md.

16.10.2026: Form lookup projections bounded; member save reads selected
catalog IDs once per table, reuses status/recipient metadata in transaction.
No cross-request lookup/permission cache, schema change or import. Keep
historical field-specific lookup IDs; legacy DEV/PROD and API/SaaS/operations
unchanged. Release and rollback: CLUBUNIO-FORM-PERFORMANCE.md.

16.10.2026: Bexio PDF reads omit local display relations; inline PDF.js viewer
replaces wrapper iframe and releases workers on close/change. Fresh document/
sandbox authorization and downloads preserved; no document cache or DB change.
Deploy Blade partial and versioned assets together; retain current OIDC rotation.
See CLUBUNIO-PDF-PERFORMANCE.md. Legacy DEV/PROD, API/SaaS/operations unchanged.

16.10.2026: Signage display lookups batched per request; bus choice fields
and duplicate loads reduced. Existing read context only for feed presentation;
fresh actions/public opt-in/locks and historical rooms preserved. No schema,
index or data migration, no legacy/API/SaaS/operations change. See
CLUBUNIO-SIGNAGE-BUS-PERFORMANCE.md.

16.10.2026: Report policy tenants eager-loaded in eight list resources;
CSV exports load only required relations/member fields, finance CSV values
prepared before headers. Authorization, filters, ordering, 5000-row guard and
audit retained. No schema/data migration, API/SaaS/operations or legacy changes.
See CLUBUNIO-REPORT-PERFORMANCE.md.

16.10.2026: Expense line button rendering reuses checks only in the existing
read context; registration rows eager-load tenants and attachment previews
load on demand. Fresh write authorization and private Azure reads unchanged.
No schema/data migration or legacy DEV/PROD changes. See
CLUBUNIO-EXPENSE-DOCUMENT-PERFORMANCE.md.

16.10.2026: Remaining DB read roundtrips reduced through existing bounded
backoffice context and SQL subqueries for represented planning members.
Fresh write/portal authorization retained; no schema migration, data refresh
or legacy DEV/PROD deployment. See CLUBUNIO-DB-READ-PERFORMANCE.md.

16.10.2026: Planning/calendar CPU work consolidated with exact time-boundary
reference tests; already authorized team labels reused without granting team
management. No schema/data migration or legacy deployment. Existing deadline,
capacity, privacy and role rules retained. See CLUBUNIO-PLANNING-PERFORMANCE.md.

16.10.2026: Dashboard and invoice detail reads consolidated without changing
roles, mutation checks, balances or membership rules. Member/expense dossiers
profiled as well. No schema migration or legacy DEV/PROD deployment.
See CLUBUNIO-DASHBOARD-DETAIL-PERFORMANCE.md for measurements and tests.

16.10.2026: Filter/search rendering now uses a bounded Livewire permission
snapshot; property changes, actions and nested mounts retain fresh checks.
No schema/data migration or legacy deployment. Filter/search combination and
tenant/role boundaries are regression tested. See CLUBUNIO-PERFORMANCE.md.

Updated 11.10.2026. The user has approved migration of the existing backend,
not just isolated screens. This is a staged implementation, not a completed
replacement. Existing PHPMaker and mobile APIs remain authoritative in PROD.

Priority clarification 11.10.2026: DEV/PROD continue independently long term.
ClubUnio has its own database. API compatibility is NOT a fixed requirement;
API redesign and SaaS productization are deferred until core workflows mature.
The living work order, dashboard/list inventory and migration decisions are in
[CLUBUNIO-ROADMAP.md](CLUBUNIO-ROADMAP.md). No imminent production replacement.

## Current delivery

### Registration review foundation (11.10.2026)

Admin/office can review status/team/TL assignment with audit, optimistic revision
and atomic held communication events. Trial requires a team; TL assignment only
in review. Full acceptance, applicant editing, TL portal and automatic delivery
are NOT complete. See [registration scope and remaining work](CLUBUNIO-REGISTRATIONS.md).

### Member dossiers and finance read models (11.10.2026)

All 13 member detail tables from PMP plus TL are accessible from the member
dossier. New invoice/expense lists and detail pages include nested positions,
status/season filters, active-line totals and expense review indicators.
Family, team, team-season and season master pages include related objects.
Bank IBANs encrypted/masked. New objects currently read-only, no financial
actions, PDFs or external calls. See [relationship coverage and dependencies](CLUBUNIO-MASTER-DETAIL.md).

The old /admin SQL Server pilot was removed on 11.10.2026, locally and in
Pi release 20261011-clubora-03. Only ClubUnio is active; old admin links redirect
there. Legacy invoice PDF routes are removed, not migrated to ClubUnio yet.

### Members, families and catalogs

- Members: list, search plus persistent filters, detail/edit slide-over, creation.
  Company OR first/last names required on server and in UI.
- Full contact projection: street/house number, text postal code, country,
  nationality, gender, birthday, private/business/mobile phones, website,
  pass number, mother/father names and contacts, invoice recipient, exit date,
  honorary/free/supporter/sponsor/Spots flags.
- Countries, genders, recipient definitions and document types are tenant tables
  with maintenance screens. Existing inactive selections remain valid; new
  assignments require active values. Recipient definitions map to the supported
  email fields, not arbitrary columns. Selected recipient must have a valid email.
- Status behavior uses database flags ends_membership/restores_membership rather
  than matching display labels. Entering an exit status sets a missing exit date;
  entering a reentry status clears the exit date. Both flags cannot be true.
- Members get a tenant-local member_number; imported numbers equal legacy IDs.
  New records have NULL legacy_id, and numbers allocate under a tenant lock.
- Families and family memberships support create/edit/deactivation, country,
  discounts and notes, duplicate checks, optimistic concurrency and audit.
  A family membership NEVER grants login or parent-access rights.
- Member archive service exists and is tested, but no deletion/archive button
  is exposed yet. Nothing is physically deleted.
- Source AHV values retained encrypted, excluded from model serialization and
  editing/audit. Source photo fields retained privately, not public URLs.
- 70 document metadata rows imported. NO binary migration, upload or download
  implemented in ClubUnio yet. Existing Azure blobs are neither changed nor deleted.

### Teams and responsibilities

- Team-season combinations can be created and deactivated. Existing team/season
  identity cannot be rewritten because other modules reference the assignment.
- Player, staff and technical-lead assignments: create/edit/deactivate, member
  lookup, season and team filters, tenant checks, duplicate prevention and audit.
- Staff functions are maintained in a tenant-specific lookup table.
- TeamList.PositionsID is an INVOICE POSITION reference, not a sporting position.
  Preserved as legacy_invoice_position_id until the invoice module is migrated.
  A linked player assignment cannot be reassigned to another member/team; it can
  be deactivated. Existing historical duplicates are preserved, not auto-merged.
- These are admin/office maintenance screens. Trainer/TL/player portal policies
  and automatic account-role mapping are NOT implemented by these assignments.

### Audit and safety

- Read-only tenant-scoped change history with actor/time and before/after values.
  Contains personal data; no broad roles or anonymous access. Retention policy
  still required before production. Integration secrets/AHV not included.
- Business mutations live in MemberService, FamilyService, TeamAssignmentService
  and CatalogService. UI maps field errors to modal state paths. Future APIs must
  reuse these services instead of calling Eloquent save directly.
- Composite database foreign keys enforce tenant consistency. Tests exercise
  forged member IDs, lookup IDs, role denial, duplicate assignments, stale forms,
  invoice-reference protection, billing validation and reentry.
- Runtime tables and validation queries are schema-qualified. A transient 42P01
  during concurrent hosted-DB checks prompted removal of the search_path
  dependency. Exact provider pool mode is unconfirmed. Regression test changes
  search_path to public and verifies real member reads/updates and authorization.
- New migrations/importers use only ClubUnio; imports are transactional and marked
  once. Re-running does not overwrite UI edits. Source legacy_prod unchanged.
- Import totals: 2,435 members, 250 countries, 88 families/208 links, 70 documents,
  6,766 player assignments, 460 staff, 29 TL assignments, 8 staff functions.
  Family country names such as Schweiz map explicitly to imported ISO countries.

## Communication and note editing (11.10.2026)

Tenant-specific email/push template editor with preview, language variants,
revision history and restore. Read-only expense notification planning resolves
office/TL recipients for F and owner for other known statuses. Member dossier
notes now support audited create/edit/deactivation with revision checks.
Migration 000006 is additive; seed-message-templates preserves existing edits.
See CLUBUNIO-COMMUNICATION.md for precise coverage and outstanding delivery work.
These templates do not replace PHPMaker production messages yet.

## Still required (not delivered)

11.10.2026: contribution prices/rules, training-time maintenance and an initial
admin dashboard have been added. Migration 000015 and marked snapshot import
are applied to the new ClubUnio DB. See CLUBUNIO-CONTRIBUTIONS-TRAINING.md.
Automatic contribution billing and complete Signage are still pending.

1. Member documents: private Azure read/preview/download implemented in release06.
   Still pending: isolated test uploads, photo handling and write workflows.
2. Identity/access: release07 adds account-member links, reviewed parent/minor
   rules, active-member login and a restricted trainer/TL/member contact portal.
   Still pending: full object permissions, account provisioning, role management,
   app authentication and authorization parity. See CLUBUNIO-ACCESS.md.
3. Registrations: release09 adds encrypted details, review/TL portal,
   Pool/trial/accept/reject, member matching/reactivation and atomic document
   reference transfer. Remaining: public intake, new uploads and mobile API.
   See CLUBUNIO-REGISTRATIONS.md for permissions and delivery restrictions.
4. Expenses: release10 adds audited role-scoped transitions, own/TL
   portal, position editing, laundry defaults/review indicator, bank management,
   attachment reads and fixed-admin mail/private notifications. Remaining: new
   uploads/AI analysis, Bexio bill creation/sync and mobile API. See CLUBUNIO-EXPENSES.md.
5. Invoices: release11 adds member-derived portal visibility, filters
   and active position details. Still required: PDF, invoice creation/editing,
   Bexio creation/status sync, failure/retry/reconciliation and notifications.
   See CLUBUNIO-INVOICES.md; preserve legacy line links.
6. Team planning: events/attendance/deadlines/absences/manual override protection,
   SFV import, training planner, visibility and notification rules.
7. Facilities/bus/signage: bookings/approval/conflicts, field availability,
   competitions/rooms/manual sync and external matches.
8. Messaging/operations: transactional outbox, integration of templates into all
   business handlers, actual email/FCM delivery, persistent notifications,
   scheduled jobs, retry/idempotency/diagnostics, classified settings UI.
9. API parity: route/payload/error/pagination/lookup contract tests against current
   mobile spec. No claim of drop-in app compatibility until these tests pass.
10. Production readiness: least-privilege DB role (current connection is migration
    owner), tenant onboarding/default catalogs, backup/restore drills, audit
    retention and final import/delta plan. SaaS is not production-ready yet.

## Verification and operation

Release06 extends Pi staging with admin-only sample mail and member document reads.
Release07 adds identity mapping and the restricted contact portal (CLUBUNIO-ACCESS.md).
Automated result: 58 tests, 531 assertions, all passed. Local authenticated browser checked template editor,
sample previews and expense notification planning; mobile-width DOM has no
horizontal page overflow. Earlier member dossier/finance checks remain covered.
Login and protected-route
HTTP checks on HTTPS passed; business acceptance remains with the user.
Source snapshot reverified: 84 tables / 49,946 rows unchanged. See PI.md.

Use clubunio:foundation for the reviewed migrations; no default Laravel migration
against the legacy SQL connection. Import commands:
clubunio:import-member-details, clubunio:import-team-assignments.
The already imported snapshot is the source; do not silently refresh it from PROD.
tools/clubunio-module-counts.php prints counts only; tools/clubunio-import.php verify
checks all 84 snapshot tables / 49,946 rows by count/hash.

Authenticated tests use synthetic tenants and rollback. Local and Pi staging share
the NEW ClubUnio database, so real edits in either UI are visible in the other.
Release09/10 extend fixed-admin test email delivery to committed registration
and expense outboxes. No actual-member email delivery, external push, Bexio
writes, Azure writes or mobile endpoint changes.

Release14: additive migration000016 for private encrypted saved list views,
scoped to tenant/account/list. Six admin/office lists support named views and
one default. No legacy data changes; see CLUBUNIO-SAVED-VIEWS.md and PI.md.

25.10.2026 tenant-boundary review: migration77 adds26 composite tenant foreign
keys to existing web relationships after validating current data. Applied only
to shared ClubUnio PostgreSQL, no data rewrite/import, no PHPMaker DEV/PROD change.
Exact opt-in customdomain mapping, host-bound context and request-local branding
are implemented locally; real DNS/TLS/hosts and code deployment remain separate.
Parallel API76 remains a separate acceptance item. Preserve FK security on
rollback. Scope, checks and rollout: CLUBUNIO-TENANT-BOUNDARIES.md.

26.10.2026 API tenant review: additive migration78 prepares a validated composite
FK from api_integrations(tenant_id,access_profile_id) to access_profiles(tenant_id,id).
Follow-up authorized26.10.2026: already installed, batch77; targeted migrate is
a no-op and the metadata audit confirms validated tenant FKs. Do not rerun up().
The constraint test retains its outer rollback. Parallel API76 sources still
require separate integration; this review does not commit that working tree.
No data rewrite/import, no PHPMaker DEV/PROD changes. API domain/token binding
and regression suite accompany this isolated security delta; not a standalone
API release. Rebuild route cache and repeat tests after parallel API completion.
Follow-up complete for the tested local snapshot:119 API tests/26554 assertions
pass with isolated built route cache; the temporary cache was removed. The
schema audit finds no unpaired tenant FK among128 tenant tables. No code
deployment or legacy environment changes were performed by this review.
Review and rollout limits: CLUBUNIO-API-TENANT-BOUNDARIES.md.

26.10.2026 combined tenant/API deployment: release20261026-clubunio-tenant-api,
source a09a197 (completed API cb8d89a plus release gates). Existing migrations
76/77/78 verified, none executed or reversed by this deployment; parallel79
is not shipped. Preserve shared configuration/storage and all audit/token/write
evidence.128 isolated tests/26755 assertions, seven server gates and HTTPS QA
passed. Previous api-v1-final retained; no legacy environment or client cutover.
Exact artifact, checks and rollback: CLUBUNIO-TENANT-API-RELEASE.md.
