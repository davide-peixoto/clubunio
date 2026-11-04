# Spesen: begrenzte Read-Capability-Projektion (04.11.2026)

Der persönliche Listencontroller lädt member/teamSeason.team/season/round/status
bereits gesammelt. Das Hauptproblem lag nicht bei diesen Relationen: pro
offenem Beleg ruft receipt dreimal editable und einmal targets auf. Alle vier
prüfen visible(...)->whereKey(...)->exists() und ziehen weitere echte aktuelle
Rechte-/Mitgliedschaftsabfragen nach sich. Eine Seite mit100 offenen Belegen
erzeugte allein400 einzelne Sichtbarkeits-EXISTS zusätzlich zur Listenabfrage.
Bei nichtoffenen Belegen verkürzt editable frühzeitig; targets bleibt pro Beleg.

ExpenseService::readCapabilities(Account,Tenant,iterable):array berechnet eine
reine Leseprojektion für exakt die übergebenen Belege. Ergebnis pro Beleg-ID:
CanManagePositions, CanAddPosition, CanDeletePosition, AllowedStatusCodes.
Die letzte Liste entspricht den bestehenden targets ohne B (keine Zahlung).
Unsichtbare, deaktivierte, fremde und ausserhalb des aktuellen Finanzsaisonscopes
liegende IDs bleiben geschlossen. Kopf-/Statusdaten bleiben die geladenen DTOs.

Pro Methodeninvokation werden ein frisches aktives Konto und die bestehenden
Modulfunktionsrechte gelesen. portalAllowed, eigene aktive genehmigte
Mitgliederzuordnungen, technische Leitungsrolle und verantwortliche Teams folgen
den bestehenden Services. Dieselbe visible-Policy wird mit diesen lokalen Fakten
auf einen einzelnen begrenzten ID-Batch angewandt: active+tenant+Finanzsaison,
bei Office/Profilmodulrechten der bestehende breitere Lesescope, sonst persönliche
Mitglieder und nur für technical_lead dessen aktive verantwortliche Teams.
Statusrelationen werden nötigenfalls gemeinsam geladen. Unbekannte Daten werden
nicht durch technische Teamrechte zu eigenen Mitgliederdaten aufgewertet.

Alle Fakten sind lokale Variablen dieses Aufrufs. Keine Properties, Singleton-/
TTL-/Crossrequest-Caches, kein wiederverwendbarer Authkontext und keine Änderungen
an editable, targets, visible oder irgendeinem Schreib-/Transitionsdienst.
Jeder zweite Aufruf liest aktuelle Rechte erneut. Die vorhandene requestlokale
Saisonauflösung bleibt bestehen. Schreibaktionen prüfen weiterhin ihre eigene
frische Autorisierung und Revision innerhalb des bisherigen Fachablaufs.

Die Einbindung in MobileExpenseData/Controller besitzt der parallele
Projektionsagent; dieser Dienst allein stellt keinen aktivierten DTO-Caller dar.
Keine Schemaänderung, kein Fremdprovider und kein Deployment in diesem Paket.

## Paritäts- und Performancenachweis

ExpenseReadCapabilitiesTest verwendet ausschliesslich synthetische Konten,
Mandanten, Mitglieder, Teams, Bank-/Belegdatensätze in einer PostgreSQLtransaktion
mit explizitem CLI-Opt-in und exaktem Datenbankguard. Rollback im teardown,
keine Migrationen/DDL und keine Bankzahlungen, Mail oder Provideraufrufe.

Vier Fälle vergleichen die gesamte Rückgabe mit den unveränderten bisherigen
drei editable-Aufrufen und targets-minus-B: admin/office/trainer/technical_lead/
member sowie leere/lesende/teilweise schreibende Profile; alle fünf Status;
eigene/fremde Mitglieder, verantwortliche/fremde Teams; inaktive/fremde/alte
Saisonbelege. Zweitaufrufe nach Profilrechts-, Mitgliederlink-, Rollen- und
Kontowiderruf bleiben frisch. Ein Querycountvergleich prüft konstante Kosten
für eine gegenüber20 Zeilen und eine deutliche Verringerung gegenüber der
bisherigen Schleife. Serieller echter PostgreSQL-Nachlauf:4 Tests/235 Assertions
grün in2:57.465. Die Queryanzahl ist für eine und20 Zeilen identisch; die
bisherige20er-Trainerprojektion benötigt mehr als das Fünffache der Batchqueries.
Das ist ein Querycountnachweis, keine behauptete reale Endgeräte-Latenzgarantie.
Alle synthetischen Zeilen zurückgerollt, keine eigene PG-Verbindung mehr aktiv;
Fenster ausdrücklich an den Projektionsagenten für dessen Integration übergeben.
