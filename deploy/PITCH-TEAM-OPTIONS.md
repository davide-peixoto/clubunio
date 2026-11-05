# Saisonbezogene Mannschaftsauswahl in der Platzplanung

Stand: 05.11.2026. Integrierter Release: CLUBUNIO-PITCH-USABILITY.md.

## Verhalten

- `PitchPlanningService::options()` bietet nur aktive Team-Saison-Zuordnungen
  mit aktiver Mannschaft aus der konfigurierten aktuellen Saison an.
- Zentrale Quelle ist `MobileSeasonScope::current()` mit dem mandantengebundenen
  Parameter `general/SaisonID`, auch fuer die Webansicht. Kein Datumsraten und
  kein Rueckfall auf die neueste oder eine beliebige historische Saison.
- Importierte Saisonwerte werden ueber `legacy_id` aufgeloest; nur nicht
  importierte Saisons koennen ueber ihre native ID referenziert werden. Die
  vorhandene Prioritaet bei ID-Kollisionen und die requestlokale Aufloesung
  bleiben erhalten.
- Fehlende, ungueltige, mehrdeutige, fremde oder inaktive Saisonkonfiguration
  ergibt eine leere Mannschaftsauswahl. Felder und bestehende Capabilities
  bleiben nach ihren bisherigen Regeln verfuegbar.
- Anzeige: `U18 (2030/31)`. Mannschafts- und Saisonnamen sind unveraenderte
  Vereinsdaten, keine automatisch uebersetzten Freitexte. Das sprachneutrale
  Klammerformat gilt fuer DE/EN; keine neuen UI-Woerter, Sprachvarianten oder
  Fallbacktexte und keine Aenderung gemeinsamer Sprachkataloge.

## Grenzen Und Kompatibilitaet

- Antwortstruktur bleibt `fields`, `teams`, `capabilities`; jede Teamoption
  behaelt genau `id` (Team-Saison-ID als Integer) und `name` (String).
- Admin/Geschaeftsstelle behalten die bestehende Verwaltungspruefung.
  Trainer behalten die echte aktive Trainerfunktion und eigene freigegebene
  Mitgliedsverknuepfung; technische Leiter ihre vorhandene Zustandspruefung
  und Teamverantwortung. Beide erhalten nur die Schnittmenge mit der aktuellen
  Saison. Keine neue Rolle, Modulberechtigung oder persoenliche Freigabe.
- Abfragen nach Team-Saisons und Mannschaftsnamen sind tenantgebunden.
  Die Auswahl wird bereits in SQL nach Saison und erlaubten IDs begrenzt;
  Mannschaftsnamen werden gesammelt nur fuer diese Kandidaten gelesen.
  Stabile Sortierung nach Team-Saison-ID, keine Abfrage pro Optionszeile.
- Gleichnamige, aber unterschiedliche IDs innerhalb derselben Saison werden
  nicht zusammengefuehrt. Die Korrektur entfernt historische Auswahlwiederholungen,
  bereinigt aber keine Daten und erratet keine Teamidentitaeten.
- Kalender, Datums-/Teamfilter, historische Wettbewerbszuordnungen und
  gespeicherte Anfragen bleiben unveraendert sichtbar. Auch eine leere
  aktuelle Optionsliste entfernt keine historischen Kalendereintraege.
- Bewusst nur Optionsumfang: `activeTeam`, Request-/Replay-Autorisierung,
  Genehmigung, Verschieben und Wettbewerbszuordnung bleiben unveraendert.
  Insbesondere ist dies keine neue serverseitige Ablehnung jeder historischen
  ID in bestehenden Schreibendpunkten. Capabilities sind weiterhin die
  bisherigen Rollenrechte und keine Zusage einer nichtleeren Auswahlliste.

## Pruefung

`tests/Feature/PitchPlanningTeamOptionsTest.php`: 11 Tests / 81 Assertions.
Isolierte SQLite-In-Memory-Datenbank mit zurueckgerollten synthetischen Daten;
keine Liveverbindung, Migration oder Provideraktion. Echte Saisonaufloesung,
Modulrechte, Trainer-/TL-Zuordnungsabfragen und historische Kalenderprojektion.
Nur Portal-Eintritt in den Rollenfaellen ist isoliert, da seine Altersabfrage
PostgreSQL-Syntax verwendet; Feldliste/Schemafreigabe und die fuer den
Kalendertest irrelevante Signage-Trainingsvariante sind Test-Doubles.

Abgedeckt: DE/EN und Web/Mobile, importierte/native Saisonwerte und Kollision,
fehlende/ungueltige/mehrdeutige Konfiguration, aktive Zustaende, Mandantengrenzen
einschliesslich fehlerhafter Referenzen, Trainerfunktion, TL-Verantwortung,
widerrufene Zuordnungen, Selbst-/Elternlinks, Profile, inaktive Konten,
Simulation, historische Anzeige und konstanter Queryumfang bei mehr Optionen.

Gezielter Regressionslauf:

```powershell
php artisan test --compact --filter='PitchPlanningTeamOptionsTest|PitchPlanningCalendarTest|PitchPlanningPageTest|MobilePitchControllerTest|PitchCompetitionTeamMappingTest|PitchWeeklyProjectionTest'
php tools/check-translations.php
```

Ergebnis: 86 bestanden / 758 Assertions; eine optionale visuelle Fixtureaufnahme
ohne `CLUBUNIO_PITCH_WEB_CAPTURE` ausgelassen. 2670 Katalogschluessel DE/EN
geprueft, kein offener dokumentierter Literalbestand. Kein PostgreSQL- oder
Browserabnahmenachweis fuer dieses Teilpaket behauptet.

## Integration

Nur Service, neuer fokussierter Test und diese Dokumentation sind Teil dieses
Pakets. Grid/Kopfzeile, uebergeordnete Migrations-/Releasedoku, Bereitstellung
und Commit/Push verbleiben beim koordinierenden Auftrag. Keine Datenmigration,
Imports, Bestandskorrekturen, globale Rechteaenderungen oder PHPMaker-Eingriffe.
