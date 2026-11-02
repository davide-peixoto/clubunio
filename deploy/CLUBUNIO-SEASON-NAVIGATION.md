# Saisonabhaengige Navigation (02.11.2026)

## Struktur und Bedienung

Unter Sport bleibt **Saisons** der Einstieg in die Saisonverwaltung. Folgende
bisher gleichrangige Eintraege liegen jetzt als native Filament-Untermenues dort:

- Saisonplanung (Wizard)
- Mannschaften pro Saison
- Spielerzuordnungen
- Staff-Zuordnungen
- Technische Leiter
- Trainingszeiten

Die Untermenues erscheinen beim Oeffnen von Saisons oder einer dieser Seiten.
Die aktive Unterseite oeffnet auch die Sportgruppe. Teamplanung, Sponsorenlauf,
Wettkaempfe und Trainingsplaene bleiben unabhaengige operative Einstiege.
Globale Stammdaten und Beitragsregeln bleiben unter Einstellungen; saisonbezogene
Beitraege und Mannschaftsdetails bleiben ueber das bestehende Saisondossier erreichbar.

Die Menuesuche beruecksichtigt die Untermenues: ausserhalb der Saisonseiten wird
bei passendem Begriff der Einstieg Saisons gefunden. In einem geoeffneten
Saisonbereich bleiben passende Unterpunkte mitsamt ihrem Elternknoten sichtbar.
Ruecksetzen stellt die bisherigen Gruppenklappzustaende wieder her.

## Rechte und Migration

Nur schon autorisierte Navigationseintraege werden umgeordnet. Ohne seasons.view,
aber mit einem erlaubten Unterbereich, entsteht ein rein struktureller Elternknoten
ohne Link auf Saisondaten; die berechtigten Unterpunkte bleiben direkt erreichbar.
Ohne erlaubte Kinder entsteht kein zusaetzlicher Knoten. Die Ausgangseintraege
werden geklont, damit wiederholte Navigationserzeugung keine Kinder dupliziert.
Keine Aenderung an Policies, Fachservices, Mandantenfiltern, URLs oder Daten.

Keine DB-Migration, Datenuebernahme, Seeder oder neue Berechtigungszuweisung.
Nur NavigationStructure, Menuesuchskript und dessen Versionsreferenz ausliefern;
Caches im neuen ClubUnio-Release aufbauen. Vorherigen Release als Rueckweg
behalten. PHPMaker DEV/PROD, Mobile und SaaS/Betriebsumstellungen unveraendert.

## Abnahme

Gezielte Tests fuer vollstaendige URL-Erhaltung ohne doppelte Links,
verschachtelten Wizard, aktive Sportgruppe, reine Team-Leseprofile ohne
Saison-/Trainingszeitenzugriff und unveraenderte Einstellungsrechte.
6 gezielte Integrationstests/46 Assertions sowie Layouttest1/5 erfolgreich.
PHP- und JavaScript-Syntaxpruefungen ohne Fehler.

## Auslieferung und Browserabnahme

Release `20261102-clubunio-season-navigation`, Quellrevision
`0eb9e17fa598d14d07f1a8022d9d847315ddfc76`. Rueckweg:
`20261102-clubunio-absence-management`, unveraendert erhalten.
Drei Laufzeitdateien vorher gegen den Ausgangsstand und nachher gegen die
Quellrevision geprueft; alle neun Release-Gates erfolgreich. Konfigurations-,
Routen- und Viewcache im neuen Release erneuert; keine DB-Aenderung.

Angemeldete Chrome-Pruefung auf dev.clubunio.ch:
- Saisons zeigt alle sechs eingerueckten Unterbereiche.
- Spielerzuordnungen direkt aufgerufen, Saisons und Nachbarunterpunkte bleiben sichtbar.
- Suche Staff findet Saisons samt Staff-Zuordnungen und die passenden Auswertungen.
- Von Teamplanung aus findet Trainingszeiten den Einstieg Saisons; nach Oeffnen
  ist Trainingszeiten direkt erreichbar.
- Mobil390x844: Seitenleiste aufgeklappt, Suche Technische zeigt Saisons mit
  Technische Leiter. Keine horizontale Seitenueberbreite.
- Suche geleert und Viewportvorgabe aufgehoben; keine fachlichen Daten geaendert.

Lokale Screenshots storage/framework/testing/season-navigation-*.png nicht
versioniert. Parallele Mobile-Aenderungen und PHPMaker DEV/PROD nicht deployed.
