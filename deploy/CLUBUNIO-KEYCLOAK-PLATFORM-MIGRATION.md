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

## Persoenliches Profil und Kontosicherheit (17.11.2026)

Der Benutzerbereich der Vereinsverwaltung (`/my-profile`), des
Mitgliederportals (`/portal/profile`) und der Plattform
(`/platform/profile`) zeigt nur das Konto der jeweiligen angemeldeten
Sitzung. Keine Freischaltung ueber Vereinsrollen und kein Zugriff in der
Benutzersimulation. Weitere Plattformberechtigungen entstehen dadurch nicht.
Bei aktivem OIDC oeffnet der Link die persoenliche Keycloak Account Console
ohne Tokens, Konto-ID oder Fremdparameter. Passwort, MFA, Passkeys und
Keycloak-Geraetesitzungen werden dort nach der Realm-Policy verwaltet.
Das Beenden einer Keycloak-Sitzung ist nicht gleich dem Widerruf eines
separat ausgestellten ClubUnio-App-Tokens.

`GET /api/mobile/v1/profile` liefert optional `keycloak_account_url`.
Flutter oeffnet nur die exakte freigegebene HTTPS-URL im Systembrowser.
Auf Keycloak-only ist der alte lokale Passwortwechsel per API gesperrt;
der serverseitige Passwort-Notlogin bleibt unveraendert. Kein Schemawechsel,
kein neuer Keycloak-Client und keine Aenderung der Rollen oder Realm-Policy.
DEV-Release `20261117-clubunio-personal-profile-host-scope`, Rueckweg
`20261117-clubunio-trainer-polish`. Sechs gezielte Backend- und 23
Flutter-Tests erfolgreich; anonyme DEV-Einstiege abgeprueft. Stage
unveraendert, kein Store-Rollout.

DEV-Nachtest 17.11.2026: Der Plattformadmin hat in Chrome ueber
`/platform/profile` die Account Console geoeffnet; Anmeldung und
Geraeteaktivitaet waren sichtbar. Im DEV-Backend mit bestehenden
Testkonten geprueft: Normales Mitglied und Plattformadmin erhalten
jeweils nur ihr eigenes Profil und denselben Keycloak-Link. Fremde
Konto-Parameter werden ignoriert und die Plattform lehnt das Mitglied
ab. Das Mitglied erhaelt den Link auch im App-Profilvertrag. Keine
Berechtigungen oder Anmeldedaten geaendert. Die native Abnahme auf
iOS/Android bleibt separat.

Browser-Abnahme als normales DEV-Mitglied (17.11.2026): Nach frischer
Keycloak-Anmeldung im Mitgliederportal fuehrt `Mein Profil` ueber
`Keycloak-Konto oeffnen` zur eigenen Account Console in einem neuen Tab.
Persoenliche Angaben sowie Anmeldung und Geraeteaktivitaet waren
sichtbar. Der erste Versuch mit einer abgelaufenen OIDC-Anmeldung
lieferte vor dem Profil HTTP 403; ein neuer Login behob dies ohne
Konfigurationsaenderung. Keine Kontoeinstellungen veraendert, Stage
unveraendert.

## Namen und Mailstatus (17.11.2026)

DEV-Release `20261117-clubunio-keycloak-invite-names`. Neue Identitaeten
erhalten Vor- und Nachname aus der genehmigten eigenen Mitgliederzuordnung
des Vereins, ansonsten aus dem persoenlichen Anzeigenamen. Keine Ableitung
aus Mailadressen oder verknuepften Kindern. Wiederholungen ergaenzen nur
fehlende Namen am bereits vorgemerkten Keycloak-Subject und behalten die
vorhandenen Profilattribute. Ein bestehendes DEV-Konto wurde gezielt
ergaenzt und lesend ueberprueft, ohne erneut eine Einladung auszusenden.

`invited_at` bedeutet HTTP 204 von Keycloak, nicht Postfachzustellung.
Bei Nichtzustellung zuerst Empfaenger-Quarantaene und Message Trace bzw.
SMTP-Relay pruefen. Ein vom Gateway abgelehnter Versand protokolliert
seinen HTTP-Status ohne E-Mail-Adresse, Token oder Antwortinhalt. Keine
Datenbankmigration; Rueckweg `20261117-clubunio-trainer-web`. Stage unveraendert.

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

#### Bereinigung und Passwort-Notanmeldung

