# ClubUnio: Teamplanung und Absenzen im Web

Stand: 12.10.2026. Ausschliesslich ClubUnio, eigene PostgreSQL-Datenbank und
Pi-Deployment. PHPMaker DEV/PROD bleiben unveraendert. Keine mobile API und
keine SaaS-Produkterweiterung.

Release24 ergaenzt Kalenderansichten, Filter und geschuetzten ICS-Export:
Details/Migrationshinweise siehe CLUBUNIO-CALENDAR.md. Keine neue DB-Migration.

Lokaler UI-Fix 12.10.2026 nach Release24: SFV-Seitennavigation verwendet eine
eigene Blade-Ansicht mit deutschen Beschriftungen, Seitenzahlen und festen
40px-Bedienelementen/20px-Pfeilen statt ungestylter Tailwind-Standardpagination.
Filterparameter bleiben erhalten; keine DB-/API-Aenderung, Auslieferung mit Release25.

## Erweiterung Release23: SFV, Gaeste und Serien

- **SFV-Spiele** in der Teamplanung: Datumfilter, manueller Liveabruf, gespeicherte
  Spielvorschau und bewusste Uebernahme/Aktualisierung eines Spiels. Kein neuer
  Cronjob und keine unbeaufsichtigte Ueberschreibung von Teamterminen.
- Authentifizierung ueber SFV /api/token, lesender Abruf /api/club/schedule.
  Ausschliesslich erlaubte SFV-HTTPS-Hosts, keine Redirects; Connect-Timeout5s,
  Request-Timeout35s. Abrufbereich maximal186 Tage, Route3 Aufrufe/Minute.
  Fehler melden eine Referenz; Logs enthalten keine Credentials/Antworttexte.
- Konfiguration aus verschluesselten tenant_settings (Bereich integrations):
  SFV_API_BASE_URL, SFV_APPLICATION_KEY/PASS, SFV_CLUB_ID, SFV_LANGUAGE,
  SFV_SEASON_ID/AUTO. Nur diese Lesekonfiguration ist aktiviert; bisherige
  Sync-/Cron-/Schreibschalter bleiben deaktiviert. Kein Token in Browser/URL.
  Automatische Saison wechselt am1.Juli zum Folgejahr (Europe/Zurich).
- SFV-Zuordnung nur mit passender teams.sfv_team_id und einer verantworteten
  TeamSaison. Bei mehreren passenden TeamSaisons zuerst Mannschaft/Saison waehlen.
  Heim/Auswaerts bevorzugt Team-ID; Sissach-Ort nur ohne bekannte eigene Team-ID.
  Neue Uebernahme: ANMELDEN,18 Plaetze, Frist zwingend manuell setzen; Dauer als
  editierbarer2h-Vorschlag. Bestehende Teilnahme-/Kapazitaetsregeln bleiben erhalten.
  Verbandstatus wird angezeigt; Absagen muessen bewusst bestaetigt werden.
- Eindeutiger Index tenant/team_season/sfv_match_id und getrennte Revisionen fuer
  SFV-Spiel und Termin verhindern Dubletten und veraltete Bestaetigungen. Ein
  erneuter Liveabruf aendert nur den SFV-Zwischenspeicher, nicht die Teamtermine.
  Fehlende Spiele werden nicht automatisch geloescht/abgesagt.
- **Gastspieler** mit Vor-/Nachname ohne Mitgliederdatensatz, Teilnahme,
  Anwesenheit, verschluesselter Bemerkung, Revision und Soft-Delete. Nur
  Mannschaftsverantwortliche verwalten sie. Kapazitaet zaehlt Gaeste mit.
- **Mitglied zusaetzlich einladen**: Namenssuche ab2 Zeichen, maximal20 Treffer,
  nur aktive Mitglieder und nur Identifikationsfelder. Einladung gewaehrt allein
  Zugriff auf diesen Termin, nie auf die gesamte fremde Mannschaft. Eigene/
  Eltern-Absenzen werden auch fuer Einladungen abgeglichen. Entfernen beendet
  diesen Zugang; Teilnahmedaten bleiben fuer Nachvollziehbarkeit erhalten.
