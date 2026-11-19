# Support-Einstieg: DEV-Abnahme 19.11.2026

## Verwaltungsbereiche

DEV `20261119-clubunio-support-management`, Rueckweg
`20261119-clubunio-campaign-operations-v3`. Gemeinsames Launcher-Partial
fuer Filament-Vereinsverwaltung (aktueller Tenant) und Plattformverwaltung
(Plattform-Support). Auf Supportseiten kein rekursiver Launcher.
Keine DB-Migration oder Rechteaenderung; Stage unveraendert.
PHP-Syntax, Viewkompilierung und DEV-Datenbankcheck erfolgreich.
Angemeldete Sichtpruefung dieser zwei neuen Einstiegspunkte noch offen:
Vereinslogin erfordert erneute Anmeldung, vorhandene Browseridentitaet
erhaelt am Plattformcallback HTTP 403. Keine Rechte dafuer erweitert.

## Seitliches Supportfenster

DEV `20261119-clubunio-support-panel`, Rueckweg
`20261119-clubunio-laravel-mail-theme`. Vollstaendiges Supportcenter im
gleich-originigen Iframe mit nativer Dialog-Fokusbegrenzung und Escape/Schliessen.
Die Ausgangsseite wird nicht navigiert; der Inhalt bleibt beim Schliessen
im Fenster erhalten. Mobile Ansicht fuellt die Bildschirmbreite.
Desktop und 390 x 844 im angemeldeten Browser geprueft: Ticketformular,
Vereinskontext und Schliessen ohne Seitenwechsel. Stylesheet versioniert,
damit das neue Formular-Design nicht aus einem alten Browsercache kommt.
Keine Datenbankmigration, keine neuen Rechte; Stage unveraendert.

- DEV: `20261119-clubunio-support-launcher`; Rueckweg:
  `20261119-clubunio-member-change-conflicts`.
- Blauer schwebender Supportlink im Vereinsportal, auf Mobilbreite als
  kompakter Iconbutton mit barrierefreiem Namen. Oeffnet die vollstaendige
  Supportseite statt des eingeschraenkten oeffentlichen Hilfe-Iframes.
- Vereinskontext wird serverseitig aus dem vorhandenen Tenant gesetzt.
  Ticket- und Artikelrechte bleiben beim bestehenden Supportcontroller.
- Keine Datenbankmigration oder neue Rechte. Stage unveraendert.

## Abnahme

- Angemeldeter Chrome-Browser: Desktop und 390 x 844; Button sichtbar,
  Absprung zu `/support/club/4`, Ticketformular erreichbar.
- Synthetisches Ticket `dd44acbb-a982-490b-a084-7d11e7081b2c` erstellt und
  im Portal wiedergefunden. Keine echten Mitgliederdaten im Testinhalt.
- Assistent im Browser: deutsche Spesenfrage beantwortet, KI-Kennzeichnung
  und Quellenlink sichtbar.
- Providercheck: synthetische englische Frage, `provider_generated=true`.
  Es werden ausschliesslich kuratierte oeffentliche Hilfetexte uebermittelt.
- DEV-Triage aktiviert, `clubunio-dev-support-triage.service` laeuft.
- Lokal sechs Supporttests mit 57 Assertions erfolgreich; JavaScript-Syntax
  und DE-/EN-Katalogpruefung erfolgreich.
- Rechteabnahme hier fuer das bestehende Portalkonto; keine vollstaendige
  manuelle Pruefung aller Admin-/Plattformprofile in diesem Delta.
