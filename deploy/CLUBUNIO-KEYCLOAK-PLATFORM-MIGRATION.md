# Keycloak-Migration aus der Plattformverwaltung

In der separaten Uebersicht `/platform/users` lassen sich Plattformkonten
erstellen und bearbeiten. Entziehen deaktiviert den Plattformzugang und
behaelt den Listeneintrag. «Aus Plattform entfernen» loescht den Eintrag
auch nach einem frueheren Entzug. Das gemeinsame Konto, Vereinszuordnungen,
Fachdaten und die Keycloak-Identitaet bleiben bestehen; ohne aktiven
Plattformzugang kann die Identitaet die Plattform nicht mehr aufrufen.
Keine Datenbankmigration erforderlich. Entzug und Entfernen des eigenen
Zugangs sind gesperrt. Aenderungen sind begruendungspflichtig und
werden protokolliert. Der Keycloak-Status zeigt die bestehende statische
Plattform-Subject-Zuordnung. Neue Konten koennen sich in Keycloak-only
erst nach eigener Plattform-Identitaetsfreigabe anmelden.
Automatische Einladungen fuer Plattformkonten sind auf DEV separat
freigeschaltet.

DEV-Release `20261116-clubunio-platform-removal`: Entfernung aktiver und
entzogener Eintraege mit synthetischen Konten unter der DEV-Laufzeitrolle
geprueft. Fehlende Berechtigung und Selbstentfernung werden abgewiesen;
Konten und Vereinszuordnungen bleiben erhalten, Audit wird geschrieben.
Die Testtransaktion wurde zurueckgerollt. Stage und bestehende Zugaenge
blieben unveraendert. Rueckweg fuer den Code: voriger DEV-Release, ohne
Schemaaenderung. Bereits absichtlich entfernte Zugriffe bleiben entfernt.

DEV-Nachtrag `20261116-clubunio-platform-removal-retry`: Ein abge-
brochener HTTP-Aufruf hatte die Entfernung bereits gespeichert, die
Wiederholung lieferte jedoch 404. Wiederholtes Entfernen desselben
bestehenden Kontos ohne Plattformzugang kehrt jetzt erfolgreich zur Liste
zurueck, ohne einen zweiten Audit-Eintrag zu schreiben. Auf DEV mit
zurueckgerollten Testkonten geprueft; keine Schemamigration.

Fuer manuell gepruefte Plattform-Identitaeten gibt es separaten Einstieg
`/vereinslogin/oidc/platform-migration` mit DEV-MFA und privater
Allowlist `CLUBUNIO_PLATFORM_OIDC_MIGRATED_SUBJECTS`. Vor der Zuordnung
Keycloak-Konto und Subject unabhaengig pruefen. Der Login prueft Issuer,
Subject, verifizierte Adresse, frischen MFA-ACR und die bereits bestehende
aktive Plattformberechtigung. Vereinslinks verleihen keine Plattformrolle.
Die bisherige Plattformzuordnung bleibt unangetastet.

### Plattform-Einladungen auf DEV

Release `20261116-clubunio-platform-invitations`, additive Migration
`2026_11_16_000092_platform_keycloak_invitations`. In der
Plattformbenutzerliste steht die Aktion nur fuer aktive, noch nicht
verknuepfte Plattformkonten mit gueltiger Adresse bereit. Selbstversand
und automatische Uebernahme bestehender Keycloak-Identitaeten sind
gesperrt. Die konkrete Empfaengeradresse muss bestaetigt werden.
Wiederholungen verwenden ausschliesslich den bereits gespeicherten
Subject. Versand wird im Plattform-Audit protokolliert.

Nach Keycloak-E-Mail-Verifizierung, Passwort und TOTP meldet sich das
Konto ueber `/vereinslogin/oidc/platform-migration` an. Die Zuordnung
wird erst nach exakter Subject-, Adress-, MFA- und Rollenpruefung aktiv.
Aktivierungsmail fuer ein separates, freigegebenes DEV-Testkonto wurde
von Keycloak zum Versand angenommen. Browser-Abnahme am 16.11.2026
erfolgreich: Der Empfaenger bestaetigte die geoeffnete Plattformuebersicht
nach Anmeldung mit dem Testkonto. DEV speicherte die Aktivierung um
17:57:10 Uhr (Europe/Zurich); der Plattformzugang des Testkontos ist aktiv.
Bestehende Admin-Zugaenge und Stage blieben unveraendert.

DEV-Release `20261116-clubunio-platform-users`: Uebersicht und separater
MFA-Einstieg bereitgestellt. Der DEV-Datenbanktest verwendete ein
synthetisches Konto und eine zurueckgerollte Transaktion: ohne Rolle
abgewiesen, exakte Zuordnung mit frischem MFA-Claim akzeptiert,
nur Passwort abgewiesen, nach Rollenentzug erneut abgewiesen.
Beide Einstiege lieferten HTTP 302. Keine neue dauerhafte Zuordnung,
kein echter Admin geaendert, kein vollstaendiger Browser-Login getestet.
Stage unveraendert.

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
