# Apple Wallet für Vereinsausweise

Stand: 22.11.2026. Apple Wallet auf DEV, Stage und nach ausdrücklicher weiterer
Benutzerfreigabe auch PROD aktiviert. Drei echte Apple-Zertifikate mit den
getrennten privaten Schlüsseln und der Apple-Root-/WWDR-G4-Kette geprüft.
Physische iPhone-Abnahme und echte Wallet-Push-Zustellung noch offen.
App-/Firebase-APNs-Schlüssel werden hierfür nicht verwendet.

Webruntime-Fix22.11.2026: Private Zertifikatspfade waren durch RunClouds
FPM-open_basedir gesperrt. Stage/PROD gezielt korrigiert, Freigabe und synthetische
Signierung jetzt auch im wirklichen FPM-Prozess geprueft.
[Ursache, Betrieb und Nachweise](CLUBUNIO-WALLET-RUNTIME.md).

## Aktueller Umfang

- Persönlich autorisierter Download unter
  `GET /api/mobile/v1/clubs/{tenant}/members/{member}/id-card/wallet/apple?SourceVersion=...`.
  Vor und nach Erzeugung frische Ausweisautorisierung und Versionsvergleich,
  MIME `application/vnd.apple.pkpass`, SHA256 und Ausweisversionsheader.
- Signiertes PassKit-ZIP mit manifest.json, echter abgesetzter CMS-Signatur,
  Vereinslogo/-farbe, Name, Status und QR-Webadresse. Keine Adresse,
  Geburtsdaten, AHV, E-Mail oder Finanzdaten im Pass.
- Stabile Pass-Seriennummer und separates verschlüsseltes Wallet-Geheimnis.
  QR-Berechtigung und ApplePass-Authentifizierung sind getrennt.
- Die Apple-Protokollrouten unter `/wallet/v1/` unterstützen Anmeldung,
  Abmeldung, geänderte Seriennummern, aktuellen Pass und verworfene Logs.
  HTTPS/Hostbindung, Drosselung, Körpergrenze, private Antworten, keine
  Cookie-Anmeldung/CSRF-Abhängigkeit.
- `clubunio:member-wallet-refresh --limit=200` vergleicht aktuellen Status,
  Austritt, Sperre, QR-Neuausgabe, Vereinsdesign und Sprachkataloge.
  Änderungstags stammen aus einer globalen Datenbank-Identity; mehrere
  Änderungen innerhalb einer Sekunde gehen nicht verloren.
- APNs verwendet das Pass-Zertifikat mit HTTP/2 und leerem JSON-Payload.
  Registrierungen bilden eine dauerhafte Benachrichtigungswarteschlange:
  erst nach Erfolg wird die Version als versandt markiert. Exponentieller
  Rückversuch, maximal acht Versuche pro Änderungsstand und ein Zeitbudget pro
  Workeraufruf; ein neuer Stand setzt
  die Versuche zurück. Entfernte/ungültige APNs-Geräte werden ausgetragen.
- PassKit-Sprachdateien entstehen nur für zentral aktivierte Sprachen, aus
  den lokalen Backendkatalogen. Benutzer-/Vereinsnamen werden nicht übersetzt.

Ein Wallet-Pass ist kein ständig verbundenes Dashboard. Betriebssystem und
APNs können Updates verzögern. Verbindlich für Partner ist die aktuelle
Webprüfung nach QR-Scan. Ein QR-Code ist weitergebbar; ein Partner muss bei
Bedarf zusätzlich die Identität der vorzeigenden Person prüfen. Ein gültiger
Mitgliedschaftsstatus begründet nicht automatisch einen bestimmten Rabatt.

## Apple einrichten: Schritt für Schritt

