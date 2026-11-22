# Event-Check-in: persönlicher Vertrag und Anwesenheit

Stand:22.11.2026. Bestehende ClubEvent-/EventGuest-Abläufe bleiben erhalten.

## Persönlicher Ablauf

- Gedruckte HTTPS-URL: `/event-check-in/{64hex}`. Der Token ist zufällig, nur
  gehasht indexiert und verschlüsselt wiederherstellbar; keine Appsession im QR.
- `GET /api/mobile/v1/event-check-ins/{token}/context`: persönliche Anmeldung
  erforderlich. Liefert ausschliesslich berechtigten Verein `Tenant{ID,Name}`
  und `EventID`; ein eigener Vereinsdomain-Aufruf kann nicht fremde Vereine öffnen.
- `GET /api/mobile/v1/clubs/{tenant}/event-check-ins/{token}`: `Event{ID,Revision,
  Name,Location,Enabled,OpensAt,ClosesAt,IsOpen}`, `TokenRevision` sowie eigene
  `Members[{ID,Name,Present,CheckedInAt}]`. Kein GET erfasst Anwesenheit.
- `POST` auf dieselbe Vereins-URL: `MemberID`, `Confirmation:true`,
  `TokenRevision`, `EventRevision`. Stringbestätigungen werden abgewiesen.
  Antwort: `Confirmed`, `AlreadyPresent`, `EventID`, `MemberID`, `CheckedInAt`.
  Ein Timeout-Retry mit gleichem Stand liefert denselben gespeicherten Zeitpunkt.

Nur aktuell genehmigte eigene Mitgliedsverknüpfungen (`kind=self`) dürfen sich
bestätigen. Elternvertretung oder Administrationsrechte verleihen im persönlichen
Endpunkt keine Stellvertretungsfreigabe. Aktives Konto, Vereinszugang und aktive
Mitgliedschaft stammen aus den vorhandenen Zugriffsregeln. Bei teambeschränkten
Events sind zusätzlich aktive Teamzuordnung zur Eventsaison oder ausdrückliche
aktive Einladung nötig.

## Vorstand und Zeitfenster

`EventCheckinService::configure/qr/rotate/close` verwenden `events.update` und
frische Vereins-/Eventversionskontrolle. Das vorhandene Eventmodul besitzt keine
Termindaten; neue Felder sind ausdrücklich das Check-in-Zeitfenster, kein Ersatz
für das fachliche Veranstaltungsdatum. Ein- und Ausschalten ist getrennt von
Event.active. Aktivierung verlangt Name, Ort, Öffnung und Ende; Beginn inklusive,
Ende exklusiv. Ausgabe erfolgt in ISO8601; Eingaben ohne Zeitzone werden als
Europe/Zurich interpretiert.

Erster aktivierter Check-in erstellt einmal den stabilen Token. Anzeigen mintet
keinen QR; Rotieren ersetzt den alten Link, Schliessen belässt den Link und stoppt
neue Anwesenheit. Bestehende Anwesenheitsdaten bleiben erhalten. Event- und
Tokenrevision schützen auch vor Bestätigen inzwischen geänderter Details.

## Getrennte Anwesenheit und Sicherheit

Additive Migration `2026_11_22_000120_event_checkin.php`: drei Eventfelder,
`event_checkin_codes` und `event_checkins`. Anwesenheit ist eindeutig je
(Verein,Event,Mitglied), mit Zeitpunkt und bestätigendem Konto. Bestehende Gäste/
RSVP-Zuordnungen werden weder erzeugt noch umgeschaltet. `ClubEvent::checkins()`
und `EventCheckin::member()/actor()` stehen für die separate Vorstandsliste bereit.

Bestätigung sperrt in konsistenter Reihenfolge Verein, Event und QR-Zeile,
prüft beide Versionen sowie Konto und eigene Mitgliedsverknüpfung frisch.
Schliessen/Rotieren kann deshalb keinen alten Scan in eine neue Anwesenheit
verwandeln. SQL-Ausdrücke verwenden immer die explizite ClubUnio-Verbindung,
unabhängig von einer separat konfigurierten Legacy-Defaultverbindung.

Konfiguration: `CLUBUNIO_EVENT_CHECKIN_ENABLED` (standardmässigfalse),
`CLUBUNIO_EVENT_CHECKIN_PUBLIC_ORIGIN` und explizite tenant-ID-/HTTPS-Origin-Map
`CLUBUNIO_EVENT_CHECKIN_TENANT_ORIGINS` als JSON. Keine frei übernommenen Hosts.
Bestehende Events bleiben nach Migration deaktiviert, bis der Vorstand ihr
Zeitfenster freigibt. Kein automatisches DROP der Anwesenheits-/Audithistorie.

## Prüfung

13 isolierte Backendtests,46 Assertions: GET ohne Schreibwirkung, Tokenstabilität/
Verschlüsselung, eigene Identität, Eltern-/Adminabweisung, Freigabestatus des
Kontos/Links, Zeitgrenzen, Team-/Einladungszuordnung, unveränderte RSVP-Daten,
Doppelbestätigung, Versionswechsel, Tokenrotation und Vereinsgrenzen.

`deploy/check-event-checkin.php --synthetic-rollback clubunio_dev|clubunio_stage`
ist die explizite PostgreSQL-Prüfung mit den echten MemberAccess-/Modulrechten.
Sie erzeugt ausschliesslich synthetische Datensätze in einer zurückgerollten
Transaktion, meldet bei Fehlern Klasse/Codezeile ohne Tokens und sendet nichts.
Ihre tatsächliche Ausführung/Bereitstellung dokumentiert der Releaseabschluss.
