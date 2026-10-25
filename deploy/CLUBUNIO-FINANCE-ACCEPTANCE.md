# Bexio/Finanzen: aktueller Abnahmestand

Stand26.10.2026. Ersetzt veraltete Offen-Vermerke zu GS-00005/07. Historische
Protokolle bleiben erhalten. Keine Benutzerzuordnung oder Produktivumstellung.

## Abschluss26.10.2026: Zwei-Zielrechnungen-Abnahme erfolgreich

Nach manueller Bestaetigung des zweiten Sandbox-Dialogs durch den Benutzer
ist die gesamte GS-00007/CHF30 eindeutig in Bexio nachgewiesen:

| Ziel | Bexio-Nachweis | Verrechnet | Rechnungsrest |
| --- | --- | --- | --- |
| RE-00015 / ClubUnio10452 | Payment36, Voucher7 | CHF10 | CHF10 |
| RE-00016 / ClubUnio10453 | Payment37, Voucher7 | CHF20 | CHF5 |

Beide Belege weiterhin ohne Geldzahlung. Erster Teil NICHT erneut gebucht.
ClubUnio ueber bestehende Fachservices vollstaendig abgeglichen:
Kundenguthaben110 hat Rest0 und zwei eindeutige Zuordnungen; Korrektur427
ist confirmed. Lokale Rechnungssalden stimmen mit Bexio ueberein.
Zwischenpruefung des ersten Teils erfolgte vor Vorbereitung des zweiten.

Das reine Abnahmewerkzeug sortiert JSON-Objektschluessel vor dem strikten
Vergleich, da PostgreSQL jsonb deren Reihenfolge aendert. Werte/Typen und
Payment-IDs werden weiterhin strikt geprueft; kein gelockerter Betragscheck.
Der vorherige Vergleichsstopp erzeugte keine zweite lokale Zuordnung.
PHP-Syntaxcheck und echter zweiter Fachabgleich erfolgreich. Finanzregression
im vorausgegangenen Schritt:35 Tests/209 Assertions erfolgreich.
Kontrollpruefer bestaetigt GS-00004/05, Rechnung6 und deren lokale
Fachfingerprints unveraendert; RE-00014 weiterhin Rest30/Gutschrift0.

Migrationshinweis: reine Sandbox-Abnahme, keine Runtime-/Schemamigration und
kein Deployment notwendig. Die gemeinsame ClubUnio-DB enthaelt die Ergebnisse.
Testbelege und deren Zuordnungen nie in Produktivmigration uebernehmen.
PHPMaker DEV/PROD, Rechte und andere Buchungen unveraendert. Kein Bankauftrag.

## Wiederaufnahme25./26.10.2026: erster Teil abgeglichen

Nach erneutem Oeffnen des Browsers ist die erste Verrechnung jetzt belegt:
GS-00007 / Payment36 / RE-00015 / CHF10. Sie wurde NICHT erneut gebucht.
Der frische Providerabruf zeigte RE-00015 Rest10, RE-00016 Rest25,
Ursprungsrechnung RE-00014 Rest30 und jeweils Geldzahlung0.
Wann die zuvor blockierte Bestaetigung wirksam wurde, ist nicht belegt.

ClubUnio ueber bestehende Fachservices abgeglichen: Kundenguthaben110 zur
Korrektur427 registriert, GS-00007/Payment36 eindeutig zu Rechnung10452
zugeordnet, lokaler Rechnungssaldo jetzt Gutschrift10/Rest10. Guthabenrest20,
Korrektur weiterhin released. Zwischenpruefung VOR dem zweiten Schritt erfolgt.

Danach im Bexio-Assistenten NUR GS-00007/CHF20/RE-00016 in den leeren
Stapel aufgenommen. Buchungsbetrag0. Beim Buchen blockiert der Browser erneut
am Bestaetigungsdialog; zweimaliger direkter Nachtest zeigt weiterhin keine
zweite Verrechnung. Keine Wiederholung des Buchens und kein lokaler Ersatz.
Benutzer um Bestaetigung ausschliesslich des offenen Dialogs gebeten.
Abnahme noch OFFEN: nach dessen Bestaetigung erneut Provider lesen und erst
bei genau GS-00007/CHF20/RE-00016 lokal uebernehmen. Erwartung Rest5/Guthaben0.

