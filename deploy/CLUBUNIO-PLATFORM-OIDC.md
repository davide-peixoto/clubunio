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
CLUBUNIO_PLATFORM_OIDC_ORIGIN=https://dev.clubunio.ch
CLUBUNIO_PLATFORM_OIDC_CLIENT=clubunio-platform-dev
CLUBUNIO_PLATFORM_OIDC_SECRET=
CLUBUNIO_PLATFORM_OIDC_SUBJECTS='{"KEYCLOAK_SUBJECT":123}'
```

Map each verified Keycloak subject to the numeric ClubUnio account ID
after confirming ownership. Account and active platform administrator grant
must already exist. No email matching, self-registration or automatic
role assignment. Never commit the secret or instance-specific mapping.

Enable first on DEV with a verified mapping, confirm login, logout, role
revocation, support access and isolation from the regular login, then
roll out independently. The flag disables the legacy platform password,
Passkey and TOTP entry points on that instance. Turning the flag off
restores the pre-existing login; local factors were not modified.
