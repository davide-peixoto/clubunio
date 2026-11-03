# ClubUnio Mobile: My Teams

Stand03.11.2026. Persoenlicher My-Teams-Vertrag und Adapter fuer die vorhandene
SVS-Oberflaeche implementiert; noch kein DEV-Release oder vollstaendige
Modulfreischaltung. Details im App-Repository: mobile/docs/MY-TEAMS-MIGRATION.md.

MobileTeamsData/Controller liefern aktuelle berechtigte Team-Saisons,
Staff-/Spielerzuordnungen mit FamilyService-Versionen, Trainings und berechtigte
Anmeldungen. Keine generische Tabellen-API. Eigene aktive Zuordnungen/Vertretung,
Teamverantwortung oder teams.view bestimmen die Teammenge. Datumsgrenzen aus
Saisonmetadaten ersetzen feste Legacy-Saison-ID38; keine erzwungene beliebige
Saison bei mehreren aktiven Saetzen mit unbekannten Daten.

Namen im eigenen Kader erweitern nicht die vorhandenen MemberAccess-Kontakt-
rechte. Private Eltern-/Adressfelder nur fuer eigene/vertretene Mitglieder oder
members.view; keine AHV-/Bank-/internen Metadaten. RegistrationReviewService
bestimmt unabhaengig die Anmeldesicht. Frischer Rechteentzug wird auch mit
bestehender persoenlicher Sitzung wirksam. Kein API-only-Stellvertreter.

POST/DELETE-Zuordnungen verwenden TeamAssignmentService unter Vereins- und
gemeinsamer Sitzungssperre mit erneuter Identitaetspruefung. Rechte fuer
create sowie update/deactivate bleiben getrennt, erwartete Version ist Pflicht
beim Entfernen. Keine harten Loeschungen, keine automatischen Mutationsretries.
Lookups nur innerhalb des Vereins und fuer berechtigte Zuordnungsanlage.

SignageService.trainingVariant extrahiert nur die bereits vorhandene gemeinsame
Platzregel; Trainings des konkreten Team-Saisonscope werden anhand dieser
Variante gelesen. Bestehende Signage-Variante18 Assertions erfolgreich.
Persoenliche My-Teams-PG-Fachfaelle3 Tests/68 Assertions, gezielter
Zuordnungs-/Lookup-Nachlauf27 Assertions und Mobile-Regression29 Tests/
340 Assertions bestanden. Keine neue Migration fuer My Teams.18 Flutterfaelle
fuer Adapter/Originalscreens und vorhandene Fachregressionen sowie19 API-/
Sitzungsgrenzen geprueft. Neuere Reads, die vor einem Entfernen-Commit lesen,
duerfen bestaetigt entfernte Versionen nicht wieder freigeben. Echte
Wiederherstellung mit neuer Version bleibt moeglich; defekte Details sind
keine leere Mannschaft. Teamrechte werden pro Seite frisch ermittelt.

Offen: persoenliche Mitgliederdossiers/Bearbeiten, Rechnungs-/Dokumentlinks,
Anmeldeanhaenge und Gesamtabnahme. Main-App-Modul bleibt bis zur durchgaengigen
Anbindung offen. Planungs-Schemastufen84/85/86/87 und DEV-Release separat offen;
deren Pruef-/Rueckwegregeln stehen in CLUBUNIO-MOBILE-PLANNING-WORK.md.
