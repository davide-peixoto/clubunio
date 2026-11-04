# Personal Pitch Planning API

Integration in progress, not deployed. No live migrations or provider sends.

Base: `/api/mobile/v1/clubs/{tenant}` using the existing personal bearer session,
tenant/domain binding and MobileAccess gate. Technical integration tokens and
web sessions do not replace a personal mobile token.

| Method | Relative path | Purpose |
| --- | --- | --- |
| GET | /pitch-planning/options | Fields, areas, own requestable teams and capabilities |
| GET | /pitch-planning/calendar?from=YYYY-MM-DD&to=YYYY-MM-DD | Effective dated occupancy and conflicts |
| POST | /pitch-planning/requests | Request an own-team friendly match |
| POST | /pitch-planning/move | Save an authorized occurrence move immediately |
| POST | /pitch-planning/requests/{booking}/review | Admin/backoffice approval or rejection |
| POST | /pitch-planning/competitions/{competition}/teams | Admin/backoffice explicit team-season mapping |
| GET | /my-teams/{team}/pitch-calendar?from=YYYY-MM-DD&to=YYYY-MM-DD | Same effective projection, authorized team only |

All return the normal `data` envelope. Errors keep the shared API envelope.
Inputs and responses use `CLUBUNIO-PITCH-CONTRACT.md` directly. The adapter calls
`calendar`, `options`, `requestFriendly`, `move`, `decide` and
`configureCompetition`; it never writes
occupancy tables itself. Dates are inclusive, up to 42 days. Unknown input fields
are rejected. Input clocks are `HH:mm` in Europe/Zurich, never device-converted.

Friendly request fields: `team_season_id`, `field_id`, optional nullable `area_id`
(whole field), `date`, `starts_at`, optional `duration_minutes` (domain default120),
`opponent` (max255), optional `notes` (max2000). Move fields: `key`, `revision`, `field_id`, nullable
`area_id`, `date`, `starts_at`, `duration_minutes`. Review fields: `revision` and
`decision` (`approved` or `rejected`). Revisions and occurrence keys are opaque
strings copied unchanged from the service projection. They are not client IDs.
Requests and non-block moves are limited to1440 minutes by the core. The move
transport accepts up to44640 minutes so existing multi-day blocks remain movable;
the source-specific limit is enforced exclusively by the domain.
No status, actor, tenant, authoritative-SFV or force-conflict input is accepted.
Competition team mapping accepts `revision` and a mandatory `team_season_ids`
list of up to20 distinct positive IDs. It replaces the current stored mapping;
`[]` clears all current assignments, including previously SFV-derived assignments.
There is no separate manual/SFV provenance. A later actually changed, reviewed
SFV import regenerates authoritative numeric mappings. Historical SFV records
are not automatically backfilled by this endpoint or an unchanged import.
The core verifies competition-update permission plus strict admin/office role,
tenant/team validity and revision. No names are used to infer mapping. Response
is the updated entry in the usual200 `data` envelope. No new mobile permission
is granted, and this API addition does not add a Flutter mapping editor.

Calendar/options return the shared field/area/capability DTOs. Calendar entries
carry source identity, original/effective date, field/area, affected team IDs,
status, revision, editability and conflicts. Source permissions, not a client
capability flag, authorize every operation. Admin/office-only move and review;
trainer/TL requests remain pending until separately approved. Geometry/block
management stays in the web interface in this mobile increment.

The existing `/my-teams/{team}/trainings` and embedded `Trainings` remain weekly
base DTOs; the new dated endpoint is their effective-occurrence companion, not
a silent change to their semantics. No website weekly-feed changes.

Activation requires the explicit default-off
`CLUBUNIO_MOBILE_NATIVE_PITCH_PLANNING_ENABLED` switch, migrated core schema and
domain readiness. Client feature negotiation uses `pitch-planning-v1` under the
existing `Platzverfugbarkeit` module, retaining configured module visibility and
the existing facilities experience for older clients.
The module row includes independent `pitch_planning` and `facilities` booleans.
The pitch flag also requires core schema readiness and `canView`; the facilities
flag retains its own module permission and `facilities-v1` negotiation. This does
not modify stored mobile profiles or grant new permissions. A hidden module stays
hidden. The dated team route resolves `MobileTeamsData::team` before calling the
core projection with its immutable route team filter.

The API does not implement conflict, approval, recurrence or notification logic.
The common PitchPlanningService must enforce current role/team/tenant rights,
bounded projections, revision checks and transactional mutation/notification
intent. Session revocation and personal tenant access are rechecked under the
existing tenant/session locks on every write. Unknown payload fields are rejected.

