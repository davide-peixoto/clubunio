# ClubUnio Sicherheitskorrekturen vom 25. November 2026

## Aktualisierung nach Bereitstellung

Stage und PROD sind gehärtet und aktiv geprüft; die nachfolgenden Angaben zur lokalen Vorbereitung beziehungsweise unveränderten Bestandsaufnahme beschreiben den früheren Prüfstand. Massgeblich für den Abschluss sind [Release, Nachweise und offene Go-live-Punkte](CLUBUNIO-SECURITY-RELEASE-20261125.md). Lokal zuletzt 161 Tests / 25'917 Assertions; echtes MFA-/Logout-Ende-zu-Ende auf beiden Instanzen bestanden. Automatisches externes Backup bleibt offen.


Die Korrekturen sind lokal vorbereitet. Es gab kein Deployment und keine
Umstellung von DEV, Stage, PROD oder Keycloak. Die allgemeinen Go-live-Gates
aus der [Sicherheitsprüfung](CLUBUNIO-SECURITY-REVIEW-20261125.md) bleiben bestehen.

## Abschliessende lokale Prüfung

Der zusammenhängende Lauf über 22 ausgewählte Testdateien besteht mit
**156 Tests und 25'826 Assertions**, ohne Fehler oder übersprungene Tests.
Die Tests verwenden SQLite im Arbeitsspeicher, synthetische Daten und gesperrte
oder gemockte Providertransporte. Kein Test verbindet sich mit einer laufenden
Anwendungsdatenbank. Die Testmenge ist ein gezielter Sicherheits-/Kompatibilitätslauf,
keine vollständige Abnahme sämtlicher ClubUnio-Funktionen.

Zusätzlich erfolgreich: PHP-Syntaxprüfung der neuen Sicherheitsdienste,
Migrationsquelle und Regressionen, Prüfung der DE/EN-Kataloge mit
`php tools/check-translations.php` (3075 Schlüssel) und null Composer-Advisories.
Die neuen Datei-Fehlermeldungen werden zusätzlich im Test in beiden aktivierten
Sprachen aufgelöst und geprüft.

Wiederholbarer Windows-Lauf ohne Aktivierung gemeinsamer Datenbankfixtures:

```powershell
./tools/check-security-local.ps1 -OpenSslConfig C:/xampp/apache/conf/openssl.cnf
```

Der OpenSSL-Pfad ist auf dem Prüfcomputer vorhanden. Auf anderen Rechnern eine
passende Konfiguration angeben oder bei korrekt eingerichteter OpenSSL-Umgebung
den Parameter weglassen. Das Skript stellt die vorherigen Prozessvariablen wieder
her, führt keine Migration aus und aktualisiert keine Pakete. Der abschliessende
Composer-Audit kontaktiert die Paketregistrierung.

## Abhängigkeiten

Sieben kompatible Updates im bestehenden Composer-Versionsrahmen:

| Paket | Vorher | Neu |
| --- | --- | --- |
| laravel/framework | 12.69.3 | 12.74.0 |
| phpseclib/phpseclib | 3.0.57 | 3.0.59 |
| symfony/html-sanitizer | 7.4.20 | 7.4.24 |
| symfony/http-foundation | 7.4.20 | 7.4.24 |
| symfony/http-kernel | 7.4.20 | 7.4.24 |
| symfony/routing | 7.4.20 | 7.4.24 |
| symfony/yaml | 7.4.20 | 7.4.24 |

Composer aktualisierte nur diese sieben Lockeinträge. Lokale Installation ohne
Composer-Scripts und Plugins; keine Artisan-/Datenbank-/Asset-Deployment-Hooks.
Die historisch bereits aus dem Lock entfernte WebAuthn-Bibliothek wurde auch aus
dem lokalen vendor-Bestand entfernt. vendor gehört nicht ins Repository.

`composer audit --locked --no-interaction --format=json` meldet nach dem Update
null Advisories und keine aufgegebenen Pakete. Auch `composer validate
--no-check-publish` und die lokalen Produktions-Plattformanforderungen sind
erfüllt. Die bestehenden Hinweise zu zwei exakt gepinnten Abhängigkeiten sind
unverändert und keine Sicherheitsbefunde. Dies prüft den lokalen Lock-/vendor-
Stand; Serverstände sind separat zu verifizieren.

## Dateiverarbeitung

`DocumentSafety` vereinheitlicht die bestehende MalwareScanner-Anbindung:

- Allgemeine Web-/API-Dokumente: Autorisierung vor Scan, Scan vor Staging;
  erneute Prüfung bestehender pending Uploads vor externem Dateizugriff/Transfer.
- Clubhaus: Prüfung vor Dateispeicherung und erneut vor jeder PDF-Publikation,
  auch für bereits vorhandene Dokumente.
- Infizierte Dateien: lokalisierte Ablehnung in DE und EN. Fehler oder unklare
  Scannerantworten: HTTP 503, keine Freigabe und keine Providerdetails.
- Clubhaus-PDF-Antworten: Sandbox-/Frameheader zusätzlich zu privaten Cache-
  und nosniff-Vorgaben. Signierte und andere PDF-Originale werden nicht verändert.

