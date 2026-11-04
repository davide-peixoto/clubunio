# Schemahilfe100/200/300: nur vorbereitet (04.11.2026)

`deploy/prepare-mobile-packages-schema.php` ist ausschliesslich fuer die exakte
aktive Linuxbasis `/var/www/svs-laravel/releases/20261104-clubunio-mobile-packages-foundation`
und deren current-Link vorbereitet. API aktiv, DEV-Origin exakt
https://dev.clubunio.ch, PostgreSQL db_acdf8d_dclubora. Native Training-,
Sponsorenlauf- und Anmeldungsflags muessen in allen Stufen false sein.
Lokale Aufgabe: nur Quellen-/Syntaxpruefung; kein PG-Lauf und keine Anwendung.

Drei getrennte Aufrufe durch den Releaseverantwortlichen:

1. `php deploy/prepare-mobile-packages-schema.php --export`
2. `php deploy/prepare-mobile-packages-schema.php --verify-backup --backup-file=/var/www/svs-laravel/shared/backups/<ausgegebener-Dateiname>`
3. `php deploy/prepare-mobile-packages-schema.php --apply --backup-file=/var/www/svs-laravel/shared/backups/<derselbe-Dateiname>`

Export erzeugt exklusiv einen neuen0600-Stream in einem festen0700-Ordner,
keine Ueberschreibung und keine Symlinks. Verification/Apply erlauben nur den
festen erzeugten Namensraum und eine eigene regulaere Datei mit einem Inodelink.
Einzelne Records sind mit dem vorhandenen App-Schluessel verschluesselt und
MAC-geschuetzt; globale SHA256-Kette, feste Reihenfolge und je Tabelle Count/
Digest werden beim erneuten Lesen geprueft. Es erscheinen keine Datensaetze,
Verbindungsdaten oder Schluessel in der Ausgabe.

Gesichert werden Spalten/Defaults, Indizes, Constraints und alle Zeilen der
acht festen Tabellen tenants, tenant_accounts, training_plans, team_seasons,
sponsor_run_events, sponsor_run_participants, registrations und migrations.
Serverseitige Cursor lesen maximal128 Zeilen je Batch. Wiederholbare read-only
Snapshots und explizite UTC-/DateStyle-/Bytea-Darstellung stabilisieren die
Vergleiche. Die Sicherung darf hoechstens eine Stunde alt sein; die Frische
wird direkt vor DDL erneut geprueft.

Apply verlangt unveraenderte feste SHA256-Dateien fuer genau100/200/300,
keine vorhandene Zieltabelle und keinen vorhandenen zugehoerigen Ledgerstand.
Teilstaende brechen ab. Eine vollstaendige Transaktion verwendet einen
nichtwartenden Advisorylock sowie SHARE ROW EXCLUSIVE NOWAIT fuer alle acht
Parents/Ledger; lock_timeout2s und statement_timeout15s. Es werden keine
wartenden Prozesse beendet und keine Locks zwangsweise uebergangen.

Vor DDL werden gesicherte Felder, Indizes, Constraints, Count/Digest und Ledger
unter diesen Locks verglichen. Nach DDL muessen alle drei neuen Tabellen leer
sein und ihre exakten Spalten, tenantgebundenen Primaer-/Fremdschluessel sowie
geschlossenen PUBLIC-Rechte passen. Alle alten Parents inklusive ihres
Metadaten-/Index-/Constraintstands und Zeilendigests bleiben identisch. Der
Ledger erhaelt ausschliesslich die drei festen neuen Migrationen in einem
neuen Batch; seine bisherigen Zeilen bleiben digestidentisch. Jeder Fehler
rollt die gesamte DDL-/Ledgertransaktion zurueck.

Es gibt keinen down-, Refresh-, Import-, Restore- oder automatischen Retrypfad.
Eine erfolgte Anwendung ist hier ausdruecklich nicht behauptet. Die finale
Quellpruefung, echte Linux-/PG-Ausfuehrung und Paketbereitstellung liegen bei Root.
