# Persoenliche SVS-Teamplanung: in Arbeit

Stand03.11.2026. Gesamtauftrag: alle Pakete fortlaufend umsetzen, beginnend mit
Teamplanung/My Teams. Noch kein fertiger Release und noch nicht auf DEV deployed.

MobilePlanningData/Controller plus drei GET-Routen teams/events/events/{event}
unter /api/mobile/v1/clubs/{tenant}/planning implementiert. Explizite DTOs fuer
die bestehende SVS-Darstellung, mit bestehenden TeamPlanningService-/Calendar-
Rechten, begrenzter Pagination, eingeladenen Terminen und privaten Kommentaren
nur fuer aktuelle Manager/vertretene Personen. Fremde Teams erweitern die
Grundabfrage nicht. MobileMiddleware/Origin-/Sitzungsgrenzen bleiben bestehen.

28 Tests/316 Assertions bestanden, einschliesslich eigener/fremder
Teilnehmerkommentare, Eltern-/Adminrechten, Fremdteam404, fremdem Teamfilter
ohne Daten und zu grosser Seitengroesse422. Nur isolierte synthetische Fixtures.
Keine produktiven Daten oder Datenbankmigration veraendert.

Offene Schreib-/Revisions-/Serien-/Statistik-/SFV-/MyTeams-Arbeit und
Weiterarbeitsnotizen: mobile/docs/PLANNING-MIGRATION-WORK.md. Keine Behauptung,
dass die bisherigen API-/Store-/Geraeteabnahmen damit ersetzt waeren.


## Erste versionierte Schreibaktionen (weiterhin Entwicklungsstand)

Anlage/Aenderung/Einzelabsage, Teilnahme und Anwesenheit ueber bestehende Services
implementiert; Tenant-Lock, gemeinsame Sitzungssperre und erneute Identitaets-/
Vereinspruefung vor jedem Write. Aktuelles Passwort/technische Tokens spielen
im Fachvertrag keine Rolle. Erwartete Revision ist Pflicht, kein Auto-Refresh.
Anwesenheit erst ab Terminbeginn, keine fremde Person durch Elternzugriff.

Additive Migration84 vorbereitet: attendance_comment_encrypted und
attendance_updated_at fuer team_participations/team_guests. Keine dauerhafte
Schemaanwendung, kein Deploy. Neuer Test auf echter ClubUnio-PG-Verbindung mit
synthetischen Fixtures und vollstaendigem Rollback einschliesslich Test-DDL.
Er bewahrt Teilnahmegruende beim Anwesenheitswechsel und prueft private
Auditgrenzen, Rollen, Fristen und alte Revisionen. Weitere Aktionen folgen,
bevor die bestehende native Planung freigeschaltet wird.

PG-Schreibtest mit26 Assertions erfolgreich; Rueckpruefung bestaetigt, dass auf
beiden Tabellen keine Testspalte verblieben ist. Bestehende Mobile-Gruppe:
28 Tests/316 Assertions erfolgreich. App:3 native Transport-/Screenfaelle und
19 Transportchecks erfolgreich, Analyse ohne Befund. Migration84 bleibt vorbereitet.


## Fortschritt: Gaeste

Gastanlage, versionierte Zu-/Absage und Anwesenheit sowie Entfernen sind im
persoenlichen Vertrag und im vorhandenen Repository angebunden. Gastrevisionen
werden getrennt von Mitgliedsrevisionen aus den gelesenen Termindetails gehalten.
Nach Loeschen darf die alte Gastrevision keinen weiteren Request erzeugen.
Kein automatisches Neulesen oder Wiederholen einer unsicheren Schreibaktion.

PlanningGuestService bleibt fachliche Quelle: frische Managementrechte fuer den
konkreten Termin, Gast-/Terminzuordnung, Revision, Kapazitaet und Audit.
Anwesenheit vor Terminbeginn gesperrt, getrennte verschluesselte Notiz gemaess
vorbereiteter Migration84. Neue Gaeste werden ohne fingierte Anwesenheitsnotiz
angelegt; diese kann anschliessend am bestehenden Gast bearbeitet werden.
Private Gastkommentare/Anwesenheitsnotizen nur fuer aktuelle Manager, nicht fuer
normale Spieler. Keine Texte im Audit. Urspruengliche SVS-Repositorymethoden
koennen den Vertrag verwenden; kein neuer Screen.

