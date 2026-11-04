# Platzplanung: Integrationsnachlauf

Stand: 04.11.2026. Nur ClubUnio; PHPMaker DEV/PROD unveraendert.

## Abnahmegrenzen

Die angemeldete lokale Sichtpruefung wurde vom Benutzer ausdruecklich auf
spaeter verschoben, da aktuell kein physischer Zugriff besteht. Automatisierte
Tests und Fragment-/Widgetpruefungen ersetzen diese Fachabnahme nicht.

Der Benutzer hat einen klar gekennzeichneten echten Push-Test ausschliesslich
an sein eigenes Konto freigegeben. Ein Versand an andere Konten oder das
Abarbeiten der allgemeinen Push-Warteschlange ist nicht freigegeben.
Der Worker bietet deshalb `clubunio:deliver-mobile-push --dispatch --outbox=UUID`:
nur dieser vorhandene Eintrag wird verarbeitet, ohne weitere Quellen zu sammeln
oder fremde abgelaufene Versandversuche zu veraendern. Providerfreigaben und
erneute Empfaenger-/Objektberechtigungen gelten weiterhin. Providerannahme ist
kein Nachweis einer Anzeige auf dem Geraet.

## Lesende Livepruefung

`deploy/audit-pitch-readiness.php --read-only`, 04.11.2026 21:19 UTC:

- Aktiver Release: `20261104-clubunio-chat-delta-v1`.
- Acht neue Platzplanungstabellen und Migrationen001500-001800 fehlen noch.
- Push-Erfassung und Versand deaktiviert; keine Providerkonfiguration und
  keine aktive Geraet-/Sitzungsbindung fuer das freigegebene Testkonto.
- Keine Nachricht versendet, keine Parameter oder Benutzerrechte geaendert.
- Ueber alle Vereine und Zeitraeume:640 aktive Trainingszeiten, davon553 ohne
  Platz. Diese Zahlen sind keine Aussage ueber die aktuelle SV-Sissach-Saison.
-101 aktive Wettkaempfe, davon99 mit SFV-Bezug; noch keine Platzplanungs-
  Mannschaftsverknuepfungen. Keine automatische Zuordnung anhand von Namen.
- Drei aktive Plaetze mit Verfuegbarkeit, davon zwei mit Trainingssperre.

Fehlende Platzangaben werden nicht geraten. Historische SFV-Zuordnungen erst
ueber den lesenden Bericht und eine separat bestaetigte Vorschau anwenden:
[Zuordnungsvertrag](CLUBUNIO-PITCH-TEAM-MAPPING.md).

## Migrationspfad

Die neue Wochenvorlagen-Teilflaeche und Antrags-Idempotenz ergaenzen den
[Platzplanungs-Migrationsplan](CLUBUNIO-PITCH-PLANNING.md). Keine pauschale
Migration, keine Datenauffrischung und keine automatische Profilfreigabe.
Vor einem gezielten Schema-Apply: gepruefte verschluesselte Sicherung,
exakte Quellhashes, Bestand-/Ledgervergleich und transaktionaler Ablauf.
Code-Staging, Schema-Apply, technische Freigabe und Aktivierung bleiben
getrennte Schritte. Additive Daten bei Code-Rollback erhalten.

Offene externe Abnahme: Firebase/ClubUnio-App konfigurieren und das eigene
Geraet anmelden, danach ausschliesslich den freigegebenen Test ausfuehren.
Angemeldete Desktop-/Mobilpruefung und native App-Abnahme bleiben offen.

## Zusammengefuehrte Pruefungen

- Unit-Gesamtlauf:126 Tests/55299 Assertions bestanden.
- API-/Web-/Nachrichten-/Kalenderlauf:83 Tests/711 Assertions, ein bewusst
  nicht erzeugtes visuelles Fragment uebersprungen; keine angemeldete Abnahme.
- API-Replay und historische Zuordnung auf PostgreSQL:45 Tests/572 Assertions.
- Echte Zwei-Verbindungen-Rennen:13 Tests/234 Assertions; keine verbliebenen
  Testschemas oder offenen Testtransaktionen. Siehe [Nachweis](PITCH-CONCURRENCY.md).
- Selektiver Push: ein isolierter PostgreSQL-Test/15 Assertions und zwei
  Commandtests/sechs Assertions, ohne Provideraufrufe oder fremde Queuearbeit.
- Flutter:29 gezielte Platzplanungstests bestanden, Mobilecommit `1c9d552`
  nach `origin/main` synchronisiert. Keine Store-/Geraeteveroeffentlichung.
