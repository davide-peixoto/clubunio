# Google Wallet: Einrichtung

Stand22.11.2026: Google Wallet ist im Backend und in Flutter implementiert und
auf DEV, Stage und PROD serverseitig aktiviert. Core und Vereinsbuilds nutzen
denselben autorisierten Vereinsausweis. Apple Wallet bleibt separat eingerichtet.
Die Google-Konsole zeigt weiterhin Publishing access 1/2. Der Publishingantrag wurde
am22.11.2026 ueber das Google-Supportformular eingereicht, weil der regulaere Request-
Button nicht reagiert. Eingang als Submitted bestaetigt; Freigabe noch ausstehend.

Webruntime-Fix22.11.2026: Stage-/PROD-FPM darf jetzt auch das jeweils eigene private
Google-Wallet-Verzeichnis lesen. GoogleAvailabletrue im FPM nachgewiesen; dies
ist keine Publishingfreigabe. [Runtime-Pruefung](CLUBUNIO-WALLET-RUNTIME.md).

## Aussteller und bestehendes Cloud-Projekt

Benutzer hat das Google-Geschäftsprofil ausgefüllt/gespeichert und die
Aussteller-ID `3388000000023233410` bereitgestellt. In Chrome wurde unter dem
aktuellen Konto genau ein ClubUnio-Projekt gefunden: `clubunio-e8d01` (ClubUnio).
Keine neuen Cloud-Projekte angelegt; drei Umgebungsidentitäten im bestehenden
Projekt verwendet. Google Wallet API und Firebase Cloud Messaging API zeigen
jeweils **API Enabled**. Keine Aktivierung anderer APIs vorgenommen.

## Dienstkonten

| Zweck | E-Mail | Stand |
|---|---|---|
| Wallet DEV | `clubunio-wallet-dev@clubunio-e8d01.iam.gserviceaccount.com` | Benutzer angelegt, Bestand geprüft |
| Wallet Stage | `clubunio-wallet-stage@clubunio-e8d01.iam.gserviceaccount.com` | In Chrome erstellt und Liste geprüft |
| Wallet PROD | `clubunio-wallet-prod@clubunio-e8d01.iam.gserviceaccount.com` | In Chrome erstellt und Liste geprüft |
| Push PROD | `clubunio-prod-push@clubunio-e8d01.iam.gserviceaccount.com` | FCM-Rolle bestätigt, Schlüssel eingerichtet, Providerprüfung200 |

Walletkonten ohne zusätzliche Cloud-Projektrollen erstellt. Die drei Konten
sind nun in der Google Pay & Wallet Console als Developer eingetragen und
einzeln als solche in der Benutzerliste bestätigt. PROD-Push ist vom Walletkonto getrennt. Erteilte Pushrolle:
`roles/firebasecloudmessaging.admin` (Firebase Cloud Messaging API admin).
Die ausdrückliche Benutzerbestätigung liegt vor; IAM meldete Policy updated und
zeigt die Rolle am PROD-Push-Konto. Für die drei Walletkonten und das separate
Pushkonto je einen JSON-Schlüssel erstellt. Keine echte Testnachricht versandt.

Ein Cloud-Projekt und ein Aussteller mit drei Dienstkonten schaffen keine harte
Aussteller-/IAM-Isolation zwischen Umgebungen. Die Integration muss
Umgebungs-/Vereinsgrenzen durch getrennte Konfigurationen, Class-/Object-IDs,
Mandantenautorisierung und getrennte sichere Schlüsselablage durchsetzen.
Vereinsbuilds verwenden die freigegebene Umgebung mit autoritativem Vereinslogo
und Vereinsfarben, keine eigene Codekopie oder pauschale Fremdvereinsfreigabe.

## Nächste Schritte

1. Google-Antwort auf den eingereichten Publishingantrag abwarten; Konsole noch1/2.
   Ohne Publishing nur berechtigte Demo-Testkonten verwenden.
2. Echte Android-Geräteabnahme mit einem autorisierten eigenen/Kinderausweis in
   Core und SV-Sissach-Testbuild: Speichern, Statusänderung, QR mit anderem Handy.
3. PROD-Push auf einem angemeldeten autorisierten Testgerät prüfen. Servereinrichtung
   und Provider-Dry-Run sind noch kein Nachweis einer realen Gerätezustellung.

## Schlüssel und tatsächlich geprüfte Zugänge

