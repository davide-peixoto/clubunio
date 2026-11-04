# Sportplatzbelegung / Pitch planning

## Freigegebener Umfang (04.11.2026)

Neues gemeinsames ClubUnio-Web-/App-Modul. PHPMaker DEV/PROD bleiben unveraendert.
Implementierung gestartet; diese Beschreibung ist kein Release-/Abnahmenachweis.

- Tagesplantafel mit allen aktiven Plaetzen nebeneinander; Monat und Woche.
- Ganze Plaetze und konfigurierbare Teilflaechen. Ueberlappende Teilflaechen
  sowie Ganzplatzbelegungen/-sperren muessen miteinander kollidieren.
- Bestehende Wettkaempfe sind Originalquelle, Standarddauer120 Minuten,
  anpassbar. Bestehende Garderoben und SFV-Verknuepfungen erhalten.
- Trainingszeiten bleiben Wochen-Grundplan. Datumsbezogene Ausnahmen fuer
  Einzelverschiebungen/Absagen, inklusive urspruenglichem Vorkommensdatum.
- Aktuelle Saison und zentraler Sommer-/Winterplan bestimmen Wiederholungen.
- Zeitliche Sperren und bestehende sperrende Anlagenstatus beachten.
- Trainer/TL beantragen Trainingsspiele nur fuer zugeordnete Mannschaften.
  Ausschliesslich Admin/Backoffice entscheiden; Anzeige ist keine Berechtigung.
- Drag & Drop bestaetigt speichern, serverseitig frisch autorisieren und
  Konflikte pruefen. Revision schuetzt gegen veraltete Browser/App-Zustaende.
- SFV-Platzwechsel gelten als genehmigt, brauchen keine zweite Freigabe und
  duerfen Konflikte mit anderen Belegungen/Sperren nicht unsichtbar machen.
- Plantafel, Signage und App muessen dieselben wirksamen Vorkommen verwenden.
- E-Mail ausschliesslich neue Anfragen an Admin/Backoffice. Entscheidungen
  an Antragsteller per Push/In-App; Verschiebungen zwingend an alle aktiven
  Staffs der betroffenen Mannschaften. Keine Zwischenstandsnachrichten.
- Zustellung erst nach erfolgreichem Speichern, dedupliziert und protokolliert.
- DE/EN im Backend/Web, Appkataloge gemaess mobile/AGENTS.md; IDs sprachneutral.

## Verbindliche Abnahme vor Freigabe

| Bereich | Nachweis | Stand |
| --- | --- | --- |
| Mandant/Rechte | Fremder Verein/Team, Nur-Lesen, Trainerentscheidung verboten | Automatisierte Kern-/API-Tests; Liveabnahme offen |
| Teilflaechen | Vollplatz gegen Haelfte, ueberlappende/nicht ueberlappende Teile | Geometrie und Wochenvorlage automatisiert geprueft |
| Zeiten | Halb-offene Intervalle, Tageswechsel, Sommerzeit, begrenzter Zeitraum | Automatisierte Kern-/Kalendertests |
| Sperren | Zeitblock und statischer Anlagenstatus; bestehende Konflikte sichtbar | Automatisierte Kernpruefung |
| Wiederholung | Einzelverschiebung ohne Grundplanaenderung, Aus-/Einblenden am Datum | PostgreSQL TEMP-/Rollbacktests |
| Originaldaten | Wettkampf aktualisiert, Garderoben/Match-ID erhalten | Automatisierte Kern-/SFV-Regression |
| SFV | Genehmigte Aenderung uebernommen, Konflikt sichtbar, kein Doppeltermin | Regression und historische Vorschau geprueft; Bestands-Apply offen |
| Nebenlaeufigkeit | Veraltete Revision und gleichzeitige Freigaben | 13 Tests/234 Assertions, zwei echte PostgreSQL-Verbindungen bestanden |
| Nachrichten | Staffempfaenger, keine Doppelzustellung, Mail nur neue Anfrage | Outboxtests gruen; echte Zustellung offen |
| Darstellung | Web Desktop/Mobil und App Tages-/Kalenderansicht | Fragment-/Widgettests; angemeldete Abnahme vom Benutzer verschoben, native Abnahme offen |
| Integration | Signage und App identisch zur wirksamen Plantafel | Automatisierte Projektion; Liveabgleich offen |
| Betrieb | Additive Migration, Sicherung/Verifikation, gezieltes ClubUnio-Release | Webrelease pitch-v1 aktiv; vier Migrationen und Servergates geprueft, native Freigabe offen |

## Migrationsgrenzen

