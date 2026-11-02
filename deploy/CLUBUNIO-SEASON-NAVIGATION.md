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
Browser-/Deploymentnachweis folgt nach Abschluss.