- **Serie bearbeiten**: neue Wochenserien bekommen gemeinsame UUID, bestehende
  Snapshot-Serien behalten ihre nachgewiesene SerieID. Vorschau fuer Titel, Ort,
  Zeitverschiebung inklusive Frist, Kapazitaet oder Absage. Auswahl ab Datum;
  vergangene, fristgesperrte, abgesagte und einzeln bearbeitete Termine werden
  ausgelassen. Keine automatische Gruppierung anhand Titel/Datum. Jede normale
  Einzelbearbeitung einer Serie markiert eine geschuetzte Ausnahme.
- Bestaetigung vergleicht eine serverseitig in der Session gehaltene Vorschau
  mit aktuellen Daten; atomare Transaktion mit Vereinssperre. Keine Teilupdates
  bei Konflikten. Teilnahme/Anwesenheit und Trainerentscheidungen bleiben erhalten;
  Absenzabgleich und interne Nachrichten nutzen die bestehende Geschaeftslogik.

### Migration / Abnahme dieses Pakets

Migration000024 fuegt sfv_schedule, team_guests, team_invitations sowie
series_key/series_exception/sfv_match_id hinzu. Bestehende17 Serientermine und
7 SFV-Verknuepfungen aus legacy_prod uebernommen. Migration000025 erhaelt
explizite historische Teilnehmer ausserhalb heutiger Teamzuordnungen als
Einzeleinladungen (20), nur fuer noch aktive Mitglieder. Snapshot-Gasttabelle hat0
Eintraege. Kein neuer PROD-Abzug und keine PHPMaker-Datenbankaenderung.

Live-Lesetest12.10.2026:63 SFV-Spiele abgerufen. Keine echten Teamtermine oder
Teilnahmen fuer den Test veraendert. Fachtests mit Rollback pruefen zusaetzlich
Gaeste/Kapazitaet/Privatsphaere, Einzeleinladungen/Absenzabgleich, Serienvorschau/
Revisionen sowie SFV-Fehler, Dublettenschutz und fehlende automatische Updates.
Die Browserpruefung bestaetigt SFV-Formular und Serienvorschau; keine Vorschau
wurde auf echte Termine angewendet. Echte fachliche Abnahme durch Benutzer folgt.
Regression Release23:128 Tests/1270 Assertions erfolgreich, dazu der ergaenzte
Test fuer Vergangenheit/Fristsperre/Serienabsage1/6. Tests nutzen separate Vereine
und vollstaendigen Rollback, SFV-Fehlerfaelle verwenden HTTP-Fakes.
Abschliessender kompletter Teamplanungstest nach den letzten Korrekturen:
14 Tests/116 Assertions erfolgreich.

Wiederherstellung: Release22 zurueckschalten, additive Tabellen/Spalten behalten.
Bei zukuenftigem vollstaendigem Neuimport muessen Serien-/SFV-Mapping und externe
Teilnehmer nach dem Snapshotimport erneut kontrolliert/abgebildet werden; diese
Migrationen sind kein Ersatz fuer den spaeteren Cutover-/Delta-Importprozess.

## Bedienung

- Backoffice: Mannschaften > Teamplanung; Portal: Teamplanung.
- Liste mit Mannschaft, Suchtext, Zeitraum und Statuszaehlern. Alle Filter
  bleiben bei Suche/Pagination erhalten; Standardzeitraum beginnt heute.
- Termine erstellen, bearbeiten und ueber Status absagen. Pflichtfrist,
  Beginn/Ende in Europe/Zurich, Ort, Beschreibung, Modus und Kapazitaet.
- Neue Termine koennen bis zu 26 Wochen wiederholt werden. Es entstehen
  einzeln bearbeitbare Termine; Schweizer Ortszeit bleibt auch bei DST gleich.
