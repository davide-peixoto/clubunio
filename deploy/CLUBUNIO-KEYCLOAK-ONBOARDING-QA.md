# Keycloak: DEV-Abnahme fuer Vereinskonten

Status: Code auf DEV bereitgestellt; Einladungs- und App-Schalter aus. Der
bestehende Vereinsadmin-Pilot verwendet jetzt lokal den gemeinsam
nutzbaren Code-Austausch fuer vertrauliche und oeffentliche Clients.
Der Zuordnungsdienst liefert nur Kandidaten; er erstellt weder Konten
noch Links. Synthetische Tests pruefen PKCE-Anfrage, Token-Bindung,
Mehrdeutigkeit und Altersgrenze. Der Pilot kann optionale Mitglieder- und
Elternlinks aktivieren und effektive Sportrollen auf dem gemeinsamen
Mitgliederzugriff ableiten. Die Flutter-Kopplung ist lokal implementiert.
Vollstaendige Fachaktionsabnahme, Selbstregistrierung und native
Geraeteabnahme stehen aus.

### Begrenzter Web-Pilot

- Die additive Migration `2026_11_16_000090_keycloak_account_links.php`
  akzeptiert ausschliesslich die Datenbank `clubunio_dev`.
- `CLUBUNIO_VEREIN_OIDC_INVITES_ENABLED` ist standardmaessig aus. Den
  bestehenden Login mit `CLUBUNIO_VEREIN_OIDC_LEGACY_ALLOWED=true` belassen.
- Nach Anlegen eines isolierten synthetischen Vereins- oder Portalkontos in
  beiden Systemen erstellt `clubunio:keycloak-test-invite <verein> <konto-id>`
  einen zwei Stunden gueltigen Code. Das Kommando akzeptiert nur
  `keycloak-qa-...@example.invalid` auf DEV. Kein E-Mail-Versand und
  keine automatische Erstellung eines Keycloak-Kontos.
- Auf der ausgegebenen HTTPS-Seite den Code eingeben. Nach frischem
  OIDC-Login wird nur bei verifizierter, passender Adresse und weiterhin
  gueltiger Vereins- oder Portalberechtigung verknuepft. Der Code wird in
  derselben Transaktion verbraucht. Bereits konfigurierte Pilotzuordnungen
  koennen nicht ueberschrieben werden. Geheimcode nicht protokollieren.
- Mit `--first-name` und `--last-name` werden die Stammdaten beim
  Einloesen erneut abgeglichen. Bestehende Freigaben oder Widerrufe
  werden nicht ueberschrieben. Portalzugang muss bereits freigeschaltet
  sein. Bestehende lokale MFA-Pflichten werden nicht umgangen.
- Die effektive Sportrolle wird beim Mitgliederzugriff pro Request
  aus aktuellen aktiven Teamzuordnungen abgeleitet. Das aendert keine
  gespeicherte Rolle und weist keine manuellen Profile zu. Andere
  Berechtigungspfade und laufende Sitzungen sind noch nicht auf DEV
  abgenommen.

### Flutter-Pilot

- Der bisherige Passwort-/Sicherheitslogin bleibt auf dem Anmeldebildschirm.
  Die separate Keycloak-Schaltflaeche erscheint nur bei
  `keycloakLoginAvailable` im oeffentlichen Bootstrap.
- Zusaetzlich zu den Einladungs- und Vereins-OIDC-Flags ist
  `CLUBUNIO_VEREIN_OIDC_MOBILE_ENABLED=true` noetig. Sicherheits- und
  OIDC-Origin muessen dem DEV-HTTPS-Origin entsprechen.
- Der Systembrowser nutzt den vertraulichen Vereinsclient mit PKCE.
  Das Geraet startet die bestehende Kopplung, bestaetigt im Browser
  den achtstelligen Code und loest die App-Sitzung einmalig mit dem
  nur im App-Speicher gehaltenen Proof ein. Weder OIDC-Tokens noch
  App-Bearer-Tokens werden in einer Callback-URL transportiert.
- Der Pilot verlangt eine persistente Kontoverknuepfung und verweigert
  Konten mit bestehender lokaler MFA-Pflicht. Keine Behauptung ueber
  Keycloak-MFA-Assurance ohne separat konfigurierte und getestete Policy.
- Native Anmeldung und Geraetewechsel nicht abgenommen.