Auf ausdrücklichen Auftrag je einen Google-JSON-Schlüssel erzeugt. Lokale
Sicherung unter `C:/ClubUnio-Secrets/google-wallet/{dev,stage,prod,prod-push}.json`;
Ordner-ACL ohne geerbte Freigaben, nur Administrators und SYSTEM. Chromes
untergeordneter Speicherdialog war nicht zuverlässig steuerbar. Jeweils die
bereits vollständig heruntergeladenen temporären JSON-Daten anhand Typ,
Projekt, genauer Dienstkonto-E-Mail und Token-URL geprüft, exklusiv in die
geschützte Ablage kopiert und anschliessend den redundanten Speicherdialog
verlassen. Keine zusätzliche Schlüsselausstellung, keine Geheimnisse ausgegeben.

Private Serverdateien:

| Zweck | Serverpfad | Eigentümer |
|---|---|---|
| Wallet DEV | `/var/lib/clubunio-google-wallet/dev/service-account.json` | www-data |
| Wallet Stage | `/home/runcloud/google-wallet/stage/service-account.json` | runcloud |
| Wallet PROD | `/home/runcloud/google-wallet/prod/service-account.json` | runcloud |
| FCM PROD | `/home/runcloud/firebase/prod/service-account.json` | runcloud |

Ordner0700, Dateien0600, ausserhalb aller Webroots/Git/Appassets. DEV via SSH,
Stage/PROD ebenfalls verschlüsselt übertragen. Alle drei Wallet-Prüfungen als
Appbenutzer erfolgreich: OAuth und GET genericClass für den oben genannten
Aussteller200, keine Klassen-/Passerstellung oder Datensatzänderung.
Expliziter Operatorbefehl: `php deploy/check-google-wallet-credentials.php --verify dev|stage|prod`.
Er prüft Umgebung, Datei/600, Konto/Projekt/Tokenziel/RSA und gibt nur technische
Statuswerte aus. Dieser frühere reine Zugangstest ist inzwischen durch die unten
beschriebene fachliche Integration und echte synthetische Provider-Schreibtests ergänzt.

## PROD-Push aktiviert

FCM-Prüfbefehl `php deploy/check-prod-push-credentials.php --verify` verwendet
die eigene PROD-Identität, OAuth-Scope firebase.messaging und ausschliesslich
`validate_only=true` mit synthetischem Topic. ErgebnisHTTP200, null Zustellungen.
Kein Gerätetoken, Token oder Nachrichteninhalt in der Ausgabe.

PROD hatte beide Pushflagsfalse und eine leere Registry. Nach privater Sicherung
beide Flags aktiviert. Zwei gezielte Core-Einträge:
`ios:com.my.clubunio.core` und `android:com.my.clubunio.core`, jeweils
Origin `https://app.clubunio.com`, Projekt clubunio-e8d01, kein fester Mandant,
dedizierte PROD-Credentials. Kein SV-Sissach-Testbuild auf PROD freigegeben;
künftige produktive Vereinsbuilds benötigen ihre exakte Kennung und eine frisch
verifizierte Vereinsdomain-/Mandantenbindung.

Auch der globale authentifizierte Mobile-Zugang war auf PROD ausgeschaltet,
wodurch die Geräteregistrierung503 lieferte. Als notwendige Voraussetzung der
beauftragten Push-Aktivierung `CLUBUNIO_MOBILE_ENABLED=true` gesetzt. Keine
zusätzlichen Modul-/Rollen-/Registrierungsrechte erteilt. /up200 und context401
ohne Login auf DEV/Stage/PROD geprüft; frische Core-Registryprüfung iOS/Android
und Push-Capability auf PRODtrue.

Der Live-Stand unterstützte bereits die Plattformpräfixe und zusätzliche
Stage-Pilotbedingungen. Diesen Stand nicht mit lokalen älteren Dateien
überschrieben. Gemeinsame Präfixunterstützung gezielt im eigenen Sourcebestand
nachgezogen; Legacy-Registry bleibt kompatibel. Auf PROD nur eine zusätzliche
Native-ID-Eingabeprüfung gegen gefälschte interne Präfixe eingefügt; übrige
Live-Abweichungen erhalten. Tests für gemeinsame native ID, Legacy-Fallback,
falsche Plattform/Origin, Vereinsbindung, Configvalidierung und Capability.

