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

Der abschliessende kombinierte PostgreSQL-/Bestandsregressionslauf wird vor
Aktivierung ausgewertet. Quellcode-Commit oder Paketierung allein ist keine
Freigabe oder Behauptung einer erfolgten Bereitstellung.
