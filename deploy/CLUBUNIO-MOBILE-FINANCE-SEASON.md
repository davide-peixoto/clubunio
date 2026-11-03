# App finance season from iniParameter (03.11.2026)

## Source of truth

The selected season is `clubora.tenant_settings`, area `general`, key `SaisonID`,
encrypted by the existing Laravel key. This is the migrated iniParameter value,
not the SFV season and not a date-derived approximation. No runtime request to
PHPMaker/legacy_prod. Existing scalar parameter `enabled=false` from import is
not an integration kill switch; its value is still authoritative.
SV Sissach currently: parameter38 -> seasons.legacy_id38 -> internal id37,
2026/2027. Values, dates, enabled flags and source databases are unchanged.

Imported IDs resolve before native collisions; native fallback only for records
without legacy_id. Inactive/missing/invalid references stay closed, even if the
calendar suggests another season. Request-local bounded caching: two reads,
no cross-request stale selection. Changing SaisonID switches the next request;
crossing1 July alone does not. Absence overlap still needs the selected dates.

## Personal read endpoints

Base: `/api/mobile/v1/clubs/{tenant}`; existing personal bearer, domain/tenant,
role and family rules apply; no new grants or anonymous access.

| Method/path | Contract |
| --- | --- |
| GET /expenses | `data.rows`, `data.total`; status O/F/Z/B/R, start>=0, limit1..100 (default20) |
| GET /expenses/options | own staff teams from selected season; seasons, members, rounds, types, game_types, institutions, capabilities |
| GET /invoices | `data.rows`, `data.total`; member_id/status_id, start>=0, limit1..100 (default20) |
| GET /invoices/options | selected season; permitted invoice-owner members (search, max100); active statuses |

SQL restriction precedes count/pagination. Invoice totals use the existing cents
and rounding calculator with a SQL line sum, not one provider/database call per
invoice. No Bexio request. Expenses retain their existing role/owner/TL checks;
only configured-season records are visible, also for direct service access.
Expense saves cannot post an old/foreign team-season ID through a forged payload.
Invoice lists use the same personal owner restrictions as existing invoice detail;
trainer roster visibility never grants unrelated financial visibility.

SaisonID/all_seasons query values cannot widen selection. Null-season invoices
do not appear in seasonal lists. Explicit authorized invoice/PDF links remain
unchanged. General catalogs (banks, expense types, rounds, statuses) have no season
relation and remain active tenant catalogs, not incorrectly filtered/dropped.
No IBAN/secret in the options. Web/integration API keep historical access.

Read endpoints are independent of the separately gated native expense writes and
receipt-upload module. This release does not publish those parallel adapters,
their migration88, member editing or SFV import work. Canonical GET expenses and
expenses/options are registered after native helper routes, retaining the same
read contract and central season policy if that module is later enabled.

## Verification and migration

No migration, import, data refresh, payment, parameter replacement or env change.
36 mobile regression tests/525 assertions: parameter changes instead of calendar
rollover, imported/native collision, inactive/invalid reference, roles, SQL totals,
money values, historical records, expense/invoice lookups and unchanged Web access.
Synthetic release gate now provides a real isolated SaisonID, verifies both
financial lists/options and selection change; all fixtures roll back.

Target20261103-clubunio-finance-season-parameter on planning-statistics-v2.
Rollback: previous schema-aware release; no down migration or data restoration.

Activated from6994bd5. Fourteen packaged paths and seven runtime/gate Git hashes
verified.13 release gates plus the native17-route gate passed; both real PG
HTTP-kernel suites passed with complete fixture rollback, including finance
lists/options and SaisonID selection change. HTTPS login200; all four financial
read endpoints401 without personal authentication. No schema/env/parameter change.
