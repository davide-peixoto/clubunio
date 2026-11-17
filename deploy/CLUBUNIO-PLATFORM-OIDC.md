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

## Stage-Pilot vom 17.11.2026

RunCloud erhielt den Funktionsstand `535bfb5`. Die elf gezielten
Migrationen für Support, Keycloak-Kontozuordnungen und ERPNext wurden im
isolierten PostgreSQL-16-Stage-Schema und danach auf Stage nach einer
Schemasicherung ausgeführt. 33 gezielte Tests mit 335 Assertions sowie
DE/EN bestanden. Nach dem Neuaufbau der Caches antworteten Vereins-,
Portal- und Plattformlogin mit HTTP 200.

Die Keycloak-Clients für den Web-Pilot im Realm `clubunio` sind
`clubunio-verein-stage` und `clubunio-platform-stage`. Vorgesehen sind
Authorization Code mit PKCE S256, Client-Authentifizierung und exakt
`https://stage.clubunio.com/vereinslogin/oidc/callback` beziehungsweise
`https://stage.clubunio.com/platform/oidc/callback`. Keine Servicekonten
oder Direct Access Grants. Nur verifizierte Subjects von vorhandenen
berechtigten Stage-Konten unter davide.peixoto@* und info@clubunio.ch
abbilden. Lokale Anmeldung mit MFA während des Pilots beibehalten.

Die Aktivierung ist offen. Zammad, KI-Triage und ERPNext warten auf
separate Stage-Testkonfigurationen. Native App-Anmeldung bleibt
bis zur Übernahme und Abnahme des nativen Callbacks deaktiviert.

### Web-Pilot aktiviert

Beide vertraulichen Stage-Clients sind mit PKCE S256, exaktem Callback
und dem MFA-Browserflow im Realm `clubunio` angelegt. Stage verlangt
frische MFA und prüft den zugesicherten ACR vor der Anmeldung.
Der Vereinsclient ist für zwei verifizierte Davide-Konten zugeordnet.
Das bestehende Plattformrecht hat davon nur das SV-Sissach-Konto.
Für `info@clubunio.ch` wurde keine passende aktive Identität gefunden.
Die lokale Anmeldung bleibt verfügbar.

Die beiden OIDC-Starts erreichten die Keycloak-Anmeldeseite. Falscher
Callback-State ergab HTTP 403. Die drei lokalen Loginseiten blieben
erreichbar. Die persönliche Browserabnahme nach Passwort und MFA steht aus.
