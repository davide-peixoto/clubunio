# Keycloak: DEV-Abnahme fuer Vereinskonten

Status: Code auf DEV bereitgestellt; Einladungs- und App-Schalter aktiv. Der
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
- DEV-Smoke nach Aktivierung: bisherige Loginseite und Einladungsseite
  HTTP 200, OIDC-Start HTTP 302 an den korrekten Client mit PKCE S256.
  Der mobile Bootstrap meldet `loginAllowed`, `strongLoginAvailable`
  und `keycloakLoginAvailable` als aktiv.
- Vertraulicher Client `clubunio-verein-dev` im Realm `clubunio`, mit
  exaktem Callback `https://dev.clubunio.ch/vereinslogin/oidc/callback`.
  Secret ausschliesslich in privater DEV-Konfiguration. Legacy bleibt aktiv.
- DEV-Web-Abnahme mit `keycloak-qa-webapp@example.invalid` im isolierten
  Testverein `keycloak-qa-webapp`: Einladung eingeloest, dauerhafte
  Verknuepfung und Dashboard nach OIDC bestaetigt. Ein beim ersten Zugriff
  beobachteter HTTP 500 wurde behoben: Der Link-Lookup nimmt Issuer und
  Subject nun explizit entgegen. Delta im aktiven DEV-Release
  `20261116-clubunio-support-polish`.
- Vereinsadmin-Rolle im Testverein bei laufender Browsersitzung entzogen:
  Neuladen des Dashboards ergibt HTTP 403, ohne erneuten Login. Die
  Kombinationen Trainer/TL, Elternzugriff und App-Sitzung wurden damit
  nicht abgenommen. Das Testkonto hat keinen Portalzugang.
- Native App und Geraetewechsel sind offen: Kein Android-Testgeraet
  verbunden, iOS nicht abgenommen. Die serverseitige MFA-Kopplung
  fuer ein neues DEV-Konto ist nachfolgend separat abgenommen.
  Stage und produktive Konten unveraendert.

### Gleichwertige MFA fuer die App

- Auf DEV aktiv seit 16.11.2026: Der Browser-Flow
  fordert den konfigurierten ACR als essenziellen ID-Token-Claim an.
  ClubUnio prueft den exakten ACR und ein frisches `auth_time` erst nach
  OIDC-Signatur-, Issuer-, Audience- und Nonce-Pruefung.
- Die einmalige Kopplung bindet die bestaetigte Assurance an die
  App-Sitzung. Der Server prueft die Bindung an Issuer, Subject, Client,
  ACR und lokale Sicherheitsversion auch bei Folgeaufrufen. Aenderungen
  daran oder das Entfernen der Kontoverknuepfung machen sie ungueltig.
- `CLUBUNIO_VEREIN_OIDC_MOBILE_MFA_ACR` erst setzen, nachdem der
  dedizierte DEV-Client eine getestete Keycloak-Policy fuer genau diesen
  ACR hat. Passwort allein, bestehende Sitzung ohne zweiten Faktor und
  fehlender/falscher ACR muessen abgewiesen werden. Eine ACR-Zeichenfolge
  in der Konfiguration ist fuer sich genommen kein MFA-Nachweis.
- Danach Trainer- und TL-Entzug mit einer bereits laufenden App-Sitzung
  sowie Zweitgeraet und Wiederherstellung auf iOS und Android abnehmen.

DEV-Release `20261116-clubunio-mobile-mfa`; Ruecksprung-Release
`20261116-clubunio-support-polish`. Der Browser-Flow
`clubunio-verein-dev-mfa` ist nur am DEV-Vereinsclient gebunden:
Passwort verpflichtend, ACR `clubunio-mobile-mfa` auf LoA 2, dort
OTP verpflichtend und Max Age 0. Realm-Standard nicht geaendert.

Mit synthetischem Konto geprueft: Passwort allein fuehrte zur
OTP-Einrichtung, und beim anschliessenden App-Ablauf wurde erneut
OTP verlangt. Nach gueltigem OTP waren Callback, einmaliges
Redeem und authentifizierter API-Kontext erfolgreich (HTTP 200).
Wiederholung desselben Redeem: HTTP 404. Sitzung beendet, temporaere
Admin-Rolle entfernt. Legacy-Loginseite HTTP 200. Stage unveraendert.
Dies war ein Browser-/API-Test, kein nativer Test.

### DEV-Nachtest: Rollenentzug bei laufender App-Sitzung (16.11.2026)

Im aktiven DEV-Backend mit einer isolierten PostgreSQL-Transaktion:
synthetischer Verein, aktives Mitglied, Keycloak-Kontolink und eine
MFA-gebundene App-Sitzung mit synthetischem Assurance-Claim. Der Test
nutzte die echte Sitzungsausstellung und -authentifizierung des
DEV-Backends. Jeder Zugriff wurde als neuer Request ausgewertet;
das Sitzungstoken blieb dasselbe.

- Trainer: wirksame Trainerrolle und Teamzugriff vorhanden. Nach
  Deaktivierung der Zuteilung: Mitglied, keine verantworteten Teams.
