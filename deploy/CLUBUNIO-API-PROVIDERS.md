# Provider actions: Bexio, SFV and training AI

26.10.2026. ClubUnio only; existing PHPMaker DEV/PROD and existing clients remain
unchanged. No bank payment, API credential export, scheduler or SaaS provisioning.

## Durable request contract

- POST `/api/v1/provider-actions/{operation}` with bearer token, an
  `Idempotency-Key` and JSON `{"object_id":123,"input":{}}` stages local work.
  Returns202, never an assertion of provider success. Exact action inputs/scopes
  are generated in the admin-only Swagger document from ProviderApiRegistry.
- GET `/api/v1/provider-work/{id}` reads work for the originating integration
  and technical account. Current action scopes/profile rights remain required.
- POST `/api/v1/provider-work/{id}/process` with JSON `{}` explicitly processes
  a direct SFV/AI action. No Idempotency-Key is needed: the persisted work ID is
  the execution identity. This does not authorize admin-only Bexio execution.

States: pending, awaiting_approval, running, succeeded, attention, rejected.
Inputs and results are encrypted. Separate immutable events retain the technical
requester and approving admin. Status returns only an allowlisted envelope and
action-specific result; no bearer/provider credentials or storage URLs.
Timestamps use RFC3339. Work events and API request UUIDs support diagnosis.

Staging uses the existing local idempotency transaction. Processing atomically
claims pending work, commits the intent, and only then calls existing services.
The process never reclaims running/attention work or repeats a failed paid call.
Repeated processing of succeeded direct work returns its recorded result.
After a lost response, read status first. Do not submit a replacement paid/write
request without checking the existing provider/domain journal. A crashed running
request deliberately stays blocked until its outcome has been investigated.

The original token must still be valid at execution, also for admin approval.
Token/profile/integration changes invalidate the recorded authorization hash.
Domain prerequisites, revisions, compound rights and quotas are checked again.
Permission removal is checked from fresh profile data even within one request,
not solely from a request-local read cache. No existing profile is expanded.
Revocation cannot undo a provider request already sent; its known/uncertain
outcome must still be retained. SFV/AI recheck API authorization under the tenant
lock before persisting returned local data, and remote work never runs under
the generic local API transaction.

## Bexio

Actions: `bexio.invoice-transfer`, `bexio.invoice-issue`,
`bexio.invoice-cancel`, `bexio.invoice-status`, `bexio.invoice-sync`,
`bexio.expense-status`, `bexio.expense-sync`, `bexio.catalog-sync`,
`bexio.invoice-reconcile`.

All require a specific real-admin approval in Einstellungen > API-Integrationen
under API-Synchronisationsauftraege. An admin opens Pruefen, inspects the object
and exact stored inputs, then explicitly approves/executes or rejects that item.
The technical account remains a profile account and never becomes an admin.
API process returns409 for these actions. Approval retains both identities.
Changes or expired/revoked originating credentials block execution.

Transfer requires the invoice's current revision and internal release. Other
actions accept input `{}`. Catalog sync uses the club ID as object_id. Services
retain their connection fingerprint, dispatch markers, uncertainty and status
reconciliation. A successful API execution is not treated as provider-confirmed
when the existing journal still reports an uncertain result: work stays attention.
No unsupported credit-note/send-mail/payment endpoint is inferred from Bexio's UI. Invoice issue
does not send an email. Expense payment and new expense transfer remain outside
this reviewed action set; existing web workflows are unchanged.

## SFV

GET `/api/v1/sfv/schedule` reads the local source cache with competitions:read,
page/per_page (max100) and optional from/to dates. It exposes source row IDs,
match/team IDs, date/location/status, home flag and revision, never provider
credentials. Date boundaries use Europe/Zurich; returned timestamps use RFC3339.

