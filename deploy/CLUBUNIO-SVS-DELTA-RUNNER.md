# SV Sissach: Import- und Delta-Runner auf PROD-Kopie

Stand 22.11.2026. Umsetzung und Tests sind ausschliesslich fuer isolierte
Datenbanken freigegeben. Der Runner lehnt clubunio_prod technisch ab.
Kein neuer SV-Sissach-Fachbestand wurde auf der echten PROD angelegt.

## Architektur und versionierte Feldzuordnung

1. build-svs-import-candidate.php liest den bereits gesicherten,
   konsistenten PHPMaker-Dump. Keine Verbindung zum SQL Server.
   Manifest-/Datei-/Zeilenpruefsummen werden vor der Verarbeitung geprueft.
   Die vollstaendige historische Aufbereitung findet ausschliesslich in
   einer neuen, leeren clubunio_stage_svs_candidate_<8 Hexzeichen> statt.
2. Die bestehenden Feldkonvertierungen der elf Fachimporte und der
   historischen Datenmigrationen erzeugen den nativen Zustand. Sie
   schreiben niemals in die PROD-Kopie oder PROD. Alle Quelltabellen
   bleiben im privaten legacy_prod-Schema der Aufbereitung verfuegbar.
   Historische SQL-Definitionen bleiben inert.
3. SvsProjectionVersion bindet Importer, Migrationen, Relationstabellen,
   Vorlagen-/Statusseed und Aufbereitungscode mit SHA256 an die
   Aufbereitungsquittung. Ein veraenderter Code wird nicht still als
   derselbe Mapper akzeptiert.
4. deploy/svs-import-mapping-v1.json friert fuer die 75 befuellten
   nativen Tabellen Spalten, Typen, stabile Identitaet, Primaerschluessel
   und Fremdschluesselzuordnungen ein. Das ist der konkrete native
   Feldvertrag; die Quellfeldkonvertierung steht im gebundenen
   Fachimportcode. Kein beliebiger Schema-/Spalten-Automerge.
5. deploy/svs-target-schema-v1.json bindet die aktuell 145 Migrationen und
   2651 Spaltendefinitionen samt Laengen, Praezision, Skala und Kollation der wiederhergestellten PROD-Basis.
   Eine andere Zielstruktur wird vor dem Import abgelehnt.
   Metadaten werden mit einheitlichem search_path kanonisiert.
6. SvsDeltaImporter uebertraegt die Aufbereitung in die isolierte
   clubunio_svs_rehearsal_<8 Hexzeichen>. Neue native IDs, bestehende
   Identities aus dem Ledger, keine Kopie von Stage-/DEV-Ziel-IDs.
   Alle Fremdschluessel werden ueber die Identitaetsabbildung aufgeloest.
   Parent-Abhaengigkeiten bestimmen die Reihenfolge; unbekannte
   Referenzen, Zyklen und Schluesselkonflikte brechen ab.

## Dauerhafte Delta-Basis

migration_control.svs_state:
- entity + HMAC der stabilen Identitaet als eindeutiger Schluessel;
- tatsaechlicher nativer Ziel-PK als JSON;
- je verwaltetem Feld HMAC des zuletzt angenommenen Quellwerts und
  HMAC der zuletzt angewendeten Zielrepraesentation;
- Mappingversion aus Feldvertrag und Projektionsfingerprint.

Keine Fachwerte, Benutzernamen, Bankdaten oder Passworthashes in der
Baseline. HMAC-Schluessel vom privaten Ziel-APP_KEY abgeleitet.
migration_control.svs_runs protokolliert nur Version, Quelldump-Hash,
technische Mengensummen und Abschlusszeit. Webausgaben erhalten weder
Baseline noch Rohwerte. Die Schemas sind nicht fuer PUBLIC freigegeben.

Verschluesselte Felder werden mit dem privaten Aufbereitungsschluessel
entschluesselt und mit dem Zielschluessel neu verschluesselt. Verglichen
werden HMACs des Klarwerts, nicht zufaellige Ciphertext-Nonces.
JSON wird kanonisiert; SQL-Repraesentation beim Insert nachgelesen.
APP_KEY und Baseline muessen gemeinsam gesichert bleiben.

Dreiwegevergleich:
- Quelle unveraendert -> Ziel einschliesslich lokaler Aenderungen erhalten.
- Quelle geaendert, Ziel unveraendert -> Delta schreiben.
- Bereits gleichlautend geaendert -> keine doppelte Mutation.
- Unterschiedliche Aenderung am selben Feld -> Konflikt, gesamter Lauf
  einschliesslich frueherer Fachdaten- und Ledgerwrites wird zurueckgerollt.
