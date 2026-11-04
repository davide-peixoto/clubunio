# Pitch concurrency and release-safety audit

Scope: continuation of backend `1e265a8` and mobile `bcb83d1`, with the
parent-integrated weekly-area and request-idempotency follow-ups. Local tests
only; no deployment, real business mutation, live migration, provider delivery
or commit. This document is separate from the shared roadmap/release documents.

## Executable proof

```powershell
$env:CLUBUNIO_TEST_PITCH_CONCURRENCY = '1'
php -d extension=pdo_pgsql vendor/phpunit/phpunit/phpunit tests/Feature/PitchPlanningConcurrencyTest.php
```

On a PHP installation already loading PDO PgSQL, omit `-d extension=pdo_pgsql`.
No `CLUBUNIO_TEST_DB` switch is needed. Without the dedicated opt-in, these tests
skip before opening PostgreSQL. The helper also requires `APP_ENV=testing` and
the existing reviewed ClubUnio PostgreSQL database configuration.

Files owned by this work:

- `tests/Feature/PitchPlanningConcurrencyTest.php`
- `tests/Support/PitchConcurrencyDatabase.php`
- `tests/Support/pitch-concurrency-worker.php`
- `tests/Feature/PitchSelectedPushIsolationTest.php`
- `deploy/PITCH-CONCURRENCY.md`

There are 13 tests: 11 real two-connection interleavings plus an exact-role
matrix and a source-write rollback test with an injected notification failure.
The parent keeps its transaction open after the real domain mutation. A separate
PHP process opens its own PDO connection, publishes its backend PID and committed
view, then invokes the competing real domain operation. The parent observes
`parent_pid = ANY(pg_blocking_pids(worker_pid))` before releasing its transaction.
Both processes assert PostgreSQL READ COMMITTED isolation and distinct backend
PIDs. This proves overlapping database transactions, not sequential service calls
presented as a race or a timing assumption based on sleeps.

The worker pins its backend in a transaction before publishing its PID, so the
proof works through transaction pooling. Serialization is provided by the tenant
row lock; this is not a claim of PostgreSQL SERIALIZABLE isolation coverage.

| Interleaving | Required outcome |
| --- | --- |
| Two overlapping pending requests approved | One approved, other 409/pending; one approval event |
| Approve/reject the same old revision | Winner commits; stale contender 409; no second event |
| Different bookings moved to one destination | Winner commits; loser 409 and retains original placement |
| Same booking/revision moved to different destinations | Stale contender 409; one move notification |
| Two bookings moved to adjacent intervals | Both commit after observed serialization |
| First approval rolled back | Waiting approval succeeds; no first-writer audit/inbox/outbox leak |
| Actor disabled while contender waits | Contender 403 after lock acquisition |
| Admin changed to a broad profile while contender waits | View remains allowed; approval 403 |
| Same request ID and payload submitted concurrently | One source/ledger/audit/event/inbox/push row; exact DTO replay |
| Same request ID and different valid payload | Contender 409; no duplicate source/event |
| First request-ID transaction rolled back | Waiting writer claims key as new; one committed request |

## Isolation and cleanup

Each test creates one random `pitch_race_<24 lowercase hex>` schema. No business
rows are copied. Required parent tables are cloned empty with `LIKE INCLUDING
ALL`; serial defaults are replaced by private identity sequences. The real DDL
of 001500/001600/001700/001800 executes only after the connection rewrites schema
references into this disposable schema. No application migration ledger is run
or changed. These are fixture DDL operations, not live-schema migration applies.

The connection rewrites qualified raw SQL, query-builder/Eloquent identifiers
and schema-inspection bindings. Residual business-schema references are rejected.
Before seeding, catalog checks reject any foreign key or default expression from
the fixture pointing back at `clubora`. No triggers or original foreign keys are
copied by PostgreSQL LIKE; the pitch migrations create their actual scoped FKs.
This harness is not a replacement for full migration/parent-FK acceptance.

All accounts, staff, teams, seasons, settings, bookings, device/session bindings
and templates are synthetic. DE and EN templates come from the real catalog.
The real notification service and real push enqueue path run; provider delivery
is disabled. Mail is faked, HTTP stray requests are forbidden, both processes
assert zero sends, and every queued push must remain `pending`.

Each worker is joined/stopped before cleanup. Finally the exact generated schema
is dropped, absence in `pg_namespace` is asserted, and no worker transaction may
remain active. Cleanup also runs after test/setup failure. A pooled idle backend
may remain available to PostgreSQL; that is not a surviving PHP worker or test
transaction. Tests do not kill unrelated pool sessions. A hard host/process kill
cannot execute PHP cleanup; inspect any matching schema before manual recovery,
never perform wildcard schema deletion.

## Exact permission boundary

The fresh planner write path locks the tenant, removes request-local read frames,
reloads the actor and rejects inactive actors or impersonation. The race tests
verify account/role revocation after the contender has begun but before it gets
the lock. The additional matrix verifies the following existing contract:

- Reads: `competitions.view` or eligible member-portal entry.
- Friendly requests: admin/office with `competitions.create`, or an eligible
  trainer/technical lead with an active explicit assignment to the selected team.
  Trainer assignment includes an active trainer/assistant function and current
  configured season; being arbitrary staff is insufficient.