`sfv.refresh`: object_id0, input from/to (`YYYY-MM-DD`, max186 days). Uses only
the configured SFV provider and existing upsert logic, not a client-supplied URL.
`sfv.competition.preview`: object_id is the source cache row ID; optional
target_id/separate. Result contains the existing reviewed fingerprint.
`sfv.competition.apply`: same selectors plus that fingerprint. The service checks
source/target revisions again and preserves manually maintained dressing rooms.
Publication effects require competitions:sync plus signage:send, not just read.
`sfv.planning-import`: source cache row ID, own team_season_id, sfv_revision and
event_revision (0 for a new event), title/kind/mode/status, local Europe/Zurich
starts_at/ends_at/deadline_at (`YYYY-MM-DDTHH:mm`), present nullable capacity and
optional location/description. Existing option catalogs, deadline/capacity and
absence/notification logic remain in the service. Planning read/sync/update/send
are required, plus conditional create/cancel/restore scopes shown in Swagger.
After SFV HTTP returns, API authorization is rechecked under the local tenant
lock before source rows are upserted. A revoked request cannot persist that result.

## AI and training PDFs

`training.generate`: object_id0, input team_season_id, durationMinutes,
playerCount, goal, fieldSize, material. Existing team ownership, daily/minute
quotas and philosophy/quality gates remain authoritative.
`training.review`: object_id is the training plan ID, input revision. Returns a
validated proposal, not an automatic saved/shared plan. Save through the existing
versioned training_plans API with training:update and training:send if sharing
changes. The review includes an expiry for the client; the generic content editor
remains a separately authorized/manual quality-validated save, not a signed
review-acceptance operation. It does not claim server-side acceptance of a review
solely because its JSON was returned previously.

The server assigns a stable UUID at staging. Clients cannot supply request_key
or _request_key. Failed/uncertain attempts are not automatically charged again.
GET `/api/v1/files/training-pdf/{id}` uses training:download and the same private
renderer/service as the web; remote image/JavaScript/PHP execution stays disabled.

## Migration and verification

Additive migration79 creates api_provider_work/api_provider_events only in the
ClubUnio database. It neither imports legacy data nor changes profiles/settings.
Preserve encryption keys, work/evidence and prior API schema on rollback; do not
drop uncertain provider records or reset state to pending to force another call.
No additional worker or hosting scheduler is installed.

Verification uses isolated rollback fixtures, blocked stray HTTP and fake AI/
Bexio responses. These tests cover real existing service logic with synthetic
data, not actual bank movements or a new paid provider acceptance run.
Deployed on ClubUnio as20261026-clubunio-api-providers, based on the independently
verified20261026-clubunio-tenant-api release (preserved rollback). All seven CLI
gates passed, including cached web/API domain guards on all20 API routes.
228 documented paths,138 scopes and15 provider actions. Existing196 encrypted
settings,46 templates and parent/self-link counts25/66 remained unchanged.

Real HTTPS acceptance with isolated QA club16152/integration1163 passed:
context/four website feeds200, member/write403, revoked401; separate restricted
provider credentials staged SFV work61 with202, replay returned the same ID,
status remained pending200, local SFV source read200. Processing was deliberately
not requested; no Bexio/SFV/AI provider call or payment occurred. All QA tokens
revoked and accounts/profiles disabled; pending evidence retained, not executable.
Anonymous specification redirected302 and unauthenticated work returned the
canonical401. Authenticated Chrome showed the administration/empty work list and
Swagger1.1.0 with all228 JSON paths; the expanded training action rendered its
exact input/202/scopes. No browser token was created or external action executed.
Final documentation-example follow-up20261026-clubunio-api-providers-final uses
object_id0 for generation/refresh instead of Swagger's generated large ID; no
schema, permission, provider or business behavior change.

Combined regression run:214 tests/31,513 assertions passed (11m14s). This includes
the existing API, module workflows, token/admin UI, tenant boundaries, provider
adapters and queue tests; schema assertions are not external provider calls.
Separate final targeted checks cover the last SFV post-HTTP authorization hook
and admin result/event display:70 tests/27,864 assertions passed (3m05s).
These overlap the combined run and must not be summed as284 unique tests.
A separate read-only code review identified and
fixed revocation gaps, ambient transaction execution and stale admin approval.
Real multi-connection concurrency/load or a full penetration test is not claimed.
