# Igloohome in ClubUnio Mobile

Stand: 23.11.2026. Benutzerauftrag: Garderobe, Ballhaus und Bus nach den
Ablaufen der bestehenden SVS-App einschliesslich Notfallfunktion.

## Referenz und Fachablauf

Die Original-App unter C:/Projects/SVS/app wurde ausschliesslich gelesen.
Massgeblich: lib/features/profile/{data,domain,presentation}/igloo_keybox*
und docs/bus-keybox.md. Die vorhandenen Masken werden mit persoenlichen
ClubUnio-Repositories weiterverwendet, ohne Providersecrets in der App.

| Box | Normaler Ablauf | Zugriff |
| --- | --- | --- |
| Garderobe | Persoenlicher 7-Tage-Code | Aktives Konto, aktueller Verein, konkrete Boxfreigabe bzw. bestehendes Verwaltungsrecht |
| Ballhaus | Persoenlicher 7-Tage-Code | Dieselbe serverseitige Boxpruefung |
| Bus | Reservationscode; Beginn minus4h, Ende plus4h, volle Stunden, Europe/Zurich; spaete Erstellung ab aktueller Stunde | Genehmigte aktuelle Reservation, eigener Antragsteller oder berechtigter Teamstaff; allgemeine Busbox nur gemaess bestehenden Verwaltungs-/TL-Rechten und Boxfreigabe |

Profil > Schluesselbox ist der mobile Einstieg. Busreservationen haben eine
eigene Codekarte. Normale Codes werden verschluesselt im Backend wiederverwendet;
das wiederholte Oeffnen erzeugt keinen neuen Code. Box-, Account-, Session-,
Grant- und Reservationsrevisionen werden frisch geprueft. Codeauftraege sind
idempotent, UUID-gebunden, mandanten- und kontogebunden.

## Notfallcode

Notfallcodes werden nur verschluesselt je Box im Backend erfasst, niemals ueber
iniApp, Boxlisten oder normale Code-GETs an die App ausgegeben. Das geschuetzte
Boxformular bietet ein optionales Passwortfeld; leer laesst den bestehenden
Wert bestehen. Model-Serialisierung, Validierungs-Flash und Audit enthalten
keinen Klartextcode.

Mindestens drei bestaetigte Ausfaelle VOR dem physischen PIN-Aufruf sind noetig:
OAuth5xx oder Verbindungsfehler zum OAuth-Dienst. OAuth4xx, fehlerhafte Tokens,
fehlende Rechte, ungueltige Daten, Speicherfehler und ungewisse PIN-Aufrufe
zaehlen nicht. Ein PIN-Timeout bleibt attention und darf weder automatisch
wiederholt noch als Beleg fuer einen sicheren Ausfall behandelt werden.

Bestaetigte Fehlschlaege bleiben terminal failed. Nur ein weiterer bewusster
Erstellklick mit neuer UUID darf einen neuen Versuch erzeugen; Wiederholung
einer alten UUID gibt denselben abgeschlossenen Auftrag zurueck. Die partielle
Scope-Unique laesst nur einen nicht fehlgeschlagenen Auftrag je Zeitraum zu.

Nach dem dritten bestaetigten Fehler fordert die App ueber einen ausdruecklichen
POST den Notfallcode an. Das Backend prueft Session, exakte Box-/Reservations-
revision, aktuelle Rechte, denselben Fehlersnapshot und beim Bus das aktuelle
Reservationsfenster. Die erste Offenlegung setzt eine feste Deadline von maximal
60 Sekunden; erneute Abfragen verlaengern sie nicht. Normale Antworten enthalten
nur providerFailure/emergencyAvailable, niemals den Code.

POST clubs/{tenant}/keyboxes/{box}/emergency-code:
keybox_revision. POST clubs/{tenant}/bus/reservations/{reservation}/emergency-code:
keybox_revision und reservation_revision. Antwort: emergencyCode,
emergencySeconds (1..60), emergencyExpiresAt. Alle Antworten bleiben private/no-store.