## Swagger and Languages

Existing admin-only Swagger: `/api-docs?surface=pitch-planning`.
Its OpenAPI document: `/api-docs/openapi.json?surface=pitch-planning`.
The default integration API document remains unchanged. The personal document
uses `/api/mobile/v1`, a distinct `personalBearer` scheme and all seven exact routes.
The Swagger request guard still limits requests to the same origin and selected
API prefix, sends no web credentials to API operations and does not persist tokens.
Normal write success is200, with no claimed automatic idempotency replay. After
an uncertain result, reload before retrying; an invalid/stale revision is not a
successful save. Error status codes401/403/404/409/422/429/503 are documented.

The pitch middleware uses the server-resolved personal mobile language and
restores the prior application locale afterwards. Validation and pitch error
envelopes support DE/EN; unrelated API error behavior remains unchanged.
Translation additions are isolated in `lang/parts/pitch-api.en.json` for the parent
catalog merge. No member-entered title, opponent or note is translated.

## Verification Boundary

`tests/Feature/MobilePitchControllerTest.php` runs HTTP/controller contracts with
an isolated in-memory SQLite schema and explicit domain/auth test doubles; no
shared database connection, live migration or provider sends. It verifies
delegation, not the domain's geometry/recurrence/role algorithm. Domain tests and
parent integration must verify those separately, including real own-team role
authorization, affected-staff notifications and SFV source precedence.

Local check: `php artisan test --compact tests/Feature/MobilePitchControllerTest.php
tests/Feature/ApiDocumentationTest.php tests/Feature/ProviderApiDocumentationTest.php`
passed49 tests /27,730 assertions (including20 new controller tests). The high
assertion count is largely existing OpenAPI schema checks, not live actions.
The follow-up includes real core type resolution/signature checks, real missing-
schema503 and real review rejection for trainer/TL/profile even with module
permission, plus admin/office rejection without module permission. These execute
against SQLite fixtures; authentication and projection readiness are controlled
test doubles, not a full PostgreSQL/domain end-to-end acceptance.
`php tools/check-translations.php` now passes after the parent catalog merge:
2589 keys in DE/EN, zero untranslated literal UI texts.
`php artisan route:list --name=clubunio.mobile.pitch -v` lists all seven routes with
MobileMiddleware and MobilePitchReady. PHP syntax checks pass for all MobilePitch
and PitchPlanning classes; the real core container resolution has no undefined
type or missing method. Core code is now present, including42-day ranges and ISO
timestamps; source lifecycle/SFV/notification integration remains owned by its
respective agents and is not asserted complete by this API check.

Notification navigation uses the separately owned existing
`POST /api/mobile/v1/clubs/{tenant}/push/resolve` with
`{kind: "pitch_planning", object_id: <pitch_planning_events.id>}`. The response
is `data: {kind, object_id, date, key}`; tenant is the route context, not a response
property. Historical notification ownership and current recipient eligibility are
checked by the notification service. The app must still check current module
availability and load the dated calendar through its independent gates. This is
not a new notification route or a permission implied by a push payload.

## API Ownership Manifest

New files:
- app/ClubUnio/Mobile/MobilePitchController.php
- app/ClubUnio/Mobile/MobilePitchRoutes.php
- app/ClubUnio/Mobile/MobilePitchReady.php
- app/ClubUnio/Mobile/MobilePitchDocumentation.php
- tests/Feature/MobilePitchControllerTest.php
- lang/parts/pitch-api.en.json
- deploy/CLUBUNIO-PITCH-API.md

Existing shared files changed by this API subtask:
- routes/mobile.php: one route registrar inside the personal tenant group.
- config/clubunio-mobile.php: explicit default-off pitch feature flag.
- app/ClubUnio/Mobile/MobileAccountController.php: independent pitch/facilities negotiation.
- app/ClubUnio/Api/ApiErrors.php: translate envelopes only for named pitch routes.
- app/ClubUnio/Http/ApiDocumentationController.php: optional protected pitch specification.
- resources/views/clubunio/api-docs.blade.php: pitch Swagger selector/link and selected-prefix guard.

No core, migration, Flutter, weekly-training DTO or root-language-catalog edits
were made by this subtask. Other dirty files in the shared workspace have separate
owners. No new API scope or stored permission was granted.

No Flutter edits, deployment, release activation or commit in this API subtask.
