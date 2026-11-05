# Native Platzplanung: Freigabe und Sichtpruefung

Stand05.11.2026, ausdruecklicher Benutzerauftrag. Nur ClubUnio.

## Aktivierung

Aktiv: `20261105-clubunio-native-pitch`, vorher
`20261105-clubunio-training-halves`. Identischer Fachcode; ausschliesslich
`CLUBUNIO_MOBILE_NATIVE_PITCH_PLANNING_ENABLED=true` in der releaseprivaten
Konfiguration. Quellcode-Default bleibt false. Kein Schema-Apply, keine
Bestandsdaten-/Profilmutation und keine PHPMaker-DEV-/PROD-Aenderung.
Pushcapture, Pushzustellung, Mail, KI und weitere Providerflags unveraendert aus.

`deploy/enable-native-pitch.sh` kopiert den festen aktiven Ausgangsrelease,
vergleicht ENV/Composer/Storage, prueft den geschlossenen Ausgang und baut
release-lokale Caches. Vollstaendiger Konfigurationsvergleich erlaubt nur den
Pitchschalter und den neuen Releasepfad. Der erste Vergleich stoppte am
laengenbehafteten PHP-Serialisierungsformat; korrigierter JSON-Vergleich bestand
vor Aktivierung. Der aktive Ausgang wurde dabei nicht geaendert.
Atomarer Symlinkwechsel und PHP8.3-FPM-Reload erst nach erfolgreicher Pruefung.

Toolhashes vor Ausfuehrung lokal/remote verglichen:
- enable-native-pitch.sh:03596cc2e7e0c2772c8751b650790d3b2bff7fd187cfb9b4b226ee0fc0f112db
- check-native-pitch-activation.php:c0b9b04a2a6873af6831ca8493bf753b4b0571ab15197d67aaa9f9a16c67c32e

## Technischer Nachweis

-26 HTTP-/Controller-Vertragstests,403 Assertions: Rechte, Mandantengrenzen,
  Idempotenz, Sprachfehler, Featureaushandlung, abgeschalteter Modus.
-24 PostgreSQL-Domaenen-/Trainingshaelftentests,192 Assertions, synthetische
  Rollbackfixtures; keine echten Terminverschiebungen oder Nachrichten.
-44 Fluttertests: Kalender, Navigation, Tagesansicht, Drag/Editor, Request-Retry,
  Fehler-/Revisionsschutz, DE/EN, Notifications-Zielaufloesung und alte Anlagenansicht.
-2670 DE/EN-Schluessel und Platzhalter erfolgreich geprueft.
-Server: READ ONLY / REPEATABLE READ prueft vier Migrationsledger, sieben
  persoenlich geschuetzte Routen und reale Projektion. Fuer das freigegebene
  eigene Konto liefert der Moduladapter pitch_planning=true, facilities=true.
  Ohne pitch-planning-v1 liefert er weiterhin pitch_planning=false,
  facilities=true. Drei Plaetze, sechs Teilflaechen, neun Termine am05.11.2026.
-Der eigene Kontotest ist ein interner lesender Adapter-/Domaincheck, kein
  erfundener Bearerlogin und kein nativer Geraetenachweis. Bestehende Module und
  gespeicherte Rechte wurden nicht erweitert.
-Aktives PHP-FPM, HTTPS /up200, anonyme persoenliche Pitch-API401.

## App verwenden

App-Quellstand `1c9d552` aus dem separaten Mobile-Repository enthaelt den
Kalender und die Featurekennung. Nach Modul-Neuladen/erneuter Anmeldung unter
Platzverfuegbarkeit erscheint die neue Planung. Aeltere Binaries ohne den
Kalender benoetigen einen neuen Testbuild; keine Storeveroeffentlichung erfolgt.
My Teams bietet bei Freigabe zusaetzlich den datierten Mannschafts-Tagesplan.

## Angemeldete Sichtpruefung

Mit der vom Benutzer geoeffneten Sitzung auf dev.clubunio.ch, DE/EN,
Desktop1280px und Mobil390px. Tages-/Wochen-/Monatsansicht, Platzsperren,
Hälfte A/B (EN Half A/B), Anfragedialog und Trainings-Einzeldialog mit
Konflikthinweis geprueft. Dialoge ohne Speichern geschlossen; kein Drag/Drop
auf realen Terminen. Keine vollstaendige native iOS-/Androidabnahme behaupten.

Auch Webchat mit echter Sitzung geprueft: Chatliste, Ankuendigungseingabe,
mobile Zuruecknavigation und Einstellungen. Keine Nachricht, kein Upload,
keine Einwilligungsaenderung. Sprache abschliessend Deutsch, Viewportoverride
entfernt. Echte Anhang-/Scanner-/Kameraabnahme weiterhin offen.

Bestaetigte Bedienungspunkte, im Nachlauf CLUBUNIO-PITCH-USABILITY.md behandelt:
- Mannschaftsauswahl enthaelt historische Saisons mit gleichen Anzeigenamen;
  saisonbezogene Auswahl und eindeutige Beschriftung bleiben erforderlich.
- Tagesplantafel verbreitert Spalten nach Parallelbelegungen; damit sind bei
  dichter Belegung nicht alle drei Plaetze gleichzeitig auf Desktop sichtbar.
- Mobile Kopfzeile: Sprachwahl und Avatar liegen bei390px zu dicht beieinander.

Offen bleiben echte Geraeteanmeldung/Push, Testmail ausschliesslich an den
freigegebenen Testempfaenger, historische SFV-Zuordnungen und fachliche
Bereinigung bestehender Sperren/Ueberbuchungen. Keine stille Datenkorrektur.

## Rueckweg / spaetere Migration

Vorherigen Release `20261105-clubunio-training-halves` atomar wieder aktivieren
und PHP-FPM neu laden; dessen private Konfiguration bleibt native_pitch=false.
Keine Daten zuruecksetzen: waehrend der Freigabe erfasste Anfragen, Revisionen
und Idempotenzledger bleiben erhalten. Bei kuenftigen Releases den bewussten
Schalter erhalten und keine alten --closed-Gates als aktuellen Iststand deuten.
Alte pitch-v1-/web-chat-v1-Gates sind historische, releasegebundene Werkzeuge.
