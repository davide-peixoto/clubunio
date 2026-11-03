# Current-season restriction for the personal App API (03.11.2026)

## Contract

The personal API `/api/mobile/v1/clubs/{tenant}/...` determines the current
season from the tenant's encrypted `general / SaisonID` parameter (the migrated
iniParameter value). This supersedes the initial date-based implementation.
Imported values resolve by tenant-scoped legacy_id; native IDs are accepted only
for seasons without a legacy ID. Legacy matches take priority, including an
inactive legacy match blocking accidental fallback to another native season.
No current-date guessing or client override. Missing/invalid/inactive references
return empty seasonal lists, never the archive. See the finance follow-up below.

Covered: communication team selection/contacts/team chats, My Teams (including
assignment access and training times), native/compatibility team planning,
event details/invitations, existing event-derived statistics and season-overlapping
absences. Now also expenses, invoice lists and their season/team/member lookups.
Filtering is SQL-side before pagination, totals, DTOs and roster reads.
Old IDs and `season_id`/`all_seasons` URL values cannot bypass the restriction.
Writes using a team/event are likewise constrained by the existing scoped queries.

Authorization remains an independent intersection: tenant, session, roles,
parent representation, invitation/privacy rules and function rights are unchanged.
An admin sees only the current seasonal teams in the App, not every past season.
Season metadata is cached only on the current request, keyed by tenant and Zurich
date; two bounded reads per tenant/request (parameter and season), no shared
user-data or stale-parameter cache across requests.

## Deliberate boundaries

No blanket filter on members, family relationships, bank accounts, documents,
private messages or invoice PDF links: these are not team-season list data.
Historical invoices/documents remain available through their existing authorized
explicit links; no record is deleted or migrated. Finance list/lookup contract:
[Parameter-based financial season](CLUBUNIO-MOBILE-FINANCE-SEASON.md).
Technical integration API `/api/v1` and Web retain their explicit historical
filters. No PHPMaker DEV/PROD or Flutter source change.

## Release and migration

No schema, import, data refresh, new grants or configuration changes.
MobileSeasonScope is activated only after personal mobile tenant authentication;
TeamPlanningService Web/background reads remain unchanged. My Teams and
communication teams use the same resolver even when called directly.
Synthetic release fixture now has real date bounds (rolled back, never persisted).

Initial release tests:33 personal API regression tests/481 assertions. Includes all four roles,
cross-tenant season references, invited old events, write/direct-ID rejection,
chat access, SQL counts, absence overlap, request-local cache and Web history.
Midnight30 June/1 July, missing metadata and ambiguous configuration covered.
No provider request, bank payment, real account or record change.

Target release20261103-clubunio-mobile-season-scope on mobile-planning.
Rollback to that previous schema-aware release; no database rollback required.
Release verification is recorded below after activation.

Activated from a9e056e;12 packaged paths verified before/after switching current.
Thirteen release gates passed, plus the existing native15-route gate and two
PostgreSQL personal HTTP-kernel suites with fully rolled-back synthetic data.
All six changed/new runtime/gate source hashes match the pinned commit. HTTPS
login200 and anonymous App teams401 after activation. No schema or env changes.
Parallel attendance/statistics work committed independently as204d1c6 was not
included in this delta; its shared planning queries inherit the same season scope
when it is separately deployed. Further financial/mobile adapters remain separate
work and must explicitly adopt this policy for their seasonal list/lookups.