**Betriebsvoraussetzung:** `CLUBUNIO_CLAMD_SOCKET` muss auf einen vom PHP-FPM-
Benutzer erreichbaren clamd-Unixsocket zeigen. Der vorhandene Scanner verlangt
eine eindeutige saubere INSTREAM-Antwort. Ohne funktionierenden Scanner sind
auch allgemeine Uploads, pending Transfers und Clubhaus-PDF-Freigaben gesperrt.
Eine Abschaltung des Scans ist kein vorgesehener Rückweg.

Vorhandene bereits veröffentlichte Dateien werden nicht automatisch zurückgezogen
oder nachträglich gescannt. Ein Bestandsaudit ist bei Verdacht gesondert zu planen.
Ein Malwarecheck und Browserheader ersetzen keine vollständige PDF-Desinfektion.

## Anmeldung und Sitzungswiderruf

Web- und Plattform-MFA erhalten eine eigene Konfiguration und einen im Callback
geprüften Nachweis. Mobile- und Einladungsflags dürfen die Web-MFA nicht abschalten.
Alte Websitzungen ohne Nachweis erfordern eine erneute Anmeldung. Die
[Keycloak-Betriebsanleitung](CLUBUNIO-KEYCLOAK-SECURITY-HARDENING.md) beschreibt
die genauen ACR-Einstellungen, erlaubte Clients, den gemeinsamen Replaycache
und das dauerhafte Erzwingen der gespeicherten Widerrufsgrenze.

Der neue, standardmässig deaktivierte Empfänger prüft signierte Logout-Ereignisse
und speichert eine monotone Grenze pro Konto. Diese bindet die Sicherheitsstempel
von App-Tokens, Webnachweisen und abgeschlossenen Pairings. Alte Claims werden
auch beim verspäteten Abschluss einer App-Kopplung abgewiesen. Konto-Rowlocks
serialisieren die Änderungen; ein wirklicher PostgreSQL-Mehrprozess-Racetest
bleibt vor Aktivierung auf Stage erforderlich.

Ein erst neu verknüpftes Konto erhält mit dem Verknüpfungstoken noch keine
Sitzung: Nach Einladung/Kontomigration wird eine zweite frische OIDC-Anmeldung
erzwungen. So kann ein vor der Erstverknüpfung nicht zuordenbarer Logout nicht
einfach durch Ausgabe einer Sitzung aus den alten Verknüpfungsclaims umgangen werden.

Neue additive Migration: `2026_11_25_010000_keycloak_logout_watermarks.php`.
Sie wurde nur als Quelle und mit isolierten In-Memory-Fixtures geprüft, auf
keiner Instanz ausgeführt. Vor Aktivierung muss der Owner-Runner das richtige
Zielschema berücksichtigen (`clubora` in der Quelle, je Instanz geprüftes Schema),
die Migration protokollieren und minimale SELECT-/INSERT-/UPDATE-Rechte erteilen.
Nach einer Aktivierung die Watermarks und `CLUBUNIO_KEYCLOAK_LOGOUT_STATE_ENFORCED`
erhalten, auch wenn der Empfänger pausiert wird. Das Entfernen der Widerrufsgrenzen
ist kein sicherer Rückweg.

Ein lokaler signaturgeprüfter Logout-Empfänger allein bewirkt noch keine zentrale
Kontosperre: Der Identitätsdienst muss passende Ereignisse zustellen. Diese
Einrichtung und ein tatsächlicher Ende-zu-Ende-Nachweis auf Stage sind vor einer
Freigabe zwingend. Kontosperre, Passwortreset und globaler Logout sind dabei
getrennte Testfälle.

## Auslieferung und Rückweg

1. Geprüften Quellstand als zusammengehöriges Paket festlegen; fremde offene
   Änderungen nicht pauschal aufnehmen. Stage-Code vorab gegen diesen Stand
   abgleichen, da die Erstprüfung eine Abweichung beim Plattform-OIDC-Start zeigte.
2. Getrennte Stage-Sicherung und Rückweg vorbereiten. clamd-Erreichbarkeit und
   MFA-ACR-Konfiguration vor Auslieferung prüfen. Benutzer benötigen bei alten
   Web-Sitzungen gegebenenfalls eine erneute starke Anmeldung.
3. Neue Abhängigkeiten aus dem geprüften Lock installieren und vorhandenen
   Releaseablauf für Cache/Assets verwenden. Keine Datenauffrischung oder
   Fremdquellenimporte.
4. Synthetische Stage-Abnahme: saubere/abgewiesene Datei, Scanner nicht erreichbar,
   bestehender pending Upload, Publikation, MFA unabhängig von Appflags sowie
   Widerruf alter App-Tokens und offene Pairings. Keine realen Nachrichten oder
   externen Provideraktionen.
5. Erst danach eine ausdrücklich freigegebene Betriebsumstellung vornehmen und
   ausgelieferte Versionsstände, Audit, Migrationen und Rückweg nachführen.

Beim Rückweg müssen Code und Composer-Lock/vendor-Paket zusammenpassen. Ein
Rücksprung auf den alten Stand bringt die ursprünglichen Sicherheitslücken zurück;
bei Scannerstörung die Verarbeitung gesperrt lassen und den Scanner reparieren.
