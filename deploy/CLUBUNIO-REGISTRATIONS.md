# ClubUnio registration review

## Expanded workflow 11.10.2026 (release09 deployed)

This section supersedes the historical release08 scope below.

- 69 active source registrations now have encrypted applicant details and
  separate portrait/identity document references. Versioned importer reads only
  legacy_prod; it preserves later UI edits. No Azure files are changed/copied.
- Admin/office create/edit applicant data with tenant lookups, billing email
  validation and revision checks. AHV stays encrypted and outside forms/audits.
- Acceptance creates or explicitly links an existing member, checks its version,
  blocks possible duplicates, restores membership and clears exit date. Existing
  pass number is preserved. Each identity file becomes a member document row.
  Member, notes, documents, audit, registration and events commit atomically.
- Accepted registrations are terminal. Review/trial/rejected/pool remain
  supported. Trial requires a team. General admin permissions are not broadened.
- TL portal /portal/{tenant}/registrations shows only direct assignments or
  responsible teams. New assignment requires a responsible current-season team.
  TL cannot change the assigned TL or select arbitrary existing members.
- Documents use authenticated tenant/object-scoped private Azure reads with
  existing size/MIME protection. SAS credentials never reach the browser.
- Persistent notifications: created to office/admin; review to assigned TL;
  trial/accepted/rejected to assigned team staff. Inbox/read/read-all are private
  to each account. External FCM delivery remains disabled.
- Applicant templates use the billing contact; trial staff CC is planned in
  encrypted payloads. Review has no applicant email. Pool uses its email template.
- Committed mail outbox: clubunio:send-registration-mail, capped batches, locked
  claims, no sends inside a business transaction. Every actual email goes ONLY
  to admin@svsissach.ch; intended CC never enters SMTP. Global mail guard remains.
  Only local/testing/staging with test_mail_enabled can dispatch.
- Envelope action shows held/ready/sending/accepted/failed/skipped metadata.
  SMTP accepted is not delivery confirmation. Failed/ambiguous sends are not
  automatically retried. No historical events are replayed.
- Additive migrations 000010/000011 applied on NEW shared ClubUnio DB. Season
  dates imported from YYYY/YYYY labels and editable. Seed adds missing templates
  without overwriting edited templates. Source PHPMaker DEV/PROD untouched.
- deploy/clubunio-scheduler.service/.timer support minute scheduling on Pi;
  existing legacy jobs must not be replaced. No SmarterASP job introduced.

Remaining: public intake/account provisioning, isolated new file uploads,
mobile API parity, production delivery activation and operational retry policy.
This portal is not yet a drop-in replacement for the mobile API.

## Historical release08 scope

11.10.2026: first backoffice review slice, not full registration parity.

## Available

- Registration list/detail shows imported technical-lead assignment.
- Admin/office action: status, team-season, TL assignment and mandatory review note.
- Workflow behavior uses registration_statuses.workflow_key, independent of labels.
  Initial SV Sissach mapping: legacy IDs 1..5 and code Pool. Other tenant status
  behavior must be configured explicitly; unknown workflow keys cannot be used.
- Supported targets: review, trial, rejected, pool. Trial requires an active team.
- New TL assignment only while entering/staying in review, requiring an active
  account with technical_lead membership, enabled portal and approved self link
  to an active member. Existing historical assignments are retained in storage.
- Missing fields preserve old values. Unexpected fields are rejected.
- Tenant-qualified row lock, optimistic revision, transaction and object_changes
  audit. No-op produces neither revision nor event. Stale retry fails cleanly.
- Accepted records remain read-only. Acceptance itself is intentionally rejected:
  full applicant/contact/document data is not yet in the registration projection.

## Communication safety

registration_events persists held events atomically with each change. It is NOT
a running sender or notification inbox. No scheduler consumes these entries.
Review assignment records registration.review only, never applicant email.
Trial/rejected/pool records its applicant event; trial/rejected with a team also
records the staff event. Events contain IDs, no applicant text or secrets.
No historical events are backfilled. Future delivery must resolve authorized
recipients, templates and revisions explicitly; do not blindly replay held tests.
Existing fixed-admin mail guard remains unchanged. No real mail/push is sent here.

## Migration and verification

Migration 000009 is additive, targets clubora only, and copies TL references
from the immutable legacy_prod snapshot by tenant and legacy ID. No access to
the live PHPMaker database, no production writes. Local/Pi share this new DB;
apply once using clubunio:foundation. Older release07 remains schema-compatible.

Four integration tests cover transaction/audit/events, no-op, stale revision,
unsafe acceptance, missing/invalid team, invalid TL, unknown payload fields,
active TL lookup, denial for non-backoffice and foreign registration IDs, plus
the actual Filament action. Tests roll back their synthetic fixtures.
Browser list and review slide-over checked without changing real records.

## Still required before full workflow replacement

1. Complete registration data/documents import and editable applicant form.
2. Explicit member matching with comparison, safe create/link/reactivation
   (membership status and exit date), separate attachment metadata, transaction.
3. TL portal with assignment/team scoping, current-season restriction and tests;
   general admin access must NOT be broadened.
4. Reviewed outbox recipient planning, trial staff CC, deduplication and fixed-admin
   staging delivery; push remains disabled until separately approved.
5. Public registration submission, mobile API compatibility and final-role tests.