Im Keycloak-only-Modus sind lokaler 2FA-Wizard und lokaler Reset im Web
ausgeblendet und gesperrt; die aktuelle Flutter-App bietet nur Keycloak
als Browser-Einstieg an. Pairing-Endpunkte und Sicherheitsstempel werden
auch von Keycloak verwendet und bleiben erhalten. Keine Tabellen loeschen.
Die bisherigen Backendpfade bleiben fuer andere Umgebungen erhalten.

Notfall nur nach dokumentierter Freigabe im Incident-Log, ueber die
private Laufzeitkonfiguration des exakten Keycloak-HTTPS-Hosts aktivieren:
`CLUBUNIO_EMERGENCY_PASSWORD_ACCOUNTS` (kommagetrennte interne Konto-IDs),
`CLUBUNIO_EMERGENCY_PASSWORD_FROM` und `CLUBUNIO_EMERGENCY_PASSWORD_UNTIL`
(UTC im Format `YYYY-MM-DDTHH:MM:SSZ`, maximal 60 Minuten) sowie
`CLUBUNIO_EMERGENCY_PASSWORD_INCIDENT` (eindeutige Ticket-ID). Account und
lokales Kennwort vorab kontrollieren; keine Kennwoerter im Incident-Log.
Konfigurationscache neu laden; danach laesst sich das Mitgliederportal
gezielt ueber `/portal/login?emergency=1` und die App ueber ihr
Passwortformular erreichen. Kein automatischer Wechsel, kein Passwortzugang
fuer Plattformadmins oder in die Verwaltungsoberflaeche. Die Fachrechte
innerhalb von App und Mitgliederportal werden wie sonst geprueft.
Jede erfolgreiche Nutzung schreibt `clubunio.emergency_password.used`;
nach Ende/Entzug der Freigabe sind auch laufende Notsitzungen ungueltig.
Nach dem Vorfall die vier Einstellungen aus der privaten Konfiguration
entfernen, Cache neu laden und Audit abgleichen. Kein Schemawechsel.

DEV-Release `20261116-clubunio-keycloak-cleanup`: isolierte Pruefung auf
DEV bestaetigt App- und Portal-Anmeldung fuer ein synthetisches Konto,
Ausschluss von Plattformadmins und nicht freigegebenen Konten sowie
Sitzungsentzug bei Deaktivierung. Testdaten zurueckgerollt. Im aktiven
Release keine Notfreigabe eingerichtet; Bootstrap meldet nur Keycloak.
Rueckweg: `20261116-clubunio-platform-removal-retry`, Stage unveraendert.

#### Entfernung des lokalen 2FA-Codes

Der lokale Wizard, Passkey-/TOTP-Pruefung, Reset fuer die Geschaeftsstelle,
Setup-Code nach Recovery-Mail, Setup-CLI sowie zugehoerige Routen und
Webassets sind aus dem Anwendungscode entfernt. Die bestehenden App-Pfade
`session/security/begin` und `session/security/redeem` bleiben kompatibel
und werden vom Keycloak-Pairing-Controller bedient. Ohne konfigurierten
Keycloak-MFA-Level wird Pairing nicht freigegeben. Im Keycloak-only-Modus
fordert der Web-Vereinslogin bei konfiguriertem Level frische MFA an;
Alt-Konten, die bisher lokale MFA benoetigten, duerfen dadurch nicht
auf eine schwache Anmeldung zurueckfallen.

Die historischen Tabellen und bestehenden Sitzungsstempel bleiben
erhalten und werden zur Kompatibilitaet und gegen MFA-Downgrade lesend
verwendet. Keine DDL, keine Loeschung von Faktoren; der auditierte
Passwort-Notlogin ist unveraendert. Stage bedient weiterhin den alten
Wizard und wird nicht aktualisiert. Vor einem Stage-Rollout erst den
Vereins-Keycloak-Client mit frischer MFA bereitstellen, betroffene Konten
verknuepfen und Browser-/Android-/iOS-Abnahme durchfuehren. Erst nach
Wegfall alter Releases und Stempel eine separate Datenmigration planen.

Aktiver DEV-Release `20261116-clubunio-remove-local-mfa`, Rueckweg
`20261116-clubunio-erpnext-workflows`. Die direkte WebAuthn-Abhaengigkeit
wurde aus dem Composer-Lock und aus dem DEV-Release entfernt. 20 gezielte
Tests bestanden. DEV-Kandidat: App-Kopplung mit synthetischem MFA-Claim,
einmalige Einloesung und Portalablauf geprueft, Testdaten zurueckgerollt.
Live: alte Routen/Assets HTTP 404, App-Bootstrap nur Keycloak, Vereinslogin
fordert frischen MFA-Nachweis an. Kein nativer Geraetetest.

#### Bisheriger Cutover

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
