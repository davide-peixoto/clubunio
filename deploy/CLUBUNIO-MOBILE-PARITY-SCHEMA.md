# Vorbereiteter Schemahelfer800/900/1000

Nur Quellpaket vorbereitet und mit `php -l` geprüft. Kein Export, keine
Datenbankprüfung, keine DDL-Anwendung oder Bereitstellung durch diesen Agenten.
Root prüft und führt die Schritte später getrennt aus. Frühere Schemahelfer,
Migrationen und gemeinsame App-/API-/Config-/Routendateien bleiben unverändert.

## Feste Ausführungsgrenzen

`deploy/prepare-mobile-parity-schema.php` bootstrapped ausschließlich
`/var/www/svs-laravel/releases/20261104-clubunio-mobile-parity-foundation`.
Auch der Runner selbst muss in genau diesem Release liegen und `current`
dorthin zeigen. Linux-CLI, PostgreSQL18, Datenbank `db_acdf8d_dclubora`,
`https://dev.clubunio.ch` und persönliche Mobile-API sind Pflicht.

Vorgabe `deploy/mobile-parity-schema.json`, fester Byte-SHA256:
`9bc49f6f1700358e2fe175e4075bacb20e3311d7c1a9ee8e18af23b28612a06c`.
Root hat die ursprüngliche Windows1252-Datei nach unabhängigem Befund in
gültiges UTF8 ohneBOM korrigiert. Drei Migrations-SHAs sind zusätzlich fest im
Spec gebunden. Migration1000 hat lokal CRLF; ihr freigegebener Git-/Linux-LF-
Bytehash ist `6d5bafd5ca296f6161622cbb4cc729dccb6dedb5f6b245c039f45d955051f630`.
Der Runner normalisiert keine Quellen: abweichende Releasebytes brechen ab.
Alle Spec-Tabellen-/Spalten-/PK-/FK-Bezeichner werden vor SQL-Verwendung als
einfache Identifier geprüft, keine Constraintausdrücke als Spalten akzeptiert.

Bootstrap, Rollenhilfe, OCR-Provenienz, Pushadmin und Keyboxadministration
müssen geschlossen sein; zusätzlich Push-/FCM-Zustellung, OCR/Azure, Mobile-AI/
Trainings-AI, Identitätsmail und physische Keyboxoperationen. Bestehende native Sponsorabrechnung bleibt erhalten.
Push-Apps, Bootstrap-Originliste und operative Keyboxlisten müssen leer sein.
Der Helfer aktiviert keine Flags und ruft keine Provider auf.

## Getrennte Schritte

Genau eine Operation pro Lauf: `--export`, `--verify-backup` oder `--apply`.
Verify/Apply benötigen ausdrücklich `--backup-file=<private .cuenc-Datei>`;
Export darf keinen bestehenden Namen entgegennehmen. Dateien entstehen nur
unter `/var/www/svs-laravel/shared/backups`, Verzeichnis privat0700, Datei0600,
Eigentümer laufende UID, reguläre Einzel-Inode ohne Symlink/Hardlink. Exklusives
Anlegen/Lock, kontrollierter Read-Inode und Shared-Lock für spätere Prüfung.

Jeder Metadaten-/Zeilendatensatz ist einzeln mit LaravelCrypt verschlüsselt.
Servercursor streamt128 Zeilen; keine gesamten Elterntabellen im PHP-Speicher.
Pro Tabelle Count/SHA256, geordnete Gesamthashkette und Schlussrecord;
beim Export fflush/fsync, beim Lesen exakte Sequenz und begrenzte Datensatzgröße.
Keine Klartextdaten, Secrets oder Verbindungsdetails in Ausgabe/Fehlern.
Bestätigte Sicherung höchstens1h alt, vor/nach DDL erneut geprüft.

Export/Verify: ausschließlich Read-only Repeatable-read-Transaktion. Apply:
eine vollständige Transaktion, eigener Advisory-Xact-Lock und alle neun Eltern
einschließlich Ledger mit SHARE ROW EXCLUSIVE NOWAIT. lock_timeout2s,
statement_timeout15s je Statement, kanonisches UTC/DateStyle/Bytea/Floatformat.
Keine wartenden Prozesse beenden. Bestehende neue Tabellen oder irgendein
800/900/1000-Ledgerteil brechen vor DDL ab; kein Resume/down/refresh/Import.

## Genau erlaubte Bestandsdeltas

