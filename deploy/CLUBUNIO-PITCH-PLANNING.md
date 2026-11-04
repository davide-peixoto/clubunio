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
| Mandant/Rechte | Fremder Verein/Team, Nur-Lesen, Trainerentscheidung verboten | Offen |
| Teilflaechen | Vollplatz gegen Haelfte, ueberlappende/nicht ueberlappende Teile | Offen |
| Zeiten | Halb-offene Intervalle, Tageswechsel, Sommerzeit, begrenzter Zeitraum | Offen |
| Sperren | Zeitblock und statischer Anlagenstatus; bestehende Konflikte sichtbar | Offen |
| Wiederholung | Einzelverschiebung ohne Grundplanaenderung, Aus-/Einblenden am Datum | Offen |
| Originaldaten | Wettkampf aktualisiert, Garderoben/Match-ID erhalten | Offen |
| SFV | Genehmigte Aenderung uebernommen, Konflikt sichtbar, kein Doppeltermin | Offen |
| Nebenlaeufigkeit | Veraltete Revision und gleichzeitige Freigaben | Offen |
| Nachrichten | Staffempfaenger, keine Doppelzustellung, Mail nur neue Anfrage | Offen |
| Darstellung | Web Desktop/Mobil und App Tages-/Kalenderansicht | Offen |
| Integration | Signage und App identisch zur wirksamen Plantafel | Offen |
| Betrieb | Additive Migration, Sicherung/Verifikation, gezieltes ClubUnio-Release | Offen |

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
Nachrichten sind implementiert. Das Modul ist noch nicht live freigegeben.
Die mobilen Endpunkte bleiben hinter einer separaten, standardmaessig
deaktivierten Freigabe. Es wurden keine echten Benachrichtigungen versendet.

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
3. Nur001500 (Belegung) und001600 (Nachrichten) gezielt anwenden; keine fremden
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
