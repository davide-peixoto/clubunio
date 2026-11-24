# Teamplanung: neue DEV-Oberfläche

Stand: 24.11.2026. Terminliste und Teamkalender erhalten eine umschaltbare
Oberfläche in ClubUnio-Blau (#164B8A / #48A9E8), mit eigener SVG-Grafik,
Einblend- und Hoverbewegungen, Kalenderkarten und mobiler Agenda.
Die Systempräferenz für reduzierte Bewegung wird berücksichtigt.

## Umfang und Rückweg

`CLUBUNIO_PLANNING_UI_ENABLED=true` aktiviert die Oberfläche ausschliesslich
in der nativen Terminliste und im Teamkalender. Standard der Konfiguration
ist aus. Bestehende Portalansichten, Detail-/Teilnehmerformulare, Absenzen,
SFV-Import und Trainingspläne bleiben in ihren bisherigen Abläufen.

„Bisherige Ansicht“ und „Neue Planungsansicht“ speichern die Wahl in der
Sitzung getrennt je Account und Verein. Ein direkter Kalenderwechsel erhält
Datum, Ansicht und GET-Filter. Listenfilter bleiben während der Livewire-
Bedienung erhalten; ein voller Seitenwechsel setzt nicht in der URL enthaltene
Livewire-Filter zurück. Die mobile Agenda zeigt dieselben bereits autorisierten
Termine mit Ort, Mannschaft, Status und Überschneidungen. Busreservierungen
bleiben bei aktivierter Busanzeige in der vorhandenen separaten Tabelle sichtbar.

Kennzahlen werden aus dem gefilterten Kalenderzeitraum berechnet. Die kleinen
grafischen Balken sind Dekoration, keine Zeitreihen. Keine zusätzlichen
Teilnehmerdaten oder privaten Kommentare im Überblick. Sämtliche Schreib-
operationen und Berechtigungsprüfungen verwenden weiterhin vorhandene Abläufe.

Rückweg: Ansicht umschalten oder Gate deaktivieren und Config-Cache erneuern.
Vorheriges DEV-Release: `20261124-clubunio-facility-ui-v3`.
Keine Migration, keine Datenimporte, keine Änderung von Stage oder PROD.

## Prüfung

15 lokale Tests / 73 Assertions (TeamPlanningUiTest, FacilityPlanningUiTest,
PlanningCalendarTimelineTest); Übersetzungskataloge DE/EN geprüft.
`deploy/check-team-planning-ui.php` prüft auf DEV im Read-only-DB-Transaction
beide Sprachen, Monat/Woche/Mannschaften und moderne/klassische Darstellung.
12 Read-only-DEV-Renderprüfungen erfolgreich. Aktives DEV-Release:
`20261124-clubunio-team-ui-v1`. PHP-FPM aktiv, CSS HTTP 200.

Browser: Terminliste und Kalender geladen; Monatsansicht mit sieben echten
Terminen geprüft. Bei 390 × 844 mobile Agenda mit sieben Terminen und
klassischer Rückweg in beide Richtungen. Bei 320 × 812 kein horizontaler
Seitenüberlauf (scrollWidth 305 bei innerWidth 320). Wochenansicht mit
Datum 27.10.2026 und Mannschaft 394: Filter bleiben beim Designwechsel
in beide Richtungen erhalten. Vorhandene Termindetails mit Teilnehmer-
formular über Agenda-Link geöffnet; nichts gespeichert oder versendet.
Viewport danach zurückgesetzt. Kein physischer Handy-/Safari-Test.

Desktop- und Agenda-Prüfbilder liegen unter `team-planning-check/` im
chatbezogenen Vorschauverzeichnis ausserhalb des Repositorys.
