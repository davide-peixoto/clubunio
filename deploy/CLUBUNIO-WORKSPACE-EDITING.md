# Navigation und Dossierbearbeitung (16.10.2026)

## Umfang

- Menuesuche innerhalb der bereits berechtigten Navigation, einschliesslich
  zusammengeklappter Gruppen. Leeren stellt den vorherigen Gruppenzustand wieder her.
- Menuebreite am Desktop 240 bis 420 px, je Benutzer im Browser gespeichert.
  Mobil bleibt die Navigation auf maximal 85 Prozent der Bildschirmbreite begrenzt.
- Fachbezogene Heroicons statt fehlender/generischer Stammdatensymbole.
- Mitgliederdossier mit Reitern Person, Kontakt, Eltern & Rechnung, Mitgliedschaft.
- Kleine Mannschaftszuordnungen als Modaldialog statt seitlichem Vollformular.
- Direkte Hinzufuegen-/Bearbeiten-/Kopieren-/Deaktivieren-Aktionen fuer
  Mannschaften pro Saison, Spieler, Staff, Technische Leiter, Beitragsregeln und
  Trainingszeiten in ihren passenden Dossiers. Saison/Team/Mitglied wird serverseitig
  festgesetzt. Die Hauptlisten dieser Zuordnungen und Beitragspreise bieten ebenfalls
  Kopieren und Deaktivieren an.

## Grenzen und Schutz

Kein physisches Loeschen: Deaktivieren erhaelt Beziehungen und Historie und kann
ueber Bearbeiten rueckgaengig gemacht werden. Die vorhandenen Duplikatpruefungen,
Jahrgangs-Ueberschneidungspruefungen, Auditdaten und Versionskontrollen bleiben aktiv.
Neue Dialoge pruefen die aktuelle Verwaltungsberechtigung, den Mandanten sowie
die Zugehoerigkeit zum konkreten Dossier. Zugehoerigkeitspruefung und Aenderung
laufen unter der bestehenden Mandantensperre in derselben Transaktion.

Rechnungen/Spesen werden NICHT pauschal kopier-/loeschbar. Deren bestehende
Detailaktionen mit Freigabe-/Bexio-/Revisionsschutz bleiben bestehen.
Direkte Finanzbearbeitung aus der Saison ist separat ergaenzt:
CLUBUNIO-SEASON-FINANCE.md (Belegkopf, Positionen, interne Statusaktionen).
Ebenso keine globale CRUD-Freigabe fuer alle Stammdaten, Statuscodes oder Mitglieder.

## Migrationsplan

Additiver UI-Schritt im parallelen ClubUnio-Aufbau. Keine Datenbankmigration,
keine Uebernahme aus PHPMaker, keine API-/SaaS-Aenderung. Bestehendes DEV/PROD
bleibt unveraendert. Bei einer spaeteren Gesamtmigration die Berechtigungen und
Lookup-Werte mit den Quellsystemen erneut abgleichen; die Bedienungsaktionen sind
kein Ersatz fuer fachliche Datenmigration oder Freigaben.

## Pruefung

`WorkspaceEditingTest`: transaktionale synthetische Daten, anschliessend Rollback;
kein externer Versand. Abgedeckt: Erstellen, Bearbeiten, Kopieren, Deaktivieren,
Duplikatschutz, fremder Mandant, fremdes Dossier, entzogene Rolle und veraltete Revision.
Dossier-/Navigation-/Mitglieder-Regressionen separat ausfuehren.
Visuelle Desktop-/Mobilpruefung erfordert eine angemeldete Browsersitzung.

Deployment ausschliesslich als ausgewaehltes Delta auf ClubUnio, aus dem jeweils
aktuellen Release; parallele Bexio-/Synchronisationsarbeiten nicht mit veroeffentlichen.

## Ergebnis

ClubUnio bereitgestellt unter `20261016-clubunio-workspace-editing-v2`, auf Basis
des inzwischen aktiven `20261016-clubunio-71-sync-final` (zugleich Ruecksprung).
Das bereits veroeffentlichte Synchronisationspaket bleibt dadurch erhalten.
Config-/Routen-/Viewcache und Mandanten-Routenchecker erfolgreich, Login HTTP 200.
9 neue Tests/104 Assertions bestanden; 31 Regressionstests/353 Assertions,
davon 1 optionaler Livetest ausgelassen. PHP-Lint, Pint und JS-Syntax erfolgreich.
Visuelle Anmeldung angefragt, noch nicht erfolgt: Desktop-/Mobilabnahme bleibt offen.
