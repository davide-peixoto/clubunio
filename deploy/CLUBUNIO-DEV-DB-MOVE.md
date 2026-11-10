# ClubUnio DEV: PostgreSQL vom bisherigen Hosting auf den VPS

Die Anwendung bleibt auf `svs-pi-server` (`dev.clubunio.ch`). Ziel ist nur
`clubunio_dev` auf dem VPS mit PostgreSQL 16. Stage, PROD und PHPMaker sind
nicht Teil dieses Umzugs. Das DEV-Anwendungsschema wird nach dem Restore
auf dem VPS von `clubora` in `clubunio` umbenannt, analog zu Stage.
Quellschema und Snapshot-Schemas werden nicht umbenannt.

## Kompatibilitaetsprobe vom 10.11.2026

- Quelle: `db_acdf8d_dclubora` auf PostgreSQL 18.4, Ziel: `clubunio_dev` auf
  PostgreSQL 16.15. Die Zieldatenbank war bis auf Standardsystemobjekte leer.
- Vollstaendiger Custom-Dump mit PostgreSQL 18 und TLS `verify-full` vom Pi;
  verschluesselt im privaten Backup-Bereich abgelegt und mit `pg_restore`
  vollstaendig gelesen. Kein neuer PROD-Abzug.
- Restore vom lokalen Windows-Rechner in einer Transaktion auf der
  Zieldatenbank erfolgreich, einschliesslich `clubora`, `legacy_prod` und
  `migration_audit`. `clubora.tenants` enthielt fuenf Zeilen. Der Test lief
  ohne Quell-Owner und ACLs, mit anschliessendem `ROLLBACK`. Das standardmaessig
  vorhandene Zielschema `public` blieb bestehen.
- Im SQL-Header musste nur `SET transaction_timeout = 0;` entfernt werden.
  Ausschliesslich diese Zeile im Header ausfiltern; keine Daten oder DDL mit
  einem globalen Textersatz bearbeiten. `ON_ERROR_STOP` verwenden und den
  Restore nur nach einem vollstaendig uebertragenen Stream bestaetigen.

## Vor der Umschaltung

1. Einen dauerhaften, auf PostgreSQL begrenzten privaten Zugang vom Pi
   einrichten. PostgreSQL nicht ins Internet freigeben und TLS-Identitaet
   weiter pruefen. Einen separaten DEV-Anwendungslogin verwenden:
   `userunio` ist auf dem VPS ein Superuser und kein App-Konto. Keine
   Snapshot-Berechtigungen an den App-Login vergeben.
2. Die DEV-Anwendung, DEV-Worker und sonstige DEV-Schreibclients fuer den
   finalen konsistenten Export anhalten. Mit `backup-pi-dev-postgresql.php`
   aus dem aktiven Pi-Release exportieren; Ausgabe direkt in die
   Verschluesselung leiten. Die bisherige Datenbank nicht loeschen.
3. Finalen Dump transaktional wiederherstellen. Besitzer und Rechte explizit
   zuordnen, Objekt- und Tabellenzeilenzahlen, Sequenzen sowie Stichproben
   pruefen. Der erfolgreiche Probe-Restore allein ist kein Freigabekriterium.
4. Stage-Schemawechsel als Referenz verwenden (`66a77b6`, Abnahme `9008751`),
   jedoch keine Stage- oder Integrationskonfiguration uebernehmen. Auf dem
   aktuellen DEV-Code qualifizierte Schemanamen, Suchpfad, Funktionen,
   Views und Migrationswerkzeuge pruefen. Erst den vorbereiteten Release mit
   `clubunio` gegen das VPS-Ziel testen, dann umschalten. Das Schema atomar
   nur in `clubunio_dev` umbenennen und den Rueckweg vorab pruefen.
5. Private Pi-Verbindungskonfiguration sichern und umschalten, APP_KEY und
   Uploadspeicher unveraendert lassen. Cache, Web- und Worker-Verbindung,
   Anmeldung und autorisierte Fachzugriffe pruefen. DEV-Werkzeuge mit alter
   hart codierter DB-Kennung vor der naechsten Verwendung gezielt umstellen.
6. Rueckweg vor erster neuer Schreibaktivitaet: alten Release, private Konfiguration
   und Cache wiederherstellen. Nach neuen Schreibvorgaengen zuerst die
   Aenderungen sichern und abgleichen, damit der Rueckweg nichts verliert.

