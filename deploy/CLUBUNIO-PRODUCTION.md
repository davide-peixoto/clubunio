# ClubUnio PROD: technische Vorbereitung

Stand: 19.11.2026 (Europe/Zurich).

## Ziel und Freigabe

Der Benutzer hat die neue RunCloud-App auf dem VPS mit app.clubunio.com,
Datenbank clubunio_prod und dem vorhandenen Git-Branch verbunden und die
Vorbereitung beauftragt. Entscheidung: leerer Vereinsbestand zum Start.

## Verifizierter Stand

- VPS: vps.clubunio.com, RunCloud-App ClubUnioProd.
- Projekt: /home/runcloud/webapps/ClubUnioProd; Webroot public/.
- Git-Branch heisst exakt prod, nicht Prod. Ausgangspunkt 8aeb91d.
- Eigenes APP_ENV=production, APP_DEBUG=false, dauerhafter neuer APP_KEY.
- Private .env und .env.postgresql.local gehoeren runcloud, Modus 0600.
  DB-Verbindung verwendet die bestehenden Zugangsdaten, Ziel clubunio_prod.
  Keine Stage-Providerkonfiguration oder Stage-App-Schluessel uebernommen.
- DB-Anmeldung als userunio mit bestehender TLS-Konfiguration erfolgreich;
  current_database() = clubunio_prod; initial leer, anschliessend Schemaaufbau wie unten.
- Composer-Lock mit PHP 8.3 installiert, keine Entwicklungsabhaengigkeiten.
  Package Discovery, Filament-Assets, Livewire-Publikation und Configcache fertig.
- Wartungsmodus mit vorgerenderter Fehlerseite: /login liefert HTTPS 503.
  Statisches Livewire-JavaScript: 200; /.env: 403.
- Versand aus: outbound_enabled=false, MAIL_MAILER=log; keine Worker oder
  Scheduler fuer PROD aktiviert. Keine Konten oder Vereinsdaten importiert.
- Deutsch und Englisch konfiguriert. Keine neuen sichtbaren UI-Texte.

## Explizite Produktionskonfiguration

Die globale Umgebungssperre erlaubt production nur mit
CLUBUNIO_PRODUCTION_ENABLED=true. Ohne Freigabe scheitert bereits der
Anwendungsstart. Der Schalter erlaubt den technischen Bootstrap; er ersetzt
weder Wartungsmodus noch Fachabnahme und schaltet keinen Provider frei.
Pruefung auf dem VPS: ohne Flag CLI-Exit 1, mit Flag erfolgreicher Bootstrap.
Lokaler Katalogcheck: 2994 Schluessel in DE/EN, keine Platzhalterfehler.

## Stage-Abgleich und leerer Schemaaufbau (19.11.2026)

Freigegeben: aktueller Stage-Code und leerer Fachbestand. Uebernommen wurde
der synchronisierte Stage-Commit f175b6f mit allen Vorfahren. Die explizite
Produktionsfreigabe bleibt als PROD-Delta erhalten. Merge-Release: 2fe7a43.
Uncommittierte Stage-Maildesign-Arbeiten und waehrend des Aufbaus parallel
hinzugekommene Social-Insights-Tabellen sind nicht Teil dieses Releases.

Das Werkzeug deploy/bootstrap-empty-production.py uebernimmt ausschliesslich
das Schema clubunio und das Migrationsjournal. Es verlangt den exakten
Zielnamen clubunio_prod, eine leere Datenbank, Owner userunio, Wartungsmodus
und ein zum Code passendes Quelljournal. Kein Stage-Import, keine
Legacy-Schemata, keine Provider-/Vereinseinstellungen und keine Fachdaten.
Alle erzeugten Objekte gehoeren userunio. Die historischen
Importmigrationen werden nicht ausgefuehrt und deren Schutz bleibt erhalten.

Ablauf auf dem VPS als root aus dem Release-Verzeichnis:

    python3 deploy/bootstrap-empty-production.py plan --target clubunio_prod
    python3 deploy/bootstrap-empty-production.py rehearse --target clubunio_prod
    python3 deploy/bootstrap-empty-production.py apply --target clubunio_prod

Probelauf vollstaendig mit ROLLBACK erfolgreich. Verbindlicher Aufbau in
einer Transaktion: 239 Tabellen, eine View, 141 Migrationsjournalzeilen.
Alle 238 anderen Tabellen sind leer, insbesondere tenants und accounts.
App-Verbindung als userunio mit search_path=clubunio erfolgreich.

Private Abzuege liegen ausschliesslich auf dem VPS unter
/root/clubunio-prod-backups/20261119T172651952164Z:
before.sql (leere DB), schema.sql und migration-journal.sql. Der
Probelauf liegt separat unter 20261119T172631555785Z.
Originaler Schema-SHA256:
3a9fdde50c3f15608b3167bdec5a24c570d08e23c79a9b0ba874d0e291f20180.

Der erste Vergleich nach Commit meldete lediglich von PostgreSQL beim
Restore umgeschriebene varchar[]/text[]-Pruefbedingungen. Exakte
Normalisierung dieser Cast-Form ergaenzt; danach gesamtes Schema gegen
den gespeicherten Abzug, alle 141 Journalnamen gegen den Code und jede
einzelne Fachtabelle unabhaengig erfolgreich geprueft. Der Vergleich
verwendet den gespeicherten Stand, weil Stage parallel weiterentwickelt
wird. Ein erneuter Aufbau wird wegen des vorhandenen Schemas abgelehnt.

    python3 deploy/bootstrap-empty-production.py verify --target clubunio_prod --baseline /root/clubunio-prod-backups/20261119T172651952164Z/schema.sql

Composer-Lock installiert, Livewire byteweise geprueft, Konfiguration und
alle Blade-Templates gecacht. DE/EN: 2984 Schluessel fehlerfrei.
HTTPS /login bleibt 503, statisches Livewire 200. Kein Scheduler/Worker.

## Naechster Schritt vor Erststart

Ersten Verein und getrennt berechtigte Administration einrichten.
Mandantenbezogene Lookups, Vorlagen und Sprachvarianten ueber die
vorgesehenen Provisionierungsservices anlegen; keine Test-Fixtures oder
fremden Konfigurationen uebernehmen. Danach Produktionslogin und
Berechtigungen pruefen. Regelmaessige private Backups samt
Wiederherstellungsnachweis einrichten. Erst nach dieser Abnahme den
Wartungsmodus gezielt aufheben.

Der Wartungsmodus bleibt bei einem Code-Rollback erhalten. Vor dem
ersten Fachdatensatz kann der ganze initiale Schemaaufbau bei Bedarf
nach expliziter Entscheidung zurueckgenommen werden; kein automatischer
Schema-Downgrade. Private Konfiguration und APP_KEY dauerhaft sichern
und bei Updates erhalten. Keine echte Zahlung oder externer Versand.

## PROD-Keycloak angebunden (19.11.2026)

Eigene Clients clubunio-verein-prod und clubunio-platform-prod im Realm
clubunio. Private Secrets, exakte PROD-Callbacks, PKCE S256 und MFA Level 2.
DE/EN-Loginformulare, Redirect-/PKCE-Abweisung und Client-Secrets geprueft.
Keycloak-only aktiv; Benutzerzuordnungen leer, Wartungsmodus erhalten.
Vollstaendige Login-Abnahme folgt mit der ersten Administration.
[Konfiguration und Nachweise](keycloak/clients/PRODUCTION.md).
