# Vereinsbezogenes Signage-Design

Release66, 15.10.2026. Nur ClubUnio; bestehendes PHPMaker DEV/PROD unveraendert.

## Bedienung

Signage-Vorschau > Vereinsdesign (nur Admin): Vereinsfarbe, Anzeigename,
Logo-Bildadresse und Standard-Trainingsperiode. Einstellungen gelten je Verein
fuer Vorschau und oeffentliche Anzeige /signage/{slug}. SV Sissach startet mit
Rot und vorhandenem Vereinswappen. Helle Farben erhalten dunkle Kopfzeilentexte.
Logo: lokale /images/ oder /brand/-Adresse oder HTTPS-Bildadresse ohne
Zugangsdaten, Query oder Fragment; kein direkter Upload in diesem Dialog.

## Darstellung

Kompakte dunkle TV-Ansicht nach bisheriger SVS-Anzeige, ohne Verwaltungsnavigation.
Spiele und Trainings mit Garderoben/Feld, Meldungen, Anlagenstatus, Wetter und
Instagram. Leere Spiel-/Meldungsbereiche werden ausgeblendet. Kleine Bildschirme
erhalten angepasste Spalten; Tabellen wechseln bei Platzmangel alle12 Sekunden
die Seite, Instagram rotiert paarweise. Auf Mobilgeraeten vertikale Darstellung.
Oeffentliche Daten werden alle300 Sekunden neu geladen; Feed-Komponenten pollen
alle60 Sekunden unter Beibehaltung vorhandener Cache-/Fehlerbehandlung.

## Migration und Schutz

Eigene additive Migration2026_10_15_000065: vier Settings im Bereich signage,
SIGNAGE_BRAND_COLOR, SIGNAGE_BRAND_LOGO, SIGNAGE_BRAND_TITLE, SIGNAGE_PERIOD_ID.
Keine Fachdatensaetze oder Altparameter werden umgeschrieben. Initial erste
aktive Trainingsperiode (SVS22 Sommer); Winter bei Bedarf explizit auswaehlen.
Keine automatische Saisonumschaltung. Speicherung mit Adminpruefung, Audit und
Versionsvergleich gegen paralleles Ueberschreiben. Fremde Perioden unzulaessig.
Keine API-/SaaS-/Betriebserweiterung. Oeffentliche Publikationsschutzregeln bleiben.

## Pruefung

Gemeinsamer finaler Lauf:27 Tests158 Assertions erfolgreich.
Blade- und JS-Syntax geprueft. Live Signage/CSS HTTP200,195 Settings/46 Vorlagen,
Outbound aus; Release66 aktiv, Ruecksprung65 erhalten.
Synthetische lokale40-Spiele-Ansicht ohne Datenbankwrites fuer Layoutpruefung;
tools/clubunio-signage-layout-fixture.php nur lokal, generiertes HTML nicht deployen.
