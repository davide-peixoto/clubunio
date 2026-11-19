# Support-Einstieg: DEV-Abnahme 19.11.2026

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