- Teilnehmerdetail: Spieler und Staff der TeamSaison, Teilnahme, Anwesenheit,
  berechtigte Bemerkungen. ANMELDEN beginnt implizit Offen, ABMELDEN Dabei.
- Absenzverwaltung: eigene Absenzen und berechtigt zugeordnete minderjaehrige
  Kinder erfassen, aendern und soft-loeschen. Verantwortliche lesen nur ihre
  zugeordneten Mannschaften, bearbeiten aber keine fremden Absenzen.
- Auswahlwerte sind tabellenbasiert. Admin/Office kann Bezeichnungen, Aktiv
  und Reihenfolge bearbeiten sowie Termin- und Absenzarten erweitern.
  Technische Workflowcodes fuer Status/Modus bleiben geschuetzt.
- Gleiche Bezeichnungen verwenden die zentrale Statusfarbe, sonst denselben
  deterministischen Farb-Fallback. Text bleibt neben der Farbe sichtbar.

## Rechte und Geschaeftsregeln

Alle Zugriffe pruefen aktive Konten, Vereinszugehoerigkeit, aktive Mitglieder
und genehmigte Kontoverknuepfungen ueber MemberAccessService.

| Rolle | Termine | Teilnahmen | Absenzen |
| --- | --- | --- | --- |
| Admin / Geschaeftsstelle | Alle aktiven TeamSaisons | Verwalten, Fristausnahme | Lesen im Teamscope; eigene/berechtigte Kinder bearbeiten |
| Trainer / TL | Nur verantwortete Teams verwalten | Eigenes Team, Fristausnahme | Teams lesen; nur eigene/berechtigte Kinder bearbeiten |
| Mitglied / Eltern | Eigene Teams lesen | Eigene/berechtigte Kinder vor Frist | Eigene/berechtigte Kinder bearbeiten |

Nicht aufgebotene Spieler duerfen ihren Status nicht selbst aendern.
Abgesagte Termine akzeptieren keine Teilnahmeaenderungen. Nach Frist/Beginn
muessen Spieler/Eltern das Trainerteam kontaktieren. Verantwortliche koennen
Teilnahme/Anwesenheit weiter korrigieren. Kapazitaetspruefung und Aenderungen
liegen innerhalb derselben Transaktion.

Revisionen verhindern veraltete Formulare. Gemeinsame Schreibsperre pro Verein
serialisiert Termine, Teilnahmen und Absenzabgleich. Fremde IDs sind kein
Zugriffspfad. Alle Schreibformulare sind sessionauthentifiziert und CSRF-geschuetzt.

## Absenzabgleich

Ganztagesintervalle einschliesslich Von/Bis. Automatische Abmeldung fuer noch
offene zukuenftige Termine; vorhandener Status/Bemerkung werden gespeichert.
Bei Aenderung/Loeschung wird die Gesamtheit aller aktiven Absenzen betrachtet.
Erst ohne weitere ueberlappende Absenz wird der Originalzustand wiederhergestellt.
Eine vorher fehlende Teilnahmezeile wird wieder entfernt (impliziter Modusstatus).
Trainerentscheidungen und Nicht-Aufgebote bleiben geschuetzt. Neue/verschobene
Termine durchlaufen denselben Abgleich. Gesperrte Termine bleiben unveraendert;
die Rueckmeldung nennt ihre Anzahl und verweist an das Trainerteam.

Gruende/Bemerkungen sind verschluesselt gespeichert und werden nur fuer
Eigentuemer, berechtigte Eltern oder Verantwortliche entschluesselt. Fremde
Kommentare gelangen nicht in die View-Daten. Audit speichert Objekt/Akteur und
Revisionen, keine Gesundheitsgruende. Nachrichten enthalten keine Absenzgruende.

## Benachrichtigungen und Betrieb

Bestehende editierbare Vorlagen team.created/changed/cancelled/reminder/deadline
werden verwendet. Interne Benachrichtigungen gehen an aktive verknuepfte Spieler,
Staff und berechtigte Eltern. Ein Link fuehrt zum erneut berechtigungsgeprueften
Termin. Kein FCM-Versand, keine echte externe E-Mail aus diesem Modul.
Die bestehende Testmail-Regel fuer andere Module bleibt admin@svsissach.ch.