- Manual requests: admin/office and `competitions.create`.
- Decisions and competition team mapping: admin/office and `competitions.update`.
  There is no independent `competitions.approve` permission in PermissionCatalog.
- Moves: strict admin/office plus source `update`: `training_slots`, `facilities`
  for blocks, or `competitions` for requests/competitions.
- Area/block creation or edit: admin/office plus `facilities.create/update`.
- Cancellation: admin/office plus source `deactivate`, not a generic delete grant.
- Broad dynamic profiles and read-only profiles may read where permitted but do
  not acquire planner requests/decisions/moves/area/block management. Status-only
  `field_availability.view/update` does not grant planner entry.
- Office is allowed to approve. Trainer, technical lead and ordinary member
  are not. Impersonation cannot submit a new request.

Existing direct competition editor rights are a different boundary. The later
explicit instruction preserves its dynamic `competitions.create/update/...`
grants; do not infer new planner management or approval rights from that editor.
Its consistency adapter is owned by the parent/Planck package, not these tests.

## Post-commit safety and findings

The real source mutation, object audit, pitch event, inbox notification and push
intent are committed/rolled back together. Each contender checks the old
committed counts before attempting its write. An injected exception after the
real notification fanout restores all counts and the pending source state.

The mail dispatcher rejects an open source transaction and claims with
`FOR UPDATE SKIP LOCKED`, committing `sending` before calling its guarded test
mailer. Ambiguous failures become `attention`; neither `attention` nor `sending`
is automatically replayed. Real office-recipient mail delivery is not enabled.
The push worker is a separate post-source-commit operation, with its own claim
and final authorization/revocation locks. Its provider attempt runs in that
separate worker transaction, not in the source booking transaction.

Concrete integration findings reported, without domain edits by this owner:

1. **P1, original direct competition path:** `FacilityService::save()` bypassed
   occupancy conflict checks, stale-area cleanup and pitch staff fanout. The
   projection hid a wrong-field area but did not clear it; changing back could
   resurrect the old area. Parent delegated a transactional adapter to Planck.
   Initial adapter review confirms the intended hook structure; its separate
   regression suite is the acceptance source. Preserve the explicitly authorized
   dynamic editor rights while applying the same consistency guarantees.
2. **P1, intermediate idempotency integration:** replay invoked missing
   `PitchPlanningService::authorizeFriendlyRequest()`. Two actual retry races
   reproduced the missing-method failure; failed workers and schemas were cleaned.
   Parent subsequently added the helper via the existing fresh/locked write path.
   The final concurrency run below verifies the integrated result successfully.

Known, not silently widened: dynamic-profile staff can own stored inbox notices
but existing mobile entry/push eligibility still excludes profiles. No claim of
live SMTP/FCM delivery, mobile-device behavior or deployment acceptance is made.

## Verification record

- Initial baseline: 6 PostgreSQL tests / 71 assertions passed.
- Intermediate integrated run: 11 tests / 129 assertions, two failures solely
  from the missing replay-authorization helper described above.
- Real enqueue smoke: 1 PostgreSQL race / 17 assertions passed.
- Exact-role matrix: 1 PostgreSQL test / 41 assertions passed.
- Existing notification suite: 30 tests / 185 assertions passed, in-memory mail
  transport/mocks only; no real providers.
- Translation checker: 2589 DE/EN keys verified, zero documented literal backlog.
- Final integrated concurrency rerun: **13 tests / 234 assertions passed**, 136s.
  All 13 exact-schema cleanup assertions and active-worker-transaction checks
  passed. This includes the parent-supplied replay authorization helper and real
  synthetic push bindings with explicit timezone-aware timestamps.

## Selected push review

The parent changes to `MobilePushOutbox::deliverOne(?string $onlyId)` apply the
UUID predicate to both expired-lease cleanup and pending-row selection. Later
claim/finalization updates use the selected row's primary key. Missing, already
processed or expired-selected records do not fall back to another pending row.
`DeliverMobilePush --outbox` returns before notification/challenge/admin-notice
collection and before the generic drain loop. Its existing flag and dispatch
guards remain in force. No source-level filtering defect found in that diff.

The extra isolated PostgreSQL test deliberately includes an older foreign pending
row and a foreign expired `sending` row. A revoked synthetic device forces local
discard before transport; a fail-closed provider replacement and HTTP/mail
assertions prevent any real sends. The test checks exact unchanged foreign rows,
selected expiry-only cleanup, a missing UUID and a repeated selected call.
Final result: **1 PostgreSQL test / 15 assertions passed** (7.5s). Parent command
suite `SelectedPushDeliveryTest`: **2 tests / 6 assertions passed**. Run the
extra database test with the same opt-in and PHP flags shown above, substituting
`tests/Feature/PitchSelectedPushIsolationTest.php`.

After all test processes completed, an independent read-only catalog check
returned `remaining_schemas: 0` and `active_transactions: 0` for `pitch_race_%`.
All owned PHP files passed syntax checks. No open test failure remains in this
owner's package; parent broader regression/release gates remain separately owned.