Vier native Planungs-Transport-/Screenfaelle und Analyse ohne Befund. Ein
gezielter PostgreSQL-Gasttest mit20 erfolgreichen Assertions prueft Anlage, stale Revision, falschen Termin,
Anwesenheitszeitpunkt, Spielergrenzen, getrennte Notizen und wiederholte Loeschung.
Alle Testdaten und DDL werden zurueckgerollt. Keine dauerhafte Migration,
DEV-Freischaltung oder neue native Abnahme dieses Zwischenstands.

Unmittelbare Fortsetzung: Absenzen mit originalem Listen-/Editorablauf,
Revisionen und korrekten Auto-Abmelde-/Wiederherstellungszaehlern; danach
Serien/Statistik/SFV und My Teams. Gesamtauftrag bleibt aktiv.


## Fortschritt: Absenzen mit SVS-Zeitfenstern

Bestehende TeamAbsencesView samt Editor unveraendert ueber das persoenliche
Repository angebunden und im Widgettest durch Bearbeiten/Speichern geprueft.
GET/POST planning/absences und PATCH/DELETE planning/absences/{id}: explizite
DTOs, begrenzte Pagination und Personenfilter auf bereits berechtigter Abfrage.
Trainer duerfen berechtigte Teamabsenzen bearbeiten, aber nicht allein deshalb
loeschen. Personentausch an einer bestehenden Absenz wird abgewiesen. Gruende
bleiben verschluesselt und gelangen nicht in das Metadatenaudit.

SVS speichert Uhrzeiten, der bisherige ClubUnio-Vertrag nur inklusive Tage.
Daher additive Migration85 vorbereitet: optionale starts_at/ends_at mit
Paar-/Reihenfolge-Constraint. Genaue mobile Zeitfenster verwenden dieselbe
strikte Ueberschneidung wie SVS (Absenzbeginn < Terminende und Absenzende >
Terminbeginn). Ganztagszeilen ohne neue Felder behalten ihr bisheriges Verhalten.
Der Adapter sendet Zeiten mit UTC-Offset; die Tagesfelder werden in Zurich
abgeleitet. Keine stille Ausweitung einer kurzen Absenz auf den ganzen Tag.
Datumsbasierte Web-/API-Editoren duerfen eine genaue Absenz weiterhin ohne
Zeitverlust kommentieren; eine Datumsverschiebung wird dort explizit abgewiesen
und muss im vorhandenen App-Editor vorgenommen werden. Web-Zeiteditor bleibt
vor dauerhaftem Rollout zu ergaenzen oder als sichtbare Grenze zu dokumentieren.

Synchronisationszaehler werden unter dem Vereinslock aus den tatsaechlichen
Source-Wechseln der zukuenftigen Teilnahmen ermittelt. Fristgesperrte Termine,
Trainerentscheidungen und ueberlappende Absenzen bleiben in der Fachlogik.
Beim Aufheben wird die vorherige Teilnahme samt verschluesseltem Kommentar
wiederhergestellt. Keine erfundenen Erfolgszaehler. Eigene Absenzrevisionen im
Transport, keine fremden Gast-/Teilnehmerrevisionen; ein spaeter eintreffender
alter Listenrequest kann einen entfernten Snapshot nicht wiederbeleben.

Nachweise:7 Flutter-Transport-/Originalscreenfaelle bestanden, einschliesslich
echtem Originaleditor; Analyse ohne Befund. Mobile-Backendgruppe29 Tests/
340 Assertions bestanden. Neuer PG-Test und sechs bestehende Absenzregressionen:7 Tests/91 Assertions
bestanden. Ein zuvor versehentlich in den Ueberlappungstest kopierter Absage-
block mit undefinierten Variablen entfernt; der richtige Absagetest bleibt erhalten.
19 bestehende API-/Sitzungs-/Mandantengrenzen weiterhin erfolgreich.