Hintergrunddienst [clubunio-prod-mobile-push.service](clubunio-prod-mobile-push.service)
installiert, systemd-verify bestanden, enabled/active/running als runcloud.
Ein Prozess mit flock, Polling und 300s Watch, Neustart1s, limit100,
TimeoutStop45s, private temporäre Dateien, schreibbarer Bereich nur Runtimecache/
Logs. Expliziter erster Workeraufruf verarbeitete0 Einträge; Bestand vor
Aktivierung:0 Geräte/0 Outbox. Keine künstliche Testbindung oder echte Nachricht.

Private Rückwege:

- `/root/prod-mobile-push-activation-20261122-212403`: Konfiguration vor Pushflags/Registry.
- `/root/prod-mobile-api-activation-20261122-212547`: vor Mobile-Basisfreigabe.
- `/root/prod-push-native-id-guard-20261122-212802`: vorherige Registryklasse.

Zum Abschalten zuerst Worker stoppen/deaktivieren, Push-Deliveryflagfalse und
Konfigurationscache als runcloud erneuern. Private Konfigurationssicherungen
gezielt vergleichen; keine anderen inzwischen geänderten Werte überschreiben.
Schlüssel bei Rotation erst nach erfolgreichem Austausch und Prüfung im
Google-Portal sperren; keine Geräte-/Outboxhistorie löschen.

Abnahme: drei echte Wallet-OAuth-/API-Prüfungen, echte FCM-Dry-Run-Prüfung200,
14 lokale Tests/35Assertions erfolgreich; PHP-Lint der Operatorbefehle,
laufender Dienst und HTTPS geprüft. Physische FCM-/APNs-Zustellung und native
Firebase-Konfiguration auf dem Benutzergerät bleiben zu prüfen. Kein Nachweis
einer Storeveröffentlichung oder Google-Publishingfreigabe.

