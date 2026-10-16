# Dossierdialoge: Familien, Anmeldungen und Finanzkorrekturen

## Umfang (16.10.2026)

1. Familienzuordnungen im Mitgliederdossier und Familienmitglieder in der
   Familie: anlegen, bearbeiten, kopieren und deaktivieren. Der jeweilige
   Elternschluessel wird serverseitig festgesetzt. Kopien muessen eine neue
   Kombination verwenden; bestehende Zuordnungen bleiben erhalten.
2. Anmeldungen im Mitgliederdossier und bei Mannschaften pro Saison:
   vorhandene Detail-, Formular-, Pruef-, Dokument- und Nachrichtenaktionen.
   Keine neue Anmeldung mit erfundener Mitgliedsverknuepfung aus dem Dossier.
   Ein bewusst geaenderter Mannschaftsbezug kann die Anmeldung aus der
   bisherigen Mannschaftsliste entfernen. Statuslogik, Revisionen und
   Benachrichtigungsregeln bleiben bei den bestehenden Fachservices.
3. Kundenguthaben im Mitgliederdossier sowie Gutschriften und Rueckerstattungen
   bei Rechnungen: eingebettete Fachseiten im rechten Dialog. Die vorhandenen
   Aktionen werden wiederverwendet, nicht als zweite Finanzlogik kopiert.
   Nach Aktionen bleiben Dialog und uebergeordnetes Dossier offen. Summen
   werden aktualisiert, Filter und Suche bleiben erhalten.

Die normale Detailseite bleibt parallel erreichbar. Verrechnungsnachweise
und historische Daten erhalten kein freies CRUD. Rueckerstattungsaktionen
erfassen nur manuelle Nachweise, keine Bankzahlung. Es gelten weiterhin die
bestehenden Bexio-/Admin- und Statussperren. Testversand von Anmeldungs-E-Mails
bleibt ausschliesslich an den konfigurierten Testempfaenger gebunden.

## Sicherheit und Migration

Keine Schema-/Datenmigration und kein Datenbankrefresh erforderlich.
Familienaktionen verwenden FamilyService mit Versions-/Dublettenpruefung und
Audit. Anmeldungsaktionen verwenden die eingeschraenkte Dossierbeziehung
und die bestehenden Registration-Services. Finanzdialoge pruefen bei jedem
Request den gesperrten Dossierbezug, frische Rolle, Mandant und Datensatz.
Keine pauschale Erweiterung von Berechtigungen.

Die bisherigen Fremdschluessel, Versionswerte und Status-Lookups sind bei
spaeterer Migration zu erhalten. PHPMaker DEV/PROD bleiben unveraendert;
API, SaaS und Betrieb werden in diesem Paket nicht erweitert.

## Pruefung und Rollout

Transaktionale Tests fuer beide Familienrichtungen, Dubletten, fremde
Dossier-IDs, Anmeldungsstatus mit Nachrichtenereignis, eingebettete Finanz-
aktionen ohne Redirect sowie Rollenentzug. Bexio in Tests simuliert; keine
echten Finanzaktionen oder Benachrichtigungen beim Test/Deployment.
Regression: WorkspaceEditingTest, RegistrationCompletionTest, CreditNoteTest,
CustomerCreditTest, SeasonFinanceTest. Visuelle Familien- und Gutschrift-
Dialogpruefung ohne Speichern echter Daten. Sichtbarkeit der Fachaktionen
verwendet die Standardkomponente von Filament und folgt dem aktuellen Status.

Gezieltes Delta auf ClubUnio, aus dem aktuellen Serverrelease geklont.
Config-/Routen-/Viewcache neu bauen. Ruecknahme durch den vorherigen Release,
keine Datenruecksetzung. Keine fremden parallelen Aenderungen mit aufnehmen.
