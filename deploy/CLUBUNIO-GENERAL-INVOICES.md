# ClubUnio: freie Rechnungen, Steuern und Rundung

14.10.2026, Release52. Nur ClubUnio/Pi und eigene PostgreSQL-DB.
PHPMaker DEV/PROD, App-API, SaaS und Betriebsentwicklung bleiben unveraendert.

## Rechnungserfassung im Web

Finanzen > Rechnungen > Rechnung erstellen. Admin/Geschaeftsstelle waehlen
Mitglied/Firma, Rechnungskategorie und ausdruecklich die Steuerbehandlung.
Danach freie Positionen, Preise, feste CHF-Rabatte und optionale Bexio-Artikel
erfassen; intern freigeben. Entwuerfe sind bearbeitbar, verknuepfte/importierte
Belege bleiben gegen nachtraegliche Preis-/Steueraenderungen geschuetzt.
Rechnungskategorie unter Stammdaten > Rechnungskategorien pflegbar:
Mitgliederbeitrag, Busse, Dressbezug, Merchandising, Sonstige Leistungen.
Erweiterbar/umbenennbar/deaktivierbar, keine fest verdrahtete Statusauswahl.
Kategorie bestimmt weder Steuersatz noch Steuerpflicht automatisch.
Neue generierte Beitragsrechnungen erhalten, sofern vorhanden, die Kategorie
Mitgliederbeitrag; vorhandene Belege werden nicht nachtraeglich umklassifiziert.
Suche, Status-/Kategoriefilter und benutzerbezogene gespeicherte Ansichten
bleiben kombinierbar. Manuelle Entwuerfe funktionieren auch ohne Bexio-Zugang.

## Steuer- und Preislogik

Exempt: kein Steuerbetrag. Inclusive: jede Position braucht eine ausdrueckliche
gueltige Umsatzsteuer-/Nichtsteuer-Zuordnung aus dem eigenen Providerkatalog,
auch bei0%. Vorsteuer und inaktive/zeitlich ungueltige/fremde IDs abweisen.
Preis minus fixer Rabatt ist der Endpreis einschliesslich Steuer, nicht ein
Nettopreis, auf den nochmals Steuer aufgeschlagen wird. Menge derzeit1 pro
Position; mehrere Leistungen koennen getrennt oder aggregiert erfasst werden.
Keine Lagerbewirtschaftung oder Kassenfunktion in diesem Paket.

Steuersnapshot je Position (Code, Rate, Typ, Provider-ID, Verbindung) wird
technisch geschrieben und auditiert. Spaetere Katalogaenderungen veraendern
bereits erfasste Steuern nicht; vor Versand aktuellen Providerstand pruefen.
Gueltigkeit und Rechnungsdatum verwenden Europe/Zurich, keine UTC-Tagesgrenze.
Exakte Dezimalrechnung mit vorhandener brick/math-Bibliothek, keine Float-
Steuerarithmetik. Neue manuelle CHF-Rechnungen erhalten technische
Fuenfrappenrundung. Steuerpflichtige Endpreise ebenfalls; Altbelege behalten
ihre bisherige Rundung. Kategorieaenderung aktiviert keine Rundung/Steuer.
Rundungsbetrag ist readonly/abgeleitet und wird separat dargestellt.

Bexio-Grossmodus mwst_type0/mwst_is_netfalse live geprueft: enthaltene Steuer
wird proportional zum gerundeten Gesamtpreis berechnet. Rohsumme, gerundeter
Gesamtpreis, Steuerbetrag, Positionspreise/-IDs/-Mengen und Preisart beim
Ruecklesen kontrollieren. Bei Grosspreisen meldet Bexio die separate
total_rounding_difference teilweise0; deshalb nicht als einzige Quelle nutzen.
Grandtotal muss rappengenau passen, VAT-Toleranz nur0.0002CHF fuer die
4-/6-Dezimaldarstellung. Unklarer Ausgang bleibt im Journal, kein Wiederhol-POST.
Null-/auf0gerundete Rechnungen werden nicht automatisch an Bexio uebermittelt.

