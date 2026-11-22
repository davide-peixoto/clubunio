# Apple Wallet für Vereinsausweise

Stand: 22.11.2026. Implementiert, aber mangels Apple-Pass-Zertifikat noch nicht
aktiviert. Die bereits vorhandenen App-/Firebase-APNs-Schlüssel ersetzen das
Pass-Type-Zertifikat nicht. Apple-Zertifikate fehlen weiterhin. Auf ausdrücklichen Benutzerauftrag wurden
getrennte private Schlüssel und CSRs für DEV, Stage und PROD vorbereitet;
keine Aktivierung und keine echten Push-Nachrichten.

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

Diese Aktivierung ist nach Zertifikatsbereitstellung noch durchzuführen.
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
