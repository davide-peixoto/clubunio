# ClubUnio: Vereinsstamm und Signage-Logos

Stand 12.10.2026, Release34. Nur ClubUnio, keine PHPMaker-/App-Aenderung.

## Bedienung

- Anlagen und Spielbetrieb -> Vereine und Logos (`football-clubs`). Admin und
  Geschaeftsstelle pflegen Vereinsname, Aktivflag und SFV-Logo-Anzeige.
- Vereinsnummer ist die SFV-Vereinsnummer, NICHT Team-ID, Mandant oder Club-API-ID.
  Nummer nur bei Anlage setzen; keine nachtraegliche Identitaetsaenderung.
- Revision verhindert verlorene Aenderungen. Mutationen landen in object_changes;
  keine physische Loeschung. Inaktiv blendet den Verein aus neuen Auswahlen aus.
- Filter, Suche und persoenliche Standardansichten sind kombinierbar.
- Wettkaempfe: Gastgeber/Gast Verein per Namen oder Nummer suchen (maximal50
  Ergebnisse). Mannschaftsname/Kategorie bleiben separat. Vereinsauswahl aendert
  weder Namen noch Garderoben, Spielfeld, Bemerkungen, Quelle oder SFV-Match-ID.
- Filter Vereinszuordnung pruefen findet eine fehlende, unbekannte oder inaktive
  Zuordnung auf mindestens einer Seite. Optionale Vereinsnummer-Spalten vorhanden.
- Historische unbekannte/inaktive Nummern duerfen unveraendert gespeichert werden.
  Neue oder geaenderte Zuordnungen muessen aktive Vereine desselben Mandanten sein.
  Kein automatisches Namensmatching oder Umdeuten von SFV-Team-IDs.

## Logos

- Wie im alten Signage feste SFV-Quelle:
  `https://asanisdatapublicprd01.blob.core.windows.net/logos/Verein/{Nummer}.gif`.
- Keine beliebigen URLs, HTML, Zugangsdaten oder Uploads. Nur positive Integer
  innerhalb des PostgreSQL-integer-Bereichs werden zur URL zusammengesetzt.
- Browser laedt die Logos direkt, asynchron/lazy, ohne Referrer; kein serverseitiger
  Download und kein externer Abruf im PHP-Request. Quelle sieht uebliche Browser-
  Verbindungsdaten. Zugriff auf Verzeichnis/Signage bleibt angemeldetes Backoffice.
- Fehlendes Bild, deaktiviertes Logo, unbekannter oder inaktiver Verein: neutraler
  Platzhalter mit stabilen36x36Pixeln, Mannschaftsname bleibt lesbar.
- Signage liest alle benoetigten Vereinszuordnungen in einer gemeinsamen Abfrage,
  nicht pro Spiel/Logo. Logo-Anzeige ist abschaltbar, Quelle kann extern ausfallen.
- Neue SFV-Spiele ohne Vereinsnummer bleiben ohne Logo bis zur fachlichen Zuordnung.

## Migration

- Migration000032 addiert football_clubs in der eigenen ClubUnio-DB; keine Aenderung
  an competitions oder legacy_prod. Unique(tenant_id,club_number), Revision/Audit.
- `clubunio:import-football-clubs` liest ausschliesslich den eingefrorenen PROD-
  Snapshot. Atomare Einmaluebernahme mit Importsperre/Marker; Wiederholung ist
  No-op und ueberschreibt keine manuellen Anpassungen. Ziel muss zuvor leer sein.
- Identische getrimmte Namen je Nummer werden zusammengefasst. Aktive Quellzeilen
  haben Vorrang vor geloeschten historischen Namen. Mehrere unterschiedliche aktive
  Namen werden NICHT geraten. Ungueltige Nummern/leere Namen werden gezaehlt.
- Ergebnis:1601 Quellzeilen ->1447 Vereine,0 ungueltige Zeilen,3 Konfliktnummern:
  3108 (FC Basel1893 / Team Basel Shaqiri),3120 (FC New Stars Basel1934 / FFV Basel),
  3538 (FC Bubendorf / FF Lausen Bubendorf). Diese bleiben bewusst ohne importierten
  Stammsatz; nach fachlicher Klaerung in Vereine und Logos manuell anlegen.
  Bestehende Wettkaempfe mit diesen Nummern bleiben unveraendert.
- Lokal/Pi nutzen dieselbe eigene ClubUnio-DB. Migration/Import nur einmal ausfuehren.
  Ruecksprung33 kann die additive Tabelle behalten; keine automatische Rueckmigration.

## Grenzen und Tests

Automatisierte Rollback-Tests: Formulare, Revision, Audit, Nummern-Identitaet,
Duplikate, Rollen/Mandanten, Filter/Suche/Standardansichten, Altbestands-Erhalt,
Logo-Deaktivierung/Scope, Importkonflikte. Bestehende SFV-/Anlagen-/Signage-Tests
werden mitgeprueft. Visuell Desktop/Mobil und echte Logos ohne Datenaenderung.
Testzahlen und Deploymentabschluss siehe PI.md.

Instagram/Wetter, automatische fachliche Vereinszuordnung und oeffentlicher
Displaybetrieb bleiben offen. API, SaaS, Betrieb/Cutover weiterhin ausgeklammert.
