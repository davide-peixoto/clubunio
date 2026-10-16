# ClubUnio: Formulare, Lookups und Speichern

16.10.2026, nur ClubUnio. Keine API-/SaaS-/Betriebserweiterung und kein
Eingriff in das bestehende PHPMaker DEV/PROD. Keine externen Schreibaufrufe.

## Umsetzung

Mitgliederauswahl in gemeinsamen Formularen (Mannschaft, Spesen, Anmeldung),
Rechnungserfassung, Materialuebergaben und Dokumentupload laedt nur sechs
Anzeigespalten statt kompletter Mitgliederdatensaetze: ID, Mitgliedernummer,
Legacy-ID als Nummernfallback, Firma, Vorname, Nachname. Suchbedingungen,
Sortierung, 50er-Grenze, Vereins- und Archivfilter sowie Labels unveraendert.
Keine Fotos, AHV oder vollstaendigen Kontaktdaten fuer diese Auswahllisten.
Keine Vorabliste aller Mitglieder oder browserseitige Datenkopie.

Bankkontoauswahl bei Spesen: ohne gewaehltes Mitglied keine Abfrage. Danach
nur Kontoschluessel, Bank-ID und verschluesselte IBAN fuer die unveraenderte
Maskierung; Bankrelation nur ID/Name. Vereins-, Mitglieds- und Aktivfilter
bleiben frisch; Mitgliedswechsel leert weiterhin das gewaehlte Konto.

MemberService liest die tatsaechlich eingereichten Lookup-IDs innerhalb der
Schreibtransaktion hoechstens einmal pro Tabelle. Land und Nationalitaet
teilen einen gebundenen Read; Statusmerkmale und Rechnungsempfaengerfeld
werden bei der Validierung mitgelesen und im selben Save wiederverwendet.
Keine vollstaendigen Kataloge und kein Cache zwischen Save-Aufrufen.
Integer-/Pflichtregeln bleiben bestehen; inaktive Altwerte sind nur fuer
das jeweilige unveraenderte Feld erlaubt, nie fuer ein anderes Feld.
Fehlende/fremde/inaktive Werte werden weiterhin als Feldfehler abgewiesen.
Der Standardfehler heisst nun ungueltige Auswahl (in) statt exists.

Schreibberechtigung, Verein, Zeilensperre, Versionsschutz, Nummernvergabe,
Rechnungsempfaenger-E-Mail, Austritt/Wiedereintritt und Audit unveraendert.
Keine pauschale Verzoegerung oder Entfernung von live(): Abhaengigkeiten
Firma/Pflichtnamen, Mitglied/Bankkonto und Spesentyp/Betragsvorschlag bleiben.
Parallel entwickelte dynamische Rollen sind nicht Teil dieses Deltas.

## Messung und Tests

CLI tools/clubunio-form-performance-check.php erzeugt ausschliesslich eine
synthetische Vereins-/Office-/Mitgliederfixture in einer Rollbacktransaktion
auf der explizit geprueften ClubUnio-DB. Kein echter Mitgliederdatensatz wird
veraendert, keine Zustellung und kein Bexio-/Azure-Aufruf. PG-Sequenzen koennen
Luecken bekommen. Ausgabe nur Dauer/Abfragezahl/Ergebnis, keine IDs/Bindings.

Pi vor Delta: synthetische Neuanlage mit allen sechs Lookups 407ms,
13 DB-Abfragen, davon8 Lookup-Reads,348ms DB-Zeit. Keine Lasttest-/SLA-Zahl.
Neuer Test bestaetigt maximal5 Lookup-Reads: eine je unterschiedlicher
Tabelle statt sechs exists plus Status-/Empfaenger-Nachlesen.

Pi nach Delta: 286ms/10 DB-Abfragen, davon5 Lookup-Reads/231ms DB-Zeit.
Gleiche synthetische Neuanlage und Rollback, keine bestehenden Daten geaendert.
Stichprobe ohne Lasttest: die Zeitdifferenz ist keine garantierte Beschleunigung.

Gezielte Tests: FormLookupPerformanceTest (6 Tests/34 Assertions),
Mitglieder-Anlage/Edit/Archiv/Versionsschutz/Fremdlookup/Rollen/Schema
(7/61) und RegistrationCompletionTest (14/94) erfolgreich.
Zusaetzlicher breiter Dossier-Test meldete im parallelen lokalen Arbeitsstand
MaterialReceiptsRelationManager:23 (Query null); nicht als erfolgreicher
Gesamtlauf gewertet. Diese Relation/parallel geaenderte Rollen nicht deployt.

Release 20261016-clubunio-form-performance aktiviert. Syntaxcheck aller sechs
Fachdateien, Cache-/Routen-/Tenantchecks und CLI-Save erfolgreich. Angemeldeter
Chrome-Test auf ClubUnio: Rechnungserfassung findet Sandbox-Mitglieder,
Auswahl uebernimmt unveraendertes Label. Mitglieder-Neuanlage und Statuslookup
mit gepflegten DB-Werten sichtbar. Beide Dialoge ohne Speichern geschlossen,
keine Konsolenfehler. Keine echten Rechnungen oder Mitglieder angelegt.

## Migration und Rollback

Keine DB-/Indexmigration, keine neuen Parameter, keine Imports oder neuen
Zugriffsprofile. Gezieltes Dateidelta auf verifiziertem ClubUnio-Release;
View-/Routencache erneuern. Ruecknahme ueber vorherigen Release-Symlink,
kein Datenrollback. Historische Lookup-IDs und Mitgliedernummern beim
spaeteren Import erhalten. Keine Secrets/Uploads/vendor in Git/Archiv.
