# SV Sissach: ergaenzte Fachzuordnungen, 22.11.2026

Nur isolierte PROD-Kopien. Kein Release und kein Import auf `clubunio_prod`.
Diese Erweiterung folgt auf den [Delta-Runner v1](CLUBUNIO-SVS-DELTA-RUNNER.md).
Die v1-Vertraege bleiben unveraendert fuer reproduzierbare Altlaeufe erhalten;
der aktuelle CLI-Runner verwendet Mapping, Zielvertrag und Quellabdeckung v2.

## Neun Quelltabellen

| PHPMaker-Quelle | Anzahl | Natives Ziel | Fachliche Behandlung |
| --- | ---: | --- | --- |
| AppAnkuendigungen | 1 | mobile_app_announcements | Betreff/Text, Gueltigkeitszeitraum und Erstellung; dauerhaft inaktiv, schreibgeschuetzte Historie |
| DokumentVorlagen | 4 | document_series_templates | Definition, Artikelpositionen, Dokumenttyp, Quellrevision; unveraenderliche Quellversion |
| DokumentSerien | 4 | document_series und 4 weitere document_series_templates | Eigene eingefrorene Vorlage aus der damaligen Definition, kein Rueckgriff auf spaeter geaenderte Vorlagen |
| DokumentSerienEmpfaenger | 17 | document_series_recipients | Serie/Mitglied/Altquittung und damalige personalisierte Werte; keine neue Zustellung oder Signatur |
| MitgliederAenderungsantraege | 18 | member_change_requests | 17 genehmigt, 1 abgelehnt; damalige Vorher-/Nachherwerte, Antragsteller und Entscheider; keine erneute Anwendung auf Mitglieder |
| MitgliederQuittungen | 19 | legacy_material_receipts | Mitglied, historische Signaturmetadaten, Original-/Signatur-Anhang und Quellchecksummen; ausschliesslich ungepruefte Dateimetadaten |
| TrainingPlannerAuditLog | 47 | legacy_training_plans | Validierte Pruefplaene oder begruendet ausgeschlossene Quelle; keine automatisch freigegebenen Trainingsplaene |
| TeamTerminGaeste | 2 | team_guests | Veranstaltung, Name, Teilnahme, Anwesenheit, verschluesselte Kommentare und Soft-Delete; normale feldweise Delta-Regeln |
| Verbandsspiele | 231 | sfv_schedule | Externe Spiel-/Saison-/Teamnummern, Datum, Spielort, Status, Heim/Gast und native ClubUnio-Team-/Saisonzuordnung; normale Delta-Regeln |

343 Quellzeilen ergeben 347 zusaetzliche native Fachdatensaetze (4 eingefrorene
Serienvorlagen). Hinzu kommen 14 Spesen-Pruefbelege: insgesamt 361 neue
Datensaetze gegenueber v1, insgesamt 37'911 gegenueber einer leeren Vereinsbasis.
Alle 100 Quelltabellen und ihre Spalten bleiben in der privaten Rohprojektion
erhalten. 58 Tabellen sind jetzt fachlich zugeordnet; keine befuellte Tabelle
bleibt als `deferred_business_mapping` klassifiziert. Infrastruktur und leere
Bestandstabellen behalten ihre gesonderte Klassifikation.

## Feldregeln und Herkunft

`deploy/svs-source-coverage-v2.json` enthaelt die vollstaendige Spaltenliste,
`deploy/svs-import-mapping-v2.json` die nativen Felder, Identitaeten und Fremdschluessel.
Die Umsetzung steht in `SvsSupplementImporter`:

- Jede der neun Quellen erhaelt Quelltabelle, Quell-ID, Quellversionshash und den
  vollstaendigen verschluesselten Quelldatensatz ohne technischen Zeilenordinal.
  Nicht nativ abbildbare Quellfelder bleiben dort unveraendert nachvollziehbar.
