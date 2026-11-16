# Platform administration via Keycloak

Only `/platform` uses the separate platform session when enabled. The
ClubUnio backoffice, member portal and native app keep their existing
login and factors. No credential deletion or database migration.

Configure a separate confidential OpenID Connect client per environment in
realm `clubunio`. Enable Standard Flow and PKCE S256; disable Direct Access
Grants, implicit flow and service accounts. Register the exact callback
`https://dev.clubunio.ch/platform/oidc/callback` for DEV, without wildcards.
Use the realm's browser flow for the required authentication factors.

Private per-instance configuration:

```dotenv
CLUBUNIO_PLATFORM_OIDC_ENABLED=false
CLUBUNIO_PLATFORM_OIDC_LEGACY_ALLOWED=true
CLUBUNIO_PLATFORM_OIDC_ORIGIN=https://dev.clubunio.ch
CLUBUNIO_PLATFORM_OIDC_CLIENT=clubunio-platform-dev
CLUBUNIO_PLATFORM_OIDC_SECRET=
CLUBUNIO_PLATFORM_OIDC_SUBJECTS='{"KEYCLOAK_SUBJECT":123}'
```

Map each verified Keycloak subject to the numeric ClubUnio account ID
after confirming ownership. Account and active platform administrator grant
must already exist. No email matching, self-registration or automatic
role assignment. Never commit the secret or instance-specific mapping.

Enable first with legacy allowed and test the explicit `/platform/oidc/start`
entry point. After successful browser acceptance, set legacy allowed to
false and rebuild the instance's configuration cache. This disables the
legacy platform password, Passkey and TOTP entry points. Restore legacy
allowed to true for a configuration-only rollback; local factors remain.

## DEV rollout, 16.11.2026

Client `clubunio-platform-dev` created in realm `clubunio`, with exact
DEV callback and PKCE S256. One verified identity mapped to its existing
platform administrator account. Browser reached the platform dashboard
through Keycloak while legacy access was still allowed. After cutover,
the platform login redirects, the old platform wizard returns 404 and
the normal login remains reachable. Stage and the native app unchanged.
Release `20261116-clubunio-platform-oidc`, prior release
`20261115-clubunio-dashboard-requests`. The private release configuration
is `/var/www/svs-laravel/shared/.env.clubora-platform-oidc`; preserve its
link when deploying again. No schema changes.