## Privater Zugang: aktueller Stand

Der direkte Windows-Zugriff bestaetigte PostgreSQL 16.15. Das dedizierte
VPS-Konto `clubunio-dev-tunnel` akzeptiert den Pi-Schluessel nur zum
Forwarding auf `127.0.0.1:5432`, ohne Shell.

Die installierte, aktive Unit `clubunio-dev-db-tunnel.service` bindet nur lokal
am Pi auf `127.0.0.1:15432` und verlangt einen ueber den bestehenden
VPS-Adminzugang verifizierten SSH-Hostschluessel. Der separate, nicht
privilegierte Login `clubunio_dev_app` wurde ueber den Tunnel gegen
`clubunio_dev` mit TLS `verify-full` bestaetigt. Dazu verwendet libpq
`clubunio.com` als Zertifikatsnamen und `127.0.0.1` als Zieladresse. Die
private Kandidatenkonfiguration auf dem Pi ist nicht produktiv verlinkt.

Ein weiterer transaktionaler Test stellte den Dump wieder her, benannte
`clubora` in `clubunio` um und las fuenf Mandanten. Keine Routinen im
umbenannten Schema verwiesen als Text auf `clubora`. Der Test wurde
vollstaendig zurueckgerollt.

Der Stage-Patch passt auf die Git-Basis, aber nicht auf den aktiven
Pi-Release `20261109-clubunio-sport-neutral-facilities`: `git apply --check`
meldete Abweichungen unter anderem im Login-/Push-Code, in Migrationen
und in Konsolenrouten. Den DEV-Kandidaten auf Basis des tatsaechlichen
Pi-Releases vorbereiten und mit der neuen Verbindung pruefen. Die App und
der aktive Push-Worker verwenden bis dahin die bisherige Datenbank.
Dieser Absatz beschreibt den Stand vor der unten protokollierten Umschaltung.

## Umschaltung und Abnahme

- Aktiv: Pi-Release `20261110-clubunio-vps-db`, Kopie von
  `20261109-clubunio-sport-neutral-facilities`. Die Code-Anpassung wurde
  auf dieser Kopie mit `prepare-pi-dev-vps-schema.php` erzeugt und alle
  358 geaenderten PHP-Dateien wurden lint-geprueft. Bestehende URL-Aliase,
  Integrationsdateien, APP_KEY, Composer und Uploadspeicher blieben erhalten.
- Web in Wartung versetzt, DEV-Push-Worker gestoppt, dann finaler DEV-Dump.
  Verschluesseltes, vollstaendig gelesenes Backup:
  `shared/backups/dev-vps-final-20261110`. Restore, Schemawechsel und Rechte
  in einer Transaktion. Ziel: 205 Anwendungstabellen, 84 Snapshot-Tabellen
  und drei Audit-Tabellen; App-Rolle ohne Zugriff auf die beiden Snapshots.
- Laravel/PDO und der Worker verwenden den privaten Tunnel mit TLS-Pruefung.
  `/login`, `/platform/login` und `/up` antworteten mit HTTP 200. Die
  Laravel-Authentifizierung akzeptierte einen isolierten Testaccount; die
  Transaktion wurde zurueckgerollt. Kein echter Benutzerlogin und kein
  erzwungener Push-Versand. Worker aktiv und Polling im Journal bestaetigt.
- Die private Verbindungsdatei ist nur im neuen Release verlinkt. Die alte
  Verbindung am vorherigen Release bleibt als Rueckweg erhalten. Vor einem
  Rueckwechsel nach neuen Schreibvorgaengen Daten sichern und abgleichen.
- Naechste Pi-Releases muessen das Schema-Mapping auf der inaktiven Kopie
  erhalten bzw. erneut anwenden und vor Aktivierung mit
  `verify-pi-dev-vps-database.php` pruefen. Ein unveraendertes `main`-Archiv
  enthaelt weiterhin alte SQL-Schemaqualifizierungen und ist kein direkt
  lauffaehiger Pi-Release. Alte Import- und Migrationswerkzeuge mit
  fest verdrahtetem Quellnamen nicht ungeprueft gegen das VPS-Ziel starten.

Stage und PROD wurden nicht geaendert. Es wurde kein PROD-Abzug erstellt.
