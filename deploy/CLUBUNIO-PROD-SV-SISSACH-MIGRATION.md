# SV Sissach: Erstimport und wiederholbare Deltas nach ClubUnio PROD

Stand 22.11.2026. Auftrag: aktuelle Dumps und Migration vorbereiten,
noch keinen Fachimport ausfuehren. Quelle vom Betreiber bestaetigt:
bestehendes PHPMaker-Produktivsystem, SQL Server db_acdf8d_mysvs.
Ziel: ausschliesslich ClubUnio clubunio_prod, Mandant sv-sissach.
DEV und Stage sind keine Datenquelle. Keine echten Bankzahlungen.

## Gesicherte Ausgangslage

ClubUnio PROD:
- /root/clubunio-prod-backups/sv-sissach-preparation-20261122T203035Z/clubunio_prod.dump
- PostgreSQL-Custom-Archiv, 1975069 Bytes, SHA256
  dfb3ef5e96fd3531021bb278c36bfe8241efbb52c3c4793827d2da42162fb782.
- Archivverzeichnis geprueft; vollstaendige Wiederherstellung in eigener
  temporaerer Pruefdatenbank erfolgreich. Diese danach entfernt.
- 143 registrierte Migrationen, ein Konto, ein Plattformadmin, null Vereine.
- Code-HEAD c7574b1; auf dem Server zusaetzliche uncommittierte Wallet-
  Dateien und zwei Walletmigrationen. Diese fremden Aenderungen erhalten.
  Vor fachlichem Apply den dann tatsaechlichen Code-/Schemastand erneut
  erfassen; nicht mit einer alten 141-Migrationen-Baseline arbeiten.
- ROOT-only Backupordner, Dump Modus 0600. manifest.json dokumentiert
  SHA256, Istzaehler und erfolgreichen Restore.

Aktueller PHPMaker-Quelldump:
- C:/ClubUnio/storage/app/private/prod-migration/svs-source-20261122-213203
- 100 Tabellen, 55588 Zeilen. Alle 100 Tabellen haben Primaerschluessel.
- Konsistente SQL-Server-SNAPSHOT-Lesetransaktion; am Ende ROLLBACK.
  Keine Quell-DML/DDL, keine PHP-Anwendung, Events oder Provider gestartet.
- Logischer Datenabzug: gzip-JSONL je Tabelle, Typ-/Spaltenmetadaten,
  Primaerschluessel und inerte View-/Prozedur-/Funktions-/Triggerdefinitionen.
  Kein nativer SQL-Server-.bak und kein binaerer Dokument-/Azure-Download.
  Der Abzug ersetzt keine Wiederherstellungssicherung des Quellsystems.
- Manifest SHA256:
  220663b8e05e25adcbefe1c8ac3e13bcec211bd270902e6c28d19e454f7bae85

- Quelldateien enthalten vertrauliche Originaldaten und gegebenenfalls
  Alt-Passworthashes/Parameter. NTFS-Zugriff nur Administrator und SYSTEM.
  Nicht in Git, Tickets, Webroot oder Berichte kopieren.

## Bereits ausfuehrbare Vorbereitung

PHP-Exporter tools/export-svs-production-snapshot.php:
liest nur die exakt benannte Quelle mit TLS-Zertifikatspruefung.
Erfordert einen neuen Ausgabeordner unter einem geschuetzten Elternordner.
Ein unvollstaendiger Lauf hat kein gueltiges Abschlussmanifest und darf
nicht als Basis verwendet werden. Konfiguration ausschliesslich aus dem
bestehenden privaten application/.env.prod.local. Keine Credentialausgabe.

    php tools/export-svs-production-snapshot.php --output-dir <neuer-privater-Ordner>

Bei Ausfuehrung aus einem Worktree kann CLUBUNIO_TOOL_AUTOLOAD auf das
vorhandene vendor/autoload.php des kanonischen Workspace zeigen.
Der Exporter bootet weder Laravel noch PHPMaker.

Python-Planer tools/plan-svs-production-delta.py:
prueft Manifest, komprimierte Dateien, alle Zeilen-Pruefsummen und Zaehler,
Spaltenform und eindeutige, nicht leere Primaerschluessel. Anschliessend
vergleicht er fachliche Quellidentitaeten und Werte, ohne DB-Verbindung.

    python tools/plan-svs-production-delta.py --previous <letzte-angenommene-Basis> --current <neuer-Quelldump>