- Vorlagen: `Name/Titel/Text/DokumentTyp/UnterschriftNotwendig/Positionen` werden
  auf `name/title/text/type_id/signature_required/items` abgebildet;
  `Artikel/Artikelnummer/Menge/PreisRappen/Groesse/Kuerzel/Marke` auf
  `description/reference/quantity/price_cents/size/initials/brand`.
  Dokumenttypen werden ausschliesslich exakt nach Quell-ID oder Code/Name aufgeloest.
- Serien verwenden die Definition aus ihrem `Snapshot`; Empfaenger die dort
  gespeicherten damaligen `Werte`, niemals aktuelle Mitgliederdaten.
  Der Empfaenger-Versionshash umfasst auch Serien- und Quittungsquellversion,
  damit spaetere Aenderungen dieser Abhaengigkeiten eine neue Historienzeile ergeben.
- Antragsschluessel werden explizit auf native Mitgliedsfelder abgebildet.
  Land/Nationalitaet/Geschlecht/Rechnungsempfaenger werden zu nativen Lookup-IDs.
  Vorher-/Nachherwerte bleiben verschluesselt. `AppliedAt`, Quellbenutzerrolle,
  Soft-Delete und Fehler bleiben Herkunftsdaten und vergeben keine Berechtigung.
- Zeitpunkte ohne Zeitzone werden als Europe/Zurich interpretiert und in UTC
  gespeichert. Freitextinhalte bleiben erhalten. Importbedingte Statuswerte und
  Fehlerschluessel bleiben sprachunabhaengig, neue UI-Hinweise liegen in DE/EN vor.
- `CreatedBy`, Antragsteller und Entscheider werden nur bei eindeutigem exaktem
  Benutzernamen zugeordnet. Bei allen vier Serien ist der urspruengliche Autor
  nicht zuordenbar: `created_by=NULL`, kein erfundener Ersatzautor. Ein reiner
  Vorlagenbearbeiter wird nicht als urspruenglicher Ersteller ausgegeben.
- Trainingsplaene behalten Originalrequest, Rohplan und Provider-/Tokenmetadaten
  verschluesselt; der native Pruefplan nutzt den vorhandenen Validator. Alte
  HTML-/SVG-Diagramme werden nicht ausgefuehrt. Zuordnungsvorschlaege aktivieren
  weder Konten noch Vereinszugang und erstellen keinen produktiven Trainingsplan.
- SFV-Zusatzfelder (u.a. Rohantwort, Tore, Liga, Runde, Fehler und Quellenloeschung)
  bleiben im verschluesselten Herkunftsdatensatz; sie erzeugen keine Buchung oder
  automatische Spielplanung. Externe Saisonnummern sind keine ClubUnio-Saison-IDs.

Historische Dokumente, Vorlagen, Empfaenger, Antraege, Ankuendigungen, Quittungen
und Altplaene sind in der Datenbank gegen UPDATE/DELETE gesperrt. Neue
Quellversionen werden angehaengt. Fehlende Quellversionen werden nur zur Pruefung
gemeldet, niemals geloescht. Normale Neuerfassung behaelt ihre Pflichtautoren
und Eindeutigkeitsregeln durch bedingte Constraints/Indizes.

19 Quittungen sind `source_metadata_only`. Es wurden keine Anhangsbinaerdaten
gelesen oder uebertragen; weder kryptografische Dateiintegritaet noch Gueltigkeit
einer Unterschrift wird behauptet. Download und neue Verarbeitung sind gesperrt,
bis eine gesonderte, ausdruecklich freigegebene Dateiuebernahme implementiert ist.

## Die 14 Spesen-/Saisonabweichungen

Die freigegebenen fachlichen Regeln stehen in `svs-expense-season-rules-v1.json`.
Sie verwenden ausschliesslich Quellschluessel, keine alten nativen Stage-IDs.
Alle 14 Faelle besitzen genau eine aktive TeamSaison-Zeile fuer dasselbe Team
in der bereits auf dem Beleg gespeicherten Saison. Ausschliesslich die native
`expenses.team_season_id` wird diesem eindeutigen Datensatz zugeordnet.
Belegsaison, Betraege, Positionen, Steuerwerte, Status und Zahlung bleiben erhalten.

