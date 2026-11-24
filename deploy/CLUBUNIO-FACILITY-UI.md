# Interaktive Anlagenplanung auf DEV – 24.11.2026

Unter `/pitch-planning` kann zwischen **Interaktiver Plan** und
**Bisherige Ansicht** gewechselt werden. Die Wahl gilt pro angemeldetem Konto
für die Serversitzung. Der neue Plan ist auf DEV Standard. Datum, Tag-/Woche-/
Monatsansicht und Mannschaftsfilter bleiben beim Wechsel erhalten.

Dies ist der erste Ausbau der Anlagenplanung. Menü und Teamplanung wurden
in diesem Arbeitsschritt nicht umgestellt. Bestehende Anlagen-, Teilflächen-,
Trainer-, Freigabe- und Konfliktregeln bleiben bei PitchPlanningService.

## Bedienung

- ClubUnio-Blau #164B8A und Hellblau #48A9E8, helle und dunkle Darstellung.
- Tagesraster nach Anlagen; Wochenraster für eine ausgewählte Anlage mit
  sieben Tagen; Monatsübersicht mit den vom bisherigen Kalender gelieferten
  vollständigen Wochen inklusive Randtagen.
- Lokale Filter für Anlage, Belegungsart, Status und konfliktbehaftete
  Einträge. Die Kennzahlen zählen eindeutige Belegungen, offene Anfragen
  und Belegungen mit Konfliktmarkierung innerhalb der gefilterten Anzeige.
  Diese Filter erzeugen keine weiteren Datenbankabfragen.
- Status, Teilfläche, Uhrzeit und Konflikthinweise stehen bei den Karten.
  Ein leer dargestellter oder weggefilterter Zeitraum ist keine Freigabe.
- Freien Slot anklicken: bestehendes Anfrageformular mit Anlage, Datum,
  Beginn und 120 Minuten. Mit Maus/Stift aufziehen: Beginn und Dauer in
  30-Minuten-Schritten, auch in umgekehrter Ziehrichtung.
- Karte mit Maus/Stift verschieben: bestehender Änderungsdialog mit neuem
  Ziel und frischer Originalrevision. Erst die ausdrückliche Bestätigung
  speichert. Klick, Touch oder Tastatur öffnen den bisherigen Detaildialog.
  Dort lassen sich Zeit und Anlage auch ohne Ziehgeste ändern.
- Randscrollen während Auswahl/Verschieben arbeitet lokal. Escape,
  Zeigerabbruch, Fokusverlust und Ansichtwechsel brechen die Geste ab.
- Auf schmalen Geräten startet der Plan mit der lesbaren **Liste**.
  **Zeitraster** bleibt auswählbar und scrollt innerhalb seines eigenen
  Bereichs. Listen- und Kalenderkarten öffnen dieselben Dialoge.

## Technik und Grenzen

React rendert nur die neue Kalenderdarstellung. Daten stammen aus der
bereits autorisierten und mandantenbezogenen Kalenderprojektion. Die neue
Projektion enthält nur Anzeigefelder und keine Revisionen oder internen
Anfragendenkennungen. Namen und Freitexte werden unverändert und escaped
ausgegeben. Neue Texte in `lang/de/facility_ui.php` und `lang/en/facility_ui.php`.

Die React-Brücke lässt nur editEntry, requestSlot, proposeMove und openDay
zu. Alle Schreibabläufe nutzen weiterhin die bestehenden Servermethoden,
Rechte-, Revisions- und Konfliktprüfungen. Kein neuer API-Endpunkt, kein
Datenimport und keine Datenbankmigration. Ohne neue Assets wird die bisherige
Ansicht verwendet. Ohne React oder bei Renderfehlern bleibt der ursprüngliche
serverseitige Kalender sichtbar. Der bisherige Kalenderblock wurde unverändert
in `pitch-planning/calendar.blade.php` ausgelagert.

`npm run build:facility` baut `public/js/clubunio-facility-planner.js`.
`npm run build` umfasst ebenfalls den neuen Build. Keine neue Abhängigkeit.
Konfiguration: `CLUBUNIO_FACILITY_UI_ENABLED`, Standard false. Nur auf DEV
aktiviert. Die ursprünglich vorhandenen übrigen Gates bleiben erhalten.

## Bereitstellung und Rückweg

