# Rechnungsablauf in der Sandbox

Ergaenzung Paket48: Test-Zahlungseingaenge, Finanzsnapshot und manuelle
Spesenbuchung bis Bezahlt stehen in CLUBUNIO-FINANCIAL-SANDBOX.md.
Der folgende Bericht dokumentiert den vorherigen Paket47-Umfang.

Stand 14.10.2026, Paket47. Ausschliesslich ClubUnio / SVS Api Test.
PHPMaker DEV/PROD, App-API, SaaS und Betrieb bleiben unveraendert.
Deployed als20261014-clubora-47; Nachweis und Testgrenzen in PI.md.

## Webablauf

Finanzen > Bexio-Sandbox > Eltern-Testrechnung (CHF33): erstellt einmalig eine
synthetische Rechnung, bestaetigt den registrierten Elternkontakt ueber die
bestehende Rechnungsempfaenger-Zuordnung und fuehrt die normale lokale
Positions-/Freigabepruefung aus. Noch kein externer Versand bei Vorbereitung.
Danach explizit In Sandbox uebertragen, In Bexio freigeben, Rechnung / PDF.
Stornieren ist nach Freigabe moeglich. Rechnungsstatus pruefen ist nur lesend
gegen Bexio; lokale Rechnung und Positionen werden kontrolliert nachgefuehrt.
Bestehende allgemeine Versandpruefung verwendet dieselben Eltern-/Rabattregeln.

Die Empfaengerquelle bleibt ein pflegbarer Lookup (Mitglied/Mutter/Vater).
Bei Eltern aendern Name/E-Mail die Identitaetsbindung: Kontakt muss dann erneut
bestaetigt werden. Im Sandbox-Schreibadapter sind ausschliesslich Member und
Parent der Registry zugelassen, keine importierten Mitglieder oder IDs.
Die PDF-Pruefung nutzt den beim Versand festgehaltenen Empfaenger, nicht einen
spaeter geaenderten Stammdateneintrag.

Rabatte sind feste CHF-Betraege: Bexio erhaelt den centgenauen Nettopreis.
Brutto/Rabatt/Netto stehen sichtbar in jeder rabattierten Positionsbeschreibung.
Absichtlich kein zweiter Prozent-Rabatt, der nochmals abgezogen wuerde.
Steuerpflichtige Rechnungen bleiben gesperrt; diese Abnahme ist steuerbefreit.

## Sicherheit und Nachvollziehbarkeit

- Admin, frische Berechtigung, aktive nicht-importierte Registry-Rechnung,
  bestaetigte Sandbox-Verbindung, Remote-ID/Referenz/Empfaenger/Betrag geprueft.
- Freigabe: POST /2.0/kb_invoice/{id}/issue ausschliesslich Status7.
- Stornierung: POST /2.0/kb_invoice/{id}/cancel nur Status8/31 und ohne
  Zahlungen/Gutschriften. Bezahlt/teilbezahlt werden nicht storniert.
- Persistente einmalige Aktion pro Auftrag/Operation, vor HTTP beansprucht.
  Timeout/unklare Antwort sperrt erneutes POST. Nur lesende Klaerung erlaubt.
  Ungeklaerte Aktionen sperren auch das Umbinden der Sandbox-Verbindung.
- Ruecklesen bestaetigt 8 bzw.19. Lokaler Statusabgleich separat nachvollziehbar;
  ein fehlgeschlagener lokaler Abgleich kann ohne erneutes POST wiederholt werden.
- Keine /send-, Zahlungs-, Gutschrift- oder automatischen Mailaktionen.
  Die allgemeine Integrationsaktivierung bleibt aus. PDF-Lesen bleibt unabhaengig.

Providervertrag: https://docs.bexio.com/ (issue, cancel, invoice PDF).

## Live-Abnahme

Rechnung7950 / Dispatch127 / Bexio4 (RE-00004), Empfaenger Parent-Kontakt3.
Testbeitrag30 minus5 =25; Testmaterial10 minus2 =8; Total33 CHF.
Lokale Freigabe ueber InvoiceWorkflowService, externe Anlage accepted,
Freigabe Status8 und anschliessend Stornierung Status19 live bestaetigt.
Rechnung, Positionen und lokaler Workflow cancelled nachgefuehrt.
PDF vor Stornierung: HTTP200,106120 Bytes,2 Seiten visuell geprueft;
QR decodierbar, SPC-Format,33.00 CHF. Elternadresse auf Rechnungsseite.
Der QR-Zahlteil laesst den Zahler leer (Bexio-Konfiguration), Bankkonto unveraendert.
Test-PDF niemals bezahlen: es enthaelt das im Sandbox-Konto hinterlegte Bankkonto.
Keine Zahlung und keine E-Mail ausgefuehrt. Synthetische Testdaten bleiben erhalten.
Automatisiert:75 Tests/343 Assertions, danach4 gezielte Tests/35 Assertions
(ueberlappend). Elternadresse/PDF-Guard, Rabatt, Freigabe/Storno, lokale
Status-/Workflow-Nachfuehrung, unklarer Ausgang ohne Doppel-POST, fremder
Empfaenger, Nicht-Admin und bezahlte Rechnung als Negativfaelle.

## Migration und Ruecksprung

000045: bexio_invoice_actions mit dauerhaftem Aktionsjournal und Unique-Sperre.
000046: fehlenden Positionslookup P/Pendent ergaenzen, bestehende Werte erhalten.
Nur eigene db_acdf8d_dclubora; lokale/Pi-App teilen diese Datenbank.
Kein Schema-/Datenupdate im PHPMaker DEV/PROD; keine neuen ini-Parameter/Jobs.
Regeneration durch PHPMaker betrifft ClubUnio nicht.
Ruecksprung auf46 nur nach Klaerung laufender Aktionen; Journal und Lookups
behalten, keine Rueckmigration und keine erneute Anlage bereits uebertragener Belege.

Noch separate Fachabnahme: echte Zahlungen/Teilzahlungen/Gutschriften,
Lieferantenrechnungs-Freigabe/Zahlungsauftrag und nicht steuerbefreite Sonderfaelle.
Kein pauschaler Abschluss aller Bexio-/Finanzprozesse.
