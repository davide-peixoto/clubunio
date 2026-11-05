# Date-independent mobile pitch request inbox

## Scope (05.11.2026)

Trainer and technical-lead accounts see their own authored requests dated today
or later in Europe/Zurich. Their inbox is not tied to a calendar date and includes
all statuses until the date passes. Admin/office with existing competition read
access see all tenant requests, including history, with a status filter.
No role assignments, new write grants, database migration or legacy changes.

Additive `GET /api/mobile/v1/clubs/{tenant}/pitch-planning/requests`, page/per_page
and optional admin-only status. Existing POST on the same path remains intact.
Personal sessions, module/schema gates and current scope checks apply. Archived
field references remain readable for old entries. The inbox is a bounded request
query, not a long calendar traversal; live conflict checks remain authoritative
at approval/move. Reading never sends a push/email or reserves a pitch.

The Flutter inbox shows dates, hides calendar day controls, supports admin
status selection and loads additional pages. Generation checks reject stale
responses; failed pagination clears stale permissions. Both DE/EN and the other
existing local catalogs are maintained, without activating extra languages.

## Verification

72 targeted Flutter tests passed; full analyze clean;1976 local translation keys
and literal-label audit passed. Menu route, large text, date-independent results,
history, status filter, paging/deduplication, review refresh, session changes and
late replies covered. Existing status-first and fixed-time-axis tests retained.
Requires a new installed app test build; no physical-device/store test claimed.

## Release Procedure

Candidate `20261105-clubunio-pitch-request-inbox` from
`20261105-clubunio-facilities-status`. Eight-file delta: five runtime PHP files
plus three CLI verification helpers. Stage an inactive copy, verify hashes and
complete normalized configuration equality (including private Firebase registry),
rebuild local caches, then run read-only native-pitch, facilities and inbox checks.
Only after passing tests switch `current` atomically and reload PHP-FPM.
Both push flags and other provider flags remain closed. No migration/seeding.

Rollback: restore `current` to `20261105-clubunio-facilities-status` and reload
PHP-FPM. New app inbox GET would then be unavailable until the backend is restored;
older app calendars and status screens remain compatible. No data rollback.

## Activation And Results

Active on ClubUnio: `20261105-clubunio-pitch-request-inbox`.
Final eight-file manifest SHA-256:
`aed867254eeecfe66fb151e757cfd1e9d310b40eab48092da42579b708bf0e30`.
The final OpenAPI document was refreshed in the inactive candidate before its
complete hash recheck and activation. No foreign dashboard/web work included.

Backend71 tests /1768 assertions passed, including22 PostgreSQL rollback tests.
Inbox queries stay constant at page sizes1/30/100 and never traverse calendars.
Shared-worktree translation check2673 DE/EN keys passed; no backend language
catalog changes were needed or included in this delta. Full contract/security
coverage: CLUBUNIO-PITCH-REQUEST-INBOX-BACKEND.md.

Read-only server checks confirmed all five admin status choices and the exact
own/current request IDs for an existing trainer (empty list in that sample).
Native-pitch eight routes, original status access, private Firebase configuration
and disabled push/mail flags preserved. Health200, unauthenticated new GET401,
PHP-FPM active. No real session, request, approval, push or email created.

Flutter commit `c2d784a` is synchronized. Its72 tests, analysis and localization
checks passed. A new installed native test build is still required; these checks
do not claim a physical-device acceptance.
