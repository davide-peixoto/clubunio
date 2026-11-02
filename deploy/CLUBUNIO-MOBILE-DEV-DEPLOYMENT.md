# DEV-Freigabe Kommunikation und Absenzen

Benutzerauftrag02.11.2026: alles auf DEV deployen, migrieren und aktivieren.
Diese Freigabe ist erteilt. Die fruehere SSH-Sperre wurde in diesem Lauf
als behoben bestaetigt. Deployment und Migration sind abgeschlossen;
aktueller Ausfuehrungsnachweis unten.

## Freigegebener Quellumfang

Kommunikationsstaende6bf6cb1 und4c76049 (App), dazu Absenzenverwaltung a0d59a6
sind inzwischen in c5a3a5a zusammengefuehrt. Die parallelen Saison-Navigations-
Commits0eb9e17/b615391 bleiben erhalten. keine offenen lokalen
AGENTS-/README-/Setup-Aenderungen pauschal mit ausrollen. Die neuen Migrationen
sind82 und83, ausschliesslich additive ClubUnio-Tabellen. PHPMaker DEV/PROD,
bestehende SVS-App und echte Bankzahlungen bleiben unberuehrt.

## Ausfuehrungsfolge nach Wiederherstellung des SSH-Zugriffs

1. Aktuellen Release aufloesen, dessen Revision und Ziel vor jedem Umschalten
   erneut pruefen. Vorherigen Release als Rueckweg erhalten. Codepaket per
   SHA256 gegen sein Manifest kontrollieren und auf eine neue Releasekopie
   anwenden, gemeinsame Storage und APP_KEY beibehalten.
2. Privates, vollstaendiges PostgreSQL-Backup der ClubUnio-Datenbank erstellen,
   Exitstatus/Dateigroesse und Wiederherstellbarkeit pruefen. Keine Passwoerter
   ausgeben und das Backup niemals ins Repository oder in ein oeffentliches Paket legen.
3. Migration82/83 zuerst in einer isolierten PostgreSQL-Pruefung bzw. einer
   zurueckgerollten DDL-Transaktion pruefen; FK-/Vereinsgrenzen kontrollieren.
   Neue Releasekopie bis zum bestandenen Gate nicht oeffentlich schalten.
4. Dort Laravel-Konfigurations-/Routencache leeren und nur diese Migrationen
   auf der ClubUnio-Verbindung ausfuehren:

```sh
php artisan migrate --database=clubunio --path=database/clubunio/2026_11_02_000082_mobile_communication.php --force
php artisan migrate --database=clubunio --path=database/clubunio/2026_11_02_000083_mobile_chat_controls.php --force
```

5. Private Releasekonfiguration setzen, ohne den bisherigen aktiven Release
   vorzeitig umzustellen. Fuer alle bereits implementierten Funktionen:

```dotenv
CLUBUNIO_MOBILE_ENABLED=true
CLUBUNIO_MOBILE_UPLOADS_ENABLED=true
CLUBUNIO_MOBILE_AI_ENABLED=true
CLUBUNIO_CLAMD_SOCKET=/run/clamav/clamd.ctl
CLUBUNIO_MOBILE_TRANSLATION_PROVIDER=openai
CLUBUNIO_MOBILE_WRITING_PROVIDER=openai
```

OPENAI_API_KEY muss bereits sicher serverseitig bereitliegen. Alternativ die
vorhandenen Azure-Variablen und Provider laut COMMUNICATION-AI konfigurieren;
Azure braucht zusaetzlich eine gepruefte
CLUBUNIO_MOBILE_AZURE_RESERVATION_MICROUSD. Keine Keys hier eintragen.
Uploads nie ohne funktionsfaehigen, aktuellen lokalen clamd aktivieren.
Scannerinstallation/Ressourcen und Providerkonfiguration zuerst pruefen.
Fehlende Voraussetzungen sind ein gesperrtes Gate, kein erfolgreicher Vollrollout.
Push/WebSocket sind noch nicht implementiert und werden nicht vorgetaeuscht.

