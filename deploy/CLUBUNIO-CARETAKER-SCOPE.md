# Platzwart: nur Verfuegbarkeit und Bemerkungen (19.10.2026)

## Rechte

Das SVS-Profil Platzwart erhaelt ausschliesslich `field_availability.view` und
`field_availability.update`. Neues schmales Modul im Profil-Editor:
Platzverfügbarkeit und Bemerkungen. Keine Namens-Sonderbehandlung im Fachcode.

- Bestehende Platzverfuegbarkeiten ansehen, Status aus bestehenden Werten
  auswaehlen und Bemerkung bearbeiten.
- Status-Wertetabelle weder anzeigen noch bearbeiten.
- Keine Neuanlage, Platzumordnung, Aktivierung/Deaktivierung oder anderen
  Fachmodule. Spielfeld und Aktiv-Schalter sind beim Bearbeiten gesperrt.
- Direkte manipulierte Fachaufrufe pruefen dieselben Grenzen innerhalb des
  gesperrten Schreibvorgangs. Revision und Vereinsbindung bleiben erhalten.
- Status-Lookup bleibt fuer die Auswahl verfuegbar, ohne Stammdatenrechte.

Bestehende `facilities.*`-Rechte gelten weiterhin fuer Anlagen inklusive
Status-Wertetabelle und Platzverfuegbarkeiten. Die Vererbung ist ausschliesslich
vom breiten zum schmalen Modul, niemals umgekehrt. Andere Profile werden nicht
geaendert. Der Nur-Lesen-Katalog umfasst jetzt25 Module; das bestehende explizite
SVS-Nur-Lesen-Profil bleibt unveraendert und deckt Plaetze ueber facilities.view ab.

## Migration / Deployment

Keine Schemamigration. Delta `20261019-clubunio-caretaker` auf aktuell verifizierter
Basis `20261018-clubunio-sync-view-performance`. Katalog, Modellzuordnung,
Fachservice und Formular gemeinsam ausrollen; Caches neu aufbauen.
CLI-Helfer `tools/restrict-svs-caretaker.php` schraenkt nur das bestehende
SVS-Profil ein, protokolliert die Aenderung ueber AccessProfileService und
vergleicht Fingerprints der anderen Profile, Konten, Rollen und Zuordnungen.
Standard ist Vorschau/Rollback, Schreiben nur mit `--apply`. Unerwartete
abweichende aktuelle Rechte werden nicht ueberschrieben.

Die Einschraenkung erfolgt vor Codeumschaltung. Alter Code kennt die neuen
Rechte nicht und sperrt deshalb bei Rollback den Platzwart, statt wieder die
Status-Wertetabelle freizugeben. Breite Rechte beim Rollback nicht zuruecksetzen.
Keine neue Benutzerzuordnung; PHPMaker DEV/PROD unveraendert.

## Tests

Synthetische PostgreSQL-Transaktionen: Status/Bemerkung speichern, manipulierte
Platzzuordnung und Aktivierung, Neuanlage und Status-Wertetabellenmutation
abweisen; Resource-Anzeige/Bearbeitung und alte breite Anlagenrechte pruefen.
Profildefinitionstest prueft die beiden ausschliesslichen Platzwartrechte.
Abschluss: 39 Tests / 1023 Assertions erfolgreich (SportsProfilePermission,
AccessProfile, ProfileUi und SVS-Definitionen). Darunter HTTP200 fuer
Platzverfuegbarkeit und HTTP403 fuer Status-Wertetabelle mit synthetischem
Platzwart. Testdaten transaktional zurueckgerollt. Kein manueller Browsertest.

Deployment erfolgreich: PHP-Lint, View-/Routen-/Konfigurationscache,
Mandantenrouten und Profilpruefung bestanden, Login HTTP200. Profil274 hat
genau die zwei schmalen Rechte, weiterhin null Zuordnungen. Fingerprints aller
anderen Profile, Konten/Rollen und Zuordnungen unveraendert. Wiederholte
Vorschau bestaetigt den Zielzustand ohne weitere Profilmutation.
