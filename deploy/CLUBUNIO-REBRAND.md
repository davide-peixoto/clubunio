# ClubUnio: Umbenennung vom 14.10.2026

## Technische Umbenennung, Release50

Aktiv lokal und auf dev-my2.svsissach.ch: 20261014-clubunio-50.
Ruecksprung Release49 erhalten. Servercode direkt aus Release49 transformiert;
keine lokalen, noch nicht ausgelieferten Fachpakete uebernommen. Autoload und
Konfigurations-/Routen-/View-Caches neu aufgebaut. Keine DB-Migration.

446 PHP-Dateien ohne Syntaxfehler. Standardlauf: 19 Tests/112 Assertions,
293 DB-Opt-in-Tests uebersprungen. Gezielte DB-Transaktionstests: 15/155
(Login/Lookups/Ansichten/Kalender/Branding/Mail) und 13/96 (OpenID/Sandbox).
Testlaeufe ueberlappen teilweise; keine Gesamtzahl unterschiedlicher Tests.
Frueher Gesamt-DB-Lauf wegen lokal fehlendem PDO-PG-Treiber verworfen;
erneuter breiter Lauf nach 29 Tests abgebrochen, keine Komplettabnahme.
Gezielte Nachtests mit explizit geladenem PDO-PG-Treiber erfolgreich.
189 Settings/42 Vorlagen auf Pi geprueft; Outbound weiterhin aus.
HTTPS-Login, Portal, CSS und neuer PDF-Viewer-JS-Pfad liefern200; alter Login
leitet weiter. OpenID-Callback anonym weiter durch Login geschuetzt.
Authentifizierte visuelle Nachpruefung nach erneutem Login steht noch aus.

Wiederholbares Quellwerkzeug: rename-clubunio.php (nur Vorzustand),
Auslieferungsnachweis: release-clubunio-technical.sh. Kein automatischer
erneuter Lauf auf bereits umbenanntem Quellbaum. Tests setzen jetzt
CLUBUNIO_TEST_DB=1; vorhandene private Konfiguration bleibt ausserhalb Git.

## Auslieferung und Nachweis

Lokal und auf dev-my2.svsissach.ch umgesetzt. Aktives Pi-Release:
20261014-clubunio-brand; Ruecksprung: 20261014-clubora-47.
Nur Branding-Delta auf dem bestehenden Serverstand; keine noch nicht
ausgelieferten Fachentwicklungen uebernommen. Konfigurations-, Routing- und
View-Caches neu erstellt. HTTP200 fuer neuen Login; alter Login leitet weiter.
189 verschluesselte Einstellungen und 42 Nachrichtenvorlagen geprueft.
18 Tests / 104 Assertions erfolgreich; 289 DB-Opt-in-Tests uebersprungen.
Kein echter Bexio-Schreibaufruf und kein Mailversand durch diese Pruefung.

ClubUnio ist der neue Produktname. Weboberflaechen, Browser-Titel,
PDF-Vorschau, Testmail-Absender/Betreff, Meldungen und aktuelle Dokumentation
verwenden den neuen Namen. Der Verwaltungszugang lautet /clubunio/login.
Alte GET-Lesezeichen unter /clubora werden mit Pfad und Query weitergeleitet.
Alte offene Formulare bitte neu laden; Schreibrequests werden nicht umgeleitet.

## Bewusste technische Kompatibilitaet

- PostgreSQL-Datenbank/Schema clubora und bestehende Zugangsdaten bleiben erhalten.
- PHP-Namespace App\ClubUnio, Auth-Guard/Panel-ID clubunio, interne Routennamen,
  Konfigurationsschluessel, Quelldateien und CLI-Kommandos sind umbenannt.
  Nach der Umstellung ist eine erneute Anmeldung erforderlich. Offene Formulare
  vor der weiteren Bearbeitung neu laden. Datenbankverbindung heisst clubunio,
  physisches Schema bleibt clubora. Validierungen nutzen clubunio.clubora.*.
- Bexio-OpenID-Callback /clubora-bexio/sv-sissach/callback bleibt unveraendert.
  Keine erneute Anmeldung, keine Tokenrotation und keine Remote-Schreibaufrufe.
- Sandbox-Kontaktnamen, Registry-Marker, historische Daten, Kalender-IDs,
  Auditprotokolle und Release-Verzeichnisse werden nicht umgeschrieben.
- Dokumentdateinamen sind jetzt CLUBUNIO-*.md; Projektverweise werden nachgefuehrt.
- Geschuetzte alte Dokument-URLs bleiben kompatibel; PDF-Viewer-Assets liegen
  neu unter public/clubunio. Bestehende Kalender-UIDs bleiben stabil.
- Integrationskonfiguration bevorzugt .env.clubunio-integrations.local,
  liest bei Bedarf die bisherige private .env.clubora-integrations.local.
  Kein Kopieren von Secrets ins Repository. Bestehende Hosting-/Scheduler-
  Dienstnamen und private Shared-ENV-Dateien bleiben Betriebs-Kompatibilitaet.
- Keine Datenbankmigration. Keine Aenderung an PHPMaker DEV/PROD, App oder SaaS.

Eine spaetere rein technische Namensmigration muss DB-Schema, persistierte
Identitaeten, externe Callback-Freigaben und Rollback gemeinsam behandeln.
Kein globales Ersetzen in Secrets, Datenbankinhalten oder Backups vornehmen.

## Pruefung

ClubUnioBrandingTest prueft Produktname, Loginseiten, alte Lesezeichen inklusive
Suchfilter und den unveraenderten OpenID-Callback. Mail-Schutztests pruefen
weiterhin den ausschliesslichen Testempfaenger.
