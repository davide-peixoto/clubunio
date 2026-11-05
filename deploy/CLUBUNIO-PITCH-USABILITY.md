# Platzplanung: Bedienungsnachlauf vom 05.11.2026

## Umfang

- Mannschaftsauswahl nutzt general/SaisonID und zeigt Team (Saison), statt
  historische gleichnamige Team-Saisons zusammen anzubieten. 28 Optionen auf
  ClubUnio statt der historischen Gesamtliste. Kalenderhistorie und Rechte
  bleiben unveraendert; keine Datenbereinigung. Details: PITCH-TEAM-OPTIONS.md.
- Tagesplantafel: drei gleich breite Platzspalten, Mindestbreite240px. Auf
  schmalen Bildschirmen scrollt nur die Plantafel. Zeitachse bleibt angeheftet.
- Parallelbelegungen teilen Breite nur innerhalb ihrer zeitlichen Gruppe;
  spaetere Einzeltermine erhalten volle Breite. Mindesthoehen werden beim
  Anordnen beruecksichtigt. Dichte Karten behalten Detaildialog, Tooltip,
  Status und Konfliktanzeige; redundanter Quelltext entfaellt nur optisch.
- Sprachwahl mit eigenen CSS-Klassen, zugaenglichem verborgenem Label,
  sofortigem Sprachwechsel und Abstand zum Avatar. Keine neuen UI-Texte.
  DE/EN sowie Dark Mode bleiben erhalten; MOBILE-LANGUAGE-HEADER.md.

## Tests

120 gezielte PHP-Tests / 1047 Assertions erfolgreich, einschliesslich echten
PostgreSQL-Rollbackfixtures fuer Domaene/Trainingshaelften und Sprachrechte.
Keine echten Termine, Rechte oder Providerdaten geaendert. Erster Lauf hatte
eine veraltete CSS-Versionsassertion; diese wurde angepasst und der komplette
Lauf erfolgreich wiederholt. Visuelle Fixture explizit aktiviert.

16 isolierte Sprachkopfzeilen-Browsertests (DE/EN, hell/dunkel,320/390/768/1440),
zusaetzlich Plantafel-Geometrietest mit zehn echten Blade-Fixtures auf vier
Viewportbreiten. Synthetische vierfach belegte Spalte und spaeterer Einzeltermin,
Dialog und Wochen-/Monatsansicht; kein Netzwerkversand. 2670 Katalogschluessel
und Platzhalter erfolgreich geprueft.

Angemeldete Livepruefung auf dev.clubunio.ch: Tagesplan mit drei Plaetzen,
aktuelle Saisonoptionen, englischer Anfragedialog, mobile Kopfzeile. Dialog
ohne Speichern geschlossen, Sprache wieder Deutsch. Livebefund nach erster
Version: Avatar schrumpfte im echten Suchheader; Nachkorrektur und dichte
Statusdarstellung im v2/v3-Nachlauf.

Finale Sichtabnahme: angemeldet,1280px Desktop mit neun echten Terminen und
drei nebeneinander sichtbaren Plaetzen. Dichte Karten ohne innere Scrollleisten,
Status/Konflikt ggf. mit Ellipse und vollstaendigem Tooltip/Detaildialog.
Mobile320/390px ohne Seitenueberlauf, Avatarabstand gemessen jeweils12px.
Keine Browserkonsolenfehler. Sprache wieder Deutsch, Viewportoverride entfernt.
Lokaler, nicht versionierter Bildnachweis: storage/app/pitch-usability-live-desktop.png.

## Deployment Und Migration

Gezieltes Delta auf Basis20261105-clubunio-native-pitch, danach Nachlauf
20261105-clubunio-pitch-usability-v3 (v2 Header, v3 kompakter Quelltext). Je zehn Dateihashes, PHP-Syntax,
releaseprivate Caches und identische Konfiguration bis auf Releasepfade.
Keine Migration, Seeds, Imports, Rechteaenderungen oder ENV-Aenderungen.
Native Platzplanung bleibt freigegeben, externe Push-/Mailprovider geschlossen.
Gate liest PostgreSQL in REPEATABLE READ / READ ONLY: sieben geschuetzte
Pitch-Routen, drei Plaetze, sechs Teilflaechen, neun Termine am05.11.2026;
eigener Moduladapter true, alter Client behaelt Anlagenansicht.

Rueckweg: vorigen Release20261105-clubunio-native-pitch atomar aktivieren und
PHP-FPM neu laden. Keine Daten ruecksetzen, bestehende Trainingshaelften-
Korrektur nicht wiederholen. PHPMaker DEV/PROD bleiben unberuehrt.

Finales Zehn-Dateien-Manifest SHA256:
`a0fa7a8bbccfb91f9ea1b039f5ebcac40c101daccc27707a06680ccdc30a3d7d`.
HTTPS Health200, anonyme persoenliche API401, PHP8.3-FPM aktiv.

## Weiterhin Offen

- Echte iOS-/Android-Abnahme mit installiertem aktuellem Testbuild.
- FCM/APNs-Zustellung ausschliesslich an freigegebenes eigenes Konto. Der
  private Firebase-Serviceaccount fuer das ClubUnio-Projekt und persoenliche
  Geraetebindung muessen verifiziert werden. GoogleService-Info.plist ist nur
  Clientkonfiguration und ersetzt diesen Schluessel nicht. Kein Legacyfallback,
  keine generelle Queueauslieferung; spaeter nur dedizierte Test-Outbox-ID.
- Testmail, Kamera/Scanner/Anhaenge und historische SFV-/Belegungskonflikte
  bleiben gesonderte Abnahmen. Bestehende Sperren/Ueberbuchungen nicht geraten.
