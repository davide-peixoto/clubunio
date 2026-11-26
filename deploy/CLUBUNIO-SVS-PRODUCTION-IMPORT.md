# SV Sissach: einseitiger PROD-Import und Delta-Agent

Freigabe des Betreibers vom 22./23.11.2026: Verein SV Sissach auf PROD
anlegen, Erstimport durchfuehren und danach alle 12 Stunden per Agent Deltas
uebernehmen. Ausschliesslich PHPMaker PROD nach ClubUnio PROD. Die vorherige
Beschraenkung auf Kopien ist fuer diesen geprueften Ablauf aufgehoben.
Keine Schluessel-/Tokenuebernahme, Providerjobs, Einladungen oder echten Zahlungen.

## Ablauf jedes Laufs

1. Der Windows-Agent exportiert `db_acdf8d_mysvs` in einer konsistenten
   SQL-Server-SNAPSHOT-Lesetransaktion. Nur SELECT, SET und abschliessendes
   ROLLBACK; kein PHPMaker-/Laravel-Bootstrap und keine Quell-DML/DDL.
2. Privater Snapshottransport per SSH zum VPS. Der dortige Runner prueft den
   unveraenderlichen Release, Quellidentitaet, Frische und alle Dumppruefsummen.
   Ein nicht blockierender exklusiver Prozesslock verhindert Parallelimporte.
3. Neues root-geschuetztes `pg_dump -Fc` von `clubunio_prod`, vollstaendiger
   Restore auf eine neue isolierte Kopie. Native Quelle separat neu aufbauen.
4. Merge und Wiederholung auf der Kopie. Bei Konflikt, unbekanntem Quellschema,
   geaendertem Zielschema oder Writes im Wiederholungslauf abbrechen.
5. Erst danach erzeugt der Runner eine kurzlebige root-eigene Freigabedatei.
   Sie bindet Richtung, Mandant, Snapshot, Backup, beide Testberichte und
   zuletzt erfolgreiches PROD-Ledger. Webzugriffe koennen sie nicht verwenden.
6. Einmalige historische Schemaerweiterung auf PROD, danach strikter Zielvertrag.
   Rollback-only-Vorschau auf den echten aktuellen Zeilen, anschliessend atomarer
   Fachmerge mit Feldbaseline und Laufbeleg. Freigabedatei nach Erfolg entfernen.

Es gibt keinen generischen Live-DB-Schalter. Ohne gueltige Freigabedatei lehnt
auch der erweiterte Runner `clubunio_prod` ab. Andere Live-Datenbanken und
andere Quellmandanten bleiben ausgeschlossen. Der Produktionspfad prueft
zusaetzlich, dass alle importierten Geheimwerte leer/deaktiviert, Konten inaktiv,
Passwoerter leer und Portalzuordnungen deaktiviert sind.

## Einbahnstrasse und Delta-Basis

Die dauerhafte Basis liegt in `migration_control.svs_state` und `svs_runs`
auf ClubUnio PROD. Ziel-IDs werden niemals durch Stage-/Quell-IDs ersetzt.
JSON-/verschluesselte Fremdschluessel werden ebenfalls umgebogen. Ein neuer
vollstaendiger Quelldump dient nur der konsistenten Deltaberechnung; auf PROD
werden ausschliesslich Inserts und angenommene Feldaenderungen geschrieben.

Lokale ClubUnio-Aenderungen werden erhalten. Gleichzeitige abweichende
Feldaenderungen fuehren zum vollstaendigen Rollback und einer Pruefmeldung;
es gibt weder einen Rueckschreibpfad nach PHPMaker noch ein automatisches
Ueberschreiben von Konflikten. Fehlende Quellzeilen sind Review-Kandidaten,
keine physischen Loeschungen. Baselines werden nur nach Commit fortgeschrieben.

Die bestehende Plattformadministration bleibt erhalten. Importierte
Benutzer werden nicht aktiviert. Die bekannte Plattformadministration kann
den Verein als lokale administrative Zuordnung verwalten; diese Zuordnung
ist kein Bestandteil der Quellsynchronisation.

## Betrieb

Persistenter Agent-Einstieg auf dem Windows-Host:

    python C:/ClubUnio/storage/app/private/prod-migration/agent-code/tools/run-svs-production-cycle.py

Quellcredentials bleiben ausschliesslich in der bestehenden privaten
PHPMaker-Konfiguration. Der Agent-Code und lokale Snapshots erben die
Administrator-/SYSTEM-ACL. Keine Secrets oder Fachdaten im Repository.

VPS: `/root/clubunio-svs-production`, nicht im Webroot. Dort liegen der
eingefrorene Importer, Release- und Runtimehashes, geschuetzte Konfiguration,
Snapshots, Backups und private technische Laufberichte. Alle Live-Schritte
verwenden die explizite Datenbank `clubunio_prod` und den Mandanten `sv-sissach`.
Die 12-Stunden-Ausfuehrung erfolgt durch eine Codex-Automation in diesem Chat,
nicht durch einen neu aktivierten Fach-/Providerjob auf dem VPS. Windows-Host,
Codex-Ausfuehrung, SQL Server und VPS muessen dafuer erreichbar sein.

Bei Fehlern keine Gate-Umgehung, keine automatische Mapper-/Schemaanpassung und
kein neues OAuth-/Providerlogin. Diagnose anhand der privaten Laufakte;
technisch berichten, keine personenbezogenen Werte oder Secrets ausgeben.
Schema-/Importercodewechsel erfordern einen erneuten geprueften Release.

Der ausfuehrbare Quellcode liegt im Branch `codex/svs-production-import-20261122`;
der Web-Runtime-Release basiert separat auf dem aktuellen PROD-Branch und
erhaelt dessen parallel vorhandene Wallet-/Push-Arbeiten. Erfolgreiche Laeufe
entfernen nur ihre beiden selbst erzeugten temporaeren Datenbanken. Snapshots,
Backups, Laufbelege und dauerhafte Feldbaselines bleiben erhalten.

## Verbleibende Grenzen

19 Altquittungen enthalten ausschliesslich Dateireferenzen und historische
Metadaten. Keine Binaerdateiuebernahme oder neue Signaturpruefung.
Vier nicht eindeutig zuordenbare historische Serienautoren bleiben unbekannt.
Historische Quelldokumente sind unveraenderlich und werden nicht erneut
versendet, signiert oder als gepruefte ClubUnio-Dateien ausgegeben.


## Abnahme am 23.11.2026

Erstimport: 37'911 Datensaetze, darunter 2'448 Mitglieder, 6'799 Rechnungen
und 487 Spesenbelege mit 3'865 Positionen. Danach echter Delta-Lauf mit
181 Updates (50 Rechnungen, 46 Wettbewerbstermine, 85 SFV-Spiele), ohne
Konflikte oder neue Datensaetze. Rohquellvergleich bestaetigt diese Aenderungen.
Wiederholung desselben Dumps auf der Kopie: 0 Inserts/0 Updates.
147 Migrationen, 14 gepruefte Saisonkorrekturen, 0 verbleibende Abweichungen.
0 aktivierte importierte Konten/Portale, 0 uebernommene Geheimwerte, 0 Push-Outbox.
Plattformadministration erhalten; info@clubunio.com lokal als Vereinsadmin
zugeordnet, ohne Portalaktivierung oder Einladung.

Automation sv-sissach-prod-delta-alle-12-stunden ist ACTIVE, alle12Stunden,
Meldungen bei fehlgeschlagenen Laeufen. [Sanitisierter Nachweis](evidence/svs-production-import-20261123.json).
