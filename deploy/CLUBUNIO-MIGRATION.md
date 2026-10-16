# ClubUnio migration work packages

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
