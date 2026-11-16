# Keycloak fuer die Vereinsverwaltung

Separate OIDC client `clubunio-verein-dev` in realm `clubunio`.
Confidential client, Standard Flow, PKCE S256, no Direct Access Grants,
implicit flow or service accounts. Exact DEV redirect URI:
`https://dev.clubunio.ch/vereinslogin/oidc/callback`.
This does not change the platform client, portal or mobile authentication.

Set the following in private DEV configuration; no schema or user import.

```dotenv
CLUBUNIO_VEREIN_OIDC_ENABLED=true
CLUBUNIO_VEREIN_OIDC_LEGACY_ALLOWED=true
CLUBUNIO_VEREIN_OIDC_ORIGIN=https://dev.clubunio.ch
CLUBUNIO_VEREIN_OIDC_CLIENT=clubunio-verein-dev
CLUBUNIO_VEREIN_OIDC_SECRET=
CLUBUNIO_VEREIN_OIDC_SUBJECTS='{"VERIFIED_SUBJECT":123}'
```

Explicitly link the Keycloak subject to an existing account. A valid
ClubUnio backoffice entitlement remains mandatory; even a linked trainer
without that entitlement cannot enter. No automatic role, tenant or
account changes.

Test `/vereinslogin/oidc/start` in a browser and verify the existing
tenant/role before setting legacy allowed to false. This disables local
backoffice login only for mapped accounts. Unmapped users retain access.
For rollback set legacy allowed to true, or disable the integration and
rebuild the configuration cache. Do not remove stored local factors.
