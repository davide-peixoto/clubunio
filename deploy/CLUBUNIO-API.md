# ClubUnio API v1

## Scope and separation

The new API is explicitly authorized from 25.10.2026. It is independent of the
PHPMaker API and does not change existing PHPMaker DEV/PROD, the mobile app,
public website configuration, SaaS provisioning or hosting/scheduler operations.
The mobile application will be rebuilt separately; personal app login is not
silently introduced through an integration account.

Base URL: `https://dev.clubunio.ch/api/v1`.
Swagger UI: `https://dev.clubunio.ch/api-docs`.
OpenAPI: `https://dev.clubunio.ch/api-docs/openapi.json`.
Both documentation routes require the current ClubUnio session and a fresh admin
role in the selected club, also locally. A bearer token alone cannot read them.
Swagger UI 5.34.0 is self-hosted with its license, without a CDN or external
validator; API authorization is not persisted in the browser.
The OpenAPI contract is generated from the same explicit resource registry used
by the runtime, not from database reflection or legacy routes.
Implemented modules and remaining specialized adapters are explicitly tracked in
[the capability matrix](CLUBUNIO-API-COVERAGE.md). This is not a claim that every
existing web operation has already been exposed through the API.

## Administration

Under Einstellungen > Zugaenge & System > API-Integrationen, an admin creates a
named integration with an existing active club access profile, a scope ceiling
and an aggregate rate limit of 1..600 requests/minute (default60).
No existing profile or user receives additional permissions during migration.
The API also applies the separately reviewed exact domain/token boundary. A
known club domain must match the token club; unknown hosts are rejected. No
custom domains are enabled and no web branding/provisioning is deployed here.

Each integration has a dedicated technical account, marked `api_only` and bound
to that profile. Its random password is not disclosed; web and portal login are
blocked. API business changes retain this real actor ID in existing domain audit
records. Tokens never inherit the issuing admin's role.

Issue a named bearer token with a subset of the integration scopes and a future
expiry no more than one year away. The plaintext appears only in the immediate
issuance response; it is not dehydrated into Livewire snapshots or stored in the
session/database/logs. Sanctum4.3.3 stores a SHA256 hash. Tokens can overlap for
rotation and be individually revoked; disabling an integration revokes all its
tokens. Expiry timestamps include their timezone offset.

Effective rights are the intersection of token scopes, integration ceiling,
active profile permissions, active technical account/membership and tenant.
Scopes never replace domain validation, revision checks or record restrictions.
Profile deactivation and permission changes take effect on the next request.
Local writes also revalidate under shared row locks, so revocation/profile
changes serialize with the authorized transaction instead of reusing stale state.
There are no wildcard tokens or automatic grants. IP allowlists and automated
rotation reminders are not implemented in this release.

## First client: website read-only

Use the Website preset with an appropriately limited access profile:

| Endpoint | Scope | Required profile permission |
| --- | --- | --- |
| GET /website/teams | website:teams:read | teams.view |
| GET /website/fixtures | website:fixtures:read | competitions.view |
| GET /website/training-times | website:training-times:read | training_slots.view |
| GET /website/facility-status | website:facility-status:read | field_availability.view |

The website stores its token only on its own server, never in public JavaScript.
These endpoints contain allowlisted display fields, not member contact data,
bank accounts, settings, provider credentials or storage paths. Inactive teams
are excluded; training slots require `website_visible`, fixtures require
`show_on_signage`. Fixtures default from today through three months ahead.
Optional `page` and `per_page` (max100); season filtering for teams/training times,
date filtering for fixtures. There are no corresponding website write routes.

## Resource contract

`GET /context` identifies the integration/club, token scopes and expiry.
Named resources are explicitly registered in `ApiModuleRegistry`; Swagger is the
authoritative list of implemented methods/fields/actions. Unknown resources or
actions are rejected, not treated as arbitrary database table names.

- GET /{resource}: bounded pagination, allowlisted search/filter/sort.
- GET /{resource}/{id}: an explicit DTO with no automatic relation serialization.
- POST /{resource}: create through an existing business service, if supported.
- PATCH /{resource}/{id}: service-based edit with the required revision/version.
- POST /{resource}/{id}/actions/{action}: a named domain action, if supported.

Module scopes use `members:read`, `teams:create`, `expenses:approve`, etc.; `read`
maps to the existing `view` permission. Catalog resources expose lookups through
their normal named endpoints. There is no generic permission-assignment,
settings/secret endpoint, arbitrary status setter, hard delete or bank payment.
Supported core resources, workflow resources and deliberate remaining gaps are
also available in the registry's `definitions()` and `unsupported()` methods.
An existing web function is not automatically a supported API function.

Requests use `Authorization: Bearer <token>`, HTTPS and JSON for normal writes.
Success envelopes use `data`; collection `meta` contains pagination. API errors
use `error.code`, `error.message`, `error.request_id` and optional validation
`fields`. Trace IDs are also returned as `X-Request-ID`. Responses are no-store.
401 indicates authentication failure;403 missing permission;404 missing/scoped
record;409 conflict;422 invalid input;429 throttling with Retry-After.
Unexpected errors do not expose SQL, provider responses, credentials or stacks.

