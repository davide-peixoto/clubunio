# Pitch Domain Contract (integration draft, 2026-11-04)

Owner: domain agent. Service: `App\ClubUnio\Services\PitchPlanningService`.
All entry points take `(Account $actor, Tenant $tenant, ...)` and enforce fresh
tenant permissions. No adapter may bypass this service for mutations.

## Public interface

- `calendar($actor, $tenant, array $filters): array`: filters `from`, `to`
  (inclusive YYYY-MM-DD, max 42 days), optional `period_id`, `team_season_id`.
- `canView($actor, $tenant): bool`: cheap shared entry authorization.
- `options($actor, $tenant): array`: `fields`, `teams`, `capabilities`.
- `requestFriendly($actor, $tenant, array $input): array`: `team_season_id`,
  `field_id`, nullable `area_id` (null = entire field), `date`, `starts_at`
  (HH:mm), `duration_minutes` (default 120), `opponent`, optional `notes`.
- `authorizeFriendlyRequest($actor, $tenant, int $teamSeasonId): void`: fresh
  non-mutating request authorization for replay, sharing the actual friendly
  creation rules, including active actor, simulation, view/create and own-team
  eligibility. This is a core integration dependency, not an adapter permission.
- `createManual($actor, $tenant, array $input): array`: same input as
  requestFriendly; directly approved admin/office booking, no request email.
- `move($actor, $tenant, string $key, array $input): array`: `revision` from
  projection, `field_id`, nullable `area_id`, `date`, `starts_at`,
  `duration_minutes`. One training occurrence only, never the weekly series.
- `decide($actor, $tenant, int $requestId, array $input): array`: `revision`,
  `decision` = approved/rejected. Strict admin/office only.
- `saveArea($actor, $tenant, array $input): array`: optional `id`, `revision`,
  `field_id`, `name_de`, `name_en`, normalized rectangle `x`,`y`,`width`,`height`
  (0..1). Named rectangles may overlap; overlap is a geometric conflict.
- `saveBlock($actor, $tenant, array $input): array`: optional `id`, `revision`,
  `field_id`, nullable `area_id`, `date`, `starts_at`, `duration_minutes`, `reason`.
- `cancel($actor, $tenant, string $key, array $input): array`: revision required;
  training occurrence, request or block only. No source deletion.
- `configureCompetition($actor, $tenant, int $id, array $input): array`:
  `revision`, `team_season_ids` (array of distinct active team-season IDs, max20).
  Empty array explicitly clears assignment; no name-based guessing.

Booking duration 1..1440 minutes; blocks 1..44640 minutes. Absolute intervals
allow midnight crossings and multi-day blocks. Block writes are authoritative
closures: existing bookings remain and display conflicts. Used area geometry is
immutable (names can change); create a new area and relocate explicitly.

Mutation returns the resulting entry DTO (area method returns area DTO).
Validation errors are Laravel ValidationException (422); stale revision/conflict
HTTP 409; forbidden 403; cross-tenant/unknown identifiers 404; missing schema 503.

## Projection

Calendar: `{from,to,timezone:"Europe/Zurich",fields,entries,capabilities}`.
Options: `{fields,teams,capabilities}`. Capabilities: `view`, `request`, `manage`,
`approve`. Field: `{id,name,available,areas:[{id,field_id,name,name_de,name_en,x,y,
width,height,revision}]}`. Team: `{id,name}` (team-season ID).
Entry: `{key,source,source_id,original_date,date,starts_at,ends_at,starts_at_iso,ends_at_iso,duration_minutes,
field_id,area_id,team_season_ids,title,status,revision,editable,conflicts}`.
Sources: `training`, `competition`, `request`, `block`. Keys are opaque strings;
examples `training:42:2026-11-04`, `competition:52`, `request:8`, `block:3`.
Revision is an opaque string. Status: approved/pending/rejected/cancelled.
Conflicts: `[{type,key}]`; type `occupancy`, `field_unavailable`, `block`.
Pending requests are visible but do not reserve a field; approval rechecks.
Adjacent time intervals and touching rectangle edges do not conflict.
Competition duration defaults 120; Competition remains authoritative source.
Training variants and active season date bounds remain respected.
`starts_at`/`ends_at` are HH:mm; ISO properties include Europe/Zurich offset and
the actual end date. Compare/layout ISO values, not civil clock strings.
`requester_account_id` is included only for management or the requester.