Ausgabe nur technische Tabellen-/Mengensummen: neue/geaenderte Zeilen,
in der Quelle fehlende Zeilen, Schemaaenderungen und Blocker.
Keine personenbezogenen Datensaetze oder Schluessel in der Ausgabe.
Der Selbstvergleich des echten Dumps: 0 Aenderungen, 0 Blocker.
Sechs synthetische Tests erfolgreich: Inserts/Updates/fehlende Zeilen,
Wiederholung, lokale Zielaenderungen und Konflikte, schluessellose
Aenderungen, korrupte Dateien, doppelte PK und Schemawechsel.

Wichtig: Der Planer meldet ausdruecklich apply_supported=false. Er ist
eine Quell-Deltapruefung; er schreibt noch keine Fachobjekte nach PROD.

## Verbindlicher Importvertrag

1. Fuer jeden Lauf unveraenderlichen Quelddump und Zielbackup erstellen.
   Manifest, Mappingversion, Code-Commit, Migrationen, Ziel-DB, Mandant,
   vorherigen ERFOLGREICH ANGEWENDETEN Lauf und Freigabe festhalten.
   Einen bloss exportierten oder fehlgeschlagenen Lauf nie zur Basis machen.
2. Neue native IDs vergeben. Dauerhafte Identitaetszuordnung:
   Quellsystem + Quelltabelle + Quell-PK + Mandant -> Zieltyp + Ziel-ID.
   tenant_id immer explizit. Bestehende legacy_id nutzen und pruefen;
   keine Stage-/DEV-Ziel-IDs uebernehmen. Beziehungen ueber diese Map
   aufloesen. zusammengesetzte PK typisiert und eindeutig serialisieren.
3. Erstimport gegen vorhandenes aktuelles PROD-Schema bauen. Kein
   migrate:fresh, Schema-Drop, ALTER zur Rueckdatierung oder erneut
   abgespielter historischer Stage-Aufbau. Plattformadmin, Subject-Map,
   APP_KEY, Domains, Laufzeitkonfiguration und Walletobjekte erhalten.
4. Einen separaten, transaktionalen Erstimport fuer sv-sissach vorbereiten.
   Reihenfolge aus den vorhandenen Fachimporten:
   Foundation/Lookups/Mitglieder -> Vereinsobjekte und Saisons ->
   Mitgliederdetails -> Mannschaftszuordnungen -> Dossier/Familien/
   Dokumentreferenzen -> Kontoverknuepfungen -> Anmeldungen ->
   Beitraege/Trainings -> Anlagen -> Teamplanung -> Fussballvereine.
   Weitere Fachbestaende (u.a. Finanzen, Material, Bus) anhand der
   vollstaendigen Importabdeckung separat abgleichen. Die elf
   Stage-Checkpoints sind KEIN Vollstaendigkeitsnachweis aller 100 Tabellen.
5. Vorhandene Importer lehnen gefuellte Ziele ab und enthalten teilweise
   Importsperren/Versionsmarker. Sie werden nicht als Delta-Runner benutzt.
   Vor Wiederverwendung jedes Feldmapping gegen den aktuellen
   143+-Migrationsstand und Abhaengigkeiten pruefen. Keine Dummy-
   Legacy-Tabellen oder leeren Konten anlegen, um Guards zu umgehen.
6. Konten aus der Quelle standardmaessig deaktiviert und ohne nutzbare
   Altanmeldung. info@clubunio.com als existierenden Plattformadmin
   erhalten; keine Kollision anhand gleicher Legacy-ID oder E-Mail
   automatisch zusammenfuehren. Vereinsadmin-Zuordnung gesondert festlegen.
   Providersecrets, Bank-/Versandfreigaben und Tokens nicht aktivieren.
7. DE und EN beruecksichtigen. Stabile Lookupcodes sprachunabhaengig;
   vorhandene Freitexte unveraendert. Dynamische Lookup-/Vorlagentexte
   erhalten explizite Sprachvarianten. Fehlendes EN sichtbar dokumentieren
   und den DE-Ursprung als Fallback ausweisen; keine stillen
   Freitextersetzungen oder automatische Sprachaktivierung.
8. Nach jeder angenommenen Mutation Feldbasis speichern: letzte
   erfolgreich angewendete Quellwerte und erwartete Zielwerte nach
   Transformation sowie Zielrevision. Rohwerte nur privat verschluesselt,
   technische Protokolle ohne Fachwerte. Hashes mit versionierter
   kanonischer Darstellung; Null, Leerstring, Dezimalwerte, Zeitpraezision
   und binaere Daten unterscheiden.