Neues CLI-Werkzeug tools/clubunio-two-target-credit-reconcile.php: Standard
--read ohne Fachwrites; --first/--second verwenden feste Fixture-IDs,
Sandbox-/DB-Guards, eindeutige Providerbelege und bestehende Fachservices.
Keine Provider-Schreiboperation. Fehlender zweiter Beleg stoppt vor Writes.
Vorhandene lokale Zuordnungen werden auf Betrag und Nachweis geprueft statt
erneut angelegt. Keine Daten-/Schemamigration, keine Runtime-Bereitstellung.
Gemeinsame ClubUnio-DB enthaelt den Zwischenabgleich bereits.
Finanzregression mit DB-Opt-in und direktem PHPUnit-Aufruf:35 Tests,
209 Assertions erfolgreich; synthetische Testaenderungen zurueckgerollt.
PHP-Syntaxpruefung des neuen Werkzeugs erfolgreich.
Bestehender Live-Abnahmepruefer: GS-00004/05 und Rechnung6 unveraendert,
Fachfingerprints unveraendert. PHPMaker DEV/PROD nicht angefasst.

## Historische Wiederaufnahme25.10.2026: keine Doppelbuchung, Browser blockiert

Erneuter direkter Leselauf mit clubunio-two-target-credit-prepare.php:
RE-00014 Rest30, RE-00015 Rest20, RE-00016 Rest25; jeweils Gutschrift0,
Geldzahlung0 und leere Provider-Verrechnungsnachweise. Lokale ClubUnio-Salden
identisch. Korrektur427 weiterhin released; kein Kundenguthaben registriert.

Im vorherigen Versuch war ausschliesslich GS-00007/Betrag10/RE-00015 im
Buchungsstapel vorbereitet. Wichtig: Rechnung zuerst auswaehlen, danach
Teilbetrag setzen, da die Auswahl das Betragsfeld auf den Rechnungsrest setzt.
Die Browserbestaetigung lief in einen Timeout; eine Buchung ist durch den
aktuellen direkten Nachtest NICHT bestaetigt. Auch erneute Tab-Verbindung
lief zweimal in einen Timeout. Aktueller Browser-Stapel daher nicht lesbar.
Keine erneute Buchung, kein zweiter Teilbetrag, kein lokaler Ersatzbeleg.

Fortsetzung: Browserzugriff wiederherstellen, Provider erneut lesen und
vorhandenen Stapel pruefen. Einen bereits vorbereiteten Eintrag nicht noch
einmal hinzufuegen. Erst bei eindeutigem GS-00007/10/RE-00015-Stapel buchen;
danach ClubUnio-Nachweis pruefen, erst dann20 auf RE-00016. Abnahme offen.
Keine Aenderung an bestehenden Buchungen oder PHPMaker DEV/PROD in diesem
Nachtest. Nur Dokumentation, kein Deployment oder Migrationslauf.

## Doku- und UI-Pruefung25.10.2026: gezielte Zuordnung gefunden

Die offizielle Dokumentation nennt den Assistenten unter Verkauf > Mehr
(Doku: Weitere) > Zahlungen erfassen (Manuell):
- https://help.bexio.com/s/article/000001727?language=de
- https://help.bexio.com/s/article/000001683?language=de

Im angemeldeten Sandbox-Browser nur lesend bestaetigt: nach Suche nach
Kontakt3 erscheint GS-00007 mit offen30, bearbeitbarem Auszifferungsbetrag
und Rechnungs-Dropdown inklusive RE-00015 und RE-00016. Buchungsbetrag0,
Buchungsstapel leer. Keine Diskette/Zuordnung und kein Buchen ausgeloest.
Damit ist der im historischen Abschnitt noch gesuchte UI-Weg gefunden;
die tatsaechliche Zwei-Schritt-Verbuchung bleibt weiterhin nicht abgenommen.

Vorbereitete Abnahme nach separater Freigabe:
1. Firma SVS Api Test, Kontakt3, Buchungsbetrag0 verwenden. Keine echte
   Zahlung erfassen; GS-00004 und sonstige bestehende Belege nicht auswaehlen.