## Konsistenz und Abgleich

Rundung und VAT gelten fuer Vorschau, Freigabe, Payload, Finanzaktionen,
Statusabgleich, PDF, Dossier/Portal, Dashboard, offene Listen/CSV und
Benachrichtigungsbetrag. Lokaler net_amount bleibt historisch der Endpreis
nach Rabatt; keine destruktive Umdeutung bestehender Spalten.
Raten-/Steuerzuordnung im Provider vor POST nochmals lesen. Danach
unveraenderliche lokale Snapshots statt spaeterer Katalogpreise verwenden.
Statusuebernahme liest frische Providerdaten; Zahlungen/Gutschriften/Rest
bleiben mit demselben gerundeten Rechnungsbetrag verknuepft.
Echter Versand ist weiterhin nur der ausdruecklich bestaetigte Sandbox-
Testbetrieb fuer registrierte synthetische Kontakte; keine echten Forderungen.

## Live-Nachweis SVS Api Test

- Rechnung8440/Dispatch389/Bexio6: gemischt8.1/2.6/0%, fixeRabatte,
  Rohsumme40.27 ->40.25, VAT1.0088 im Provider. Draft->Offen->Bezahlt9,
  testweiser Zahlungseingang40.25, Rest0. Wiederholung ohne weitere Zahlung.
- Rechnung8519/Dispatch435/Bexio7:10.01->10.00, Draft angenommen.
- Rechnung8520/Dispatch436/Bexio8:10.03->10.05, Draft angenommen.
- Rechnung8546/Dispatch440/Bexio9: steuerbefreite Testbusse10.03->10.05,
  mwst_type2/Steuer0, Draft angenommen.
- Bexio-PDF6:109910Bytes,2Seiten,40.25 und Steuerzeilen sichtbar geprueft.
  Private Test-PDF/Renderbilder unter storage/app, nicht im Release/Git.

Erster Mixed-Test blieb wegen strenger VAT-Pruefung uncertain. Mathematische
Abbildung anhand Providerdaten korrigiert und ausschliesslich lesend
reconciled; Bexio6 wurde nicht erneut angelegt. Keine echte Bankzahlung,
kein Emailversand. Outbound bleibt aus; Testkontakte verwenden Testadressen.
Die Testsaetze sind keine rechtliche Empfehlung fuer Dress/Merchandising.

## Migration und Tests

Migration50 auf eigener DB db_acdf8d_dclubora, Schema clubora: Kategorien,
invoice_category_id/tax_mode/rounding_cents und lineTaxFK/JSON-Snapshot.
Migration51: cash_rounding defaultfalse fuer vorhandene Belege; neue manuelle
Rechnungen setzen es explizittrue. Keine Bestandsbetraege/PROD-Daten geaendert.
Keine neuen ini-Parameter/Jobs, keine PHPMaker/PMP-Aenderung erforderlich.
Ruecksprung auf Release51 vorhanden; steuerpflichtige Belege brauchen fuer
ihren Abgleich den neuen Code. Offene alte Versandvorschauen neu pruefen.

Regression41Tests/324Assertions erfolgreich, zusaetzliche gezielte Nachlaeufe:
4/45Dashboard/Filter/Edit,2/38Portal/PDF,2/28Beitraege/Edit,
2/24Taxpflicht/LivewireCreate und3/28arithmetischeTests (ueberlappend).
Synthetische Testmandanten werden zurueckgerollt. Tests decken fehlende und
inaktive Steuern, Mandant/Role/Revision, Rabatt, Rundung, UI-Erfassung,
Finanzabgleich bisBezahlt und unveraenderte Altbelege ab.
API/SaaS/Betrieb und eigenstaendige Gutschriftanlage bleiben weitere Pakete.
CLI: tools/clubunio-tax-invoice-check.php prepare|send|issue|pdf|pay|status
[--single|--up|--exempt]. Ausschliesslich eigene DB/Sandbox/Admin, feste Marker.