## Delta-Regeln

Pro explizit gemapptem Feld Dreiwegevergleich:
- Quelle unveraendert seit letzter angewendeter Basis: Ziel erhalten.
- Quelle geaendert, Ziel entspricht letzter angewendeter Zielbasis:
  neue Quelle ueber versioniertes Mapping anwenden.
- Quelle und Ziel bereits auf denselben transformierten Wert geaendert:
  konvergiert, keine doppelte Mutation.
- Quelle und Ziel unterschiedlich geaendert: Konflikt, kein Ueberschreiben.
  Ueberpruefung mit geschuetztem Vorher-/Nachhervergleich.
- Quellzeile neu: nach FK-Aufloesung einmalig einfuegen.
- Quellzeile fehlt: nur Loeschkandidat. Keine automatischen physischen
  Loeschungen, Kaskaden oder Wiederverwendung alter IDs; fachliche
  Deaktivierung/Archivierung nach Freigabe.
- Nur in ClubUnio angelegte Objekte erhalten. Zielrevision unter
  Zeilensperre unmittelbar vor Write erneut pruefen.
- Abhaengige Aenderungsgruppe bei Konflikt blockieren. Baseline nur fuer
  tatsaechlich committed Felder fortschreiben; gesamte Importgruppe und
  Ledger in einer Zieltransaktion bzw. explizit quittierten Teilgruppen.
- Quellschemawechsel, unbekannte Schluessel oder fehlende Beziehungen:
  abbrechen und Mapping pruefen. Kein Zeitstempel-Watermark allein.
- Vollstaendige Quellabzuege bleiben fuer konsistente Deltaberechnung
  erlaubt; in die native Fachschicht werden nur angenommene Deltas
  geschrieben. Das ist keine SQL-Server-CDC-/Transaktionslog-Replikation.

## Abnahme vor dem ersten PROD-Apply

Aktueller Nachweis: [Fachzuordnungen v2](CLUBUNIO-SVS-HISTORY-MAPPING.md).
Die neun zuvor offenen Tabellen und 14 Saisonabweichungen sind auf Kopien
bearbeitet. Dateibinaerdaten, Web-Abnahme mit persoenlichem Login und der erste
PROD-Apply bleiben separate Schritte; dafuer liegt keine Ausfuehrungsfreigabe vor.

- Vollstaendige Tabelle-/Feld-Abdeckungsmatrix, persistentes Identitaets-
  und Feldbaseline-Ledger und versionierte Mapper implementieren.
  Neue Tabellen/Migrationen als eigenes geprueftes Paket vorbereiten.
- Import an wiederhergestellter PROD-Kopie proben; Konten-/Plattformrechte
  und Walletbestand vor/nach vergleichen. Neue Vereinsdaten ueber
  native Dienste und deren Invarianten testen.
- Saisonbeziehungen, Familien, Rechnungsbetraege, Stornos/Gutschriften,
  Dokumentreferenzen und historische Spesen-/Saisonkonflikte abgleichen.
  Historische Stage-Korrektur-IDs niemals pauschal nach PROD uebernehmen.
- Fach-/Summenabgleich mit dem neuen Quelldump; unbekannte Tabellen,
  Luecken und bewusst ausgelassene Felder explizit ausweisen.
- Wiederholung desselben Imports: null Writes/Dubletten. Zweiten
  synthetischen Quelldump mit Insert/Update/Loeschkandidat und paralleler
  Zielaenderung testen. Konflikt muss Zielaenderung erhalten.
- Fehler mitten im Lauf und Wiederanlauf, fremder Mandant, falsche
  Datenbank und veralteter Plan muessen ohne Teilimport scheitern.
- Externe Aktionen ausgeschaltet; Web-Abnahme DE/EN und persoenlicher
  Login erst nach Rechtevergabe. Erst danach separater Apply-Auftrag.

## Ergebnis dieses Vorbereitungsschritts

Dumps, Restore-Nachweis, ausfuehrbarer Source-Exporter, gepruefter
Delta-Planer und Import-/Konfliktvertrag liegen vor. Kein Verein und
keine SV-Sissach-Fachdaten in ClubUnio PROD angelegt. Der fachliche
Erstimport-/Delta-Apply-Runner ist noch nicht implementiert oder
freigegeben; das naechste Paket muss diese Umsetzung und die Probe auf
einer isolierten Kopie liefern. Kein ungepruefter Apply-Befehl angeboten.

