# ClubUnio: Fachlisten und Dashboard-Ausbau

Release43: zentrale Schluesselliste und Spots-Versandliste mit gespeicherten
Ansichten und gefiltertem CSV. Fachlicher SQL-Abgleich und bewusst geaenderte
Archiv-/Aktivregeln: CLUBUNIO-LEGACY-REPORTS.md. Migration40/41 nur ClubUnio.

Release42: aktueller Listenabgleich in CLUBUNIO-WEB-COMPLETION.md. Bankadress-
Luecke geschlossen (Migration39/Editor/Export), Mitgliederlisten erhalten CSV,
Spielerlisten optionale Elternspalten und getrennten Kontakt-CSV mit Bestaetigung.
Bestehende Filter/persoenliche Ansichten und Maskierung gelten unveraendert.

Stand 12.10.2026. Dieses Paket ist ein weiterer Teil des Gesamtauftrags,
nicht der Abschluss aller Fachmodule. API und SaaS bleiben zurueckgestellt.

## Paket36: Speziallisten-Exporte

Mitglieder ohne Mannschaft und Passivmitglieder erhalten CSV exportieren.
Gefilterte/sortierte Gesamtauswahl einschliesslich persoenlicher Ansichten,
nicht nur die aktuelle Seite. Maximal5000 Zeilen; keine stille Kuerzung.
Mitglied-Nr.,Name,Mitgliederart/-status; bei Ohne Mannschaft zusaetzlich Saison.
Keine Kontaktdaten,Geburtsdaten,Ausweisnummern oder internen Notizen im Export.

Export prueft aktive Admin-/Office-Berechtigung erneut, begrenzt auf den
Mandanten und wendet die fachliche Eignungsabfrage nochmals an. Ohne Mannschaft
benoetigt eine gueltige eigene Saison; fehlende/fremde Saison liefert nur Kopfzeile.
Keine neue Regel fuer Mitgliedschaft: Austritt/Archivierung und Ausnahmen bleiben
wie in den bestehenden Listen. Kein automatisches Zuweisen von Mannschaften.
Lookuptexte statt technischer Status-IDs; UTF-8/BOM,Semikolon,Formelschutz,
private/no-store und Audit nur mit Exporttyp/Anzahl/Akteur ohne Suchtexte.

Keine DB-Migration,keine neuen Parameter,keine Quellmigration oder Nachrichten.
Ruecksprung auf Release35 ohne DB-Rueckmigration moeglich. Test-/Deploymentstand
in PI.md; weitere Altlisten/Bankadress-Abgleich und Fachabnahme bleiben offen.

## Historischer Abschluss Paket31

Release31 lokal/Pi aktiv. Gezielte Regression10 Tests/135 Assertions erfolgreich,
Format/Blade erfolgreich, Desktop/Mobil-Schaltflaeche lokal geprueft.
HTTPS200/302 und Serverdienste geprueft. Ruecksprung30 erhalten.

Spieler-, Staff-, Familien- und Bankkontenlisten haben einen CSV-Download.
Der Export nutzt die gefilterte und sortierte Tabellenabfrage (nicht nur die
aktuelle Seite), einschliesslich geladener persoenlicher Ansichten. Rollen-
und Mandantenpruefung erfolgen nochmals serverseitig. Nur Admin/Office.
Aktiv-/Historienfilter bleiben erhalten; Familien muessen aktiv sein und
Familien-/Banklisten schliessen archivierte Mitglieder wie die Webliste aus.

Feste Fachspalten statt Model-JSON:
- Spieler: Mitglied-Nr./Name, Saison, Mannschaft, Aktiv, Beitragsposition vorhanden.
- Staff: Mitglied-Nr./Name, Saison, Mannschaft, Funktion, Aktiv.
- Familien: Familie, Mitglied, Familienadresse, Geburtsdatum, Art/Status, Rabatt,
  Zuordnung aktiv. Eine Zeile pro Zuordnung, auch Erwachsene.
- Bankkonten: Mitglied, Bank, maskierte IBAN, Bankort, SWIFT, Status, Aktiv.
Keine AHV-/Ausweisdaten, keine internen Bemerkungen, kein Klartext-IBAN-Export.
Der Download respektiert Filter/Sortierung; ausgeblendete UI-Spalten veraendern
das feste dokumentierte Exportformat nicht.

UTF-8 mit BOM, Semikolon, korrektes CSV-Quoting und Formelneutralisierung.
Maximal5000 Zeilen; groessere Auswahlen werden statt stiller Kuerzung abgelehnt.
Werte werden vor Versand der Antwort aufbereitet; Entschluesselungsfehler
erzeugen keine unvollstaendige CSV-Datei. Audit report_exports protokolliert
Akteur, Listentyp und Anzahl als export_prepared (keine Zustellbestaetigung),
keine Personendaten/Suchtexte im Audit. Private/no-store-Antwort.

