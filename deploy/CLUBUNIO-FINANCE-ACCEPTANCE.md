# Bexio/Finanzen: aktueller Abnahmestand

Stand19.10.2026. Ersetzt veraltete Offen-Vermerke zu GS-00005. Historische
Protokolle bleiben erhalten. Keine Benutzerzuordnung oder Produktivumstellung.

## Heute lesend in der Sandbox bestaetigt

| Fall | Ergebnis |
| --- | --- |
| RE-00009 / GS-00004 | CHF10.05, Gutschrift10.05, Rest0, Geldzahlung0 |
| RE-00013 / GS-00005 | CHF20.00, Gutschrift10.00, Rest10.00, Geldzahlung0 |
| Kundenguthaben41 | CHF10.00 vollstaendig zugeordnet, unverrechneter Rest0 |
| Lokale Korrektur18 | Bestaetigt, Originalsteuer0.748933 unveraendert |
| Ursprungsrechnung RE-00006 | CHF40.25 bezahlt, keine nachtraegliche Gutschrift auf der Ursprungsrechnung |

Bestehende Fachservices pruefen Sandbox, Registry, Empfaenger und Steuern.
Fingerprints der betroffenen lokalen Rechnungen, Korrekturen, Guthaben und
Verrechnungen unveraendert. Kein Versand, keine Buchung, Zahlung oder lokale
Statusanwendung. OAuth kann bei Bedarf den bestehenden Zugang erneuern.

CLI-Pruefer `tools/clubunio-finance-acceptance-read.php` hat keinen Schreibmodus.
Nur bekannte synthetische Belege; Ausgabe nur Referenzen/Summen; Abweichungen
enden mit Fehlercode. Erwartungen nicht blind an veraenderte Providerdaten
anpassen. Eine Verrechnung ist keine Geldzahlung und kein Rueckerstattungsanspruch.

## Naechstes Fachpaket: Mehrrechnungsverrechnung abnehmen

Ein Guthaben auf **zwei Zielrechnungen** verteilen. Der Fachweg ist implementiert;
eine echte Zwei-Zielrechnungen-Sandboxabnahme ist bisher nicht belegt.

1. Neue synthetische Daten desselben verifizierten Rechnungsempfaengers
   vorbereiten. Bestehende GS-00004/05 nicht umbuchen.
2. Gutschrift im Bexio-Web manuell erstellen und Steuerbetrag fachlich
   bestaetigen. Kein angenommener Anlage-Endpunkt oder Ersatz durch Zahlungen.
3. Erste Teilverrechnung: Guthabenrest und Zielrechnungsrest pruefen.
4. Zweite Teilverrechnung: Gesamtverbrauch und beide Zielrechnungen pruefen.
5. Wiederholungen duerfen keine Doppelzuordnung erzeugen. Fremder Empfaenger
   und Ueberverrechnung bleiben gesperrt; Originalsteuer/Geldguthaben unveraendert.
6. Provider- und lokale Nachweise mit datensparsamen Screenshots dokumentieren.

Manuelle Providerbuchung ist ein eigener Abnahmeschritt, nicht durch Tests
ersetzt. Vieraugenprinzip und bewusste Benutzerzuordnung sind separate Aufgaben.
GS-00005 erneut anzulegen waere dagegen Doppelarbeit: seit Paket72 erledigt.

## Migration und Bereitstellung

Nur Pruefwerkzeug und Doku, keine Runtime-/Schema-/Datenmigration. Auf ClubUnio
ausserhalb des Webroots in shared mit CLUBUNIO_BASE auf current ausfuehren.
Kein Releasewechsel oder Cache-Reset erforderlich. DEV/PROD, Benutzerrechte,
API, SaaS und Betrieb unveraendert. Keine Gesamt-/Go-live-Freigabe ableiten.

Finanzregression:35 Tests/209 Assertions erfolgreich (CustomerCreditTest und
CreditNoteTest), synthetische Daten zurueckgerollt. Neuer Livepruefer lokal
und auf ClubUnio erfolgreich; identische Summen und unveraenderte lokale
Fachfingerprints. Serverbasis20261019-clubunio-performance-acceptance bleibt
unveraendert. Keine manuelle Browserabnahme in diesem Nachtest.
