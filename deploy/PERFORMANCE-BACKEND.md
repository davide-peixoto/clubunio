# Mobile: Chat- und Spesenprojektionen

Stand 04.11.2026. Gezieltes Backenddelta auf Basis der bestehenden persoenlichen
API; keine Migration, neuen Abhaengigkeiten oder Provideroperationen. Bestehende
Mandanten-, Mitgliedschafts-, Saison-, Hash-, Revisions- und Mutationspruefungen
bleiben verbindlich. Laufzeitfreigabe und Commit/Sync koordiniert Root.

## Nachgewiesene Engpaesse und Umsetzung

ChatAttachments::forMessage las zuvor alle Spalten einschliesslich der privaten
verschluesselten Dateibytes fuer jeden Nachrichten-Datensatz. Die normale
Nachrichtenliste braucht nur Metadaten. forMessages projiziert diese explizit
und laedt sie einmal pro Seite. ChatService::messageDtos buendelt Autoren,
Anhangmetadaten, Polls und Votes; Moderationsrechte werden frisch je betroffener
Konversation statt je Nachricht bestimmt. Einzelmethoden bleiben kompatibel.
ChatControls::changes laedt Nachrichtendaten gesammelt und behaelt Reihenfolge,
Aenderungscursor, neueste Revision, Loeschungen und private Eintrittsgrenze.
Leere Aenderungsseiten fuehren keine zusaetzliche Nachrichtenprojektion aus.

ChatService::conversations fuehrte je Team zwei ensure-Queries, je Chat weitere
Membership-, Unread- und wiederholte Team-/Adminpruefungen aus. Die Liste nutzt
jetzt ausschliesslich methodenlokale, frisch autorisierte Team-/Staffqueries:
fehlende Scope-Keys werden gesammelt angelegt; vorhandene Konversationen werden
beim Lesen nicht erneut geschrieben. Memberships und Unreadzahlen kommen aus
Batchqueries. Kandidaten ausserhalb der100 Provisionierungsteams werden gegen
ihre echten aktuellen Scopes geprueft. Direkte und Eventkonversationen behalten
ihre frischen bisherigen Kontakt-/Block-/Planungspruefungen.

Neue oder verlassene Memberships erhalten wie bisher die aktuelle Join-/Read-
Grenze. Aktive Memberships behalten ihre Grenzen und Stummschaltung. Falls ein
konkurrierender Abruf seit dem ersten SELECT bereits beigetreten ist, ueberschreibt
das bedingte Upsert dessen aktive Read-/Joinwerte nicht. Kein Permission- oder
Membershipcache ueber Requests hinweg. Der einzelne membership()-Detailweg
verwendet eine schon frisch gelesene aktive Zeile ohne identischen Zweit-SELECT.

MobileExpenseOcrProvenance::fields verursachte je Position eine Historienabfrage
und bei vorhandenem Nachweis weitere Draft-/Uploadreads. Der Uploadread lud
encrypted_bytes vollstaendig, obwohl nur dessen Existenz geprueft wurde.
fieldsForLines laedt die jeweils neueste Historie und Quellmetadaten gesammelt;
die Pending-Bytepraesenz ist ausschliesslich ein SQL-Boolean. Alle bisherigen
Tenant-/Account-/State-/Line-/Revision-/Hash-/Dateireferenzpruefungen gelten
unveraendert. MobileExpenseData::detail verwendet die Batchprojektion.

Die Spesenliste verwendet zudem ExpenseService::readCapabilities einmal fuer
ihre geladene Seite. MobileExpenseData::receipt uebernimmt optional genau diese
Read-Capabilities; fehlende Eintraege bleiben geschlossen. Die bisherigen
edit-/target-/save-/transition-Pruefungen fuer echte Mutationen bleiben bestehen.

## Gezielte Nachweise

Common-/Provenienz-Boundaryregression:43 Tests/652 Assertions gruen. Darunter
drei neue Chatfaelle:40 Nachrichten haben dieselbe SQL-Zahl wie eine Nachricht;
Anhangqueries selektieren keine verschluesselten Bytes;20 Team-Scopes/41 Chats
haben dieselbe SQL-Zahl wie ein Team/3 Chats. Ein warmer Listenabruf schreibt
keine Konversationen oder Memberships. Staffzugriff, Eventloeschung, Direktblock,
Accountwiderruf und bestehende Poll-/Cursor-/Revisions-/Downloadpruefungen bleiben
abgedeckt. Die Listenfaelle sind nach dem bedingten Upsert erneut gruen.

Abschliessender PostgreSQL-Projektionslauf:2 Tests/44 Assertions gruen.
40 Provenienzpositionen benoetigen wie eine Position exakt3 Queries; die
Pending-Payloadpraesenz liefert auf PostgreSQL ausdruecklich bool(true), keine
Cipherbytes. Neueste Historie, fremder Tenant/Account, ersetzte Quelle,
abweichender Hash, Revision, Objektbindung, fehlende Pendingbytes und echte
publizierte Dateireferenz bleiben geprueft. Der zweite Fall ruft die echte
Chatliste mit PostgreSQL-Unique-/Composite-FKs auf: aktive Read-/Joinwerte und
Muted bleiben erhalten, Rejoin setzt die Grenze korrekt, ein deterministisch
eingeschobener konkurrierender Readfortschritt wird nicht ueberschrieben;
Fremdtenant-FK und entzogener Zugang bleiben geschlossen.

Vier bestehende persoenliche Spesen-/OCR-/Indexfaelle sind nach Integration
ebenfalls gruen; darunter Herkunftshistorie, ungueltige Claims und echte
Sicht-/Workflow-/Bankprivatsphaere mit dem neuen Index-Batchhook. Der getrennte
ExpenseService-Helpernachweis umfasst4 PG-Faelle/235 Assertions: Rollen-/Status-
Paritaet, geschlossene Scopes, frische Widerrufe, gleiche Queryanzahl1vs20 und
ueber5x weniger Queries als die alte20er-Trainerprojektion.

Alle neuen PG-Schemata sind TEMP mit ausschliesslich temporaeren FK-Eltern und
Rollback; keine permanente DDL. Reale synthetische Elternfixtures werden
vollstaendig zurueckgerollt. Testschritte haben weder Provider noch reale
Datei-/Nachrichtenuebertragungen ausgeloest. Keine Millisekunden- oder
DEV-Speedupbehauptung aus lokalen Querycounttests ableiten. Root uebernimmt
den gesonderten gehashten Release und die Runtime-/Kernelgates.

## Verbleibende separate Hebel

Der Netzwerkaudit identifizierte vier ruhige Chatpolling-Requests je6s
(Changes, neue Nachrichten, letzte Seite, Read), doppelte Spesendetail-GETs und
mehrfache Optionsabfragen. Die Appoptimierung dieser Stellen wird getrennt von
Root/den Appagents gefuehrt. Die vollstaendige Spesenliste wird im bisherigen
Adapter weiterhin in100er-Seiten vor Rueckgabe geladen; echte sichtbare
Pagination braucht einen eigenen kompatiblen UI-/Repositoryvertrag.

MobileMiddleware/MobileAccess/MemberAccess wiederholen frische Account-/
Membershipreads; der Maintenancecheck liest Settings pro Fachrequest.
Moegliche weitere Optimierungen muessen strikt requestlokale Readprojektionen
nutzen. Tenant-/Sessionlocks und Reauth bei Writes duerfen nicht durch einen
TTL- oder globalen Rechtecache ersetzt werden. Direkte Kontaktpruefungen und
Bankkontoobjektpruefungen bleiben in diesem Delta bewusst frisch.