- Fehlende Quellidentitaet -> missing_review, keine physische Loeschung.
- Native Zielobjekte ohne Ledger bleiben erhalten.
- Revisionen werden bei Fachupdates erhoeht. Erstell-/Aenderungszeit,
  Passwoerter und operatorgesteuerte Kontofreigaben werden nicht als
  normale Quelldeltas ueberschrieben.
- Ein identischer Lauf schreibt keine Fachdaten und keine Feldbaselines.
  Ein technischer erfolgreicher svs_runs-Protokolleintrag ist beabsichtigt.

Aufbereitung wird in einem konsistenten READ-ONLY/REPEATABLE-READ-Snapshot
gelesen. Ziel hat einen laufweiten Advisory Lock und Zeilensperren bis
Commit; Zielrevision/Feldstand werden unter diesen Sperren verglichen.
Import und neue Baselines sind eine Zieltransaktion. Kein Teilfortschritt
nach Konflikt oder Fehler. Mappingwechsel verlangen explizite
Baseline-Migration statt stiller Neubewertung.

## Lauf auf der isolierten Umgebung

Private Umgebung: /root/clubunio-import-lab/code.
Pakete werden aus dem vorhandenen vendor genutzt; svs-lab-autoload.php
bindet App-Klassen ausdruecklich an das Lab und verhindert, dass die
optimierte Composer-Classmap versehentlich Live-App-Klassen laedt.
Kein Webserver, Scheduler oder Worker fuer das Lab.

Private Dateien:
- .env: eigener Aufbereitungsschluessel, staging, keine OIDC-/Provider-
  Konfiguration, Mailarray und keine externe Queue.
- .env.postgresql.local: bestehende TLS-Verbindung; der DB-Name wird
  ausschliesslich auf die expliziten isolierten Namen gesetzt; der private
  Lab-Standard ist ebenfalls die isolierte Aufbereitungsdatenbank.
- .env.import-target.local: gesicherter Ziel-APP_KEY und SOURCE_SNAPSHOT_DIR.
  Diese Dateien liegen root-only, nicht im Repository.
- Http::preventStrayRequests, outbound_enabled=false; keine Modelsaves
  oder fachlichen Versandevents beim Merge.

Aufbereitung (nur neue leere isolierte DB):

    php -d memory_limit=1G tools/build-svs-import-candidate.php clubunio_stage_svs_candidate_a1b2c3d4 /root/clubunio-import-lab/source 220663b8e05e25adcbefe1c8ac3e13bcec211bd270902e6c28d19e454f7bae85

Merge-Probelauf mit ROLLBACK oder Apply ausschliesslich auf PROD-Kopie:

    php -d memory_limit=1G tools/run-svs-delta-import.php --rehearse clubunio_stage_svs_candidate_a1b2c3d4 clubunio_svs_rehearsal_c3d4e5f6 220663b8e05e25adcbefe1c8ac3e13bcec211bd270902e6c28d19e454f7bae85
    php -d memory_limit=1G tools/run-svs-delta-import.php --apply clubunio_stage_svs_candidate_a1b2c3d4 clubunio_svs_rehearsal_c3d4e5f6 220663b8e05e25adcbefe1c8ac3e13bcec211bd270902e6c28d19e454f7bae85

Aktuelle Testdatenbanken:
- clubunio_stage_svs_candidate_a1b2c3d4: reproduzierbare Aufbereitung;
- clubunio_svs_rehearsal_a1b2c3d4: Integrationstests mit synthetischen
  Aenderungen, Loeschkandidat und lokalen Zielaenderungen;
- clubunio_svs_rehearsal_b2c3d4e5: erste saubere Abschlusskopie mit 143 Migrationen.

Die Testdatenbanken enthalten echte Quelldaten und bleiben privat.
Keiner dieser Namen wird von einer laufenden Web-App verwendet.
Sie nicht als neue Stage-/PROD-Instanz anbinden.

## Nachgewiesene Tests

Echter Quelldump: 100 Tabellen / 55588 Zeilen. Erstaufbereitung:
37550 native Datensaetze in 75 Tabellen. Vollstaendiger Merge mit ROLLBACK
erfolgreich; danach verbindlicher Erstimport ausschliesslich auf PROD-Kopie.
Identischer Wiederanlauf in einem neuen PHP-Prozess: 0 Inserts, 0 Updates,
37550 unveraenderte Datensaetze, keine Konflikte.

Acht Integrationsszenarien (tools/test-svs-delta-rehearsal.php):
1. Genau ein Insert und ein Update, daneben lokale Feldveraenderung
   und ein nur nativ angelegter anderer Mandant bleiben erhalten.
