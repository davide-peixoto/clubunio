# SV Sissach: automatische Altplanuebernahme

23.11.2026, ausdruecklicher Betreiberauftrag auf PROD. Alle 34 vollstaendigen,
gueltigen Trainingsplaene aus 47 historischen Staenden als private native
Archivplaene uebernommen. Zwoelf Fehler-/Zwischenstaende und ein fachlich
ungueltiger Inhaltsvertrag bleiben ausgeschlossen. Wiederholung: 0 neue
Datensaetze, 34 bereits uebernommen, 0 ungeklaerte Zuordnungen.

## Bestaetigte Zuordnungen

Der Betreiber hat B-Junioren immer den Ba-Junioren und C-Junioren immer den
Ca-Junioren zugeordnet. Massgeblich bleibt die Saison des Erstellungsdatums
(Europe/Zurich, Saisonbeginn Juli); keine Zuordnung zur neuesten Saison.
Diese expliziten Regeln haben Vorrang vor der abgeleiteten Trainerzuordnung.
Der einzelne verbleibende Herrenplan wurde ausdruecklich der 1ten Mannschaft
zugeordnet. Diese Einzelregel ist an Quell-ID und Quellversionshash gebunden.

`svs-training-adoption-rules-v1.json` enthaelt nur fachliche Quellschluessel
und Regeln, keine nativen Stage-/PROD-IDs oder personenbezogenen Planinhalte.
Ergebnis: 24 Plaene nach bestaetigter B-/C-Regel, acht nach eindeutiger
Schnittmenge von urspruenglichem Trainer, Kategorie und Saison, einer nach
eindeutiger Kategorie/Saison, einer nach der bestaetigten Herren-Einzelregel.

## Unveraenderliche Herkunft und Rechte

`HistoricalTrainingPlanAdoption` erzeugt ausschliesslich neue Datensaetze
in `training_plans`. `legacy_source_id` verweist auf die unveraenderliche
historische Zeile. Der vorhandene Unique-Index verhindert Mehrfachuebernahmen;
der Mandantenlock serialisiert automatische und manuelle Uebernahmen.
Quellzeilen, verschluesselte Rohherkunft, deren Status und das dauerhafte
PHPMaker-Delta-Ledger werden nicht geaendert. Die Anzeige "Uebernommen" und
der Filter werden aus der nativen Archivkopie abgeleitet.

Die Originalbesitzer bleiben erhalten: 31 der 34 Plaene gehoeren weiterhin
inaktiven historischen Konten. Diese Konten werden nicht aktiviert und
bekommen keine neuen Rollen oder Portalrechte. Die ausfuehrende Person
benoetigt weiterhin `training.sync`. Ein anderer Mandant darf weder die
SV-Sissach-Regeln noch fremde Team-/Besitzerreferenzen verwenden.

Alle Plaene bleiben privat und archiviert. 19 bestehende Hinweise auf
ausstehende Qualitaetspruefung bleiben bestehen; keine automatische
Qualitaetsfreigabe, Veroeffentlichung oder Wiederherstellung aktiver Plaene.
Die vorhandene Inhaltsvalidierung prueft Phasenfolge, Dauer und Planvertrag
erneut. Historisches SVG/HTML wird nicht ausgefuehrt; die vorhandene sichere
schematische Konvertierung wird verwendet und in der Planansicht kenntlich
gemacht. Originalinhalte bleiben in der geschuetzten Herkunft erhalten.

## Betrieb und Anzeige

Unter Altplaene uebernehmen zeigt "Uebernommen" jetzt auch native Kopien
schreibgeschuetzter Quellversionen. "Archivplan oeffnen" fuehrt direkt zum
Plan. "Altpläne automatisch übernehmen" kann spaeter erneut bewusst
ausgeloest werden; der Lauf ist idempotent. Der alte Quellen-Neueinleseknopf
wird fuer diese schreibgeschuetzte PROD-Herkunft nicht angeboten.

Dies ist eine eigene fachliche Uebernahme, kein neuer Hintergrundjob und
kein Bestandteil des zwoelfstuendlichen Deltalaufs. Dieser importiert weiter
nur PHPMaker nach ClubUnio; lokale Archivkopien bleiben erhalten. Keine
Schemaaenderung und keine Lockerung der Historien- oder Konfliktsperren.
Die Runtime-Hashes des Delta-Runners wurden fuer diesen geprueften Release
aktualisiert; der eingefrorene Fachmapper wurde nicht veraendert.

CLI fuer ausdrueckliche Betreiberaktionen:

    php tools/adopt-svs-historical-training.php --preview clubunio_prod
    php tools/adopt-svs-historical-training.php --apply clubunio_prod

`--test` akzeptiert ausschliesslich isolierte Rehearsal-Datenbanken.
Vor dem Live-Lauf neues root-geschuetztes PROD-Backup und erfolgreicher
Restore-/Uebernahmetest. Getestet: Zuordnungen, Originalbesitzer, unveraenderte
Konten/Portale, private Archivflags, Qualitaetshinweise, unveraenderliche
Herkunft und Feldbaseline, Anzeige und Filter sowie atomarer Rollback bei
spaetem Datenbankfehler. DE/EN-Texte und Platzhalter geprueft. Der Katalogcheck
behandelt Platzhalter symbolischer Schluessel anhand des englischen
Quellkatalogs; bei ausgeschriebenen Textschluesseln bleibt die bisherige
Platzhalterpruefung erhalten.