`svs_expense_season_reviews` speichert Quellbeleg, alte Zuordnung, Teamschluessel,
Zielzuordnung, Quellhash, Regelversion und Entscheidung. Die urspruengliche
Rohquelle bleibt unveraendert mit ihren 14 Abweichungen erhalten. Neue oder
mehrdeutige Abweichungen brechen den gesamten Aufbau ab. Bereits in der Quelle
behobene oder entfernte Belege erzwingen keine erneute historische Korrektur.

## Delta-Basis v1 nach v2

Ein leerer Zielverein kann v2 direkt erhalten. Bestehende v1-Baselines werden
standardmaessig abgelehnt. `--upgrade-v1` erlaubt ausschliesslich den bekannten
v1-Vertrag (festgelegter SHA256) und seine bekannte Mappingversion. Alle alten
Feld-/Identitaets-/Referenzvertraege muessen unveraendert sein. Quell- und
Zielschema sowie Code-Projektionshash bleiben vorgeschaltet.

Die bisherigen Ziel-IDs und HMAC-Feldbaselines werden nicht neu initialisiert.
In derselben Transaktion werden 361 neue Datensaetze ergaenzt und die 14
geprueften Team-/Saisonreferenzen nach den normalen Dreiwegregeln aktualisiert.
Eine kollidierende lokale Korrektur rollt den gesamten Lauf inklusive
Mappingversionswechsel zurueck. Ein Erfolg erzeugt einen technischen Laufbeleg.

Native IDs innerhalb von JSON-/verschluesselten Dokument- und Antragssnapshots
werden ebenfalls auf Ziel-IDs umgebogen; abhaengige Definitions-/Snapshothashes
werden neu berechnet. Verschluesselte Rohherkunft bleibt dabei unangetastet.

## Betrieb und Grenzen

`prepare-svs-history-rehearsal.php` kann die Zusatzmigration nur nach expliziter
Autorisierung einer isolierten Rehearsal-Verbindung anwenden. Die Migration
verweigert Live-DBs. Auch der Merge kann ausschliesslich nach Namensmuster und
`current_database()` bestaetigte Candidate-/Rehearsal-Datenbanken verwenden.
Die produktive Einfuehrung dieser Schemaerweiterung braucht einen gesonderten
Release-Schritt; dieser Arbeitszweig wird nicht auf PROD ausgerollt.

Reproduzierbare Pruefwerkzeuge:

    php tools/test-svs-history-rehearsal.php <candidate> <importierte Kopie>
    php -d memory_limit=768M tools/test-svs-v1-upgrade.php <v1 candidate> <v2 candidate> <leere vorbereitete Kopie>

Der Upgrade-Test erzeugt bewusst eine v1-Fixture und benoetigt mehr Arbeitsspeicher
fuer den Vergleich aller alten Feldbaselines. Die Datenbanknamen bleiben in allen
Werkzeugen strikt auf Kopien beschraenkt. Technische Abnahmenachweise stehen unter
`deploy/evidence`; private Dumps und Quelldaten gehoeren niemals ins Repository.

## Abnahme

[Sanitisierter Nachweis](evidence/svs-history-rehearsal-20261122.json):
37 Historien-/Datenpruefungen, 14 Anwendungs-/DE-/EN-Ansichtspruefungen,
acht bestehende Delta-Regressionsszenarien, fuenf CLI-/Schemagates und
fuenf Fachregel-Negativ-/Positivfaelle bestanden. Sechs bestehende Source-Delta-
Unit-Tests sowie PHP-Syntax- und Uebersetzungskatalogpruefung erfolgreich.
Erstimport 37'911; v1-Upgrade 361 Inserts/14 Updates; Wiederholung jeweils
0 Inserts/0 Updates. Auch der CLI-Upgradepfad funktioniert mit normalem
PHP-Speicherlimit. Kopien behalten Plattformadmin und alle 146 urspruenglichen
Migrationen; PROD bleibt bei 0 Vereinen ohne Import-Ledger.

Die versionierte Regeldatei ist bytegenau im Projektionshash gebunden;
`.gitattributes` bewahrt ihre geprueften Zeilenenden bei Commit/Checkout.
