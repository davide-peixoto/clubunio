# Pitch Request Inbox: Backend Contract

Implemented and activated05.11.2026 on ClubUnio as
`20261105-clubunio-pitch-request-inbox`, baseline facilities-status. Deployment
checks and Flutter counterpart: CLUBUNIO-PITCH-REQUEST-INBOX-RELEASE.md.
No migration, refresh, provider call or configuration change. Firebase registry,
push=false, PHPMaker DEV/PROD and existing write permissions are unchanged.

## Runtime Scope

Exactly five existing runtime PHP files, no new class or provider registration:

- `app/ClubUnio/Mobile/MobilePitchController.php`
- `app/ClubUnio/Mobile/MobilePitchRoutes.php`
- `app/ClubUnio/Mobile/MobilePitchDocumentation.php`
- `app/ClubUnio/Services/PitchPlanningService.php`
- `app/ClubUnio/Services/PitchPlanningProjection.php`

The additive GET shares the existing personal-session, tenant and native-pitch
middleware. Existing POST requests/review/move and calendar routes are unchanged.

## Flutter Contract

`GET /api/mobile/v1/clubs/{tenant}/pitch-planning/requests`

```json
{
  "data": {
    "timezone": "Europe/Zurich",
    "fields": [],
    "teams": [],
    "entries": [],
    "capabilities": {"view": true, "request": false, "manage": false, "approve": false},
    "can_filter_status": false,
    "page": 1,
    "has_more": false
  }
}
```

- `page`: positive integer, default 1, maximum 2147483647 (overflow guard).
- `per_page`: integer 1..100, default 30. Values above 100 return 422.
- `status`: optional `pending|approved|rejected|cancelled`; omit for all statuses.
- Unknown inputs, including from/to/date/team/requester/tenant overrides, return
  422. Empty, null or invalid status values fail validation with 422.
- A valid explicit status filter for personal Trainer/TL returns 403. There is
  no personal same-owner status-filter variant.
- Existing `Field`, `Team` and full `Entry` DTOs are preserved. Inbox entries
  always have `source=request`, with no requester account ID or private notes.
  The narrower OpenAPI `RequestInboxEntry` excludes those private properties.
- Inbox `conflicts` is always `[]`. It is NOT proof of availability. The calendar
  provides conflict display; approval and move retain fresh authoritative
  checks. There is no separate detail endpoint added by this task.
- Entries are SQL-paginated by immutable `id DESC`; limit is per_page + 1 for
  has_more, without a COUNT or calendar traversal. Empty pages are successful.
  Ordering is deterministic for unchanged data, not a cross-request snapshot;
  refresh from page 1 after concurrent insertions, reviews or scope changes.
- Responses keep the personal API's `Cache-Control: no-store, private`.

## Authorization

Admin/office with current `competitions.view` receive all tenant requests,
including all authors, past dates and inactive/historical team references.
Only this scope has `can_filter_status=true`. Manage/approve capabilities still
use the existing competition-update authorization; inbox access grants no write
authority and does not alter profiles.

Personal trainer/technical_lead accounts require current portal admission and
`Platzverfugbarkeit` in `MobileModuleSettings.visibleKeys`. Explicit exclusion,
inactive mobile profile or disabled module returns 403 on the next request.
This additional gate is limited to the new list, not a legacy calendar refactor.

Personal entries match BOTH requester_account_id=current account and civil
date >= today in Europe/Zurich. The date is the request's booking date, not its
creation timestamp or ending instant. Yesterday's overnight request is hidden.
Team responsibility never grants another author's requests. Neither the selected
calendar date, a 42-day range, current season nor active team options restrict
the owned inbox. Losing an assignment removes creation options, but ownership
remains readable while the personal role, portal and module access remain valid.
Other roles, including a read-only access-profile role, do not acquire this inbox.

Admin/office continue to use competitions.view independently of personal mobile
profile exclusions. Permission/portal/profile facts are request-scoped, not cached
across requests. Existing mutation authorization is unchanged.

