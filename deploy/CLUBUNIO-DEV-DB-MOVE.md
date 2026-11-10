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

Status: Kompatibilitaetsprobe abgeschlossen; kein produktiver Restore und
keine Umschaltung des Pi oder Schema-Umbenennung. Beim erneuten direkten
SSH-Zugriff von Windows war der VPS nicht erreichbar (Timeout an Port 22).