Keine Migration, keine Quellaktualisierung, kein neuer Parameter. Bestehende
PHPMaker-Systeme, API/SaaS/Betrieb und Integrationsversand bleiben unberuehrt.
Deployment-/Pruefnachweis siehe PI.md. Detailabgleich weiterer Altlisten,
vollstaendige Bankadressfelder und Benutzerabnahme bleiben offen.

## Neue Listen

- Offene Rechnungen: aktive Rechnung mit mindestens einer aktiven Position im
  Status O. Offener Betrag summiert ausschliesslich diese Positionen. Die
  bisherige Dashboard-Formel bleibt erhalten: Netto ungleich null/0, sonst
  Brutto ungleich null/0, sonst 0. Eine voll rabattierte Nullposition mit
  Bruttobetrag wird daher wie im Altsystem bewertet; fachliche Klaerung offen.
  Der Kopfstatus kann abweichen. Detailaufruf fuehrt zur normalen Rechnung.
- Spielerlisten: Zuordnung je Mannschaft/Saison, kombinierbare Suche und Filter.
  Ohne Beitragsposition bedeutet invoice_line_id IS NULL wie in der alten View.
  Keine automatische Rechnungserstellung oder Aussage ueber Zahlungseingaenge.
  Alte feste Saison29 bewusst durch auswaehlbare Saison ersetzt.
- Stafflisten: Saison, Mannschaft, Funktion und Mitgliederdossier.
- Spesen zur Bezahlung: aktive Belege mit Statuscode Z, aktive Positionssumme,
  Bank, maskierte IBAN und Freigabedatum. Keine Zahlungsausloesung. Fehlende
  Bankverbindungen verbergen den Beleg nicht, anders als der alte INNER JOIN.

Alle vier Listen sind nur lesend, fuer Admin/Geschaeftsstelle und auf den
aktuellen Mandanten begrenzt. Persoenliche Filteransichten werden unter
eigenen Listenschluesseln gespeichert. Bestehende Mutationen bleiben in den
zugehoerigen Fachmasken mit deren Berechtigungspruefung.

## Dashboard

Offene Rechnungsanzahl und Betrag verlinken zur neuen Fachliste.
Altersverteilung verwendet aktive Mitglieder gemaess MemberAccessService,
Tagesdatum Europe/Zurich und vollendete Lebensjahre. Fehlendes/zukuenftiges
Geburtsdatum erscheint unter Unbekannt. Austrittsdatum heute wird ausgeschlossen.
Spesenbetraege nach Saison summieren ausschliesslich aktive Positionen aktiver
Belege; alle Status, CHF, ohne automatische Auswahl einer vermeintlich aktuellen
Saison. Fehlende Saison wird explizit ausgewiesen.

## Grenzen und Migration

Keine neue Migration, kein neuer Snapshot und keine PHPMaker-Aenderung.
Keine E-Mails, Pushs, Bexio-Zahlungen oder anderen externen Schreibaufrufe.
Mitgliederentwicklung verwendet seit Release17 das originale Erfassungsdatum;
auch Rechnungssaison-/Statusdiagramme sind inzwischen vorhanden. Familien-/
Banklisten siehe Paket18 unten; vollstaendige Exporte bleiben offen. Mitglieder ohne Team und
Passivmitglieder werden im folgenden Paket16 beschrieben.
Anlagen/Signage, Beitragslauf und weitere Fachmodule siehe CLUBUNIO-ROADMAP.md.

Tests decken offene/bezahlt/inaktive Positionen, alte Betragsformel, Mandanten,
Altersgrenzen, Austritt, Seitenzugriff und kombinierte Saison-/Suchfilter ab.

## Paket16: Mitglieder ohne Mannschaft und Passivmitglieder

Migration000017 erweitert Mitgliederarten um team_required und passive_membership.
Die alten Bedeutungen (Art1 / Art5) werden einmalig nur fuer den importierten
Verein sv-sissach gesetzt. Laufende Abfragen verwenden keine festen Arten-IDs.
Beide Merkmale sind unter Stammdaten > Mitgliederart bearbeitbar; Aenderungen
werden durch den bestehenden CatalogService validiert und protokolliert.
Sie klassifizieren Listen, vergeben aber keine Berechtigungen.

Beide Listen enthalten nicht archivierte Mitglieder, deren Status als
restores_membership konfiguriert ist und die nicht ausgetreten sind. Ein
Austrittsdatum heute oder frueher schliesst aus. Stammdaten-Deaktivierung allein
loescht keine historische Bedeutung der Mitgliederart.

Ohne Mannschaft:
- Nur Arten mit team_required. Ehren-, Frei-, Supporter- und Sponsorenmitglieder
  sind wie in der alten View ausgenommen. Spots ist bewusst keine Ausnahme.
