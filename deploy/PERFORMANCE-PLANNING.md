# Mobile Teamplanung: Seitenprojektion und Requestketten

Stand 04.11.2026. Ausgangspunkt Backendf0ebea3/DEVperformance-v1 und App9b830e5.
Root hat den bisherigen echten DEV-Kalenderabruf mit synthetischem Admin und
Rollback gemessen:20 von26 Terminen,15.836/15.871ms Gesamtdauer,609/608 SQL-
Abfragen; ein Lauf davon15.542ms SQL-Zeit. Rund26ms je Datenbankroundtrip
machen wiederholte Einzelprojektionen zum entscheidenden Engpass. Der private
Laufnachweis liegt unter storage/app/mobile-api-baseline-populated-timings.log;
keine privaten Datensaetze oder Zugangsdaten in dieser Dokumentation.

## Backenddelta

MobilePlanningData::events lud eine bereits autorisierte Kalenderseite und
rief danach pro Termin erneut event() auf. Dadurch wurden Sichtbarkeit,
Teamrechte, eigene Personen, Teilnahmen, Gaeste, Spieler-/Staffzuordnungen,
Teamlabel und Einladungskontexte mehrfach gelesen.

Die neue private eventRows-Projektion verarbeitet ausschliesslich die aktuelle
Seite aus PlanningCalendarService::visible. Alle genannten Beziehungen und
der Manager-/Eigene-Personen-Scope werden fuer diese Seite gesammelt geladen.
renderEvent verwendet dieselbe DTO- und Kommentar-/Zaehlerlogik fuer Liste und
Einzeldetail. Einzelaufrufe event() sowie alle Mutationen behalten ihre frische
Objekt-/Rechtepruefung. Keine oeffentliche Methode akzeptiert vom Client
behauptete vorautorisierte Termindaten.

MobilePlanningInvitationsData::metadataBatch buendelt Einladungshistorie und
Mitgliederstatus. Die verschluesselten Snapshots binden weiterhin Actor,
Tenant, Event-/Teilnahme-/Mitgliederzustand, Einladungs-Audit und Ablaufzeit.
verify() berechnet beim Write weiterhin den echten frischen Einzelkontext;
die Writepruefung erhaelt keinen Readcache. Private Kontakte werden nicht
zusaetzlich geladen oder ausgegeben. Kalenderfilter, Reihenfolge, Seitenlimit,
Total, aktuelle Saison und eingeladene Teilnehmer bleiben erhalten.

Teamoptionen lesen aktive Spieler-/Staffzuordnungen ebenfalls einmal je Seite
statt zweimal je Mannschaft. Kein TTL-, globaler oder dauerhafter Rechtecache;
keine Migration, Imports, Provideraufrufe oder Aenderungen an Shared-Middleware,
MobileAccess, MemberAccess oder ModulePermission durch dieses Teilpaket.

## Original-Appablaeufe

Direkt-/Notificationhelfer luden ein frisches Detail und der Zielscreen lud es
sofort noch einmal. Nur diese unmittelbar verifizierten Einstiege geben ihren
bereits geladenen Datensatz an den Originalscreen weiter:1 statt2 GETs. Ein
normaler Kalenderlisteneintrag braucht weiterhin seinen eigenen frischen
Detail-GET; manuelles Aktualisieren und Schreibnachlaeufe bleiben unveraendert.

PersonalPlanningTransport teilt ausschliesslich identische gleichzeitig laufende
GETs, getrennt nach Pfad, Filtern und Writegeneration. Maximal16 offene
Eintraege, sofortige Entfernung nach Erfolg/Fehler, kein Ergebniscache. Jeder
Write trennt vorhandene Reads; nach Konto-/Mandantenwechsel kann ein alter
Adapter weder Versionsstaende noch Callerbefehle weiterverwenden. Bestehende
Antwortreihenfolge-/Revisionsguards bleiben bestehen.

Die persoenliche Mannschaftsauswahl nutzt den vorhandenen maximalen100er-
Seitenvertrag:85 Optionen benoetigen1 statt5 Requests. Der Legacyadapter bleibt
bei seinem20er-Vertrag. Der Original-SFV-, Kalender-, Detail-, Teilnahme-,
Anwesenheits-, Absenzen-, Statistik-, Serien- und Einladungsvorgang bleibt
erhalten; kein neuer Ersatzscreen und keine automatische Schreibwiederholung.

## Nachweise und verbleibende Grenzen

Zwei neue SQLite-Querycount-/Vertragsfaelle ohne Shared-Readframe sind gruen
(12 Assertions):20 volle Termine inklusive Einladungen und Gaesten haben die
gleiche SQL-Anzahl wie1 Termin und gegenueber dem bisherigen Einzelprojektions-
weg ueber3x weniger Abfragen. Batch- und Einzel-DTOs stimmen ueberein; aus dem
Batch ausgegebene InvitationSnapshots bestehen die echte Verifypruefung.
Private Fremdkommentare bleiben verborgen, ein entzogener Zugang wird beim
Folgeabruf abgelehnt.20 Teamoptionen benoetigen ebenso viele Queries wie1.

Flutter:75/75 gezielte Planungsfaelle gruen; der abschliessende Performanceblock
umfasst7 gruen gepruefte Faelle, darunter ein zusaetzlicher Fehler-/Retryfall.
Insgesamt76 unterschiedliche Faelle fuer Direktdetail, frische Kalenderdetails,
100er-Teamlookup, parallele Reads, Writegrenzen und Sitzungswechsel. Gezielte
Analyse fehlerfrei. Vollstaendiger aktueller Commonlauf mit den gemeinsamen
Readaenderungen:42 Tests/648 Assertions gruen.

Der serielle PostgreSQL-Nachlauf bestaetigt21 Planungs-/Statistik-/SFV-/
Einladungs-/Original-Web-/Mutationsfaelle. Der einzige zunaechst fehlgeschlagene
Menuefall hatte keine expliziten Moduloptionen fuer seinen neuen synthetischen
Tenant. Ausschliesslich dieser Test wurde um Teams-/Teamplanung-/Spesenoptionen
aus dem bestehenden Administrationsvertrag ergaenzt; seine Rechteerwartungen
bleiben unveraendert. Der gezielte Menue-Nachlauf ist inzwischen ebenfalls
gruen:1 Test/21 Assertions. Damit sind22 unterschiedliche PG-Faelle bestanden;
kein Produktrechte-Fallback fuer fehlende Moduloptionen wurde eingefuehrt.
Alle Fixtures sind zurueckgerollt, das PG-Fenster ist wieder frei.
Die abschliessende DEV-Nachmessung uebernimmt Root.

Statistik und Absenzen verwenden schon SQL-/Seitenprojektionen; hier wurde kein
unbelegter zweiter Umbau vorgenommen. Bei gesondert aktivierter Aufgebot-Push-
Faehigkeit bleibt deren bisherige frische Snapshotpruefung je Termin erhalten.
Die Erstseite enthaelt weiterhin die vollstaendigen urspruenglichen DTOs, keinen
schmaleren ungeprueften Ersatzvertrag. Keine konkrete DEV-Laufzeitverbesserung
behaupten, bevor Root das gehashte Delta bereitgestellt und nachgemessen hat.
