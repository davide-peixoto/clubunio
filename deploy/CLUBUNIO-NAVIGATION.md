# Aufgabenorientierte Navigation (16.10.2026)

Reihenfolge: Dashboard; Mitglieder; Sport; Finanzen; Anlagen & Kommunikation;
Auswertungen; Einstellungen. Innerhalb der Fachgruppen stehen die haeufigen
Arbeitswege vor Detailzuordnungen und Spezialprozessen. Inaktive Gruppen sind
initial geschlossen; die bestehende persoenliche Gruppenauswahl bleibt erhalten.

Stammdaten sind nicht mehr einzeln in der Sidebar aufgefuehrt. Unter
`/configuration` gibt es eine Einstellungen-Uebersicht mit den Bereichen:

- Mitglieder & Verein
- Sport & Anlagen
- Finanzen
- Zugaenge & System

Statuswerte, Beitragskonfiguration, Sport-/Anlagenlookups, Vorlagen, Benutzer,
Parameter und Synchronisation werden dort aufgerufen. Die bestehende
Parameterverwaltung heisst in der Uebersicht "Parameter & Integrationen".
Die Uebersicht ist durchsuchbar; die Menuesuche bietet die Weitergabe des
Suchbegriffs an die Einstellungen an. Alle bisherigen Direktlinks bleiben gueltig.

## Schutz und Migration

NavigationStructure ordnet ausschliesslich die zuvor von Filament autorisierten
Menueeintraege um. Keine neuen Objektberechtigungen; keine Freigabe versteckter
Testseiten. Nicht zugeordnete zukuenftige Menueeintraege bleiben unter Weitere
Einstellungen erreichbar, bis sie fachlich eingeordnet werden.
Einstellungen-Uebersicht verlangt aktuelle Verwaltungsberechtigung; die
Zielseiten behalten ihre eigenen strengeren Regeln (z.B. nur Admin).

Keine Datenbankmigration. Additiver Bedienungsschritt im parallelen ClubUnio,
kein PHPMaker-/App-/API-/SaaS-Umbau. DEV/PROD bleiben unveraendert.

## Testnachweis

WorkspaceEditingTest prueft Gruppenreihenfolge, Entfernung der Stammdaten aus
der Sidebar, vollstaendige Erhaltung aller autorisierten Links, Rendering der
Einstellungen und Sperre nach Rollenentzug. Browser-Sichtpruefung nach Anmeldung
noch ausstehend.

Bereitgestellt: `20261016-clubunio-navigation`, Ruecksprung
`20261016-clubunio-workspace-editing-v2`. Config-/Routen-/Viewcache und
Mandantenchecker erfolgreich. 11 Tests/117 Assertions erfolgreich.