6. Cache neu aufbauen; als www-data alle bisherigen Web-/API-/Tenant-Gates
   und `php deploy/check-mobile.php` ausfuehren. Anschliessend
   `php deploy/provision-mobile-chats.php` idempotent aufrufen. Neue Team- und
   Trainerchats entstehen je aktiver TeamSaison, ohne Nachrichtenkopien/Push.
7. Zuletzt unveraendertes vorheriges Symlinkziel kontrollieren, auf den geprueften
   Release umschalten und FPM neu laden. HTTPS ohne Credentials: context muss401
   liefern; leeres POST session422 statt404. Danach echter Login im Simulator mit
   bestehendem Account, eigene Teams/Kindwahl sowie Logout/Widerruf pruefen.
   Ein erfolgreicher Fake-/Widgettest ersetzt diese Accountabnahme nicht.
8. Synthetische Datei/Scan und kontrollierte kleine KI-Anfrage separat pruefen.
   Keine echten Rechnungen versenden; Bexio-Hinweis ausschliesslich nach bestaetigtem
   Offenstatus testen. Releasepfad, Revision, Schemajournal und Abnahme nachtragen.

Rueckweg: Mobile-/Upload-/KI-Flags deaktivieren und vorherigen Code bereitstellen;
Tabellen, APP_KEY und Journal erhalten. Kein migrate:fresh, automatisches down
oder pauschales Zurueckspielen der Datenbank.

## Lokaler Pruefstand dieses Syncs

29 Tests/287 Assertions bestanden;4 PostgreSQL-Planungstests ohne ausdruecklichen
DB-Testmodus uebersprungen. Der Absenzen-Quellcommit dokumentiert eigene fruehere
13 Tests/157 Assertions; diese wurden hier nicht als erneut bestanden ausgegeben.
Kommunikationsbasis zuvor36 Tests/343 Assertions,23 Transportchecks,3 Widgets,
strikte Lockaufloesung und Webbuild bestanden. Reale DEV-/PG-/Scanner-/Account-
Abnahme bleibt wegen der SSH-Sperre offen.

## DEV-Ausfuehrung02.11.2026

SSH admin@svs-pi-server erfolgreich. Vollbackup der ausschliesslichen ClubUnio-DB
privat auf dem Server abgelegt und erfolgreich in einer isolierten PostgreSQL18-
Instanz wiederhergestellt (pg_restore --exit-on-error --no-owner --no-privileges).
Migration82/83 zuerst gemeinsam in PostgreSQL-Transaktion ausgefuehrt und
zurueckgerollt, danach beide gezielt angewendet. Keine Bestandsdatenuebernahme.

Release20261102-clubunio-mobile-communication auf navigation-cleanup,
25 Laufzeitdateien gegen SHA256-Manifest geprueft. Bestehende gemeinsame Storage,
APP_KEY und Composer-Lock erhalten; private releaseeigene .env fuer Aktivierung.
Alle elf Web-/API-/Tenant-/Mobile-Gates erfolgreich; echter synthetischer
Dateiscan als www-data bestanden. ClamAV-Signaturen laut Freshclam aktuell.
29 lokale Tests/287 Assertions bestanden. Team-/Trainerchats werden idempotent
fuer die421 aktiven TeamSaisons vorbereitet, ohne Benachrichtigungsversand.

Kommunikation und Uploads aktiviert; KI bleibt deaktiviert, weil im DEV-Laravel-
Environment weder OPENAI_API_KEY noch AZURE_OPENAI_API_KEY bereitliegen.
Kein Provideraufruf und keine fingierte KI-Abnahme. Push/WebSocket weiterhin
nicht implementiert. Persoenlicher Simulator-Login, Kindwahl und Widerruf mit
echtem Benutzer bleiben als fachliche App-Abnahme offen.

Rueckweg: /var/www/svs-laravel/releases/20261102-clubunio-navigation-cleanup.
Die alte gemeinsame .env blieb unveraendert und deaktiviert das Mobile-Modul
beim Ruecksprung. Neue Tabellen und Journale stets erhalten.

HTTPS nach Umschaltung: context401, leeres POST session422, Weblogin200.
FPM aktiv; Releaseziel bestaetigt. Restore-Testcontainer entfernt, privates Backup erhalten.