Direkte Test-DDL auf der gemeinsam verwendeten Absenztabelle wurde zweimal
nach5 Sekunden Locktimeout abgebrochen (bestehende fremde Lesesperre). Keine
fremde Sitzung beendet. Der neue PG-Test erstellt stattdessen eine leere temporaere
Tabelle mit LIKE INCLUDING ALL, wendet exakt die aufgezeichnete Migration85-DDL
auf diese an und leitet nur team_absences-Querybuilderzugriffe dorthin um.
Echte PostgreSQL-Fachlogik, persoenliche HTTP-Requests und synthetische Fixtures,
vollstaendiger Rollback/ON COMMIT DROP; keine Kopie von Bestandsdaten.
Dauerhafte Schemaanwendung84/85 und DEV-Release bleiben ausstehend.

Naechster fachlicher Schritt: Serien/Batchanlage, Statistik/SFV, vollstaendiger
Managerkontext und My Teams. Planung noch nicht im persoenlichen Einstieg auf
den teilweise angebundenen Originalscreen umgeschaltet. Gesamtauftrag aktiv.


## Fortschritt: atomare Serienanlage

Die bestehende Wochentags-/Bis-Datum-Auswahl verwendet im persoenlichen
Repository jetzt einen Batch-Writer. Bestehender Screen, Formular und
Rueckmeldung bleiben erhalten. Der alte sequenzielle PHPMaker-Transport wird
hier nicht benutzt. Kalenderfortschaltung verwendet DateTime(Jahr, Monat, Tag+1)
statt 24-Stunden-Spruengen; der letzte Tag einer Serie darf nach der
Sommerzeitumstellung nicht verschwinden. Explizite UTC-Zeitpunkte im Vertrag.
Maximal104 Termine pro Auftrag, lokal vor HTTP und serverseitig begrenzt.

POST planning/event-batches validiert alle Termine und speichert sie unter dem
bestehenden Vereins-/Sitzungslock ueber TeamPlanningService in einer Transaktion.
Ein Fehler rollt Termine, Audit, interne Nachrichten und Auftrag gemeinsam
zurueck. Keine vom Client frei uebernommene SerieID; Server erzeugt den Serien-
schluessel und begrenzt den Auftrag auf eine aktuell verwaltbare Mannschaft.
Ohne explizite Frist schliesst die Teilnahme zum Terminbeginn, wie im SVS-Ablauf.

Additive Migration86 vorbereitet: mobile_planning_commands mit persoenlichem
Vereins-/Konto-/UUID-Schluessel, Operation, SHA256 des normalisierten Vertrags
und Ergebnis-IDs. Keine Passwoerter, Teilnehmergruende oder Formulartexte darin.
Gleicher Schluessel und gleiche Daten liefern dieselben Termin-IDs; andere Daten
unter demselben Schluessel409. Rechte werden auch bei Wiederholung frisch
geprueft. Entfernte Termine duerfen durch Wiederholung nicht neu entstehen.
Der App-Writer behaelt eine fehlgeschlagene Auftragskennung waehrend seiner
Repository-Lebensdauer fuer einen ausdruecklichen erneuten Versuch; niemals
automatische Wiederholung. Nach bestaetigtem Erfolg ist eine neue identische
Serienanlage ein neuer bewusster Auftrag. Persistente Entwurfswiederaufnahme
nach App-Beendigung und Laufzeitmessung grosser Serien auf DEV noch offen.

Nachweise:8 persoenliche Transport-/Originalscreenfaelle, darunter unterbrochene
Antwort mit gleicher Auftragskennung und kalenderbasierte Wochentage;11
bestehende Identitaets-/Fristfaelle bestanden. PostgreSQL-HTTP-Test mit27
Assertions prueft Atomaritaet, Wiederholung, geaenderte Auftragsdaten,
Kontentrennung, Rechte, doppelte Zeitpunkte, Grenzen und Standardfrist.
Migration86-DDL auf temporaerer Auftragstabelle mit temporaeren FK-Eltern fuer
nur die synthetischen IDs; echte Benutzer-/Teamrechte verwenden die bestehenden
ClubUnio-Tabellen. Alles zurueckgerollt, kein dauerhaftes Schema/DEV-Release.

Offen: Serien aendern/entfernen, Statistik/SFV, Anwesenheitsstapel, Managerkontext
und My Teams. Schema84/85/86 und vollstaendiger Planungsrelease weiterhin offen.