`clubunio:planning-reminders` wird alle fuenf Minuten vom vorhandenen Pi-Laravel-
Scheduler aufgerufen. Frist-/Terminerinnerung im 24h-Fenster; je Ereignis und
Terminrevision idempotent. Aenderungen duerfen eine erneute Erinnerung erzeugen.
Keine neuen Hosting-Cronjobs, keine HTTP-Cron-API.

## Migration und Wiederholung

Migration000022: planning_options, team_events, team_participations,
team_absences, team_planning_messages, zusammengesetzte Vereins-FKs/Indizes.
Migration000023: explizite Schweizer Zeitinterpretation fuer noch unveraenderte
Snapshot-Termine. Schreibwerte/Filter tragen danach immer einen Zeitzonenoffset;
die Zeitzone des gehosteten PostgreSQL wird nicht vorausgesetzt.

`clubunio:planning-import` uebernimmt einmalig nur den vorhandenen legacy_prod-
Snapshot; Importmarker team-planning-v1. Kein neuer Live-PROD-Abzug.
Aktive Quelldatensaetze werden abgebildet, geloeschte bleiben im unveraenderten
Snapshot. Nicht aufloesbare Verknuepfungen brechen die Transaktion ab.
Verifiziert: Quelle27 Termine/471 Teilnahmen/2 Absenzen; aktiv importiert26/451/2.
Die abweichenden Mengen sind geloeschte Termine/Teilnahmen, kein fehlgeschlagener
Import. Die gehostete DB meldet America/Los_Angeles; explizite Offsets sind daher
auch bei weiteren Modulen zu beachten, ohne deren Zeitsemantik blind umzustellen.
Nicht als Absenz markierte alte Teilnahmen werden vorsichtshalber wie manuelle
Trainerentscheidungen geschuetzt, da die historische Herkunft nicht sicher ist.
Quelldaten inklusive alter Serien-/Verband-IDs und Anwesenheitskommentare bleiben
vollstaendig im Snapshot erhalten, sind noch nicht alle in Webfelder uebertragen.

## Abnahme und naechste Ausbauschritte

Automatisierte Tests: Rechte/Eltern/Portalguard, fremde IDs, private Kommentare,
Fristen/Trainer-Ausnahme, Kapazitaet, Revisionen, ueberlappende Absenzen,
Wiederherstellung, neue/verschobene Termine, DST-Wiederholungen, interne Nachrichten
und doppelte Scheduleraufrufe. Browserabnahme: Liste, Detail und mobile Breite.
Testlaeufe zusammen: 131 Tests / 1264 Assertions erfolgreich (115/1160 bestehende
ClubUnio-Integration, 9/64 Teamplanung, 7/40 weitere Tests). Alle DB-Fachtests mit
separaten Testvereinen und vollstaendigem Rollback; keine echten Teilnahmen geaendert.
Echte Rollentests durch Fachanwender folgen auf ClubUnio, ohne PHPMaker-Umschaltung.

Kein behaupteter vollstaendiger Ersatz aller spaeter ergaenzten Alt-API-Funktionen:
SFV-Liveimport, Gastspieler/Einladungen und Serien-Massenbearbeitung sind mit
Release23 als Webfunktionen ergaenzt (siehe oben). Kalenderexport und
KI-Trainingsplaene bleiben Folgepakete. Mobile API und SaaS bleiben geparkt.

Rollback: voriges Pi-Release zurueckschalten, additive Tabellen behalten.
Neue Fachdatensaetze nicht mit erneutem Snapshotimport ueberschreiben. Fuer den
spaeteren echten Cutover: aktueller Snapshot, Mapping-/Mengenkontrolle, Rechte-
und DST-Abnahme sowie gesonderte Freigabe und Wiederherstellungsprobe erforderlich.
