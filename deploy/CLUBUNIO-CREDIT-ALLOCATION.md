# Konkreter Bexio-Gutschriftrueckfluss

Nachtrag67: kontrollierte Mehrrechnungsverteilung jetzt als eigener Fachweg,
siehe CLUBUNIO-CUSTOMER-CREDITS.md. Bestehender Einzelabgleich und seine
Nachweise bleiben erhalten; keine automatische Umbuchung alter Gutschriften.

Release63, 15.10.2026. Nur ClubUnio-Web, eigene DB und lesende Bexio-Sandbox.
Keine Aenderungen an PHPMaker DEV/PROD, keine Bankzahlungen oder API-/SaaS-/
Betriebsentwicklung. Keine neuen externen Schnittstellen oder DB-Migration.

## Bestaetigter Livefall

Sandbox SVS Api Test (Profil1), Rechnung9 = RE-00009, eigener Beleg8546:

| Merkmal | Rueckfluss |
|---|---|
| Rechnungsbetrag | CHF10.05 |
| Gutschrift | GS-00004, interne Bexio-ID4 |
| Verrechnungseintrag | Zahlungsliste-ID20 auf Rechnung9 |
| Gutschriftsbetrag | CHF10.05 |
| Geldzahlungen | CHF0.00 |
| Restbetrag | CHF0.00 |
| Bexio-Status | 9 (ausgeglichen; nicht als Geldzahlung interpretieren) |

Beleg8546 lokal per bestehendem Vorschau-/Anwenden-Abgleich aktualisiert:
Revision4->5, Status6 und zugehoerige Positionsstatus. Finanzsnapshot speichert
credit_cents1005, paid_cents0, cash_received_cents0 und cash_excess_cents0.
Lokale Gutschrift107 auf Basis derselben Rechnungspositionen erstellt, intern
freigegeben und gegen den frischen Live-Nachweis bestaetigt. Betrag/Steuer-
snapshots unveraendert nach bestehender Positionslogik. Audit und externe
Snapshotdaten dokumentieren GS-00004, Voucher4 und Eintrag20.

Wiederholung erzeugt weder neue Gutschrift noch neue Rechnungsrevision.
Keine externen POST-Schreibaufrufe, keine E-Mail/FCM- oder Bankzustellung.
Der vorhandene lokale Rechnungsstatus-Abgleich behaelt seine internen
Benachrichtigungsregeln. Der alte Test Gutschrift18/Rechnung6/GS-00002 bleibt
unveraendert und wird nicht auf Rechnung9 umgehaengt.

## Fachkorrektur

Bisher pruefte die manuelle Bestaetigung nur eine ausreichende Summe auf der
Rechnung und eine noch freie eingegebene Referenz. Eine falsche Gutschriftnummer
konnte dadurch trotz passendem Gesamtsaldo bestaetigt werden.

Jetzt liest BexioInvoiceCashService fuer die Gutschriftpruefung eine einzelne,
begrenzte Zahlungsliste und trennt Geldzahlungen von Gutschriftsverrechnungen.
Geprueft werden Eintrags-ID/Duplikate, Rechnungs-ID, Voucher-ID, Flags, exakte
Rappenbetraege und Summe gegen total_credit_vouchers. Mehrere Eintraege derselben
Voucher-ID werden addiert, widerspruechliche Nummern abgewiesen. Nicht sicher
erkennbare Referenzen bleiben nicht bestaetigbar statt geraten zu werden.

Die Bestaetigung verlangt genau eine passende GS-Nummer und denselben Betrag
wie die lokale Gutschrift, urspruengliche Bexio-Rechnung, aktuelle Sandbox-
Verbindung, Revision, Rechte und einen noch nicht verwendeten Voucher.
Gross-/Kleinschreibung wird kanonisiert; alternative manuelle Labels erlauben
keine Wiederverwendung derselben Provider-ID. Nachweis im bestehenden JSON-
Snapshot, kein neuer Migrationsbedarf.

Web: Auswahl der tatsaechlich verrechneten Gutschriften anstelle eines freien
Referenzfeldes. Bestaetigte Details zeigen Voucher-ID und Verrechnungseintraege.
Beim Bestaetigen wird erneut frisch gelesen, nicht der Auswahlsnapshot vertraut.
Bestehende bestaetigte Altnachweise werden nicht rueckwirkend umgeschrieben.

## Grenzen

- Eine unverrechnete eigenstaendige Gutschrift ist nicht durch diese Rechnung
  belegt. Kein erfundener Direkt-Gutschrift-Endpunkt oder automatischer Ersatz.
- Eine auf mehrere Rechnungen verteilte Gutschrift wird nicht automatisch als
  mehrere unabhaengige lokale Gutschriften bestaetigt. Das benoetigt einen
  gesonderten Verteilungs-/Guthabenvertrag; Schutz vor Doppelverwendung bleibt.
- Eine reine Gutschriftsverrechnung erzeugt keine rueckerstattbare Geldzahlung.
- Gutschriftbetrag groesser/kleiner als lokale Korrektur bleibt zur Klaerung
  gesperrt; keine automatische Anpassung der urspruenglichen Forderung.

## Test und Wiederholung

`php tools/clubunio-credit-allocation-check.php` liest und prueft ausschliesslich
den expliziten Fall RE-00009/GS-00004. `apply` uebernimmt nur lokale Daten,
mit fixer Request-ID und Registry-/Kontakt-/Steuer-/Sandbox-Identitaetspruefung.
Nur eigene db_acdf8d_dclubora, registrierter Sandboxbeleg, Adminrolle.
Keine Ausgabe von Tokens, Provideradressen, Kontodaten oder ganzen Payloads.

Regression: konkrete Nummer/Betrag/Originalrechnung, doppelte Voucher/Eintraege,
reiner Gutschriftsausgleich ohne Cashrefund, Webauswahl und bestehende
Rechnungs-/Finanzabgleiche. Abschlusszahlen und Servernachweise nach Pruefung.

Abgeschlossen:59 Tests/394 Assertions (CreditNote, BexioReconciliation,
BexioSandboxDispatch). Gezielter Nachlauf Voucher-Doppelschutz1/2 ueberlappend.
Pint, PHP-Lint, Diff-/PMP-Check und Viewcache erfolgreich. Livefall zweimal
lokal angewendet, zweiter Lauf ohne weitere Revision/Beleg. Pi nach Deployment
erneut ausschliesslich lesend verifiziert: identischer Saldo und Nachweis107;
beide Service-Hashes stimmen mit dem lokalen Stand ueberein.
189 verschluesselte Settings/46 Vorlagen geprueft, Outbound aus.
Webauswahl/Details automatisiert mit Livewire geprueft; keine neue manuelle
Browserabnahme behaupten. Ruecksprung62 erhalten, keine Migration.