## Delta-Runner auf isolierter PROD-Kopie (22.11.2026)

Native Feldvertraege fuer 75 Tabellen, dauerhafte HMAC-Feldbasis und
transaktionaler Dreiwegevergleich implementiert. Echter Erstimport:
37550 Datensaetze; Wiederholung 0 Inserts/Updates. Acht Integrations-
szenarien, feldweiser Parallelmerge und vier CLI-/Schemagates geprueft.
Finanzsummen und Tabellenzaehler abgeglichen, Plattformadmin und 143
Migrationen erhalten. 14 Quell-Saisonkonflikte bewusst nicht repariert.
Neun befuellte Quelltabellen bleiben fachlich ungemappt; genaue Liste
und Grenzen im [Runner-Nachweis](CLUBUNIO-SVS-DELTA-RUNNER.md).
Keine PROD-Ausfuehrung; Live-Zielname ist technisch gesperrt.


Nach paralleler PROD-Konsolidierung Abnahme auf frischer Kopie von
b1d44c8 mit 145 Migrationen wiederholt: 37550 Erstimporte, identische
Wiederholung ohne Fachwrites. Acht Integrationsszenarien erneut gruen.
Auch neu aufbereiteter synthetischer Quelldump: genau 1 Insert, 1 Update,
1 Review-Kandidat; Wiederholung 0 Writes und stabile Ziel-IDs. Numerische
Schemaabweichung wird vor Import abgelehnt. Umsetzung nur auf eigenem
Branch codex/svs-import-runner-20261122, nicht auf PROD ausgerollt.

## SV-Sissach-Fachzuordnungen v2 (22.11.2026)

Die neun zuvor offenen befuellten Quelltabellen sind fachlich zugeordnet.
85 native Tabellen, 37'911 Datensaetze im Erstimport auf isolierter PROD-Kopie.
14 Spesen-/Saisonabweichungen eindeutig geprueft und ausschliesslich durch
Zuordnung zum selben Team in der Belegsaison korrigiert; keine Betragsaenderung.
Historische Quelldokumente und Antraege sind unveraenderlich, Quittungen bleiben
ungepruefte Dateimetadaten ohne Download. V1-Baselineupgrade erhaelt native IDs
und Feldbaselines: 361 Inserts, 14 Updates, Wiederholung 0 Fachwrites;
Konflikt rollt auch den Mappingversionswechsel zurueck.

Die frische PROD-Sicherung enthaelt 146 Migrationen; die Kopie erhaelt zusaetzlich
eine explizit auf isolierte Datenbanken begrenzte Zusatzmigration (147).
Details, Grenzen und Nachweise: [Fachzuordnungen v2](CLUBUNIO-SVS-HISTORY-MAPPING.md).
Arbeit nur im Branch codex/svs-history-mappings-20261122. Kein Release und
kein Import auf PROD; produktive Freigabe und Dateiuebernahme stehen aus.
## SV Sissach auf PROD importiert (23.11.2026)

Auf expliziten Betreiberauftrag SV Sissach angelegt und 37'911 native
Datensaetze aus PHPMaker PROD importiert: 2'448 Mitglieder, 6'799 Rechnungen,
487 Spesenbelege/3'865 Positionen. 14 gepruefte Team-/Saisonzuordnungen,
keine verbleibenden Spesen-/Saisonabweichungen. Dauerhafte Feldbaseline auf PROD.
Keine Quellschreibzugriffe, keine Schluessel-/Tokenuebernahme, keine importierten
Benutzer aktiviert, keine Einladungen oder Push-Outboxeintraege erzeugt.
Lokale Vereinsadministration durch die bestehende Plattformadministration.

Jeder Delta-Lauf sichert PROD, erstellt einen frischen konsistenten Quelddump,
probt auf wiederhergestellter Kopie und verlangt eine Wiederholung ohne
Fachwrites. Live-Merge nur mit kurzlebiger, root-eigener Lauf-Freigabedatei;
Konflikte oder Schemaaenderungen brechen ab. Aktiv: Codex-Agent alle12Stunden,
ausschliesslich PHPMaker PROD nach ClubUnio PROD, keine bidirektionale Sync.
[Betrieb, Grenzen und Nachweise](CLUBUNIO-SVS-PRODUCTION-IMPORT.md).