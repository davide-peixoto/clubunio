# Keycloak-Migration aus der Plattformverwaltung

Unter Plattformverwaltung > Mandant > Benutzerzuordnungen > Zu Keycloak
migrieren kann ein aktiver Plattformadmin einzelne Konten oder bis zu zehn
markierte Konten auswaehlen. Die Vorschau zeigt Versandadresse, alle Vereine
des Kontos und den Status. Bestaetigung ist an Vorschau, Sitzung, Admin,
Mandant und eine zehnminuetige Frist gebunden. Vor Verarbeitung werden die
geprueften Kontodaten nochmals verglichen.

Die Empfaengeradresse ist der als E-Mail gemaess Vorschau bestaetigte
ClubUnio-Benutzername. Technische, deaktivierte, Plattformadmin- und
statisch verknuepfte Konten werden nicht neu provisioniert. Vorhandene
Keycloak-Identitaeten werden nicht anhand der Adresse uebernommen. Keycloak
legt neue Identitaeten ohne kopiertes Passwort an und versendet die
Aktionsmail fuer E-Mail-Verifizierung, neues Passwort und OTP-Einrichtung.
Ein erneuter Versand verwendet die zuvor gespeicherte Keycloak-ID, nur
wenn die Empfaengeradresse weiterhin passt. Bei einem Netzwerkabbruch
zwischen Keycloak-Erstellung und Speicherung ist ein vorhandenes Konto
als Konflikt manuell zu klaeren; keine automatische Uebernahme.

Die erstmalige Verknuepfung erfolgt im Vereins-Web-Login anhand des
vorgemerkten Issuer/Subject und der verifizierten E-Mail. Sie setzt
bestehende Vereinsberechtigungen voraus und umgeht keine lokale MFA.
App-Erstaktivierung und automatischer Legacy-Cutover sind nicht Teil
dieses Stands. Der Legacy-Login kann nach bestaetigter Web-Anmeldung
gezielt pro Konto gesperrt werden; Rollen bleiben unveraendert.

## DEV-Bereitstellung

- Release `20261116-clubunio-platform-migration` aktiv; Ruecksprung
  `20261116-clubunio-mobile-mfa`. Die UI-Funktion ist auf DEV freigeschaltet.
- DDL auf `clubunio_dev` ausgefuehrt und in `clubunio.migrations`
  registriert. Legacy-Loginseite HTTP 200. Stage unveraendert.
- `clubunio-migration-dev` im Realm `clubunio` als vertraulicher
  Serviceclient ohne interaktiven Login eingerichtet. Realm-Rolle
  `realm-management/manage-users` und Default-Clientscope
  `kc-admin-api` zugewiesen. Der fehlende Clientscope verursachte unter
  Keycloak 26.8.2 HTTP 401; die Benutzerabfrage liefert jetzt HTTP 200.
  Client-Secret liegt ausschliesslich in privater DEV-Konfiguration.
- `info@clubunio.com` gehoert bereits einem Keycloak-Admin und darf
  nicht als synthetisches Migrationskonto verwendet werden. Fuer echte
  DEV-Einzel-/Mehrfachmails und Wiederholung getrennte Testadressen oder
  bestaetigte Aliasse verwenden. Eine separat freigegebene Einzelmigration
  ist nach der ersten Web-Anmeldung aktiviert und dauerhaft verknuepft.
  Kein globaler Cutover.

### Kontospezifischer Passwort-Cutover

DEV-Release `20261116-clubunio-keycloak-account-cutover` aktiv.
`CLUBUNIO_KEYCLOAK_LEGACY_DISABLED_ACCOUNTS` enthaelt interne
Konto-IDs, die erst nach verifizierter Keycloak-Anmeldung eingetragen
werden. Der zentrale ClubUnio-Provider lehnt fuer diese Konten neue
lokale Passwortanmeldungen ab; OIDC und andere Konten sind nicht
betroffen. Passwort-Hash und bestehende Sitzungen werden nicht geaendert.
Rollback: ID aus der privaten DEV-Liste entfernen und Config-Cache
erneuern. Auf DEV mit Ersatz-Hash nachgewiesen: ausgewaehltes Konto
abgewiesen, Kontrollkonto zugelassen; Loginseite HTTP 200. Stage unveraendert.

### DEV: nur Keycloak

Release `20261116-clubunio-keycloak-only` mit
`CLUBUNIO_KEYCLOAK_ONLY=true`. Neue lokale Passwortanmeldungen werden
fuer alle DEV-Konten abgewiesen. Verwaltung, Portal und Plattform
leiten zu ihrem jeweiligen OIDC-Einstieg um; die alte lokale
Sicherheitsanmeldung ist gesperrt. App-Bootstrap: Keycloak aktiv,
lokale Sicherheitsanmeldung aus. Bestehende Sitzungen wurden nicht
pauschal widerrufen. Unverknuepfte Konten muessen vor erneuter Anmeldung
migriert werden. Stage unveraendert.

Rollback: DEV-Schalter auf false setzen und Config-Cache erneuern;
der zuvor definierte kontospezifische Cutover bleibt bestehen.

DEV-Release `20261116-clubunio-keycloak-logout`: Abmeldung aus
Vereinsverwaltung, Portal und Plattform beendet die lokale Sitzung und
ruft Keycloak mit der konfigurierten App-Adresse als Ruecksprung auf.
Bei beiden DEV-Clients ist exakt `https://dev.clubunio.ch/` als
Post-Logout-URI eingetragen. Beide Keycloak-Endpunkte antworteten ohne
aktive Testsitzung mit HTTP 302 an diese Adresse. Stage unveraendert.

- Die Migration `2026_11_16_000091_keycloak_migrations.php` ist additiv
  und akzeptiert ausschliesslich `clubunio_dev`. Die DDL erfolgt ueber
  die DEV-DB-Verwaltung; Runtime-Rechte nur fuer die neue Tabelle vergeben.
- Den separaten Keycloak-Serviceclient nur mit den notwendigen Benutzer-
  und Aktionsmail-Rechten betreiben. Keine Plattform- oder Realm-Adminrolle
  fuer ClubUnio hinterlegen. Zugangsdaten privat konfigurieren:
  `CLUBUNIO_KEYCLOAK_MIGRATION_CLIENT`,
  `CLUBUNIO_KEYCLOAK_MIGRATION_SECRET`.
- Die Funktion bleibt standardmaessig deaktiviert. Erst nach Migration,
  Rollenpruefung und isoliertem Mailtest
  `CLUBUNIO_KEYCLOAK_MIGRATION_ENABLED=true` setzen. Aus dem Pilot keine
  echten Konten versenden. Stage bleibt unveraendert.

Referenz: [Keycloak Admin REST API](https://www.keycloak.org/docs-api/latest/rest-api/index.html).
