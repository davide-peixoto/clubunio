# Training: halber Platz als Standard

05.11.2026, ausdruecklicher Auftrag inklusive bestehender Trainings. Nur ClubUnio,
kein Eingriff in PHPMaker DEV/PROD oder bestehende Kontorechte.

## Verhalten

Neue Trainingszeiten sowie neue Zeilen im Saisonwizard verwenden "Halber Platz
(automatisch)". Die null-Auswahl bleibt ein ausdruecklicher ganzer Platz;
bestehende explizite Teilflaechen bleiben bei gewoehnlichen Aenderungen erhalten.
Ein Platzwechsel in der Wochenvorlage setzt wieder den Halbplatzvorschlag.
Der Formularwert0 wird beim Speichern zu einer echten Teilflaechen-ID aufgeloest,
nie als0 in der Datenbank gespeichert. Alte Aufrufer ohne area_id bekommen bei
Neuanlage ebenfalls den Halbplatzstandard; explizites null bleibt unveraendert.

Auswahl unter dem bestehenden Mandanten-Schreiblock: halbe geometrische Flaechen,
gleiche Saison, Periode, Wochentag, ueberlappende Uhrzeit und gemeinsame Variante
(Normalbetrieb/ohne Hauptplatz/nur Kunstrasen). Zuerst moeglichst unbelegte Haelfte;
bei Ueberbelegung kleinste bestehende Ueberschneidung, stabil nach Flaechen-ID.
Dies ist keine konfliktfreie Platzfreigabe. Tagesausnahmen, Spiele und Sperren
bleiben in der bestehenden Plantafel-Konfliktpruefung; keine automatische
Verschiebung oder Aufhebung einer Sperre. Halle ohne Platz bleibt ohne area_id.
Neue Plaetze ohne konfigurierte Halbteilflaeche erfordern zuerst deren Einrichtung.

Signage und App lesen bereits dieselben numerischen area_id und DE/EN-Namen.
Keine neue Datenstruktur oder erforderliche App-Aenderung fuer diese Darstellung.

## Bestandskorrektur und Wiederholung

Lesender Bericht `deploy/audit-training-halves.php --read-only`: SV Sissach,
648 Trainingszeiten,95 mit Platz ohne Teilflaeche,553 ohne Platz, keine Ausnahmen
und noch keine Teilflaechen. Keine Platzzuordnung anhand von Namen geraten.

`deploy/correct-training-halves.php --preview` liefert den Snapshot-Hash.
`--rollback-test --expected=<hash> --actor=<existing-admin>` prueft den gesamten
Ablauf transaktional. `--apply` ist nur auf dem ClubUnio-Linuxserver erlaubt:
privater verschluesselter Export mit exklusiver Datei0600, fsync, Decrypt-Readback,
frischer Hashvergleich und Mandantenlock vor dem Commit. Keine allgemeinen Seeds.

Nur fuer SV Sissach: A/B-Haelften mit DE/EN-Namen fuer Plaetze ohne eigene
Geometrie erzeugen, bestehende Trainings ohne Teilflaeche zuordnen. Pro Aenderung
Revision und object_changes; Zeiten, Teams, Varianten, Garderoben und Aktivstatus
bleiben unveraendert.553 fehlende Plaetze nicht raten. Keine Mails/Push fuer diese
technische Erstzuordnung. Vorhandene Ausnahmen stoppen den Apply zur Fachpruefung.
Wiederholung behandelt nur weiterhin unzugeordnete Trainings; vorhandene
Geometrie/Zuordnungen werden nicht ueberschrieben. Geschuetzte Tabellen werden
vor/nach Apply verglichen. Bereits vorhandene fachliche Konflikte bleiben sichtbar.

Rueckweg: Code auf vorherigen Release zurueckstellen, additive Teilflaechen
behalten. Datenkorrektur nur aus der geprueften Sicherung und nach Vergleich
der aktuellen Revisionen gezielt zuruecknehmen; keine spaeteren Aenderungen
ueberschreiben und verknuepfte Teilflaechen nicht pauschal loeschen.

## Nachweis

- Wochenvorlagen/Teilflaechen:11 PostgreSQL-Rollbacktests/87 Assertions.
- Echter Filament-Erfassungsdialog:1 Test/14 Assertions, Halbplatz gespeichert.
- Bestandsprobe95 Trainings/sechs Teilflaechen komplett zurueckgerollt;
  anschliessend identischer Snapshot-Hash.
- DE/EN:2670 Schluessel geprueft.
- Erweiterte Saison-/Platzregression und Serverrelease werden nachgefuehrt.

Ausgangsrelease `20261104-clubunio-web-chat-v1`. Kein Schemaeingriff,
keine ENV-/Providerfreigabe und keine Aenderung von Login-/Benutzerrechten.
