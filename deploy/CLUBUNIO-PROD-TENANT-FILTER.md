# PROD mobile tenant selection, 23.11.2026

The Stage pilot tenant filter hid SV Sissach from the existing active Office account on PROD. StageMobilePilot::allowsTenant now applies its pilot restriction only when app.url is https://stage.clubunio.com. Normal tenant membership, account status, MemberAccessService and origin binding remain required.

Validation: reproduce empty context before fix; verify SV Sissach in the same account context after fix; verify Stage review account still accepts only sv-sissach-refresh and no arbitrary Stage tenant. No account changes, database migration or data import. No UI text changes. Rollback: restore the previous StageMobilePilot.php and reload PHP FPM. Private backup: /root/clubunio-prod-fixes/20261123-tenant-filter.

Migration plan: no schema change; existing migration and delta baseline retained.
