# Spieler, Eltern und Azure: Fachabnahme15.10.2026

## Durchgefuehrt

Eigene synthetische Spieler-/Eltern-/Office-/Fremdkonten und Mitglieder in
einer isolierten PostgreSQL-Transaktion der ClubUnio-DB. Kein Bestandskonto
umgestellt, keine echten Mitgliederdaten oder Freigaben veraendert.

- Spieler: Weblogin mit Passwort, eigene Formularansicht, Aenderungsantrag per
  POST, keine direkte Mitgliederaenderung vor Freigabe, Genehmigung durch
  Office, anschliessend aktualisierte Daten und eigene interne Notification.
- Eltern: Weblogin, Antrag fuer freigegebenes minderjaehriges Kind, Entscheidung
  und Rueckmeldung. Anderer berechtigter Elternteil sieht den fremden Antrag
  und dessen Entscheidungsnotiz nicht und kann ihn nicht zurueckziehen.
- Volljaehrigkeit, Austritt und widerrufene Elternzuordnung sperren den Zugriff.
  Ein weiteres berechtigt zugeordnetes Kind bleibt unabhaengig erreichbar.
- Dokument vor ausdruecklicher Portal-Freigabe fuer Spieler und Eltern404.
  Danach beide200, fremder Benutzer404. Elternwiderruf und Dokumentdeaktivierung
  entziehen den Zugriff wieder. Deaktiviertes Dokument auch fuer Office404.

## Echter Azure-Test

Ein neu mit Dompdf erzeugtes PDF enthaelt ausschliesslich Testtext, keine
Personendaten. Upload ueber den unveraenderten MemberDocumentMaintenanceService
und RegistrationBlobStore, keine HTTP-Doubles fuer den Live-Test.
Privater Container svs-prod-private, ausschliesslich eigener separater Pfad
clubunio/members/{synthetischer_tenant}/{synthetisches_mitglied}/{neue_uuid}.pdf.
Keine bestehenden PHPMaker-Pfade oder Dateien angefasst.

Azure-PUT201; Ruecklesen ueber AzureDocumentReader und die geschuetzten
Dokument-Webrouten200. SHA256 und Groesse entsprechen dem erzeugten PDF;
keine lokale Kopie am erwarteten Uploadpfad. Anonymer unsignierter Azure-GET
wird abgewiesen, kein oeffentlicher Link. Webantwort application/pdf/nosniff.

Bereinigung im finally-Block nur fuer den eben aufgezeichneten Fixturepfad:
Tenant-/Mitgliedspfad und UUID pruefen, Inhalt erneut per SHA256 vergleichen,
DELETE mit If-Match/ETag202, abschliessender HEAD404 bestaetigt. Keine
Loeschrechte in die Produktiv-Uploadklasse eingebaut, kein Verzeichnis-Cleanup.
Eventuelle Azure-Aufbewahrungs-/Backupkopien bleiben unangetastet.
Alle synthetischen DB-Zeilen inklusive Konten, Links, Audit, Antraegen,
Dokumentmetadaten und Notifications durch Transaktionsrollback entfernt.
Identity-Sequenzen koennen erwartungsgemaess fortgeschrieben sein.

## Testnachweis und Grenzen

Live-Lauf: drei Tests/80 Assertions erfolgreich, darunter ein echter Azure-Test.
Nachlauf ohne erneuten Azure-Upload:16 Tests/147 Assertions erfolgreich,
einschliesslich kompletter Antrags-/Dokumentregression und Azure-Adaptertests.
Die zwei Rollenfaelle ueberschneiden sich mit dem Live-Lauf.
Aufruf lokal mit PHP8.3, PDO-PGSQL und beiden expliziten Flags:

```powershell
$env:CLUBUNIO_TEST_DB='1'
$env:CLUBUNIO_TEST_AZURE='1'
C:\xampp\php\php.exe -d extension=pdo_pgsql vendor/phpunit/phpunit/phpunit tests/Feature/MemberChangesDocumentsTest.php --filter '(player_web|parent_web|live_azure)'
```

Ohne CLUBUNIO_TEST_DB=1 keine DB-Fixtures; ohne CLUBUNIO_TEST_AZURE=1 wird der
echte Azure-Test uebersprungen. Zugangsdaten ausschliesslich aus vorhandener
privater Konfiguration, keine Tokens/Schluessel/SAS in Ausgabe oder Repository.
Anonyme Fixtureabfrage ist unsigniert; Bereinigung verwendet kurzlebiges SAS
fuer genau diesen Blob und gibt es nie aus.

Rollen-/Webaufrufe laufen im echten Laravel-HTTP-Testkernel lokal mit eigener
ClubUnio-DB, Azure-Anfragen real ueber HTTPS. Das ist kein manueller Browsertest
als Spieler/Elternteil und kein externer End-to-End-Test durch den Pi-Proxy.
Vorige angemeldete Verwaltungspruefung bleibt separat dokumentiert.
Kein Testversand von E-Mail/FCM; Outbound bleibt aus. Es waren keine fachlichen
Codekorrekturen, Migrationen, ENV-/Server-/Jobaenderungen oder Poolneustarts noetig.
API/SaaS/Betrieb und PHPMaker DEV/PROD bleiben unveraendert.

## Migrationsplan-Nachtrag

Migration59 unveraendert und bereits vorhanden. Der Nachtest fuegt keine
Migration, keinen Bestandsimport und keine Dokumentverschiebung hinzu.
Vor spaeterem Cutover bleiben reale Identitaets-/Elternzuordnungen, explizite
Dokumentfreigaben und Altantragsuebernahme fachlich zu bestaetigen. Die neuen
Tests sind wiederholbarer Abnahmenachweis, keine Freigabe zum Betriebswechsel.