Die App validiert relative und absolute Frist, behandelt den Notfallcode nicht
als normalen PIN, speichert ihn nicht im Credential-/PINcache und entfernt ihn
spaetestens beim Timerablauf, Hintergrundwechsel oder Schliessen. Beim Wechsel
der ausgewaehlten Profilbox wird die vorherige Offenlegung verworfen.

Die Anzeigezeit ist keine physische Codegueltigkeit. Ein bereits bekannter
statischer Notfallcode bleibt am Schloss verwendbar, bis er dort geaendert wird.
Ein Widerruf nach Offenlegung markiert physische Abhilfe als erforderlich und
sperrt weitere Ausgabe auch nach Ablauf der urspruenglichen Auftragsfrist.
Eine Datenbankaenderung behauptet keine erfolgreiche Hardwareloeschung.

## Konfiguration und Rollout

Native Ansicht und geschuetzte Boxverwaltung wurden auf DEV und Stage aktiviert;
PROD hatte beide Schalter bereits aktiv. Alle drei Instanzen hatten zum Audit
0 persoenliche Boxen, 0 Freigaben und 0 Codeauftraege. SV-Sissach-PROD hatte auch
keine Provider-Zugangsdaten im Bereich keybox. Das erklaert die fehlende nutzbare
Integration; es gab noch keine migrierte Geraetezuordnung.

Additive Migration 2026_11_23_000160_mobile_keybox_emergency: Cipherfeld an Boxen,
Fehler-/Offenlegungsmetadaten, terminal failed und partielle Scope-Unique.
CLUBUNIO_KEYBOX_EMERGENCY_ENABLED standardmaessig false, erst nach Migration
aktivieren. Keine neue Hardwareaktion durch Schema oder Aktivierung der Ansicht.
Private gezielte Datei-/Env- und PG-Sicherungen liegen auf den jeweiligen Servern.

Fuer den Betrieb fehlen weiterhin die sicheren Zugangsdaten, die drei echten
Geraete-IDs und die zugehoerigen Notfallcodes. Diese im geschuetzten Backend
unter persoenlichen Schluesselboxen erfassen, nicht im Chat oder Repository.
Busbox zusaetzlich dem richtigen Bus zuordnen. Rollen-/Accountfreigaben fuer die
beiden normalen Boxen und die allgemeine Busbox festlegen. Providerprotokoll,
Tenant-/Box-Allowlist und Umgang mit physischem Offlinewiderruf muessen passen,
bevor echte Ausgabe- und Providerflags aktiviert werden. Keine realen Codes
wurden beim technischen Test erzeugt und keine Original-App-Konfiguration kopiert.

## Tests

Neue App-Notfallfaelle und bestehende Profil-/Busregressionen:21 Tests bestanden.
Analyse der sechs betroffenen Quellen/Testdateien sauber;2172 lokale Texte in
fuenf Katalogen und AST-Pruefung erfolgreich. Neue Backendlabels DE/EN ueber
JSON-Kataloge,3051 Schluessel konsistent. Lokaler gemeinsamer Backendablauf:
45 Assertions bestanden; alte sechs PG-Opt-in-Faelle lokal uebersprungen.

Live: php deploy/check-keybox-emergency.php --synthetic-rollback
clubunio_dev|clubunio_stage|clubunio_prod. Alle Provideraufrufe simuliert;
Fixturetransaktion wird zurueckgerollt. Prueft UUID-Replay, eindeutigen Scope,
drei Fehler, Nichtzaehlen von401, ungewissen PIN-Timeout, feste Frist, Widerruf,
Busfenster, Revisionskonflikte und Sessionentzug. deploy/check-mobile-keybox.php
--ready prueft acht geschuetzte Routen und weiter geschlossene Hardwareflags.

Native iPhone-/Hardwareabnahme und echte Geraetekonfiguration bleiben offen.

## Zusaetzliche Backend- und Authentifizierungspruefung

