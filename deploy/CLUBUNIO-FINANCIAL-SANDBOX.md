# Zahlungen und Finanzabgleich in der Sandbox

Stand 14.10.2026, Paket48. ClubUnio (bisher ClubUnio), eigene
db_acdf8d_dclubora und Bexio-Unternehmen SVS Api Test.
Deployed als20261014-clubunio-48; Ruecksprung clubunio-brand erhalten.

## Webablauf

Finanzen > Bexio-Sandbox: eine uebertragene Testrechnung in Bexio freigeben.
Test-Teilzahlung bucht einmalig die Haelfte des Restbetrags; Test-Restzahlung
einmalig den verbleibenden Betrag. Finanzabgleich liest und bestaetigt den Stand.
Das Journal zeigt Ergebnis, Betrag und HTTP-Status. Bei unklarer Antwort ist
nur lesender Abgleich moeglich, keine erneute Buchung.

Spesen zuerst lokal auf Z freigeben, mit Zahlungsfreigabedatum.
Testspesen verbuchen: Bexio DRAFT -> BOOKED. Manuelle Testzahlung buchen:
ausschliesslich payment_type=MANUAL. Lokal bleibt Z, bis Bexio PAID mit Rest0
bestaetigt ist. Danach B, Bezahlt-am und vorhandener Nachrichtenprozess.
Wiederholung bestaetigter Aktionen erstellt keine weitere Buchung/Nachricht.

Kein IBAN-/QR-Auftrag, Banking-Payment-Endpunkt oder Bank-Uebermittlung.
Keine E-Mail oder externe Push-Zustellung; Outbound bleibt aus.
Die synthetischen Buchhaltungsvorgaenge bleiben in der Sandbox erhalten.

## Finanzdaten und Anzeigen

Cent-Pruefung: Gesamtbetrag = Zahlung + Gutschrift + Restbetrag.
Fehlende/negative/subcentgenaue Werte, andere lokale Positionssummen,
widerspruechliche PAID-/Teilbezahlt-Meldungen und fremde IDs werden abgelehnt.
Vor lokaler Uebernahme nochmals aus Bexio lesen; geaenderte Werte sperren die
alte Vorschau. Pruefung und angewandter Finanzstand bleiben gespeichert.

Dossier und Sandbox zeigen bezahlt, Gutschriften, Rest und Abgleichzeitpunkt.
Offene Rechnungen, Dashboardsummen und CSV beruecksichtigen Teilzahlungen und
den bestaetigten Restbetrag. Ohne Finanzstand gelten die historischen
Positions-/Betragsregeln weiter; offene Positionscodes O/T/U werden beruecksichtigt.

Gutschriften werden im Abgleich beruecksichtigt. Live geprueft: der durch
Stornierung entstandene Ausgleich der Elternrechnung. Unabhaengige neue
Gutschriften werden nicht erzeugt; im verwendeten oeffentlichen API-Vertrag
fehlt ein eigener Anlageendpunkt. Gemischte Zahlung/Gutschrift automatisiert getestet.

## Schreibschutz und Nachweis

Frischer Admin, aktiver nicht-importierter Registry-Beleg, bestaetigte Verbindung,
Referenz/Kontakt/Waehrung/Betrag und erlaubter Vorstatus vor jeder Buchung.
Feste Operationen: invoice/payment, bill/bookings/BOOKED und
purchase/outgoing-payments ausschliesslich MANUAL. Keine freien URL/Payloads.
Dauerhafte Unique-Sperre je Auftrag/Operation vor HTTP. Laufende/unklare
Finanzaktionen sperren neue Buchungen, Storno und Reconnect.
Providerablehnungen bleiben rejected; kein automatischer Retry.

Eingaenge anhand neuer Zahlungs-ID, Beleg-ID, Betrag, Datum und Konto pruefen.
Bei verlorener Antwort nur eindeutige neue Treffer. Spesen zusaetzlich anhand
Zahlungsdetail, MANUAL, Referenznotiz, PAID/TRANSFERRED und fehlender
banking_payment_id. Das Detail-API liefert mehr Felder als die Liste und meldet
live PAID. Keine Zugangsdaten oder vollstaendigen Providerantworten im Journal.
Providervertrag: https://docs.bexio.com/ (invoice payment, bill booking,
outgoing payment MANUAL und Zahlungsdetail).

## Live-Abnahme

- Rechnung7765 / Bexio3 CHF20: Freigabe8, Teilzahlung10 (Zahlung5) ->16,
  Restzahlung10 (Zahlung7) ->9; bezahlt20, Gutschrift0, Rest0. Beide accepted.
- Spesen1114 / Bill ebb98b7c-ac67-4ce0-bda4-b0e598c8a708 CHF20:
  DRAFT -> BOOKED -> PAID. Manuelle Zahlung81261068-a919-413b-ac41-2aa89e81fb2b,
  CHF20, passendes Datum/Konto/Notiz, keine banking_payment_id; accepted.
  Lokal Bezahlt, Rest0, ein expense.paid-Ereignis.
- Elternrechnung7950 / Bexio4: Gesamt33, Zahlung0, Gutschrift33, Rest0;
  der Finanzabgleich erhaelt den stornierten Status.
- Feste Basistests7688/1083 bleiben als historische Entwuerfe erhalten.

Tests: Status-/Positions-/Nachrichtenabgleich, stale Daten, Gutschriften,
widerspruechliche Betraege, Teil-/Vollzahlung, manuelle Spesenzahlung,
Timeout ohne Doppel-POST, offene Listen/CSV/Dashboard. Nachweise in PI.md.
Keine reale Bankzahlung und kein Buchungseintrag in PHPMaker DEV/PROD.
Gezielte Laeufe26/179,5/55,3/33,Dashboard2/21,final8/90 erfolgreich
(ueberlappend). Dossier-Nachlauf2/29 erfolgreich. Serverstatus und Quellhashes
identisch bestaetigt. LoginHTTP200 und anonym geschuetzte Sandbox302.
Nach Branding fehlt die Browser-Anmeldung fuer die visuelle Nachpruefung;
Webansichten/-aktionen sind mit Livewire automatisiert geprueft.

## Migration und Ruecksprung

000047 additiv: bexio_checks.financials, bexio_balances,
bexio_financial_actions mit Aktionssperren. Lokal/Pi teilen die eigene DB.
Keine neuen ini-Parameter, Cronjobs, App-API oder SaaS-/Betriebsentwicklung.
Vor Ruecksprung offene Auftraege klaeren; Testbuchungen und Journal behalten.
Der alte Code kennt die Finanzaktionen nicht: dort waehrend Ruecksprung keine
alten Schreibaktionen ausfuehren. Branding ClubUnio bleibt im Paket erhalten.
Weiter offen: unabhaengige Gutschriftanlage, Steuer-/Artikel-Sonderfaelle und
spaetere Produktivfreigabe. Echte Bankauftraege bleiben ausgeklammert.