2. NUR in der Zeile GS-00007 Auszifferungsbetrag10 und RE-00015 waehlen.
   Diskette, Stapel kontrollieren, erst dann Buchen. Erwartung: GS-Rest20,
   RE-00015 Rest10, Geldzahlung0. Bei abweichendem Stapel NICHT buchen.
3. Neu lesen und Provider-Nachweise in ClubUnio pruefen/registrieren.
4. Erst danach denselben Assistenten erneut fuer GS-00007, Betrag20 und
   RE-00016 verwenden. Erwartung: GS-Rest0, RE-00016 Rest5, Geldzahlung0.
5. Alte Zuordnungen und RE-00014 unveraendert nachweisen. Keine automatische
   Sammelverrechnung ueber den gelben Hinweis verwenden.

Kein Ersatztest oder neue Testkontakte erforderlich, solange dieser
gezielte Weg die erwarteten Teilbetraege akzeptiert. Die Dokumentation
belegt den UI-Weg, nicht einen oeffentlichen API-Schreibendpunkt.
Diese Pruefung hat keine Buchungen/DB-Daten geaendert; DEV/PROD unveraendert.

## Historische Browserabnahme25.10.2026: Detailansichten ohne Auswahl

Firma `SVS Api Test` im angemeldeten Browser geprueft. Liste aller vorhandenen
Gutschriften enthielt keine neue CHF30-Gutschrift. Mit Benutzerfreigabe wurde
GS-00007 fuer Kontakt3 angelegt: SANDBOX TWO-TARGET-20261019, CHF30 inklusive
8.1%, MWST2.25, Konto3200, keine E-Mail oder Geldzahlung.

Wichtige Korrektur der untenstehenden Testanleitung: Der Link
`Gutschrift mit Rechnungen verbuchen` oeffnet KEINEN Auswahldialog. Er hat
sofort CHF10 auf RE-00013 und CHF20 auf RE-00014 verteilt, wie im Hinweis
ueber dem Link vorgeschlagen. Diese vom Agenten irrtuemlich ausgeloesten
neuen Zuordnungen (Payment34/35, GS-00007) wurden gezielt wieder entfernt.
Die bestehende Zuordnung GS-00005/Payment28 wurde nicht entfernt.
Auditspuren/Zeitstempel der Zwischenaktionen koennen bestehen bleiben;
keine Behauptung byte-identischer Providerhistorie.

Erneuter direkter API-/Fachservice-Nachtest erfolgreich:
- RE-00013 wieder GS-00005 CHF10, Rest10; RE-00009/GS-00004 unveraendert.
- RE-00014 Rest30, RE-00015 Rest20, RE-00016 Rest25; keine Zuordnungen,
  Geldzahlungen jeweils0. Lokale ClubUnio-Salden stimmen ueberein.
- GS-00007 im Browser offen30, abgerechnet0, Steuer2.25.
- Korrektur427 weiterhin freigegeben; kein Kundenguthaben registriert und
  keine lokale Verrechnung ohne Providerbeleg nachgebildet.

Auf RE-00015 bietet der allgemeine Link stattdessen die alte GS-00004 an;
diesen NICHT benutzen. In den geprueften Ansichten wurde keine Auswahl
einzelner Gutschriften/Zielrechnungen oder eines Teilbetrags gefunden.
Die geplante10/20-Abnahme ist NICHT abgeschlossen. Naechster Schritt:
unterstuetzten gezielten Zuordnungsweg mit Bexio klaeren oder neuen Test
mit komplett isoliertem Empfaenger/gestaffelt offenen Zielbelegen planen.
Bestehende Belege nicht umdatieren, umhaengen oder temporaer bezahlen,
um die automatische Reihenfolge zu manipulieren. GS-00007 nicht duplizieren.

Lokaler Screenshot (nicht Git):
`storage/app/private/qa/bexio-gs00007-open-20261025.png`.
Keine Runtime-/Schemamigration, kein Deployment/Cache-Reset erforderlich.
PHPMaker DEV/PROD unveraendert; fremde parallele Sponsorenlauf-Aenderungen
nicht Bestandteil dieses Commits. Keine neue automatisierte Vollregression,
da kein Anwendungscode geaendert; beide vorhandenen Live-Lesepruefer bestanden.

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

Urspruenglicher Soll-Testplan, NICHT als bestaetigte Bexio-Klickanleitung
verwenden (siehe Browserbefund25.10.2026):

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