Alle neun Eltern werden vor/nach verglichen: Relationseigenschaften/ACL,
Spalten/Defaults/Nullbarkeit/Identität/Kollation, Indexdefinitionen und
Gültigkeits-/Unique-/Ready-Eigenschaften, Constraintdefinitionen mit PG18-
Enforcement/Vererbung/Deferral/Validierung sowie alle Zeilen als Count/Digest.
Indexkatalogzeilen werden zusätzlich vollständig gesichert/verglichen;
Constraintkatalog ebenfalls vollständig außer der bei Drop/Create zwangsläufig
neuen OID und dem durch exakte Definition geprüften internen conbin-Ausdruck.
Damit bleiben auch weitere PG18-Constraintprops beim erlaubten Checktausch
verbindlich unverändert, keine pauschale Name-/Text-Normalisierung.

800: Vorher geordnete IDs sämtlicher Settings, vorhandene Tenant-IDs und exakt
fehlende app/Tenant/Key-Paare in verschlüsseltem Backupheader. Grenzen:
2000Tenants,50000Bestandssettings,6000fehlendePaare; darüber sicher abbrechen.
Nachher nur genau diese neuen Paare zulassen, jede neue ID eindeutig vom
Bestand unterscheiden. Label/value_type, entschlüsselter Wert, enabledtrue,
is_secretfalse und area=app müssen exakt dem Spec entsprechen. Keine alten
IDs entfernen; alte Zeilen einschließlich vorhandener Standardwerte bleiben
im unveränderten Digest. Ausschluss aus Nachherdigest betrifft ausschließlich
die zuvor vollständig verifizierten neuen IDs. Vorgaben: MaintenanceMode0,
MaintenanceMessage exakt im Spec, RegistrationActive1.

900: ausschließlich neue Constraint/Index-Kombination
`mobile_ai_work_tenant_actor_id_unique` auf `(tenant_id,account_id,id)`.
Vorher beide absent; nachher exakt einmal UNIQUE, gültiger/ready/live Index,
nicht deferrable. Nur diese verifizierte Addition wird aus dem Metadatenvergleich
normalisiert; Bestandszeilen und alle übrigen Eigenschaften bleiben gleich.

1000: ausschließlich Austausch `mobile_push_outbox_kind_check`. Bekannte13
Kinds in exakter PG18-ANY/ARRAY-Definition, danach genau einmal
`, 'admin_notice'::character varying` hinter penalty. Keine semantisch ähnliche
oder breitere Definition akzeptiert. Nur diese eine Definition wird für den
Vergleich normalisiert; sämtliche sonstigen geprüften Constraint-/Elternprops
und Bestandszeilen müssen unverändert bleiben.

## Abschlussbedingungen

Vier neue Tabellen müssen leer sein, vollständige Spaltenreihenfolge,
elf validierte/enforced, sofortige Composite-/Tenant-FKs mit exakten Eltern
und Spalten sowie exakte sofortige PKs. Keine PUBLIC-Tabellenrechte.
Ledger genau drei neue Zeilen in einem einzigen neuen Batch; alte Ledgerzeilen
unverändert. Source-/Spec-/Runtime-/Backupaltergates nach Abschluss erneut.
Jeder Fehler vor Commit rollt DDL/Zeilen/Ledger atomar zurück. Identitäts-
Sequenzfortschritte werden wie üblich von PostgreSQL nicht zurückgerollt;
dieser Helfer setzt keine Sequenzen zurück oder importiert Daten.

## Bisherige Prüfung

Neue Datei PHP-Syntax grün. Statische Inventarprüfung3 Migrationen/4 Tabellen/
11FKs/9 Eltern, SpecUTF8 und festes SpecSHA geprüft.800/900-Arbeitsbytes
entsprechen dem Spec;1000-Git-/LF-Bytes entsprechen dem Spec, lokale CRLF-
Arbeitsbytes absichtlich nicht. Keine tatsächliche PostgreSQL-/Backupdatei-
Prüfung oder Anwendung behauptet; Root muss den festen Release bereitstellen
und alle drei Stufen unabhängig prüfen/ausführen.

Unabhaengiger zweiter Quellreview abgeschlossen: Spec-SHA/Inventare/Atomaritaet/
Bestandsschutz bestaetigt, keine konkrete Ausfuehrungs- oder Datenverlustluecke.
Kein PG-/DDL-Lauf durch die Reviews. Runtimeanwendung weiterhin separat.