## Notification hook

Domain invokes `PitchPlanningNotificationService::record(Tenant $tenant, array
$event): void` mandatorily inside the same transaction. Availability requires
both migration001500 and notification event schema001600; failure rolls back.
Event: `{event_key,action,actor_id,requester_account_id,team_season_ids,before,after}`.
`action`: `requested`, `moved`, `approved`, `rejected`, `sfv_moved`, `created`, `cancelled`.
`event_key` is deterministic per resulting key/revision/action. `before` nullable
and `after` are entry DTOs. Team IDs union of explicit old/new assignments;
never guessed from names. Notification service owns durable dedup/outbox migration.
Only `requested` emails admin/office. Moves notify all active affected staff by
in-app/push, decisions requester. No provider send inside domain transactions.
Manual creation/cancellation also invoke push/in-app notification handling; no
email. Block changes include explicit team IDs of overlapping approved bookings
from both old and new intervals, even though a closure has no own team.

## Scope

Strict admin/office manage geometry, blocks and occupancy; trainer/TL may request
friendly matches for active explicitly assigned teams, not approve/move training.
Role is intersected with ModulePermissionService and simulation guards. Current
mapping: competitions.view for backoffice reads; existing eligible member portal
may view occupancy. competitions.create for manual, competitions.update for
request approval/competition move/team mapping; training_slots.update for dated
training moves; facilities.create/update for areas/blocks. Cancellation uses
source deactivate. Dynamic profile roles cannot approve even with broad rights;
read-only profile reads are allowed. Status-only caretaker rights grant no planner.
SFV reviewed apply remains existing sync authorization (no external SFV writes).
Numeric SFV teams + matching active season are mapped; field changes invalidate
an area belonging to the old field. Accepted SFV conflicts are flagged, not vetoed.
Signage uses effective moved/cancelled training occurrences when schema exists;
without schema the unchanged legacy source still renders. Existing weekly team
endpoint remains a template; API agent adds a separate dated effective endpoint.
Missing schema fails closed for planner; legacy Signage remains functional.
No migration application, provider send, deployment or commit in this increment.

## Explicit integration limits

- Geometry is normalized rectangles (any overlapping defined rectangles), not
  polygon drawing. Training partial allocation is per occurrence; weekly source
  editor still owns its existing whole-field template.
- Historical competitions with missing field/time are not projected; repair source
  assignment first. Existing SFV mappings populate on the next reviewed apply;
  unchanged historical competitions can be mapped explicitly by an administrator.
- Friendly API/web creation uses `PitchFriendlyRequestService::submit(actor,
  tenant, input, requestId)`, returning `{data: originalEntry, replayed: bool}`.
  `requestId` never reaches source mutation input. Migration001800 stores the
  tenant/account/key-hash primary key, canonical payload hash and encrypted
  original response in the same transaction as request/notification intent.
  The existing tenant lock serializes submissions; same key/payload returns the
  original result without another notification, different payload is409, failed
  transaction leaves no claim. Replays call `authorizeFriendlyRequest` afresh.
  API requires JSON `request_id`; web uses a locked form UUID. Manual admin
  bookings and revision-based decisions/moves remain unchanged. No TTL pruning.
- Real simultaneous two-connection race not yet exercised; PostgreSQL TEMP DDL,
  FK behavior, transaction rollback and stale-revision paths are covered separately.
- Notification transport restrictions remain documented by its owner (profile
  mobile boundary and guarded test mail); no provider sends in this increment.
