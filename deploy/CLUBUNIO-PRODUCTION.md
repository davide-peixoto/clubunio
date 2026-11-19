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
  current_database() = clubunio_prod, null Anwendungstabellen.
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

## Naechster Schritt vor Erststart

Der verbundene prod-Stand ist aelter als Stage. Erst den gewuenschten,
abgenommenen Produktstand auswaehlen und gezielt uebernehmen. Anschliessend
einen eigenstaendigen Aufbau fuer die leere Produktionsdatenbank vorbereiten:
Die vorhandenen Migrationen und Stage-Runner haben Zielschutz und
Import-Zwischenpunkte; diese nicht durch Umbenennen der Umgebung oder mit
dem Stage-Import umgehen. Es wurde noch keine Migration ausgefuehrt.
Nach leerem Schemaaufbau Lookups/Sprachvarianten pruefen, ersten Verein und
separat berechtigte Administration einrichten, Produktions-Login abnehmen.
Backups samt Wiederherstellungsnachweis und Release-Rueckweg vor Freigabe
einrichten. Erst dann Wartungsmodus gezielt aufheben.

Kein Datenrefresh, keine Stage-Uebernahme und keine echte Zahlung.
Der Wartungsmodus bleibt bei einem Code-Rollback erhalten. Private
Konfiguration und APP_KEY dauerhaft sichern und bei Updates erhalten.