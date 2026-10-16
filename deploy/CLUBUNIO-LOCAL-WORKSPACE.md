# Eigenstaendiger lokaler ClubUnio-Arbeitsordner

16.10.2026: C:\Projects\SVS\backend\laravel physisch nach C:\ClubUnio
verschoben. Keine neue Kopie, kein neues Git-Repository und keine neue Historie.
Origin bleibt https://github.com/davide-peixoto/clubunio.git, Branch main.
Am alten Pfad bleibt eine Windows-Junction. Bestehende Chats/Verknuepfungen
koennen damit weiterarbeiten; neue Arbeiten/IDE-Projekte verwenden C:\ClubUnio.
Projektanweisungen liegen jetzt direkt im eigenen AGENTS.md.

Alle18906 Dateien inklusive .git, vendor, lokaler .env-Dateien und Laufzeitdaten
wurden verschoben. Dateizahl und SHA256 der lokalen Konfigurationsdateien
vor/nach dem Umzug verglichen. APP_KEY, Datenbank- und Providerdaten bleiben
unveraendert und werden nicht committed. Keine Cloud-Uploads kopiert/geloescht.

## Lokal starten

tools/start-local.ps1 startet den bisherigen PHP-Server verborgen mit PHP8.3,
pdo_pgsql und Arbeitsordner aus dem Skriptpfad. Standard8093, nur127.0.0.1.
Ein belegter Port fuehrt zum Abbruch, nicht zum Stoppen fremder Prozesse.
Lokaladresse:http://127.0.0.1:8093/login. Abhaengigkeiten bleiben erhalten.
Views/Routen/Konfiguration nach dem Umzug lokal neu aufloesen, keine Sessions
oder Datenbanken leeren. Keine Service-/Cron-Automatisierung eingerichtet.

## Getrennte Migrationsquellen

PHPMaker bleibt in C:\Projects\SVS\backend. Nur die CLI-Import-/Exportwerkzeuge
verwenden tools/legacy-source-paths.php fuer PMP und PROD-Quellkonfiguration.
Optionaler Prozessparameter CLUBUNIO_LEGACY_BACKEND_ROOT kann einen anderen
Quellordner festlegen; Standard unter Windows ist der obige Pfad. Linux behaelt
das bisherige Nachbarordnerlayout. Keine Quellen werden beim Start der Webapp
gelesen, keine Konfigurationen automatisch uebertragen und kein Import gestartet.
Die separate Website-Konfiguration fuer den einmaligen Import bleibt separat.

## Abgrenzung / Ruecknahme

Nur lokaler Arbeitsordner und Git-Dokumentation angepasst. Pi-ClubUnio,
PHPMaker DEV/PROD, PostgreSQL und Azure bleiben unveraendert. Kein Deployment
fuer diesen organisatorischen Schritt notwendig. Die Repo-Historie und origin
brauchen beim Move keine Neuanlage oder URL-Aenderung. Ruecknahme erfordert
Stoppen des lokalen Servers, Entfernen nur der geprueften alten Junction und
Zurueckverschieben des einen Arbeitsverzeichnisses; niemals dessen Inhalt loeschen.

## Pruefnachweis

Lokale Config-/Routen-/Viewcaches geloest, Server aus C:\ClubUnio neu gestartet.
/up und /login HTTP200.195 verschluesselte Settings und46 Nachrichtenvorlagen
erfolgreich gelesen; Outbound weiterhin aus. Zwei Pfadauflösungstests mit drei
Assertions und Syntaxpruefung aller sechs betroffenen PHP-Dateien erfolgreich.
Legacy-PMP und Quellkonfiguration am separaten Pfad vorhanden, Inhalte dabei
nicht importiert. Secrets und vendor bleiben von Git ausgeschlossen.