- Technischer Leiter: wirksame TL-Rolle und Teamzugriff vorhanden.
  Nach Deaktivierung der Zuteilung: Mitglied, keine verantworteten Teams.
- In beiden Faellen blieb der regulaere Vereinszugang bestehen.
  Testdaten und Sitzung wurden zurueckgerollt.

Dies bestaetigt die serverseitige Rollen- und Teamzugriffsentscheidung
mit laufender Sitzung, nicht alle einzelnen Fachaktionen oder die native
Anzeige. Der Geraetewechsel bleibt auf iOS und Android offen: Es war
kein natives Testgeraet verfuegbar. Alter Login aktiv, Stage unveraendert.

### DEV-Aktivierungscheckliste

1. Im Realm `clubunio` einen separaten Browser-Flow fuer
   `clubunio-verein-dev` erstellen und nur dort als Client-Override binden.
   Realm-Standard und Plattformclient nicht bearbeiten.
2. Fuer den Client `clubunio-mobile-mfa` auf LoA 2 abbilden. Im Flow
   muss diese Stufe nach primaerer Anmeldung einen zweiten Faktor
   zwingend verlangen; kein bedingtes Ueberspringen ohne eingerichteten
   Faktor. LoA-Max-Age 0 fuer eine frische MFA bei App-Anmeldung.
3. Mit dem synthetischen Konto den Browser-Flow bis zur MFA pruefen,
   dann die exakte ACR- und Auth-Time-Pruefung im Callback und die
   einmalige App-Kopplung testen. Erst dann den ACR in DEV dauerhaft
   setzen. Den bisherigen ClubUnio-Login aktiv lassen.
4. App-Sitzung fuer Trainer und TL jeweils offen lassen, Zuteilung
   entfernen und gefilterte Fachaktion erneut aufrufen. Separat
   Testbuild auf iOS und Android mit zweitem Geraet pruefen.

Referenz: [Keycloak Step-up Authentication](https://www.keycloak.org/docs/latest/server_admin/#_step-up-flow).
Ein ACR-Mapping oder ein Default-ACR ersetzt die Flow-Pruefung nicht.

### Native DEV-Abnahme: Geraetewechsel

- Flutter-Stand `18d29b0` oder neuer und DEV-Origin
  `https://dev.clubunio.ch`. Bei bestehender Installation die gespeicherte
  Umgebung pruefen: Sie kann den Build-Default ueberschreiben. Nur DEV
  auswaehlen. Keine Store-Veroeffentlichung erforderlich.
- Android: `flutter build apk --debug --dart-define=CLUBUNIO_ORIGIN=https://dev.clubunio.ch`.
  Debug-APK nicht ueber eine anders signierte Installation installieren;
  bevorzugt separates Testgeraet verwenden.
- iOS: auf Mac mit Xcode und CocoaPods im App-Repo
  `flutter pub get` und danach
  `flutter run -d <geraete-id> --dart-define=CLUBUNIO_ORIGIN=https://dev.clubunio.ch`.
  Entwicklungs-Signing und registriertes iPhone erforderlich.
- Synthetisches Konto `keycloak-qa-webapp@example.invalid`, Testverein
  `keycloak-qa-webapp`, Nur-Mitglied mit eigener synthetischer
  Mitgliederzuordnung und Portalzugang. Vor dem Geraetetest das
  Testkennwort privat setzen und ggf. die bisherige synthetische
  OTP-Credential im Keycloak-Adminbereich ersetzen. Kein Passwort,
  QR-Code, OTP oder Seed in Chat, Tickets oder Screenshots.
- Auf dem ersten Geraet **Mit Keycloak anmelden**, die Kopplung im
  Browser bestaetigen und Passwort plus TOTP eingeben. Zur App zurueck
  und die Anmeldung abschliessen. Nur der synthetische Verein soll
  verfuegbar sein. Ein Passwort allein darf keine Sitzung ausstellen.
- Auf dem zweiten Geraet ohne Wiederherstellung der ClubUnio-App-Daten
  neu anmelden und eine neue Kopplung bestaetigen. Fuer diesen Test
  einen weiterhin verfuegbaren oder sicher uebertragenen Authenticator
  verwenden. Die vorige App-Sitzung darf nicht auf das neue Geraet
  kopiert werden. Optional die alte Sitzung gezielt widerrufen und
  pruefen, dass die neue Sitzung weiter funktioniert.
- Getrennt pruefen: ohne alten oder migrierten OTP-Faktor muss die
  Neuanmeldung scheitern. Erst nach kontrolliertem Reset ausschliesslich
  des synthetischen Faktors und erneuter TOTP-Einrichtung darf sie
  wieder funktionieren. Diese Anleitung bestaetigt keine
  Self-Service-Wiederherstellung oder Passkey-Synchronisation.
- Dieselben Faelle auf iOS und Android protokollieren: OS, Build,
  Quell-/Zielgeraet, Ergebnis und anonymisierte Fehlermeldung.

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
