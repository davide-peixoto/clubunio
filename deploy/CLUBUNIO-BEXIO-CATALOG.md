# ClubUnio: Artikel- und Steuerkatalog

Fortschreibung: Release52 implementiert freie Rechnungen, Steuerberechnung
und Rundungs-/Finanzabgleich. Die unten beschriebene Sperre war Stand51;
aktueller Stand und Testgrenzen stehen in CLUBUNIO-GENERAL-INVOICES.md.

14.10.2026, Release51, nach technischer Umbenennung Release50.
PHPMaker DEV/PROD, App-API, SaaS und Betriebsentwicklung unveraendert.

## Weboberflaeche

Finanzen > Bexio-Artikel und Steuern. Nur Admins der eigenen Organisation.
Suche, Typ-/Aktivfilter, Provider-ID, Satz, Gueltigkeit und Abgleichsdatum.
Lokale Bezeichnung/Aktivierung pflegbar, Providerwerte nicht manuell editierbar.
Sync liest nur die bestaetigte SVS Api Test-Verbindung. Vollstaendige,
validierte Kataloge atomar uebernehmen; verschwundene Artikel deaktivieren.
Vorsteuerarten werden nicht als Umsatzsteuer-Lookups importiert.
Revisionsschutz und object_changes protokollieren lokale Aenderungen/Sync.
Verbindungswechsel trennt die Kataloge; kein automatisches Uebernehmen fremder IDs.
Providerfehler zeigen eine Diagnose-Referenz statt vertraulicher Antworten.

Rechnungspositionen koennen einen freigegebenen Katalogartikel auswaehlen.
Eigener Preis und fixer Rabatt bleiben erhalten, keine automatische Preis-
aenderung. Vor echtem Versand Artikel-ID/Code/Name sowie vorhandene Konto-,
Einheiten-, Waehrungs- und Steuerzuordnung frisch in Bexio kontrollieren.
Die fachliche Katalogdefinition ist Teil der Versandvorschau; reine lokale
Umbenennung und unveraenderter Sync veraendern nicht den Rechnungsbetrag.

## Synthetische Testdaten

Testartikel: CODE CLUBUNIO-SANDBOX-ARTICLE-V1, Service ohne Lagerbestand,
PreisCHF1, UEX0, eindeutig markiert. Journal bexio_catalog_fixture_actions
vor POST committed. Timeout: nur lesender Abgleich, niemals blinde Wiederholung.
Vorhandenen markierten Artikel nach DB-Wiederherstellung verifizieren statt
duplizieren. Testartikel/Artikeltestrechnung ueber bestaetigte Webaktionen.
Artikeltestrechnung vorbereiten legt lokal den Entwurf an, erfasst den Artikel
ueber InvoiceWorkflowService und gibt ihn intern frei. Uebermittlung danach
ueber die vorhandene Bexio-Sandbox-Oberflaeche, nicht automatisch per Email.

Live bestaetigt: Bexio-Artikel1, native Rechnung8371/Dispatch347/Bexio5,
Position KbPositionArticle, Brutto12 minus Rabatt2 = EndbetragCHF10,
accepted als Bexio-Entwurf. Erneuter Aufruf bleibt bei denselben IDs.
Keine echte Bankzahlung, keine Bexio-Freigabe/Email, Outbound bleibt aus.
Katalog umfasst8 Umsatz-/Nichtsteuerarten und1Testartikel.

## Migration und Tests

Eigene DB db_acdf8d_dclubora, physisches Schema clubora unveraendert:
- Migration48: Katalog mit Verbindung/Revision/Gueltigkeit und Artikel-FK
  auf invoice_lines. Keine bestehenden Rechnungswerte veraendert.
- Migration49: dauerhaftes Journal fuer Testartikelanlage.
Beide auf der gemeinsamen lokalen/Pi-DB ausgefuehrt. Keine PHPMaker-Migration.
Restore nur geplant, keine automatische destructive down-Migration.

Regression24 Tests/180 Assertions erfolgreich; gezielter Nachlauf5/39
und final4/51 (ueberlappend) erfolgreich.
inklusive idempotenter Rechnungserstellung. Tests laufen in zurueckgerollten
synthetischen Mandanten: fehlerhafte/teilweise Providerantworten, verschwundene
Artikel, fremde Verbindung, Rollen, stale edit, UI200, Preis/Rabatt,
Provider-Aenderung und Timeout ohne zweiten POST.
Auf Pi Release51 aktiv, Ruecksprung50 erhalten,189 Settings/42 Vorlagen und
Outbound aus verifiziert. Login200/Katalog unangemeldet302, CLI-Fixture-IDs
identisch, relevante SHA256-Quellhashes stimmen. Visuelle Live-Abnahme mit
angemeldetem Admin bleibt offen; HTML/Rollen prueft der automatisierte Webtest.

## Noch offen

Der Katalog ist implementiert, steuerpflichtiger Versand NICHT freigegeben.
Noch erforderlich: feste Steuersnapshots pro Position, gemischte Sätze,
CHF-Fuenfrappenrundung, lokale/PDF-Summen und konsistenter Zahlungs-/
Statusabgleich fuer steuerpflichtige Testrechnungen. Exempt-Vereinsrechnungen
laufen unveraendert. Unabhaengige Gutschriften ebenfalls weiterhin offen.
Keine gesetzlichen Steuerzuordnungen aus Testdaten auf echte Beitraege ableiten.

CLI: php tools/clubunio-catalog-sync.php; --test-article;
--test-invoice [--send-invoice]. Ziel-DB/Admin/Sandbox fest geprueft.
Providervertrag: https://docs.bexio.com/ (Articles, Taxes, Invoice Positions).
