# Fachpaket 56: Altplaene, Schluesselbox und Instagram

Stand: 15.10.2026. Nur ClubUnio; PHPMaker DEV/PROD unveraendert.

## Trainingsaltbestand

Die eigene, lesend verwendete legacy_prod-Kopie enthaelt 41 Protokolle.
30 erfolgreiche Plaene sind strukturell uebernehmbar, einer hat eine
ungueltige Uebungsdauer. Zehn Fehler-/Zwischenstaende bleiben ausgeschlossen.
Ein eindeutig zugeordneter Plan wurde privat und archiviert als Plan46
uebernommen. 29 benoetigen die explizite Mannschaftszuordnung im Web unter
Mannschaften > Altplaene uebernehmen. Keine Mannschaft wird erraten.

18 strukturell gueltige Altplaene benoetigen eine aktuelle Qualitaetspruefung.
Solche Plaene bleiben privat; Kopieren umgeht diese Sperre nicht. Erst eine
validierte Bearbeitung erlaubt Freigabe. Original-SVG wird nicht ausgefuehrt;
Beschreibung und Varianten bleiben erhalten, Grafiken sind schematisch.
Quellhash, Revision, Besitzer/Mannschaft und Uebernahmegrund werden gesichert.
Wiederholte Uebernahme ist idempotent; geaenderte Quellen werden nicht ersetzt.

Eigene additive Migrationen55/56: Pruefwarteschlange, Herkunftsverknuepfung,
Qualitaetskennzeichen, Test-Schluesselboxen/Codes und Integrationspruefungen.
Keine App-API-Aenderung und keine neue Fremddatenbankmigration.

## Bus-Schluesselbox

Anlagen und Spielbetrieb > Schluesselbox-Testzuordnungen ist nur fuer Admins.
Es existiert ausschliesslich SIMULATION. Verschluesselte TEST-Codes oeffnen
keine echte Box. Ausgabe nur fuer genehmigte Reservierungen, berechtigte
eigene Mitglieder/Staff oder Verwaltung und im Reservierungszeitfenster.
Aenderung der Zuordnung, Verschiebung, Ablehnung oder Storno invalidieren
Testcodes. Keine Notfallcode-Fallbacks, keine echten PINs im Audit.

Offen: Modell, Bridge, separate Testbox und freigegebene Zugangsdaten.
Offline-algoPIN kann nicht pauschal sofort fernwiderrufen werden. Ein echter
Adapter mit bestaetigtem Hardwaretest ist noch nicht implementiert.

## Instagram

Expliziter Livecheck gegen Meta lieferte acht Beitraege, HTTP200. Der Feed
wurde nur in der ClubUnio-Konfiguration aktiviert. Adminaktion Instagram
pruefen speichert Ergebnis, Anzahl und Referenz, niemals Token/Antwortinhalt.
Bestehende Feed-Caches und Fehlerabkuehlung bleiben erhalten.

## Bexio-Teilkorrektur

Die oeffentliche API stellt keinen eigenstaendigen Anlage-Endpunkt fuer
Teilgutschriften bereit. In Sandbox SVS Api Test gegen bezahlte Rechnung6
manuell CHF10 gutschreiben, weder versenden noch Bankzahlung ausloesen.
Danach Referenz bei ClubUnio-Gutschrift18 erfassen und Abgleich pruefen.
Dieser manuelle Fachtest ist offen; keine negative Ersatzrechnung verwenden.

## Migration und Ruecksprung

Alte Anwendungen bleiben parallel. Keine Ablosung, API/SaaS/Betrieb ausgenommen.
Vorheriges Pi-Release55 erhalten; additive Daten nicht automatisch loeschen.
Nach jedem Release Commit/Push und Remote-Gleichstand pruefen.

## Pruefnachweis

39 Tests, 262 Assertions erfolgreich. Pi-Release56 aktiv, vorheriges55 erhalten.
Cache und Views aufgebaut; 189 verschluesselte Einstellungen und42 Templates
geprueft, Outbound bleibt aus. Angemeldete Warteschlange zeigt41 Quellen.
Instagram-Livecheck auch vom Pi: HTTP200, acht Beitraege.