- Spielerzuordnung (nicht Staff) muss aktiv sein, zur ausgewaehlten Saison
  gehoeren und eine aktive TeamSaison referenzieren, sonst zaehlt sie nicht.
- Saisonwahl ist oberhalb der Liste sichtbar, mit weiteren Filtern/Suche
  kombinierbar und in persoenlichen Ansichten speicherbar.
- Vorgabe nur bei genau einer aktiven Saison mit gepflegtem Beginn/Ende,
  die das heutige Datum Europe/Zurich umfasst. Fehlende/mehrdeutige Vorgabe,
  leere Auswahl oder fremde Saison liefert keine Treffer statt alle Mitglieder.
  Importierte Saisons ohne Zeitraum muessen manuell ausgewaehlt werden.

Passivmitglieder: Mitgliederart mit passive_membership; keine Teampruefung.
Beide Listen nur lesend fuer Admin/Geschaeftsstelle, mit Mitgliederdossier-Link.
Neue Listen: /unassigned-members und /passive-members im ClubUnio-Mandantenpanel.
Kein App-API-Vertrag; PHPMaker DEV/PROD bleiben unveraendert.

Regressionen: Kandidaten/Ausnahmen/Austritt/Archivierung, alte/inaktive/geloeschte
Teamzuordnungen, Saisonfremdzugriff, uneindeutige Saison, Rollen, kombinierte
Suche und gespeicherter Standard sowie bearbeitbare/auditierte Klassifikation.
Rollback auf Release15 laesst die additiven Felder bestehen. Keine Nachrichten,
Integrationsschreibzugriffe oder Aenderung am unveraenderten PROD-Snapshot.

## Paket18: Familien- und Bankkontenlisten

Zwei lesende Admin-/Office-Listen unter Listen, keine neue DB-Migration:
`family-reports` und `bank-account-reports`. Beide haben eigene persoenliche
Ansichten mit Standardwahl, kombinierbare Suche/Filter und Dossierlinks.
Benutzer-, Trainer- und TL-Portalrechte werden ausdruecklich nicht erweitert.

Abgleich mit TblSQL der PMP-Views ListeFamilienMitKinder und ListeBankkontenStaff:
- Familienliste zeigt eine Zeile pro Zuordnung, auch erwachsene Mitglieder.
  Kein erfundener Minderjaehrigkeitsfilter. Familie, Adresse, Mitglied,
  Geburtsdatum, Mitgliederart/-status, Rabatt und Bemerkung sind verfuegbar.
  Aktive Familie und nicht archiviertes Mitglied sind Pflicht; die Zuordnung
  ist standardmaessig aktiv, per Filter auch historisch einsehbar. Austritt
  allein blendet keine Verwaltungszuordnung aus. Filter: Familie, Art, Status.
- Bankliste enthaelt wie die bisherige View alle zugeordneten Mitgliedskonten,
  nicht automatisch nur aktuell als Staff eingesetzte Personen. Konto standard-
  maessig aktiv, Mitglied nicht archiviert. Fehlende Bank blendet das Konto
  bewusst nicht aus, sondern wird sichtbar ausgewiesen. Bank, Ort, SWIFT,
  Mitgliederstatus und optionale Kontaktspalten vorhanden.
- Staff-Saison ist optional. Bei Auswahl muss mindestens eine aktive Staff-
  Zuordnung zu einer aktiven TeamSaison dieser Saison bestehen. EXISTS statt
  vervielfachendem JOIN: mehrere Zuordnungen erzeugen keine Kontoduplikate.
  Fremde oder ungueltige Saison ergibt keine Treffer. Leere Wahl = alle Konten.
- IBAN bleibt maskiert (letzte vier Stellen). Kein Klartext-Export, keine
  IBAN-Suche, keine Zahlungsaktion und keine Weitergabe des verschluesselten
  Feldes in Model-JSON. Vollstaendige Bankadresse/Postkonto aus alter View
  fehlen im aktuellen ClubUnio-Bankmodell; dies bleibt ein offener Detailabgleich.
- Bearbeiten erfolgt nur in bestehenden Familiendossiers/Mitgliederdossiers;
  die Berichte haben keine Create-/Edit-/Delete-Aktionen.

Automatisierte Regressionen pruefen Erwachsene/Familien, Archivierung,
Mandantengrenzen, Admin/Office gegen Portalrollen, Aktivfilter, kombinierte
Such-/Filterauswahl, gespeicherte Standards, maskierte IBAN sowie Mehrfachstaff.
Visuelle Fachabnahme mit echter Anmeldung bleibt separat erforderlich.
Keine PHPMaker-Aenderungen, keine Quellmigration und keine externen Aufrufe.