1. Im bestehenden Apple-Developer-Team **Certificates, Identifiers & Profiles**
   öffnen. Unter **Identifiers** einen neuen **Pass Type ID** registrieren.
   Getrennte IDs verhindern Überschreiben zwischen Umgebungen, beispielsweise
   `pass.com.clubunio.dev.membercard` und `pass.com.clubunio.stage.membercard`.
   PROD-Zertifikatsvorbereitung ist ausdrücklich freigegeben, Aktivierung erst
   beim freigegebenen Produktivstart. Diese IDs sind nicht
   die Bundle-ID einer App und erfordern keinen separaten Store-Eintrag.
   [Apple-Anleitung](https://developer.apple.com/help/account/capabilities/create-wallet-identifiers-and-certificates).
2. Für jede Umgebung einen privaten Ordner ausserhalb des Webroots anlegen.
   Beispiel für die **Stage**-Dateien auf dem Server; zunächst Schlüssel und
   CSR erstellen. Die private Datei verlässt diesen sicheren Speicher nicht.

   ```sh
   umask 077
   mkdir -p /home/runcloud/wallet/stage
   openssl genrsa -out /home/runcloud/wallet/stage/pass-key.pem 2048
   openssl req -new -sha256 \
     -key /home/runcloud/wallet/stage/pass-key.pem \
     -out /home/runcloud/wallet/stage/pass.csr
   ```

   CSR-Angaben interaktiv erfassen. Nur die `.csr`-Datei wird im nächsten Schritt
   an Apple übergeben; keinen privaten Schlüssel in Chat oder Git laden.
   Alternativ die CSR im macOS-Schlüsselbund erstellen und anschliessend
   Schlüssel und Zertifikat sicher als PEM für den Server exportieren.
3. Im Apple-Portal unter **Certificates → + → Pass Type ID Certificate** die
   Stage-Pass-ID auswählen und die Stage-CSR hochladen. Zertifikat herunterladen.
   Das Zertifikat muss zur CSR und damit zum privaten Stage-Schlüssel passen.
4. Apples `.cer` als `pass.cer` in den privaten Ordner übertragen und in PEM
   umwandeln. Das zur Zertifikatsaussteller-Zeile passende **Worldwide Developer
   Relations**-Zwischenzertifikat von [Apple PKI](https://www.apple.com/certificateauthority/)
   herunterladen, ebenfalls als PEM speichern. Nicht blind eine Generation
   wählen: `issuer` des Passzertifikats muss zu `subject` des Zwischenzertifikats passen.

   ```sh
   openssl x509 -inform DER -in /home/runcloud/wallet/stage/pass.cer \
     -out /home/runcloud/wallet/stage/pass-cert.pem
   openssl x509 -inform DER -in /home/runcloud/wallet/stage/wwdr.cer \
     -out /home/runcloud/wallet/stage/wwdr.pem
   openssl x509 -in /home/runcloud/wallet/stage/pass-cert.pem \
     -noout -subject -issuer -dates
   openssl x509 -in /home/runcloud/wallet/stage/wwdr.pem -noout -subject -dates
   ```

   Aus `subject` übernehmen: `UID` ist die Pass-Type-ID, `OU` die Team-ID.
   Die Implementierung prüft diese Werte, Gültigkeitsdaten, passenden privaten
   Schlüssel sowie die Signatur des Passzertifikats mit dem Zwischenzertifikat.
5. Den privaten Ordner nur für den jeweiligen App-/Scheduler-Benutzer lesbar
   machen. Bei RunCloud gehören die Dateien zum App-Benutzer; Ordner `0700`,
   Schlüssel `0600`. Keine Dateien im `public`-Ordner ablegen.
6. Folgende Werte in der privaten Stage-Konfiguration setzen; DEV erhält eigene
   Pfade und eigene Pass-ID. Das optionale Schlüsselpasswort gehört in den
   vorhandenen Secret-Speicher. Keine Werte in Git ablegen.

   ```dotenv
   CLUBUNIO_WALLET_ENABLED=true
   CLUBUNIO_WALLET_PASS_TYPE_IDENTIFIER=pass.com.clubunio.stage.membercard
   CLUBUNIO_WALLET_TEAM_IDENTIFIER=<OU aus dem Zertifikat>
   CLUBUNIO_WALLET_CERTIFICATE_PATH=/home/runcloud/wallet/stage/pass-cert.pem
   CLUBUNIO_WALLET_PRIVATE_KEY_PATH=/home/runcloud/wallet/stage/pass-key.pem
   CLUBUNIO_WALLET_PRIVATE_KEY_PASSWORD=
   CLUBUNIO_WALLET_WWDR_CERTIFICATE_PATH=/home/runcloud/wallet/stage/wwdr.pem
   CLUBUNIO_WALLET_WEB_SERVICE_ORIGIN=https://stage.clubunio.com
   ```

   Es werden PHP OpenSSL/CMS, ZipArchive, GD und cURL mit HTTP/2 benötigt.
   Der Server muss `https://api.push.apple.com:443` erreichen können.
   `config:cache` als App-Benutzer neu erstellen und Capability prüfen:
   `app(App\ClubUnio\Wallet\MemberWalletService::class)->available()` muss
   `true` liefern. Bei fehlendem Vereinslogo bleibt die Vereins-Capability aus.
7. Sicherstellen, dass die beiden additiven Karten-/Walletmigrationen angewendet
   und die expliziten Laufzeitrechte erteilt sind. Der bestehende Laravel-
   Minutenscheduler führt den überlappungsgeschützten Walletbefehl aus; dessen
   Ausgabe enthält nur technische Summen, keine Token oder Namen.
8. App auf dem Mac aktualisieren und auf einem physischen iPhone starten.
   Einen autorisierten Mitgliederausweis öffnen, **Zu Apple Wallet hinzufügen**
   wählen und den nativen Dialog bestätigen. Danach Registrierung und QR-Ziel
   prüfen. Der iOS-Simulator bestätigt keine echten Wallet-Push-Updates.
9. Mit isolierten Testdaten den Mitgliedschaftsstatus ändern, den Scheduler
   ausführen und die Aktualisierung im Wallet kontrollieren. Anschliessend
   Ausweis sperren: QR-Webprüfung muss sofort ungültig zeigen; Wallet folgt per
   Update als `voided`. Abmelden des Passes muss die Registrierung entfernen.
   Rollen-/Mandantengrenzen und Core-/Custom-Build auf dem Gerät mitprüfen.

Serveraktivierung auf allen drei Instanzen abgeschlossen; Geräteabnahme offen.
Die Tests verwenden ausschliesslich eine synthetische CA und bestätigen keine
Apple-Ausstellung, reale APNs-Zustellung oder iPhone-Abnahme.

## Erneuern und zurückrollen

Zertifikatsablauf überwachen und vor Ablauf im Apple-Portal für dieselbe
Pass-Type-ID erneuern. Team-ID, Seriennummern und Authentifizierungstoken
installierter Pässe beibehalten. Neue Zertifikatsdateien atomar einsetzen,
Konfigurationscache erneuern, Capability und Update prüfen. Der Zertifikats-
Fingerprint invalidiert die Paketkopie; bestehende Seriennummern bleiben stabil.

Zum vorübergehenden Abschalten `CLUBUNIO_WALLET_ENABLED=false` setzen und
Konfigurationscache erneuern. Die QR-Webprüfung bleibt unabhängig davon aktiv.
Keine Wallettabellen leeren und keine QR-Token neu erzeugen, um Updates zu
erzwingen. Eine Sperre soll als ungültiger Pass verteilt werden, bevor die
Updatezustellung endgültig ausser Betrieb genommen wird.

Protokollreferenz: [Apple – Updating a Pass](https://developer.apple.com/library/archive/documentation/UserExperience/Conceptual/PassKit_PG/Updating.html).

## Technische Prüfungen

```sh
php vendor/bin/phpunit tests/Feature/ApplePassBundleTest.php tests/Feature/MemberWalletTest.php
```

Zehn Tests mit 73 Assertions: echtes synthetisch signiertes Paket,
Manifest-/Assets-/Sprachprüfung, abgesetzte Signatur mit Manipulationsabwehr,
Pass-ID-/Team-/Zwischenzertifikatsabgleich, private Authentifizierung,
Gerätezuordnung, identische Sekunde mit mehreren globalen Änderungstags,
stabile Seriennummer/Geheimnis, Statussperre, APNs-Rückversuch/410-Auswertung,
HTTPS/Host-/Grössenprüfung und vollständig inaktiver Zustand ohne DBabfrage.


## Gemeinsame Vorbereitung aller drei Umgebungen (22.11.2026)

Benutzer hat die Pass-IDs angelegt und die Zertifikatsvorbereitung für alle drei
beauftragt. Vereinsbuilds verwenden dieselbe umgebungsbezogene Pass-ID wie Core;
Logo und Farben bleiben pro Verein. Keine eigene Pass-ID je Vereinsbuild nötig,
solange dieselbe Apple-Team-Signierung verwendet wird.

| Umgebung | Pass Type ID | CSR-Datei |
|---|---|---|
| DEV | `pass.com.clubunio.dev.membercard` | `clubunio-dev.certSigningRequest` |
| Stage | `pass.com.clubunio.stage.membercard` | `clubunio-stage.certSigningRequest` |
| PROD | `pass.com.clubunio.membercard` | `clubunio-prod.certSigningRequest` |

Drei getrennte RSA-2048-Schlüssel und SHA-256-CSRs auf dem VPS vorbereitet.
Private Ablage `/root/clubunio-wallet-csr/{dev,stage,prod}` ausserhalb aller
Webroots, Ordner0700/Dateien0600. CSR-Selbstsignaturen mit OpenSSL geprüft.
Nur die öffentlichen CSRs nach
`C:\Users\Administrator\Downloads\ClubUnio-Wallet-CSR` kopiert; keine Schlüssel
auf den Arbeitsplatz oder ins Repository übertragen. Schlüssel später sicher
in den jeweiligen App-Speicher überführen; PROD-Vorbereitung aktiviert keinen
Produktivdienst. Wallet bleibt bis Zertifikatsimport deaktiviert.

Nächster Benutzerschritt je Umgebung: Apple Developer → Certificates → + →
Pass Type ID Certificate; passende Pass-ID und dazugehörige CSR wählen.
Ausgestelltes `.cer` sofort eindeutig als `clubunio-dev.cer`,
`clubunio-stage.cer`, `clubunio-prod.cer` benennen. Nach Bereitstellung Zertifikat/
Schlüsselabgleich, WWDR-Kette und Laufzeitrechte prüfen; DEV/Stage aktivieren und
auf echtem iPhone testen. PROD-Zertifikat lediglich sicher vorbereiten.

## Abschluss: alle drei Instanzen aktiviert (22.11.2026)

Der Benutzer hat nachträglich auch die PROD-Aktivierung ausdrücklich freigegeben.
Die vorherige Beschränkung auf Vorbereitung ist damit aufgehoben. Zertifikate:
Team `XP7296D478`, passende UID je Umgebung, passende private Schlüssel,
vollständige Apple-Root-/WWDR-G4-Kette mit OpenSSL erfolgreich verifiziert.
Alle drei Zertifikate laufen am **22.12.2027** ab; rechtzeitig erneuern.
Zwischen- und Rootzertifikat ausschliesslich über HTTPS von Apple PKI bezogen.

| Umgebung | Private Laufzeitablage | Wallet-/QR-Origin |
|---|---|---|
| DEV | `/var/lib/clubunio-wallet/dev` | `https://dev.clubunio.ch` |
| Stage | `/home/runcloud/wallet/stage` | `https://stage.clubunio.com` |
| PROD | `/home/runcloud/wallet/prod` | `https://app.clubunio.com` |

Ordner0700/Dateien0600, DEV-Eigentümer www-data, Stage/PROD runcloud. DEV-Schlüssel
zwischen SSH-Verbindungen übertragen, keine lokale Schlüsseldatei. Keine
Zertifikate/Schlüssel im Git. Konfigurationscache als Webserverbenutzer erneuert.
Core und Vereinsbuild verwenden dieselbe Pass-ID je Umgebung.

DEV nutzt den vorhandenen Minutenlauf clubora-scheduler.timer als www-data.
Auf dem VPS war kein Laravel-Minutenlauf nachweisbar. Daher führt
`/etc/cron.d/clubunio-wallet-refresh` nur den Walletbefehl für Stage/PROD aus:
jede Minute, limit200, separater flock, umask077, private technische Summenlogs
im jeweiligen Walletordner. Keine weiteren Scheduleraufträge aktiviert.
Beide Cronläufe anhand der Logs nachgewiesen: configured=true, keine Fehler,
noch keine Geräte registriert. Referenz: [Wallet-Cron](clubunio-wallet-refresh.cron).

Bestehende root-owned Stage-Cachedateien sowie admin-owned DEV-Cachedateien und
Tageslog waren für den Appbenutzer nicht beschreibbar. Nur betroffene
Runtime-Caches und das Tageslog korrigiert. Eigentümerinventare privat unter
`/root/wallet-cache-ownership-20261122-203251.json` beziehungsweise auf DEV
`shared/wallet-cache-ownership-20261122-203251.json`.

### PROD-Delta und Rückweg

PROD unter `/home/runcloud/webapps/ClubUnioProd`, Datenbank clubunio_prod, hatte
das Ausweis-/Walletdelta noch nicht. 26 gezielte Dateien anhand des ursprünglichen
Ausweisrelease-Manifests bereitgestellt; vorhandene Baselines per SHA256 geprüft,
PHP-Lint vor Austausch. Gemeinsame Routen/Scheduler nur additiv ergänzt, keine
Event-/sonstigen Features übertragen, RunCloud-Eigentümer sichergestellt.

Migrationen 000100_member_cards und 000110_member_wallet gezielt nach geprüftem
pg_dump angewandt: Datenbankidentität, fehlende Zielobjekte, Transaktion,
Advisorylock und Journal-Lock. Nur die SQL-Blöcke dieser Migrationen verwendet;
generische DEV/Stage-Migrationsschutzschalter nicht aufgeweicht. Live-Schema
clubunio, Tabellen-/Sequenzrechte für userunio. Keine Altdaten importiert.
PROD enthält aktuell keine Mitgliedschaftsstatus; bei späterer Einrichtung muss
deren Ausweisberechtigung ausdrücklich konfiguriert werden.

Private Sicherungen:

- DEV Konfiguration: `shared/wallet-activation-20261122-202652`.
- Stage Konfiguration: `/root/wallet-activation-20261122-202651`.
- PROD Code/Manifest: `/root/wallet-prod-release-20261122-202855`.
- PROD Katalog-/Migrationsjournalbackup: `/root/wallet-prod-db-preflight-20261122-202915`.
- PROD Konfiguration: `/root/wallet-prod-config-20261122-202937`.

Rückweg: Wallet pro Instanz deaktivieren, config:cache als Appbenutzer erneuern,
betroffene VPS-Cronzeile aussetzen. Daten und Seriennummern erhalten. Das gezielte
PROD-Preflightbackup ist kein Vollbackup. Code anhand Manifest/Bestandssicherung
zurücknehmen; nach ausgegebenen Pässen zuerst Widerrufs-/Updatezustellung beachten.

### Nachweise und offene Geräteabnahme

- Echte Zertifikate signieren auf allen drei Instanzen synthetische .pkpass-Dateien;
  ZIP/Manifest und abgesetzte CMS-Signatur geprüft, Testpakete danach gelöscht.
  Prüfbefehl: `php deploy/check-wallet-signing.php --synthetic-signing`.
  Kein Datenbankzugriff, keine APNs-Geräteanfrage.
- PostgreSQL-Fachprüfung auf DEV/Stage/PROD als Appbenutzer erfolgreich: DTO und
  Vereinsbranding, QR-Webprüfung DE/EN, Datenschutz, Statussperre, Neuausgabe,
  Mandantentrennung, Walletrevision und Authentifizierung. Alle synthetischen
  Daten zurückgerollt. Fehlender PROD-Admin-Rollenkatalog nur innerhalb der
  Testtransaktion ergänzt; anschliessend null Testvereine, Pässe, Registrierungen.
- Extern alle drei Origins: /up200, unbekannter QR ungültige HTML-Seite,
  unautorisierter Walletpass401.
- APNs per TLS/HTTP2 mit jeweiligem Zertifikat erreichbar, HEAD405/version2.
  Dies bestätigt Verbindung, keine echte Push-Zustellung.
- Zehn Backendtests/73Assertions, PHP-Lint und zentrale DE/EN-Prüfung mit
  3044 Schlüsseln erfolgreich.

Noch offen: aktuellen nativen Build auf Benutzer-Mac erstellen, echten Ausweis
auf physischem iPhone zu Wallet hinzufügen, Geräteanmeldung und Statusänderung/
Widerruf samt APNs-Update testen. Simulator/TLS-Test ersetzen keine Geräteabnahme.
Google Wallet ist ein separater nächster Einrichtungsschritt.


## iPhone-Signierung und Associated Domains, 22.11.2026

Apple-Portal unter Team XP7296D478 geprueft. Core com.my.clubunio.core hat
Associated Domains, Push Notifications und Wallet bereits aktiv. Die bislang
fehlende Test-App-ID com.my.sportvereinSissach.test als My SV Sissach Test mit
diesen drei Capabilities registriert und im gespeicherten Stand bestaetigt.
Produktive alte SVS-App und bestehende Zertifikate nicht veraendert.

Portal zeigt ein gueltiges Development- und ein Distribution-Managed-Zertifikat
bis20.07.2027. Manuelle Altprofile APP_DEV/APP_PRD zeigen Invalid und wurden
nicht ersetzt. Sichtbare Geraeteliste enthaelt nur aeltere Geraete sowie den
Hinweis zur jaehrlichen Geraetepruefung. Keine UDIDs in die Doku uebernommen.

Benutzer hat ausdruecklich Registrierung des aktuellen iPhones und Profile
ueber automatische Xcode-Signierung auf dem Mac gewaehlt. Deshalb kein
manuelles Testprofil fuer falsche Altgeraete erzeugt und keine Geraete geloescht.
Auf dem Mac muss Xcode das Teamkonto, einen gueltigen privaten Signierschluessel
und das registrierte aktuelle iPhone verwenden; lokale Profilerzeugung und
Installation konnten von diesem Windows-Arbeitsplatz nicht ausgefuehrt werden.

Die gemeinsame Appquelle setzt jetzt CODE_SIGN_STYLE=Automatic als ueberschreibbaren
Default. Das Markenwerkzeug prueft bei signierten Geraetebuilds die wirklichen
Associated Domains exakt gegen das Core-/Vereinsprofil (neben App-ID/APNs/Signatur).
15 Markenbuildtests erfolgreich,1 bestehender plattformbedingter Skip, inklusive
fehlender/falscher/zusaetzlicher signierter Domains.

Parallel eingegangener Mac-Nachweis 27a7579: Core-Build15 erfolgreich als IPA aus
dem bestehenden Archiv a0596e9 exportiert, Distributionsblocker behoben. Dieses
Archiv enthaelt DEV-Standard und vier Domains; fuer den aktuellen PROD-Stand
mit fuenf Core-Domains weiterhin nativ neu bauen. Keine Storeveroeffentlichung.

Die genaue Mac-Einrichtung und beide Buildbefehle stehen in
[IOS-DEVICE-SIGNING.md](https://github.com/davide-peixoto/clubunio_mobile/blob/main/docs/IOS-DEVICE-SIGNING.md).
Apple Wallet und Event-QR weiterhin auf physischem iPhone abnehmen; Portal-
Capabilities und Buildnachweise sind keine bestaetigte Ende-zu-Ende-Abnahme.