### DEV-Release

- Aktiv: `20261116-clubunio-keycloak-pilot` (nur Backend).
  Ruecksprung: `20261116-clubunio-platform-oidc`. Stage unveraendert.
- Additive Migration in `clubunio_dev` als Datenbankverwaltung ausgefuehrt
  und registriert. Die Anwendungsrolle hat keine DDL-Rechte.
- Flutter-Analyse der drei geaenderten Dart-Dateien: keine Befunde.
  Lokale Backend-Pruefung: 11 Tests, 53 Assertions.
- DEV-Smoke: `/login` HTTP 200; Einladungs- und mobile OIDC-Routen
  HTTP 404 bei deaktivierten Schaltern. Kein Testkonto veraendert.
- Aktivierung offen: DEV hat noch keinen konfigurierten vertraulichen
  Vereinsclient. Separaten Client `clubunio-verein-dev` mit PKCE S256 und
  Callback `https://dev.clubunio.ch/vereinslogin/oidc/callback` einrichten,
  Secret privat hinterlegen und den bisherigen Login aktiv lassen.
  Danach native DEV-Testbuilds und synthetische Konten fuer Rollenentzug
  sowie Passkey-/Wiederherstellung auf einem zweiten Geraet pruefen.

## Umsetzung und Sicherheitsgrenzen

1. ClubUnio ermittelt moegliche Zuordnungen ausschliesslich im ausgewaehlten
   Verein. Eine Einladung bindet serverseitig den Verein und das vorgemerkte
   Konto, ist befristet und nur einmal nutzbar. Bestehende Konten nicht anhand
   einer E-Mail-Adresse allein mit einer IdP-Identitaet verknuepfen.
2. Nach verifizierter Keycloak-Anmeldung die Bindung an Issuer und Subject
   speichern und die Zuordnungen erneut pruefen. Nur eindeutige, aktuelle
   Mitglieder- und Elternbeziehungen aktivieren; minderjaehrige Kinder
   brauchen dabei keine zusaetzliche Freigabe. Mehrdeutige oder nicht mehr
   gueltige Beziehungen blockieren, ohne Details oeffentlich preiszugeben.
3. Automatische Trainer-, TL-, Mitglieder- und Elternrechte von manuellen
   Rollen trennen. Beim Login neu berechnen und bei geschuetzten Zugriffen
   aktuelle Zuordnungen pruefen, auch bei bereits laufenden Sitzungen.
4. Web und Flutter an denselben Identitaets- und Rechteprozess anbinden.
   Mobile: Systembrowser und serverseitiger Authorization Code Flow mit
   PKCE, anschliessend Geraetekopplung; kein Client-Secret im Build. Keycloak verwaltet
   Passkeys und Wiederherstellung; Face ID ist ggf. die lokale Freigabe.

## Synthetische Abnahme auf DEV

| Fall | Erwartung |
| --- | --- |
| Einladung mit abgelaufenem oder erneut verwendetem Link | Keine Aktivierung |
| Identitaet oder Verein stimmt nicht mit Einladung ueberein | Keine Verknuepfung |
| Mitglied zugleich Elternteil eines minderjaehrigen Kindes | Eigener und erlaubter Kinderzugang ohne manuelle Genehmigung |
| Mehrdeutige Elternbeziehung, volljaehriges Kind oder fremder Verein | Kein automatisch erteilter Kinderzugang |
| Trainer- oder TL-Zuordnung entfernt | Bisherige Fachaktion verweigert; gueltige Basisrechte und manuelle Rollen bleiben |
| Zweiter Browser und separates iOS-/Android-Geraet | Anmeldung mit synchronisiertem oder zweitem Passkey; Wiederherstellung ohne altes Geraet gesondert pruefen |
| Bestehender, nicht migrierter Benutzer | Alter Login bleibt funktionsfaehig |

Nur synthetische Profile, kontrollierte Test-Postfaecher und getrennte Clients
verwenden. Keine echten Konten oder Passwort-Hashes migrieren. Stage
unveraendert lassen. Ergebnisse mit Umgebung und Testmethode protokollieren;
API-Tests ersetzen keine native Geraeteabnahme.

Passwortbefund: [DEV-Vorabpruefung](CLUBUNIO-KEYCLOAK-PASSWORD-MIGRATION.md).
