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

### Zwei-Zielrechnungen-Test vorbereitet (19.10.2026)

Neue isolierte synthetische Belege in der verifizierten Bexio-Sandbox
`SVS Api Test`, gleicher Empfaenger Kontakt3, CHF, inklusive8.1% MWST:

| Funktion | ClubUnio | Bexio | Brutto |
| --- | --- | --- | --- |
| Grundlage der lokalen Korrektur | 10451 | RE-00014 (ID14) | CHF30.00 |
| Ziel A | 10452 | RE-00015 (ID15) | CHF20.00 |
| Ziel B | 10453 | RE-00016 (ID16) | CHF25.00 |

Alle drei Rechnungen sind offen, ohne E-Mail, Zahlung oder Verrechnung.
Neue lokale Korrektur427 ist freigegeben, CHF30.00, Originalsteuer2.247919
(Anzeige2.25). Noch keine externe GS-Referenz und kein registriertes neues
Kundenguthaben. RE-00014 bleibt bewusst unverrechnet: nur synthetische
Korrekturgrundlage, keine vorgetaeuschte Zahlung oder Rueckerstattung.

Manuell in Bexio:

1. Firma `SVS Api Test` waehlen; Empfaenger von RE-00015 verwenden (Kontakt3).
2. Eine NEUE eigenstaendige Gutschrift ueber CHF30.00 inklusive8.1% erstellen;
   Position/Ertragskonto wie RE-00014, Titel `SANDBOX TWO-TARGET-20261019`.
   MWST-Anzeige2.25 pruefen. Freigeben, nicht per E-Mail versenden.
   Keine Stornierung einer Rechnung und keine automatische Verrechnung
   mit der Ursprungsrechnung ausloesen.
3. Aus genau dieser neuen Gutschrift CHF10.00 auf RE-00015 verrechnen.
   Keine Sammelaktion mit alten Gutschriften benutzen. Erwartung:
   RE-00015 Rest10.00; neue Gutschrift Rest20.00; Geldzahlung0.
4. Hier pausieren und die neue GS-Nummer melden. Erst den Zwischenstand
   in ClubUnio registrieren/nachweisen, bevor der zweite Teil gebucht wird.
5. Danach aus derselben GS CHF20.00 auf RE-00016 verrechnen. Erwartung:
   RE-00016 Rest5.00, RE-00015 Rest10.00, Guthabenrest0, Geldzahlungen0.

Die konkrete neue GS-Nummer wird nicht vorweggenommen. Bei abweichenden
Betraegen, Kontakt oder fehlender Teilbetragsauswahl abbrechen und pruefen,
nicht durch Zahlung oder Umbuchung ersetzen. GS-00004/05 unveraendert lassen.
Manuelle Providerabnahme und lokaler Zwei-Schritt-Nachweis weiterhin OFFEN.

Vorbereitung ueber `tools/clubunio-two-target-credit-prepare.php`:
Standard `--read`; `--prepare` legt nur fehlende feste synthetische
Rechnungen an; `--send` uebertraegt/oeffnet sie ueber die vorhandenen
Sandbox-Fachservices und legt die lokale Korrektur an. Kein Modus erzeugt
eine Bexio-Gutschrift, Verrechnung, E-Mail oder Zahlung. CLI-only;
DB-/Sandbox-/Kontakt-/Betragspruefung; Tenantlock und feste Marker/UUID.
Unklare Providerantworten werden durch den vorhandenen Sync-Weg abgeglichen.

Wiederholtes `--prepare` lieferte dieselben IDs10451/10452/10453 und427,
keine Doppelanlage. Bestehender lesender Abnahmepruefer erfolgreich:
GS-00004/05 und Ursprungsrechnung6 mit unveraenderten erwarteten Salden.
Neuer Standard-Leselauf bestaetigt fuer RE-00014/15/16 jeweils Geldzahlung0
und verrechnete Gutschrift0. Finanzregression erneut35 Tests/209 Assertions
erfolgreich (CustomerCreditTest/CreditNoteTest); PHP-Syntaxpruefung erfolgreich.
Keine Anwendungs-/Schemamigration. Neue Daten sind durch die gemeinsame
ClubUnio-Datenbank bereits auf ClubUnio sichtbar; kein Releasewechsel noetig.
Diese Fixtures niemals nach PHPMaker DEV/PROD oder spaeter produktiv migrieren.

### Vorheriger lesender Nachtest

### Zwischenpruefung nach gemeldeter erster Verrechnung (19.10.2026)

Benutzer meldet neue GS und CHF10 auf RE-00015. Frischer direkter
Providerabruf bestaetigt dies noch NICHT: RE-00015 Rest20/Gutschrift0,
Verrechnungsnachweise leer; RE-00016 Rest25/Gutschrift0; RE-00014 Rest30.
Lokale Salden identisch. Korrektur427 freigegeben, noch kein Kundenguthaben
registriert. Keine GS-Nummer aus den Nachweisen bestimmbar. Kein Cacheabruf.
Nicht blind registrieren oder eine Verrechnung nachbilden. Vor Schritt2
GS-Nummer und Bexio-Firma/Verrechnungsansicht mit Benutzer klaeren.
Nur lesend geprueft; keine Fachbuchung, keine Statusanwendung, keine
Provider-Schreibaktion. CLI-Lesemodus zeigt jetzt zusaetzlich Provider-
Nachweise, lokale Salden und vorhandene Kundenguthabenzuordnungen.

### Historischer Pruefumfang

Nur Pruefwerkzeug und Doku, keine Runtime-/Schema-/Datenmigration. Auf ClubUnio
ausserhalb des Webroots in shared mit CLUBUNIO_BASE auf current ausfuehren.
Kein Releasewechsel oder Cache-Reset erforderlich. DEV/PROD, Benutzerrechte,
API, SaaS und Betrieb unveraendert. Keine Gesamt-/Go-live-Freigabe ableiten.

Finanzregression:35 Tests/209 Assertions erfolgreich (CustomerCreditTest und
CreditNoteTest), synthetische Daten zurueckgerollt. Neuer Livepruefer lokal
und auf ClubUnio erfolgreich; identische Summen und unveraenderte lokale
Fachfingerprints. Serverbasis20261019-clubunio-performance-acceptance bleibt
unveraendert. Keine manuelle Browserabnahme in diesem Nachtest.