Keine Bestandsdatenauffrischung, keine automatische Benutzerrechtevergabe,
keine echten Testnachrichten an Vereinsmitglieder. Neue Tabellen erst nach
Schema-/Rollbacktests und expliziter gezielter Releasevorbereitung anwenden.
Neue laufzeitabhaengige Ansichten duerfen bei fehlender Migration nicht die
bestehenden Anlagen-, Trainings- oder Signageansichten unterbrechen.

Detailvertrag und Implementierungs-/Testergebnisse werden bei Zusammenfuehrung
nachgefuehrt. Noch keine Behauptung einer vollstaendigen App-/Providerabnahme.

## Entwicklung und Integration

Fuenf getrennte Arbeitspakete: Domaene/Schema, Filament-Plantafel, persoenliche
API, Flutter und Benachrichtigungen. Gemeinsamer Vertrag:
`CLUBUNIO-PITCH-CONTRACT.md`; API-Details: `CLUBUNIO-PITCH-API.md`.
Die Fachservices besitzen die Mutation; Browser und App duerfen weder
Mandanten-/Akteur-IDs noch eine Freigabe aus dem Client uebernehmen.

### Lokaler Zwischenstand

Web-Plantafel, persoenlicher API-Adapter, App-Inkrement und transaktionale
Nachrichten sind implementiert. Webrelease pitch-v1 ist auf ClubUnio aktiv.
Die mobilen Endpunkte bleiben hinter einer separaten, weiterhin deaktivierten
Freigabe. Keine echte Benachrichtigung versendet; Fach-/Geraeteabnahme offen.

Web:27 Tests/129 Assertions; Fragment-Geometrie in DE/EN auf1440/768/390/320px.
Dies ist keine Abnahme der angemeldeten Filament-Shell mit echten Daten.
App:86 abschliessende gezielte Tests, vorheriger Gesamtlauf691 Tests; Analyse
und Sprachkatalogpruefung erfolgreich. Keine native Geraeteabnahme.
Nachrichten:54 Tests/597 Assertions einschliesslich temporaerer PostgreSQL-DDL;
E-Mail-Testworker ausschliesslich zum bestehenden festen Testempfaenger.
Bestehende App-Zugriffssperren fuer dynamische Profile werden nicht erweitert;
betroffene Staffkonten erhalten derzeit keinen mobilen Push. Details:
`CLUBUNIO-PITCH-NOTIFICATIONS.md` und `mobile/docs/PITCH-PLANNING.md`.

### Geplanter Migrationsablauf

Abschluss des lokalen Inkrements: Unit-Gesamtlauf121 Tests/55210 Assertions
gruen; Kern-PostgreSQL TEMP-DDL/Rollback13 Tests/105 Assertions, nachfolgende
Kernpruefungen5/46 und Signage/SFV/Profilregression48/651 gruen. Keine permanente
Migration. Offene Grenzen: Zwei-Verbindungen-Renntest, Trainings-Teilflaechen
in der Wochenvorlage (bisher pro Vorkommen), historische SFV-Teamrueckfuellung
und Neuantrags-Idempotenz. Nach unklarer Antwort erst neu laden, nicht blind
erneut beantragen. App-Inkrement bcb83d1 nach origin/main synchronisiert.

1. Datenbanktests mit temporaeren Tabellen und zurueckgerollten Vereinsfixtures.
2. Vor permanentem Apply separate Sicherung und Pruefung der Eltern-/Ledgertabellen.
3. Nur001500 (Belegung),001600 (Nachrichten),001700 (Wochen-Teilflaechen) und
   001800 (Antrags-Idempotenz) gezielt anwenden; keine fremden
   ausstehenden Migrationen pauschal starten, keine Daten auffrischen.
4. Live-Fachdaten lesend auf zuordenbare Plaetze, Staff und SFV-Verknuepfungen
   pruefen; fehlende Wettkampf-Mannschaftszuordnungen ausdruecklich pflegen.
5. Angemeldete Web-/App-Abnahme, Signage-Abgleich und isolierte Testzustellung.
6. Erst danach ClubUnio-Freigabe; bestehende PHPMaker-Umgebungen unveraendert.
   Schema bleibt bei Code-Rollback erhalten, keine Belegungs-/Auditdaten loeschen.

Der erste Bestandsregressionstest der SFV-Wettkampftabelle bestand mit6 Tests
und130 Assertions. Der Uebersetzungs-Ausgangsstand bestand mit2523 Schluesseln.
Das sind Ausgangspruefungen, keine Abnahme des neuen Moduls. Neue Sprachfragmente
werden erst bei Zusammenfuehrung in die gemeinsamen Kataloge eingelesen.