Referenzen: [Google REST-Einrichtung](https://developers.google.com/wallet/generic/getting-started/auth/rest),
[Firebase-FCM-Rollen](https://firebase.google.com/docs/projects/iam/roles-predefined-product).


## Implementierung und Live-Rollout vom 22.11.2026

`GoogleWalletClient` und `GoogleMemberWalletService` sind unabhängig von Apples
Signierkonfiguration. `MobileMemberIdCardData::show()` bleibt die gemeinsame
Berechtigungsprüfung für eigene/vertretene Kinder sowie berechtigte Verwaltung.
Reine Team-/Kontakt-Sichtbarkeit berechtigt weiterhin nicht zum privaten Ausweis.
SourceVersion und Rechte werden vor und nach der Vorbereitung erneut geprüft.

Android erhält `Wallet.GoogleAvailable` im Ausweis-DTO und eine Hinzufügen-Aktion:
`POST api/mobile/v1/clubs/{tenant}/members/{member}/id-card/wallet/google`, Body
`{SourceVersion: <64 hex>}`, Antwort `data.SaveUrl` und `data.SourceVersion`.
Öffnung extern nur für exakt `https://pay.google.com/gp/v/save/<JWT>`; kein SDK,
kein Dienstkontoschlüssel im Build und kein zusätzlicher Android-Signaturfingerabdruck
für diesen Web-Speicherablauf. Abbruch bei Konto-/Mandanten-/Versionswechsel.
Der Browseraufruf wird nicht als erfolgreiche Speicherung ausgegeben.

Der Pass übernimmt ausschliesslich Name, Verein, Gültigkeit, Vereinsfarben,
normalisiertes Vereinslogo und die bestehende öffentliche QR-Prüfadresse.
Keine AHV, Geburtstage, Kontakt-/Finanzdaten oder Dokumente an Google. Bei
ungültiger Mitgliedschaft werden Name ausgeblendet und Objekt auf INACTIVE gesetzt.
Die deutsche/englische Wallet-Projektion verwendet die zentralen lokalen Kataloge;
Flutter pflegt die vorhandenen fünf Kataloge. Keine Übersetzungs-API.

Logo-Endpunkt: `GET /wallet/google/artwork/{opaqueUUID}/{sha256}.png` unter der
kanonischen HTTPS-Origin; ausschliesslich normalisiertes Vereinslogo, kein Name,
Mitglieder-ID oder QR-Token im Bildpfad. Er wird mit aktuellem gespeicherten Logo
bedient. Nach fehlgeschlagener Logoaktualisierung kann eine alte Google-Bildadresse
bis zum erfolgreichen Abgleich vorübergehend 404 liefern.

Neue additive Tabelle `member_google_wallet_passes`, Migration
`2026_11_22_000130_google_wallet`, auf allen drei Ziel-Datenbanken eingespielt.
Projektquelle verwendet clubora, Live-Schema clubunio. Stabile UUID pro Karte,
Umgebungs- und Fremdschlüssel, eindeutige Objekt-ID, verschlüsselte Projektion/PNG,
verschlüsselter OAuth-Cache, Fingerprints, Fehler-/Backoffzustand. Keine Save-JWTs,
Private Keys oder Mitgliederdaten in Operatorausgaben und Git.

### Umgebungsgrenzen und Konfiguration

| Umgebung | Kanonische Wallet-/QR-Origin | Class-ID-Suffix |
|---|---|---|
| DEV | https://dev.clubunio.ch | clubunio_dev_membercard_v1 |
| Stage | https://stage.clubunio.com | clubunio_stage_membercard_v1 |
| PROD | https://app.clubunio.com | clubunio_prod_membercard_v1 |

Alle IDs beginnen mit Issuer `3388000000023233410.`. Objekt-ID-Suffixe enthalten
nur `clubunio_{environment}_{opaqueUUIDohneBindestriche}`. Keine Mitgliedernummer.
Die Klasse verwendet ONE_USER_ALL_DEVICES. Der Client prüft Environment, App-Origin,
Dienstkonto-E-Mail, Projekt, RSA, private Datei und feste Google-Endpunkte.
Andere Umgebungen/Fremdklassen werden vor Provideränderungen abgewiesen.
Vereinsbuilds bleiben an ihre freigegebenen Vereinsdomains und Mandanten gebunden;
keine zusätzliche produktive SVS-Testkennung oder Domainfreigabe erteilt.

Konfiguration in privater Umgebung (Werte keine Secrets ausser externer Schlüsseldatei):
`GOOGLE_WALLET_ENABLED=true`, `GOOGLE_WALLET_ENVIRONMENT=dev|stage|prod`,
`GOOGLE_WALLET_ISSUER_ID`, `GOOGLE_WALLET_PROJECT_ID`,
`GOOGLE_WALLET_SERVICE_ACCOUNT_EMAIL`, `GOOGLE_WALLET_CREDENTIALS`,
`GOOGLE_WALLET_PUBLIC_ORIGIN`. Private Pfade siehe Zugangstabelle oben.

### Hintergrundaktualisierung und Wiederholungen

`php artisan clubunio:google-wallet-refresh --limit=100` prüft ausgegebene Karten
rotierend mit 40s Batchbudget. Netzwerk pro Google-Aufruf maximal8s, Connect3s;
ein bereits laufender Objektabgleich darf das Batchbudget überschreiten.
DEV: bestehender Minutenscheduler. Stage/PROD: ergänzte Wallet-only-Cronjobs mit
je eigenem flock/privatem Log, keine übrigen Anwendungsschedules aktiviert.
Erste echte Workeraufrufe überall checked0/failed0; es wurden keine echten
Mitgliederausweise testweise an Google übertragen.

Unveränderte Fingerprints verursachen keine Google-API-Änderung. GET/INSERT mit
409-Abgleich und danach PATCH, stets gleiche Objekt-ID auch nach Timeout.
Kurztransaktion nur zur lokalen Erstzuordnung; Provider-I/O ausserhalb der
Transaktion mit Objektlock. Nach dem Provideraufruf lokale Revision erneut prüfen.
Fehler sind dauerhaft mit begrenztem exponentiellem Retry gespeichert; geänderter
Status umgeht alten Backoff. Rotierende Auswahl vermeidet Hunger durch Fehlerfälle.

Wallet-Anzeige ist asynchron. Ein Minutentakt ist keine Garantie für Zustellung
innerhalb einer Minute: Batchgrösse, Anzahl ausgegebener Pässe, Fehler und Googles
Gerätesynchronisierung beeinflussen die Verzögerung. Der QR-Scan prüft sofort
gegen den aktuellen Backendstatus, einschliesslich Widerruf/Tokenrotation, und
ist für Partner verbindlich. Bei Scan keine alten Wallet-Anzeigen als Beleg nehmen.

### Verifikation, Rückweg und offene Abnahme

33 lokale Backendtests /200 Assertions erfolgreich, einschliesslich Apple-Regression,
Google-Client/-Service, Status-/Revision-/Retry-/Environment-/Privacy-Prüfungen.
3044 Übersetzungsschlüssel DE/EN und Literalprüfung grün. Mobile:20 fokussierte
Tests bestanden,1 optionaler Capture-Skip; gezielte Analyse und i18n/AST grün.

Auf DEV, Stage und PROD jeweils:

- `php deploy/check-google-member-wallet.php --synthetic-rollback clubunio_<env>`:
  echte PostgreSQL-Fixture inklusive DTO/Berechtigungspfad, stabiler Objekt-ID,
  DE/EN QR-Seite, PNG-HTTP-Ausgabe, veralteter SourceVersion, Widerruf und Datenschutz;
  alle synthetischen Datenbankzeilen anschliessend zurückgerollt, Provider ersetzt.
- `php deploy/check-google-wallet-provider.php --synthetic-provider <env>`:
  echte Google-Klasse, synthetisches INACTIVE-Objekt mit vollständiger Projektion,
  wiederholtes PATCH und signierter kompakter Referenzlink. Keine realen
  Mitgliederdaten, kein echter Pass in einem Google-Konto gespeichert.
  Reservierte synthetische UUID 00000000-0000-4000-8000-000000000001 je Umgebung.
- HTTPS /up200 und unauthentifizierter context401 auf allen drei Core-Origins und
  beiden SVS-Testdomains. Privilegien/Modul-/Registrierungsrechte unverändert.

Private Datenbank-/Konfigurationssicherungen vor dem Rollout:
`/root/google-wallet-integration-20261122-214214` auf VPS und
`/var/lib/clubunio-google-wallet/dev/integration-20261122-214214` auf DEV.
Nur bestehende Walletdateien mit geprüftem Ausgangsstand ersetzt; sonstige
Live-Abweichungen erhalten. Erst `.env`-Freigabe abschalten und Konfigcache als
Appbenutzer erneuern, dann Google-Cronjob aussetzen, wenn ein Rückweg nötig ist.
Installierte Ausweise vor einer bewussten dauerhaften Stilllegung auf INACTIVE
setzen; Tabelle/Schlüssel nicht blind entfernen. QR-Prüfung unabhängig erhalten.

Google-Konsole aktuell ausdrücklich geprüft: Businessprofile abgeschlossen,
Publishing access1/2. Publishingantrag inzwischen ueber Support eingereicht (siehe unten). Google-Freigabe und reales
Speichern auf Android bleiben offen. Eine API200 ist keine Publishingfreigabe.

Referenzen: [GenericObject](https://developers.google.com/wallet/reference/rest/v1/genericobject),
[GenericClass](https://developers.google.com/wallet/reference/rest/v1/genericclass),
[Save-JWT](https://developers.google.com/wallet/generic/use-cases/jwt),
[Pass-Updates](https://developers.google.com/wallet/generic/use-cases/updates).


## Publishingantrag eingereicht am22.11.2026

Nach ausdruecklicher Benutzerbestaetigung den Publishingzugang fuer Issuer
3388000000023233410 beantragt. Der regulaere Request-Button blieb ohne Dialog,
Statusaenderung oder Bestaetigung, auch nach Reload und in einem frischen Tab.
Keine erfolgreiche Einreichung ueber diesen Button behauptet.

Stattdessen den freigegebenen Antrag im offiziellen Contact-support-Formular
unter Google Wallet API Integration / Integration troubleshooting abgeschickt.
Beschreibung: Publishingfreigabe fuer ClubUnio, Issuer/Businesskennung,
Mitgliederausweise/QR-Livepruefung, funktionierende synthetische API-Tests,
PROD-URL https://app.clubunio.com und der nicht reagierende Request-Button.
Keine Schluessel, Tokens oder echten Mitgliederdaten uebermittelt.

Die Konsole bestaetigte Submitted und nennt bis zu36 Stunden fuer eine Rueckmeldung
per E-Mail durch einen Spezialisten. Das ist die Eingangs-/Supportbestaetigung,
keine Publishingfreigabe und kein Nachweis eines formalen Pending-Status im
Publishing-Assistenten. Dort zuletzt weiterhin1/2. Keine Fallnummer angezeigt.
Keine zweite Supportanfrage erzeugt. Android-Speichertest und Google-Freigabe
bleiben offen; naechster Schritt ist die Antwort des Google-Spezialisten.
