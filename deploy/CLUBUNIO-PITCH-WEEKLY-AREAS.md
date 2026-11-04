# Weekly Training Pitch Areas

Local implementation following backend `1e265a8` and mobile `bcb83d1`.
No deployment, permanent migration, PHPMaker change, import, provider send or
commit is part of this work. Existing unrelated changes remain untouched.

## Storage and Boundaries

Reserved migration `2026_11_04_001700_pitch_weekly_areas.php` adds nullable
`clubora.training_slots.area_id`; null means the whole field. It changes no
existing row values and requires the `pitch_areas` table from migration001500.

- `training_slots_pitch_area_fk`: `(tenant_id,field_id,area_id)` references
  `pitch_areas(tenant_id,field_id,id)`.
- `training_slots_pitch_area_field_check`: an area requires a non-null field.
- `training_slots_pitch_area_idx`: index on `(tenant_id,field_id,area_id)`.
- `down()` refuses destructive rollback. Keep additive metadata on code rollback;
  any later schema removal requires a separately reviewed restore.

No live apply is authorized here. Before a future targeted apply, verify and back
up `training_slots`, `pitch_areas`, their referenced parents and migration ledger.
Check existing rows, IDs, revisions and constraints before/after. Only001700 is
owned by this increment; do not run pending migrations collectively.

## Write and Copy Semantics

`ContributionTrainingService` remains the write boundary: existing module rights,
tenant lock, active field validation, fresh area pairing, revision check and
object-change audit. An omitted area preserves the old default if the field is
unchanged; omission with a changed/removed field clears it. Explicit null clears
it. A supplied incompatible or foreign area fails validation, never silently
reassigns. Without001700, null/omitted values keep legacy writes working and a
nonempty area is rejected.

The resource form offers field-dependent areas and resets the selection on field
changes. The existing copy action uses that form's fields, retaining the area.
Season-plan seed/shape/save/review/completion preserve and validate the default;
the season editor also resets areas on field changes. Existing same-place season
warnings remain conservative; they may warn for disjoint partial areas.

Only the weekly usage check in `PitchPlanningService::saveArea` changes: any
weekly reference, including inactive templates, prevents geometry/field changes;
renaming remains allowed. Weekly edits keep the existing audit behavior; dated
planner moves retain their existing notification transaction.

## Projection and API

`PitchPlanningProjection` emits the weekly default for unmodified occurrences.
A dated exception owns its complete placement: its null area means whole field,
not inheritance. Field/tenant-mismatched metadata is conservatively projected as
whole field. Area validation is batched during calendar expansion. Revisions
remain opaque `slot_revision:exception_revision`; base edits invalidate existing
occurrence revisions even when an exception keeps its placement.

Signage clones carry effective `area_id` and a localized `pitch_area_name`, shown
next to the field without mutating the weekly source. Existing legacy fallback
continues when pitch schema is unavailable.

Generic `training_slots` API gains nullable `area_id` in its explicit read/write
allowlist, with unchanged permissions and service validation. Export/query
projection supplies SQL NULL when001700 is absent, avoiding missing-column
errors. No tenant ID, area names or geometry become writable through this API.

Authorized My Teams weekly-template responses add `field_id`, `area_id` and
`area_name`; tenant/field pairing is checked and names are batched. This remains
a weekly template endpoint, not the dated effective calendar. Personal pitch
calendar DTOs retain their existing effective `area_id` contract.

## Languages and Verification

DE/EN labels reuse existing catalog keys. `lang/parts/pitch-weekly.en.json`
records this increment's catalog subset; no catalog rewrite is required.
Area names use the selected DE/EN variant, falling back to the other variant
only when empty. User-entered names are not translated or replaced.

Dedicated tests: `tests/Feature/PitchWeeklyAreasTest.php` (session-local exact
migration DDL and rolled-back synthetic parent fixtures) and
`tests/Unit/PitchWeeklyProjectionTest.php` (no database).

Windows invocation: `CLUBUNIO_TEST_DB=1` with
`php -d extension=pdo_pgsql vendor/phpunit/phpunit/phpunit` and the desired test
paths. PHP's default Windows configuration does not load pdo_pgsql.
Run `php tools/check-translations.php` separately.

Authenticated browser/device acceptance and permanent-schema deployment remain
parent-owned; these tests do not claim either acceptance.

### Local Results (2026-11-04)

- Dedicated final suite:15 tests /97 assertions passed, including complete
  season-copy persistence, default/exception precedence, stale revisions,
  tenant/field pairing, SQL constraints, read-only denial, localized Signage,
  mobile template metadata and generic API pre-migration query safety.
- Existing PitchPlanningDomainTest, SeasonPlanningTest and ApiModulesTest:
  57 tests /2027 assertions,56 passed and one old physical-schema inventory
  assertion failed because001700 is intentionally not applied. That assertion
  now explicitly permits the guarded SQL-NULL area projection and executes its
  query. Targeted rerun:1 test /70 assertions passed. The entire57-test batch
  was not repeated after this test-only adjustment.
- `php tools/check-translations.php`:2589 keys in DE/EN, zero backlog.
- PHP lint of changed runtime/migration/test files and `git diff --check` passed.
- No running test process, permanent migration, deployment, provider send or
  commit remains from this package. All database fixtures were rolled back.