### Integrationsnachlauf

Die oben genannten Implementierungsluecken Wochen-Teilflaeche, historische
SFV-Zuordnungsvorschau und Neuantrags-Idempotenz sind inzwischen geschlossen.
Der frische Replay-Rechtecheck verwendet dieselben Sperren und Teamregeln wie
ein neuer Antrag. Keine neue Freigabe durch einen alten Idempotenzschluessel.
Mobile-Nachlauf `1c9d552` ist synchronisiert;29 gezielte App-Tests bestanden.
126 Backend-Unit-Tests/55299 Assertions sowie45 PostgreSQL-Tests/572 Assertions
fuer API-Replay und historische Zuordnung bestanden. Keine echte Zustellung.
Aktueller Betriebs-/Abnahmestand: [Nachlauf](CLUBUNIO-PITCH-FOLLOWUP.md),
gezielte Schritte: [Releaseverfahren](CLUBUNIO-PITCH-RELEASE.md).

### Direkte Wettkampfaenderungen / Direct competition edits

`PitchCompetitionEdits` verbindet ausschliesslich den direkten `FacilityService`-
Save mit der Platzplanung. Normalisierter Vorher-/Nachhervergleich von Datum,
Zeit, Platz und Aktivstatus; reine Raum-, Freitext- und gleichwertige Zeitformate
erzeugen keine neue Konfliktpruefung oder Platznachricht. Bestehende dynamische
`competitions`-Profilrechte bleiben erhalten und werden bei Belegungsaenderungen
frisch geprueft. Das erweitert keine strengeren Planner-/Freigaberechte.

Manuelle Saves pruefen normale Konflikte auch bei SFV-verknuepften Quellen.
Nur der getrennte SFV-Import behaelt seinen autoritativen Sonderpfad. Die
Projektion verwirft eine Teilflaeche des alten Platzes bereits vor der Pruefung
des Zielplatzes; nach erfolgreichem Save wird die alte Zuordnung dauerhaft
geleert und ihre Metadatenrevision erhoeht. Dauer, SFV-/Legacy-IDs, Garderoben
und weitere nicht bearbeitete Quellfelder bleiben erhalten.

Altbestand ohne Platz oder Zeit bleibt ohne erfundene Mitternachtsbelegung
editierbar. Unveraenderte Belegung: kein Plannerzugriff. Weiterhin unvollstaendige
Belegung nach einer Aenderung: Audit, aber keine Belegungsnachricht. Vollstaendige
Belegung herstellen/reaktivieren: Konfliktpruefung und `created`; bestehende
Belegung verschieben: `moved`; Platz/Zeit entfernen oder deaktivieren: `cancelled`.
SFV-`withdrawn` bleibt erhalten. Fehlt das komplette Planner-Schema, bleibt der
Legacy-Save ohne Plannerintegration nutzbar; keine automatische Nachholung.

Quellsave, Teilflaechenbereinigung, Audit und Staff-Inbox sind eine Transaktion.
Fanoutfehler rollen alles zurueck. Kein direkter Mail-/Pushversand; bestehende
DE/EN-Nachrichtenvorlagen und Empfaengerregeln bleiben massgeblich. Planner und
SFV rufen diesen Adapter nicht auf, daher kein doppelter Ereignispfad.

English: Direct editor grants are preserved, independently of planner approval
roles. Only actual placement changes trigger normal conflict checks and atomic
audit/staff notices. Cross-field moves use whole-field geometry before checking
conflicts and permanently discard the old area after saving. Incomplete legacy
placements remain editable without invented times: audit only while incomplete,
created on completion/reactivation, cancelled on loss of placement/deactivation.
Missing planner schema retains legacy behavior. Existing source IDs, rooms,
duration and SFV withdrawal metadata are preserved. No provider delivery occurs.

Regression: `tests/Feature/PitchCompetitionEditsTest.php`, ausschliesslich
PostgreSQL-`pg_temp`, permanente Schreibzugriffe im Test gesperrt. Keine permanente
Migration, Bestandsaenderung, Bereitstellung oder Commit fuer diese Ergaenzung.
Lokaler Nachweis:10 Tests/158 Assertions; bestehende Notification-/Kalendertests
34/199;2589 DE/EN-Schluessel und Platzhalter geprueft. Dynamische Updateprofile,
widerrufene gecachte Rechte, alte linke/neue rechte Teilflaeche, Originalidentitaet,
Raum-/Text-No-ops, unvollstaendige Quellen, Anlage/Deaktivierung, Planner-
Deduplizierung und Rollback bereits erzeugter Inboxzeilen eingeschlossen.
