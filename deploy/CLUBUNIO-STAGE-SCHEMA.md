# Stage-Schema: clubunio

Die Umstellung betrifft nur `clubunio_stage` auf dem VPS. Der Datenbankname,
Mandant, native IDs und die 14 korrigierten Spesenverknuepfungen bleiben gleich.
`legacy_prod` und `migration_audit` bleiben getrennt und unveraendert.

## Code und Migration

`codex/stage-clubunio-schema` ist der Stage-Branch; nicht nach `main` mergen
oder auf DEV/PROD ausrollen, solange diese Umgebungen `clubora` verwenden.
Anwendungsabfragen, SQL-Referenzen, Suchpfad, Tests und der Stage-Migrator
verwenden `clubunio`. Alte Web-URLs, Integrationskennungen, Kalender-UIDs und
Dateinamen bleiben absichtlich kompatibel. Historische DEV-Release-Anleitungen
behalten ihre urspruenglichen Namen. Neue gemeinsame SQL-Aenderungen muessen
beim Uebernehmen in diesen Branch auf den Stage-Schemanamen geprueft werden.

Die 117 bereits gebuchten Migrationen werden beim Wechsel nicht wiederholt:
PostgreSQL verschiebt das Schema mitsamt Journal, Tabellen, Sequenzen und
objektgebundenen Abhaengigkeiten durch `ALTER SCHEMA ... RENAME TO`. Ein
spaeterer leerer Stage-Neuaufbau verwendet direkt den neuen Namen; der Runner
lehnt sowohl ein vorhandenes `clubora` als auch `clubunio` ab.

## Durchfuehrung und Rueckweg

[rename-stage-schema.sh](rename-stage-schema.sh) erwartet die festgelegte
Stage-Basis, einen geprueften Zielcommit und ein Git-Bundle. Vor dem Wechsel
erstellt es ein privates verschluesseltes `pg_dump` des nativen Schemas,
liest das ganze Archiv testweise und speichert die SHA-256-Pruefsumme.
Die Passphrase bleibt im root-geschuetzten Sicherungsordner, ausserhalb von
Git und Webroot. Das Wiederherstellen in eine Testdatenbank ist ein separater
Schritt; der normale Rueckweg braucht keinen Datenrestore.

Kurze Stage-Wartung, Rollback-Probe der Umbenennung, danach Namens- und
Codewechsel. Datenfingerabdruecke aller Tabellen im nativen und beiden
Snapshot-Schemas muessen vorher/nachher uebereinstimmen. Die Stage-Anwendung
prueft lesend Suchpfad, Mandant, Saisonreferenzen, Journal und deaktivierte
Konten. Nach Freigabe: Health, Login und geschuetzte Mitgliederliste pruefen.
Keine Aenderung der Inhalte von Stage-Umgebungsdateien oder Neustart
gemeinsamer Dienste. Falls die Stage-PostgreSQL-Datei fuer den Webbenutzer
nicht lesbar ist, wird ihr ACL privat gesichert und nur diesem Benutzer
Leserecht gegeben. Der Anwendungstest vor der Wartung muss bestehen;
bei Abbruch wird auch der vorherige ACL wiederhergestellt.

Bei Fehler: Stage in Wartung belassen, nur auf `clubunio_stage`
`ALTER SCHEMA clubunio RENAME TO clubora` ausfuehren, Code auf `6621b86`
zurueckstellen, Stage-Konfigurations-/Routen-/View-Caches entfernen, pruefen
und Wartung beenden. Das Skript versucht diesen Rueckweg automatisch und
bricht bei einer fehlgeschlagenen Ruecknahme ohne weitere Freigabe ab.

## Status

10.11.2026: 219 automatisierte Tests bestanden, ein optionaler uebersprungen.
Ein bestehender, unveraenderter Marketing-Uebersetzungstest separat gelassen.
Erster Stage-Versuch: verschluesseltes Archiv erstellt und vollstaendig
gelesen, Rollback-Probe bestanden. Der Anwendungstest scheiterte am fehlenden
Leserecht der Stage-PostgreSQL-Datei. Code und Schema automatisch auf den
vorigen Stand zurueckgesetzt. Das Skript prueft dies jetzt vor Wartung und
Umbenennung. Der direkte SSH-Zugang ist zeitweise nicht erreichbar.

Weiterer direkter Versuch von Windows am 10.11.2026: SSH auf Port 22
brach erneut vor der Lesepruefung ab. Stage-Health ueber HTTPS antwortete
weiterhin mit 200. Keine erneute Umbenennung und keine ACL-Aenderung.

Beim naechsten Verbindungsfenster wurde der neuere Stage-Basiscommit
6621b86 lesend festgestellt. Die Sicherheitspruefung stoppte vor jeder
Aenderung; dessen Marketing-Updates wurden in den Stage-Branch uebernommen.
PostgreSQL-Hostname und Loopback-Aufloesung wurden bestaetigt. Das angepasste
Paket konnte wegen erneuter SSH-Timeouts noch nicht aktiviert werden.

Stage verwendet den RunCloud-Pool fuer PHP 8.3; dort ist PDO-PgSQL fuer
die CLI vorhanden. Die Vorpruefung unterscheidet jetzt CLI-Treiber,
FPM-Treiber, Checkout und Schema-Zustand. Eine Stage-spezifische Sperre
verhindert parallele Umstellungslaeufe. Der direkte Wiederanlauf stoppte
erneut an SSH-Port 22, bevor die erweiterten Pruefungen ausgefuehrt wurden.

## Erfolgreicher Wechsel

10.11.2026: Direkt von Windows, ohne Pi oder offenen SSH-Tunnel. Stage
verwendet jetzt `clubunio_stage.clubunio` mit Codecommit `89baee5`.
PDO-PgSQL fuer PHP 8.3 CLI und FPM geprueft. Die vorhandene private
PostgreSQL-Konfiguration ist fuer den Stage-Webbenutzer lesbar; ihr Inhalt
blieb unveraendert. Keine gemeinsamen Dienste neu gestartet.

Verschluesseltes, vollstaendig lesbares Backup unter
`/root/clubunio-stage-schema-SAxjzr2n`. Rollback-Probe bestanden. Alle 308
Tabellenfingerabdruecke vor/nach Wechsel identisch, einschliesslich beider
Snapshot-Schemas. Journal mit 117 Migrationen, Mandant und Saisonreferenzen
lesend geprueft. Health und Login HTTP 200, Mitgliederseite anonym HTTP 302.
Importierte Konten und geheime Integrationen bleiben deaktiviert. Kein
PROD-Neuabzug; DEV und PROD unveraendert. Rueckweg wie oben beschrieben.
