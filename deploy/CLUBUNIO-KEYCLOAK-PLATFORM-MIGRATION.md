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
dieses Stands. Bestehender Login und Rollen bleiben unveraendert.

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
  hat den Status Einladung versendet erreicht. Erste Anmeldung und
  kontospezifischer Legacy-Cutover stehen aus; kein globaler Cutover.

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