Geprueft sind serverseitiger OAuth Client-Credentials-Ablauf mit Basic Auth,
feste Igloohome-Ziele ohne Redirects, minimaler Hourly-Scope, geheime
mandantenbezogene Zugangsdaten, aktuelle persoenliche Sitzung und
Mandanten-/Boxrechte. Codeerstellung und Notfalloffenlegung pruefen die Sitzung
innerhalb der gesperrten Transaktion erneut. Das normale Keycloak-/MFA-Verfahren
wird nicht geaendert. Die PG-Fachfixtures schalten Keycloak-only ausschliesslich
in ihrem isolierten CLI-Prozess aus; das reale Stage-/PROD-Verfahren verlangt
weiter seinen gueltigen Security-Stamp. Anonyme echte HTTP-Aufrufe beider neuer
POST-Routen wurden auf allen drei Umgebungen mit401 abgewiesen.

Zwei Auditbefunde wurden behoben: Scope-Fingerprints enthalten jetzt die
Benutzer-ID, damit zwei unabhaengig berechtigte Benutzer denselben Zeitraum
nutzen koennen. Nach Offenlegung eines statischen Notfallcodes erkennt auch
eine Kontosperre oder verlorene Vereinsberechtigung die erforderliche physische
Abhilfe. Logout und reine Profilaenderungen erzeugen dagegen keine neue
physische Sperre. Die Verwaltungsanzeige zeigt offene statische Widerrufe auch
nach Ablauf des urspruenglichen Zeitraums.

Im Boxformular kann die Verwaltung eine bereits erfolgte physische Aenderung
explizit bestaetigen: neuer anderer Notfallcode plus Checkbox, bestehende Box,
unveraendertes Geraet und Begruendung. Erst diese ausdrueckliche Bestaetigung
quittiert die alten Notfalloffenlegungen. Einfaches Speichern oder ein leerer/
gleicher Code reicht nicht. Normale PIN-Risiken bleiben separat zu bearbeiten.

Schema und Ansicht/Verwaltung/Notfall-Unterbau sind auf DEV, Stage und PROD
bereitgestellt. Echte Provider-/PIN-Ausgabe bleibt mangels realer Konfiguration
geschlossen. Jede Instanz bestand die erste35-PG-Pruefung; der abschliessende
50-Punkte-Lauf umfasst die beiden Auditkorrekturen und die physische
Rotationsbestaetigung. Alle synthetischen Daten werden zurueckgerollt.

Produktionsverwaltung: https://app.clubunio.com/mobile-keyboxes, dort den
richtigen Verein waehlen und Providerzugang bzw. Boxen/Freigaben mit einem
berechtigten Backofficekonto erfassen. Fuer den echten Funktionstest werden
Client-ID/Secret sowie Geraete-IDs und Notfallcodes fuer Garderobe, Ballhaus und
Bus gebraucht. Danach enge Ausgabefreigabe und Hardwaretest koordinieren. Die
App benoetigt fuer die neue Notfallanzeige einen aktuellen nativen Neubau.

Abschluss: Alle drei Instanzen bestanden auch den finalen50-Punkte-PG-Lauf.
Acht geschuetzte Routen und geschlossene physische Ausgabeflags je Umgebung
bestaetigt; beide anonymen POST-Endpunkte je Umgebung401. NativeAnsicht,
Verwaltung und Notfall-Unterbau aktiv, echteIgloohomeAusgabe weiterhinnicht
konfiguriert. Mobile-Commit e4a8c98; iPhone-Neubau erforderlich.

## Rueckweg

Zuerst echte Ausgabe/Provider und Notfallfunktion ueber ihre separaten Schalter
schliessen und Konfigurationscache erneuern. Fuer eine Oberflaechenruecknahme
koennen die gezielt gesicherten Quelldateien wiederhergestellt werden. Neue
Cipher-/Fehler-/Offenlegungsspalten und Auditnachweise dabei erhalten; keine
pauschale Schema- oder Datenbankwiederherstellung nach inzwischen erfolgter
Nutzung. Eine Schema-Rueckmigration verlangt eine gepruefte Einzelfallplanung.
Bereits bekannte physische PINs/Notfallcodes werden durch Software-Rollback
nicht widerrufen. Nicht benoetigte temporäre Diagnosehelfer wurden entfernt.
