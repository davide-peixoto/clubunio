# ClubUnio: blaue Identitaet

15.10.2026, Release60-blue-brand auf Basis59-member-changes.
Nur ClubUnio/Pi; PHPMaker DEV/PROD unveraendert. Keine DB-Migration,
keine ENV-, Berechtigungs-, API- oder OAuth-Aenderung.

## Umfang

Verbundenes U (Entwurf02) als echte SVG-Pfade, auch die Wortmarke.
Hauptfarbe #164B8A, Akzent #48A9E8, Schrift #202C3B.
Filament-Login/Navigation inklusive weisser Dark-Mode-Wortmarke;
Mitgliederportal, Teamplanung/Training, Bus und Bexio-Verbindung erhalten
Logo und gemeinsame Icon-Metadaten. Markenaktionen, Fokus, Navigation,
Kalender und allgemeine Mitglieder-/Rechnungsdiagramme sind blau.
Semantische Status-/Erfolgs-/Fehlerfarben und gepflegte Lookups bleiben
absichtlich erhalten; Vereins-/Gegnerlogos bleiben ebenfalls unveraendert.

SVG-Favicon, ICO-Fallback unter /favicon.ico und Apple-Touch-Icon180.
Laufzeitdateien public/brand/blue-v1, Quellen resources/brand/blue-v1.
CSS-URLs versioniert mit ?v=blue-v1, um alte gruene Browsercaches zu vermeiden.
Kein externer Font-/Bildabruf fuer das Logo. SVGs ohne Scripts/Rasterdaten.

## Reproduktion

build.py erzeugt SVG-Pfade, render.cjs Design-PNGs, publish.cjs kopiert nur
Laufzeitdateien und erzeugt Touch-/ICO-Dateien. Python fontTools/Brotli und
Node sharp werden lokal benoetigt; nicht auf dem Server.
Designpaket enthaelt Varianten und Anwendungshinweise. Kein nativer App-Release.

## Abnahme und Ruecksprung

Brandingtests pruefen beide Logins, exakte Markenfarben, SVG-Inhalt,
Touch-Icon und Entfernung der alten Markenfarbe in Portal-/Planungs-CSS.
Angemeldete Navigation und Light/Dark/Mobil visuell kontrollieren.
Abnahme abgeschlossen: 8 Branding-/Navigations-/Paginationtests mit88
Assertions; 27 DB-Regressionstests (Mandantenwahl, Bus, Signage) mit187
Assertions erfolgreich. Fixtures zurueckgerollt. Login Desktop/Mobil,
Portal mobil, angemeldetes Live-Dashboard Light/Dark visuell geprueft;
keine Browserkonsolenfehler. Theme nach Test auf System zurueckgestellt.
Oeffentliche SVG-/ICO-/Touch-Icons liefern200, Deployment inkl. erneuerter
Config-/Route-/View-Caches erfolgreich. Markenfarben400/600 exakt festgelegt.
Release59 als Ruecksprung erhalten: current zuruecksetzen, PHP-FPM neu laden.
Keine Daten zurueckzusetzen.