2. Wiederholung des geaenderten Bestands: 0 Inserts/Updates.
3. Fehlende Quellzeile: ein Review-Kandidat, Zieldatensatz bleibt erhalten.
4. Unterschiedliche Quell-/Zielaenderung: Konflikt; Laufjournal und
   HMAC-Baseline bleiben unveraendert.
5. Kuenstlicher SQL-Fehler bei Trainingszeiten nach bereits versuchtem
   Bemerkungsupdate: beide Fachdaten und Baseline zurueckgerollt.
6. Produktivname clubunio_prod wird vor einem Schreibzugriff abgelehnt.
7. Falscher Quellmandant wird abgelehnt.
8. Bestehender Plattformadmin und alle 143 Migrationen samt Wallet bleiben.

Technische Berichte ohne Fachwerte:
/root/clubunio-import-lab/build-result.json,
first-apply-result.json, replay-result.json, integration-results.json,
final-apply-result.json und final-replay-result.json.

## Abdeckung und ausdrueckliche Grenzen

deploy/svs-source-coverage-v1.json inventarisiert alle 100 Quelltabellen
und alle Quellspalten mit Mapping-Codeverweisen bzw. Ausnahmekategorie.
Ein Codeverweis ist KEIN Beweis, dass jedes Quellfeld fachlich gemappt ist.
Das Original bleibt immer im geprueften privaten Quelldump erhalten.
Es werden keine Original-Provider-/Sessiontoken in native aktive Dienste
uebernommen. Alle 78 importierten Konten bleiben deaktiviert, ohne
nutzbare Altanmeldung und ohne automatische Keycloak-Zuordnung.

Neun befuellte Quelltabellen sind noch nicht nativ gemappt:
AppAnkuendigungen (1), DokumentSerien (4), DokumentSerienEmpfaenger (17),
DokumentVorlagen (4), MitgliederAenderungsantraege (18),
MitgliederQuittungen (19), TeamTerminGaeste (2),
TrainingPlannerAuditLog (47), Verbandsspiele (231).
Diese Bestands-/Workflowkonvertierungen benoetigen eigene gepruefte
Mapper und Abnahmen; keine erfundenen Autoren, Freigaben oder
materialisierten Zustellungen. Das Paket ist deshalb kein
Vollstaendigkeitsnachweis einer 100-Tabellen-Fachmigration.

Der Vereinsverzeichnis-Importer vereinigt Quellzeilen nach Vereinsnummer
und laesst uneindeutige/invalide Gruppen aus. Historische Spesen-/
Teamsaisonabweichungen werden erhalten und ausgewiesen, nicht mit
alten Stage-ID-Korrekturen repariert. Dokumente sind Referenzen,
keine kopierten Azure-/Upload-Binaerdateien. Originale Freitexte bleiben
erhalten. Vorlagen sind getrennt DE/EN angelegt; Legacy-Lookupnamen
behalten den dokumentierten Originalsprachen-Fallback.

Vor spaeterer PROD-Freigabe: die neun Fachluecken und ausgeschlossenen
Felder beurteilen/schliessen, historisches Mapping fachlich abnehmen,
separaten Migrationsbenutzer/Rechte fuer PROD festlegen, neuen aktuellen
Quelldump und Zielbackup erzeugen, Probe wiederholen und Live-Zielschutz
erst als eigenstaendige gepruefte Aenderung erweitern.
Keine PROD-Ausfuehrung in diesem Paket.
## Abschliessender Abgleich

Frische PROD-Kopie b2c3d4e5: alle 75 Tabellenzaehler entsprechen der
Aufbereitung; accounts enthaelt zusaetzlich genau den erhaltenen
Plattformadmin. 2448 Mitglieder, 6799 Rechnungen, 487 Spesenbelege.
Native Brutto-/Nettorechnungssummen und Spesensummen stimmen zwischen
Aufbereitung und Kopie exakt ueberein. Plattformadmin unveraendert,
143 Migrationen erhalten. Historisch bereits in der Quelle vorhandene
14 Spesen-/Teamsaisonabweichungen werden identisch ausgewiesen.
53 DE-CH- und 53 EN-GB-Vorlagen vorhanden.

Zusaetzlich getestet:
- verschiedene Felder desselben Datensatzes gleichzeitig lokal und
  in der Quelle geaendert: Quellfeld aktualisiert, lokales Feld erhalten.
  Beide aeusseren Testtransaktionen anschliessend zurueckgerollt;
  saubere Abschlusskopie bleibt erhalten.