Aktiv: `/var/www/svs-laravel/releases/20261124-clubunio-facility-ui-v3`.
Erhaltene Ausgangsbasis:
`/var/www/svs-laravel/releases/20261124-clubunio-dashboard-motion-v2`.
V1 führte elf explizit geprüfte Laufzeitdateien ein. V2 korrigierte die
Fallbackanzeige nach Livewire-Aktualisierungen und ergänzte die mobile Liste.
V3 verwendet direkte Zeigergesten für zuverlässige Verschiebevorschläge.
Jeder Schritt prüfte die exakte vorherige Quellbasis und behielt das vorige
Release. Keine Stage-/PROD-Aktualisierung, Composer oder Schema unverändert.

Beim ersten Schritt nur das neue DEV-Gate in der releaseeigenen Umgebung
ergänzt; private Sicherung `shared/facility-ui-env-before-20261124`.
Sofortiger persönlicher Rückweg: **Bisherige Ansicht**.
Global: Gate deaktivieren und Konfigurationscache neu bauen. Vollständiger
Rückweg: current auf die oben genannte Ausgangsbasis setzen und PHP-FPM
neu laden. Keine Datenbankrückmigration.

## Prüfung

- 37 PHP-Tests/195 Assertions erfolgreich, ein optionaler visueller
  Fixtureexport übersprungen. Darunter sieben neue UI-Fälle: DE/EN, sichere
  Ausgabe, Darstellungspräferenz, Kontoabgrenzung, Gates/Assets, Anfrage-
  berechtigung und Verschiebevorschlag ohne Write. Bestehende Kalender- und
  Dialogtests bleiben grün.
- Drei neue JavaScriptfälle prüfen Zeit-/Bereichsgeometrie, Überlappungs-
  spalten und lokale Filter. Weitere 18 bestehende Fälle prüfen den
  unveränderten Fallbackkalender inklusive Randscrollen. Zusammen 21 grün.
- Sprachprüfung: 3075 JSON-Schlüssel plus PHP-Gruppen und Platzhalter grün.
- `deploy/check-facility-ui.php`: acht echte DEV-Renderprüfungen in einer
  Nur-Lesen-Transaktion: Tag/Woche/Monat DE/EN sowie beide klassischen
  Rückwege. Das globale Gate ebenfalls geprüft. Keine Daten gespeichert.
- CSS/JS über DEV-HTTPS jeweils 200, PHP-FPM aktiv.

### Browser Desktop

Bestehende Anmeldung/Mandant SV Sissach. Tagesplan, Anlagenfilter und lokale
Konfliktfilter geprüft. Wochenplan mit sieben Spalten und Monatsplan mit
42 Rand-/Monatstagen gerendert. Wechsel modern → klassisch → modern erhält
Datum 24.11.2026 und Monatsansicht.

Slot 17:00 öffnete die Anfrage mit 120 Minuten. Aufgezogener Bereich öffnete
15:00 mit 90 Minuten. Karte F blau von 17:45 auf 17:00 gezogen: Änderungsdialog
mit bisherigem Termin, neuer Zeit, erhaltener Teilfläche und 75 Minuten.
Alle Dialoge abgebrochen. Keine Anfrage verschickt, keine Belegung geändert.
Ein beim Test gefundener doppelter Fallbackblock nach Livewire-Morph wurde
behoben und nach Dialog- und Ansichtwechsel erneut auf Unsichtbarkeit geprüft.

### Browser Handybreiten

390 × 844: Liste mit zehn echten Tagesbelegungen und lesbaren Konflikt-/
Teilflächenangaben. 320 × 812: Zeitraster intern scrollbar (804 Pixel Inhalt
bei 238 Pixel Scrollbereich), Seite bleibt ohne horizontalen Überlauf
(scrollWidth 305 bei innerWidth 320). Kein physischer Handy-/Safari-Test.

Beim letzten mobilen Wechsel zur bisherigen Ansicht lief die existierende
starke Sitzung ab (401). Normaler Reload führt zur OIDC-Anmeldung. Browser-
grösse zurückgesetzt, kein Authentifizierungsablauf automatisiert oder
umgangen. Desktop-Designwechsel ist bestanden; die Wiederholung dieses
mobilen Wechseltests benötigt eine neue Benutzeranmeldung.

Prüfbild `facility-browser-check/mobile-list.jpg` liegt ausserhalb des
Repositorys im chatbezogenen Vorschauverzeichnis.