- DE/EN:2589 Schluessel und Platzhalter geprueft; zehn Archiv-Sicherheitstests.

Abschliessender kombinierter PostgreSQL-/Bestandsregressionslauf:211 Tests,
3589 Assertions, bestanden; ein opt-in visuelles Fragment bewusst uebersprungen.

## Ausgefuehrter ClubUnio-Release

`20261104-clubunio-pitch-v1` aus `9c0fbbe` ist aktiv, vorheriger Code
`20261104-clubunio-chat-delta-v1` bleibt als Rueckweg erhalten.68 exakte Git-
Dateihashes gegen den Serverbestand geprueft, keine fremden Dateien ueberschrieben.
Private Konfiguration, Composer-Abhaengigkeiten und geteilte Uploads erhalten.
Release-lokale Views und Config-/Routecache aufgebaut; PHP-FPM aktiv/neu geladen.

Migrationen001500-001800 nach verschluesseltem Export, Decrypt-/Hash-Readback
und frischem Bestandsvergleich in einer Transaktion angewendet. Alle alten
Elternzeilen/Definitionen unveraendert ausser den dokumentierten additiven
Deltas: nullable `training_slots.area_id`, zusaetzliche Push-Art,56 neue
DE/EN-Vorlagen samt Initialrevision und exakt vier Ledgerzeilen in einem Batch.
Acht neue Tabellen sind leer. Kein Bestands-Apply oder Provideraufruf.

Private Sicherung:
`shared/pitch-backups/before-20261104213309-33cc10f8bf9304c2d93d3693f5206e8b.cuenc`,
SHA256 `a117d1c23f711166ac2086d4fc19dd6d2cee356421bc0871ced48f011c0b48cf`.
Manifest-SHA256 `dc2f34f2d2720d52b629fa55b496b65a06b545f10ee1973d20e5cbbf2b84663b`.
Gezielte Elternsicherung, kein Nachweis einer vollstaendigen Datenbankwiederherstellung.

Der erste Serverlauf stoppte an einer QueryException im bestehenden
Plattform-Pruefskript. Einzelpruefungen sowohl des alten als auch neuen Release
bestanden anschliessend; danach bestand der gesamte Serverlauf mit Rollen-,
API-, Mobile-, Team-, Kontakt- und Chat-Kernelpruefungen. Ursache des einzelnen
Datenbankfehlers nicht nachgewiesen, keine Rechte-/Datenlockerung zur Umgehung.
Der abschliessende Eltern-/Ledgervergleich nach allen Gates bestand erneut.

HTTPS: Health/Login/CSS200; Webkalender anonym302 zum Login (JSON401),
numerische persoenliche Club-API anonym401. Slugs sind in dieser API keine
gueltigen Club-IDs. Nachaudit21:39 UTC: alle vier Ledgercounts1, keine fehlende
Tabelle,424 Vorlagen/Revisionen,0 Push-Outbox-Zeilen. Native Pitch, Push und
externer Mailversand bleiben geschlossen; keine ENV-Freigabe vorgenommen.

## Fachliche Restpunkte

Lesende aktuelle SV-Sissach-Projektion04.-10.11.2026: drei Plaetze,44 Eintraege
(42 Trainings, zwei Wettkaempfe),43 Eintraege mit Konfliktkennzeichnung.
Bestehende Sperren und Ueberschneidungen werden nicht als Teil des Release
bereinigt. Teilflaechen/Zuordnungen muessen fachlich gepflegt werden; neue
Teilflaechentabelle ist leer. Administrative Teamoptionen enthalten auch alte
aktive Teamsaisons; saisonbezogene Auswahl/Bezeichnung in der Sichtabnahme pruefen.

Historische SFV-Vorschau01.07.2026-30.06.2027, rein lesend:23 eindeutig,
125 uebersprungen, sieben mehrdeutig. Gruende:54 inaktive Wettkaempfe, zwei ohne
Match-ID,66 ohne gespeicherte Quelle, drei ohne lokales Team, sieben mehrdeutige
Matches. Kein Apply: der gesamte gewaehlte Bereich bleibt bei diesen Fehlern
gesperrt. Vor einer spaeteren Zuordnung Quelle/Dubletten fachlich pruefen und
eine neue Vorschau bestaetigen, keine Namen raten und keine Spiele loeschen.

Naechste Abnahme: angemeldete Desktop-/Mobilansicht, initiale Fachkonfiguration,
separate native Pitch-Freigabe und echte App-Pruefung. Firebase und eigene
Geraetebindung vor dem explizit freigegebenen Einzel-Push-Test einrichten.