- veralteter/anderer Quelldump-Hash vom CLI abgewiesen;
- Produktivname bereits vom CLI vor Bootstrap abgewiesen;
- testweise geaenderte Zielspalte vom Schemafingerprint abgewiesen;
- testweise geaenderter Quellspaltentyp abgewiesen.
  deploy/svs-source-schema-v1.json bindet dafuer alle 100 Quelltabellen,
  Spaltenmetadaten und Primaerschluessel. Private SOURCE_SNAPSHOT_DIR
  muss zum gewaehlten Manifest-Hash passen. Keine stille Schemaanpassung.

acceptance-summary.json, guard-results.json und field-merge-result.txt
liegen privat im Lab. Abschliessender CLI-Lauf nach allen Guards mit
ROLLBACK: erneut 0 Inserts/Updates und 37550 unveraenderte Datensaetze.

Die echte clubunio_prod hat weiterhin null Vereine und kein
migration_control-Schema. Damit ist auch die Importbasis dort nicht
angelegt. Dieses Paket wird nur auf codex/svs-import-runner-20261122
synchronisiert, nicht auf dem PROD-Branch ausgerollt.
## Wiederholte Abnahme nach paralleler PROD-Konsolidierung

Waehrend der Arbeit wurden in einem anderen Auftrag weitere Aenderungen
nach PROD uebernommen. Sie wurden in diesem Paket weder angefasst noch
mitcommittet. Um die Umsetzung von dieser parallelen Arbeit zu trennen,
liegt sie auf eigenem Worktree/Branch codex/svs-import-runner-20261122,
mit der reproduzierbaren Projektionsbasis 38c428e.

Der inzwischen aktuelle PROD-Stand b1d44c8 wurde erneut lesend gesichert:
 /root/clubunio-import-lab/current-prod-20261122T210821Z/prod.dump
SHA256 fa308afd2c9a3b535e4bf8376bf9de96942d47949c7af0b1ec8cc620f4321022.
Diese Sicherung wurde nach clubunio_svs_rehearsal_c3d4e5f6
wiederhergestellt (145 Migrationen, 2651 Spalten) und ausschliesslich
dort erneut importiert: 37550 Inserts, danach 0 Inserts/Updates.
Die 75 Tabellenzaehler, nativen Finanzsummen, Plattformadmin und
deaktivierten Importkonten sind auch auf diesem Stand geprueft.

Eine weitere Kopie d4e5f6a7 hat alle acht Konflikt-/Rollback-/
Berechtigungsszenarien mit 145 Migrationen erneut bestanden. Die
frueheren 143-Tests bleiben als historische Regressionsnachweise erhalten.
Der aktuelle Zielvertrag ist ausdruecklich auf 145 festgelegt; die
143-Datenbanken werden vom aktuellen CLI-Vertrag daher abgelehnt.

Ende-zu-Ende-Deltatest ab einer synthetisch abgeaenderten Kopie des
echten Quelldumps, ohne Aenderung des SQL Servers:
Eine Bemerkung neu, eine geaendert, eine aus dem Fixture entfernt.
Aufbereitung komplett neu aufgebaut, dabei verschieben sich native
Projektions-IDs. Merge auf geklonter 145-Basis ergibt genau
1 Insert / 1 Update / 1 fehlende Quellzeile zur Pruefung.
Wiederholung: 0 Inserts / 0 Updates, weiterhin 1 Review-Kandidat.
Alle vorherigen nativen Ziel-IDs bleiben erhalten; keine physische
Loeschung. Auch als wiederverwendbares Testwerkzeug verfuegbar:

    python3 tools/test-svs-snapshot-delta.py --lab-root /root/clubunio-import-lab --baseline-db clubunio_svs_rehearsal_c3d4e5f6

Das Werkzeug erzeugt neue zufaellig benannte isolierte Datenbanken,
private synthetische Snapshotdateien und einen technischen Ergebnisbericht.
CLUBUNIO_SVS_SNAPSHOT_DIR waehlt dabei den privaten Fixturepfad, ohne
die .env zu veraendern. Es kann keine Live-PROD als Basiskopie waehlen.

Ein zusaetzlicher Test aendert nur die numerische Praezision eines
Finanzfelds auf einer Testkopie: Der CLI-Schemagate lehnt auch dies vor
dem Merge ab. Feld-Updates erhoehen Revision und updated_at; der
Wiederholungslauf laesst beide unberuehrt.

Sanitisierter Abnahmenachweis:
deploy/evidence/svs-delta-rehearsal-20261122.json.
Er enthaelt nur technische Zaehler, Versionen und Testresultate,
keine Fachwerte, Source-Identitaeten, Secrets oder Feldbaseline-HMACs.