## Fields And Options

`fields` includes active fields plus archived fields referenced by the current
page, using tenant-scoped lookups and the same DTO including area geometry.
Archived fields always have `available=false`, even with a formerly open status.
Unreferenced archived fields and foreign fields are not added. Database foreign
keys preserve reference integrity; no cross-tenant fallback is used.

The server rejects inactive field submissions with 404 through the unchanged
placement validator. `available=false` also covers currently unavailable active
fields; it is not a new archived flag or a change to pending-request policy.
Historical references are returned for display and grant no creation permission.

`teams` uses the existing current-season requestable team options. It is not a
historical-label dictionary. Entries keep their historical team_season_ids even
when teams is empty. Inbox capabilities.request is false without requestable
teams; existing create/move/review checks remain authoritative.

Field labels use existing lookup translations and their original-name fallback;
areas use their stored DE/EN variants. Opponent/team free text is unchanged.
No new UI strings, language activation or automatic text replacement.

## Verification

- `PitchRequestInboxPostgresTest`: 9 tests / 1066 assertions passed with real
  PostgreSQL queries, temporary pitch tables, synthetic parent fixtures and
  transaction rollback. No live schema DDL, seed, refresh or provider execution.
- Coverage: own/other authors in the same team, TL, foreign tenant, Zurich midnight,
  history beyond 42 days, all statuses, pagination/ties/empty pages, full DTO
  privacy, archived fields/areas, fresh role/profile/portal/account revocation,
  creation-option revocation, unchanged mutation denial and conflict recheck.
- Query regression: identical query counts for page sizes 1/30/100 on distinct
  dates, for admin, trainer and TL; each tested domain GET uses at most 25 SELECTs.
  No calendar/training/competition/block queries run in the inbox. Schema readiness
  is stubbed in this domain fixture; production adds its fixed readiness lookup.
- Adapter/route plus existing calendar/options/projection suite: 49 tests /
  597 assertions passed.
- Existing `PitchPlanningDomainTest`: 13 real PostgreSQL rollback tests /
  105 assertions passed, including existing calendar, geometry and mutation rules.
- Combined: 71 tests / 1768 assertions passed, including 22 PostgreSQL tests.
- `php tools/check-translations.php`: final shared-worktree check verified 2673
  DE/EN keys, zero existing literal UI texts remaining in the documented backlog.
  The earlier 2670-key check also passed; this task changes no language catalogs.
- Final route listing confirms the additive GET alongside unchanged POST request
  and review routes, all with personal mobile and native-pitch middleware.
- Scoped `git diff --check` passed. No running test processes remain.

Final runtime SHA-256 values (source freeze for the release owner):

| File | SHA-256 |
| --- | --- |
| MobilePitchController.php | 4C9B80A535E9D3FB8267D12CD861BCAF790527657EFC719245233D24E19F98A3 |
| MobilePitchRoutes.php | 14C480CE06376B4A3C5DFD75616648BFAA8FB2492D296673D672BCA6AA9154F1 |
| MobilePitchDocumentation.php | F0BC04E7AF85F32780831F3822CFE8517FB939F58CD6FB8C22509B00E513A4FE |
| PitchPlanningService.php | 0F63BB6B68B7EE05111B5E368446987C4398602950FA542DE941715AAE6E7A1E |
| PitchPlanningProjection.php | FCBFDF27CAE07243A1FA3AEA80C819520F23DEF4BC9FE1E3BC77C858A1A667AB |

Reproduce the real PG suite with the existing configured ClubUnio test connection:

```powershell
$env:CLUBUNIO_TEST_DB = '1'
php -d extension=pdo_pgsql vendor/phpunit/phpunit/phpunit tests/Feature/PitchRequestInboxPostgresTest.php
```

The extension flag loads the already installed PostgreSQL driver only for that
process. No PHP/environment configuration is changed. Tests opt out without the
explicit CLUBUNIO_TEST_DB flag and enforce the existing test database guard.
