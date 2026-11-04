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
- Saisonplanung:27 Tests/344 Assertions; Platzdomaene:13 Tests/105 Assertions.
  Zusammen mit den obigen Laeufen52 Tests/550 Assertions. Erste Saisonfixtures
  wurden um die nun erforderliche Halbplatzkonfiguration ergaenzt, Nachlauf gruen.

Ausgangsrelease `20261104-clubunio-web-chat-v1`. Kein Schemaeingriff,
keine ENV-/Providerfreigabe und keine Aenderung von Login-/Benutzerrechten.

## Ausgefuehrt

Aktiv `20261105-clubunio-training-halves` aus `6d35c0f`: zehn exakte Git-Dateien
gegen vorherigen Serverstand geprueft, release-lokale Caches aufgebaut, Rollen-/
Mandanten-/Supportgates bestanden. Health/Login200, Trainingszeiten/Plantafel
anonym302 zum Login. ENV/Composer erhalten; PHP-FPM neu geladen.

SV Sissach:sechs Halbteilflaechen IDs35-40 angelegt,95 Trainings zugeordnet,
jeweils Revision und Audit. Nachpruefung:0 verbleibende Kandidaten,553 fehlende
Platzangaben unveraendert. Apply UTC04.11.2026 23:09, lokal05.11.2026.
Keine Mails/Push, keine Wettkaempfe/Sperren/Termine verschoben oder freigegeben.
Ausdruecklich bestaetigte letzte Auswahl: automatische Verteilung, nicht feste A.

Private Sicherung `shared/backups/training-halves-20261104230925-17e098204bbc2022.cuenc`,
SHA256 `9fe353ad326c3994c00cf474391a7335e0489bbf57d8c12369aa2e44116231f3`.
Vorher-Snapshot `2bb851adf1d98e7836acb1028cd89ee6bdf07a24babaa368eb09bf5997454aec`,
Nachher `8ec6f70f3bc69bca1dac4755a3586c022b752485f409ba197e0f816edfc9fb39`.
Gezielte Sicherung, kein Nachweis eines vollstaendigen Datenbank-Restore.
Paket-SHA256SUMS `d39997b4a8c3f7f7e61f402eec0ddbf06dc15f021c3e021744f0290b6f62df5e`.
Angemeldete manuelle Sichtpruefung bleibt gemaess vorheriger Vereinbarung offen.