Normal JSON writes require `Idempotency-Key` (8..120 alphanumeric/._:- chars).
A completed identical request returns its original encrypted stored response;
different payload reuse is409. Authorization runs before replay. Local business
writes also bind the replay hash to the effective profile/token/integration
permissions; changed authorization cannot retrieve a previous wider response.
Business writes and completion evidence share one transaction. Failed or abandoned
requests remain blocked for investigation rather than blindly repeated. After a
validation error, correct the data and use a new key. These wrappers must never
be used around nontransactional provider writes.

## Files and upload recovery

GET /files/{kind}/{id} uses the existing record-scoped download services and the
respective module's `:download` scope. Kinds: invoice-pdf, invoice-bexio-pdf,
member-document, registration-document, expense-document, material-receipt and
historical-material-receipt. Responses are private binary content, not signed or
public Azure URLs. `download` controls disposition; expense `index` selects one
attachment (0..20). Existing Bexio sandbox identity checks remain in force.

POST /uploads/{kind}/{id} accepts multipart `file`, with kind member,
registration or expense and a mandatory Idempotency-Key. Max20MiB, MIME verified
by the existing upload service. Member metadata: `type`, `portal_visible`;
registration metadata: `kind` portrait/identity; expense metadata: `revision` and
`expense_revision`. Module `:upload` is required, plus `documents:send` for portal
publication. Files are staged encrypted, never placed in a public folder.
The202 response gives upload ID/state; it does not claim successful Azure storage.

GET /uploads/{id} returns the caller integration's upload status/result ID.
POST /uploads/{id}/process runs the existing durable Azure intent/reconciliation
through SyncWorkService, outside the generic SQL transaction. It is inherently
idempotent by upload ID and does not require a new Idempotency-Key. Existing
source revision/permissions and portal-publication scope are rechecked. Failure
remains visible in the existing synchronization queue; no silent local fallback.
No additional scheduler is created. Storage configuration remains unchanged.

## Audit, tests and migration

API audit stores club/integration/technical actor/token ID, request UUID, route
template, method, status and duration, never bearer headers or business payloads.
The admin screen shows the latest50 audit events. Idempotency response bodies are
encrypted, not logged. Normal domain change history remains in effect.
If the request-audit insert fails after a known business outcome, a sanitized
critical log retains request/integration/token/tenant IDs and status, and
`X-Audit-Status: fallback` identifies that condition without exposing SQL or
misreporting an already committed write as failed. Authentication is additionally
limited to600 requests/minute per hashed source IP; admission uses cache locks,
also for the per-integration counter, so parallel requests cannot bypass limits.

Migration76 is additive and ClubUnio-only: `accounts.api_only`,
`api_integrations`, `api_access_tokens`, `api_audit`, `api_idempotency`.
Reviewed migration78 additionally enforces the composite club/profile foreign
key on integrations. Its fixture test is rollback-only before release; apply
the explicit migration path after concurrent fixture transactions have ended.
No legacy imports, database refresh, profile seed changes or automatic client
tokens. Restore requires retaining encryption keys, token revocations, audit and
idempotency evidence; migration down is deliberately destructive-operation safe.

Tests use isolated synthetic club data with an outer rollback and
`CLUBUNIO_TEST_DB=1`; HTTP providers are prevented/faked unless a separate
explicit read-only release smoke test is invoked. No real bank payments or
automatic website/app switching form part of API acceptance.

Livewire was narrowly patched from4.4.7 to4.4.8 for advisory
PKSA-xhv3-nr94-h8db (authorization attribute ordering). No broad dependency update
was performed; UI/security regression tests must pass on the patched version.

The opt-in CLI `tools/clubunio-api-live-smoke.php` creates only an isolated QA
club, admin/technical accounts, restricted profile and five-minute website token,
then calls the deployed HTTPS API. It verifies all four empty website feeds,
forbidden member/write access and revoked-token401. Finally all QA tokens are
revoked and QA accounts/profile disabled; their audit evidence is retained.
It does not grant a real user or consumer access to the existing club.

## Acceptance on 26.10.2026

Final combined API group:119 tests,26,554 assertions passed, including domain/
scope/token, workflow, lookup-column, admin UI, OpenAPI and tenant-boundary tests.
These include many schema assertions, not26,554 real external business actions.
The earlier156-case API/profile run had three API error-envelope failures; the
underlying replay was already denied409. Laravel's inner routing pipeline was
rendering those exceptions before the authentication middleware could sanitize
them. The new API-only exception renderer and safe reporting hook fix this;
the unchanged replay regressions and injected debug/SQL error now pass. Existing
profile groups passed in that earlier run; no all-project test-suite claim.

Release20261026-clubunio-api-v1 deployed on ClubUnio; all six CLI release gates
passed. Real HTTPS smoke with isolated QA club15478/integration620 passed:
context and four website feeds200, member access403, write403 without a created
team, revoked token401. QA tokens revoked and accounts/profile disabled; audit
evidence retained. Anonymous Swagger/spec redirect to login and v1 returns401.
Authenticated Chrome displayed the integration administration and self-hosted
Swagger successfully. Follow-up release20261026-clubunio-api-v1-final adds a
responsive scope grid, visible scope extensions in Swagger and explicit private
no-store/referrer protection for token administration including Livewire replies.
No existing app/website client has been switched. Advanced provider/bulk adapters
remain explicitly open in CLUBUNIO-API-COVERAGE.md.
