# Chat: Reaktionspersonen bei Bedarf

04.11.2026. Backend-Ergänzung ohne Schemaänderung; die vorhandene Tabelle `chat_reactions` aus 1100 wird weiterverwendet. Der Appdialog lädt diese Daten beim Antippen eines Reaktionschips; die koordinierte DEV-Auslieferung folgt den bestehenden Release-Gates.

## Persönlicher Lesevertrag

`GET /api/mobile/v1/clubs/{tenant}/messages/{message}/reactions`

Optionale Queryparameter: `after` (Account-ID-Cursor, Ganzzahl ab 0, Standard 0) und `limit` (1 bis 50, Standard 30). Andere Queryparameter werden abgewiesen. Antwort:

```json
{"data":[{"account_id":123,"name":"Anzeigename","emoji":"👍","mine":false}],"meta":{"has_more":false,"next_after":123}}
```

Aufsteigend nach Account-ID, maximal `limit` Personen; der Server liest höchstens `limit+1` Zeilen mit einer gemeinsamen Namensabfrage. `next_after` ist die letzte gelieferte Account-ID, bei leerer Seite der ursprüngliche Cursor. Eine Person hat gemäß bestehendem Reaktionsmodell genau eine aktuelle Reaktion je Nachricht. Die Oberfläche muss `api.call` verwenden, um `meta` zu erhalten. Es gibt keine Profil-, E-Mail-, Telefon-, Eltern- oder Mitgliedsfelder und keine zusätzliche Kontaktberechtigung.

Die Liste wird ausschließlich beim Öffnen des Teilnehmerdialogs geladen. Bestehende Message-DTOs enthalten weiterhin nur ihre bisherigen Reaktionsaggregate; keine Teilnehmer-N+1-Abfragen oder Namenslisten beim Polling. Ein GET ändert weder Reaktionen noch Änderungsrevisionen. Die vorhandene Schreibaktion für Reaktionen bleibt getrennt.

## Frische Sicht und Grenzen

Die neue Methode verwendet unverändert `ChatControls::visible`: persönliche Sitzung und Mandant über die Mobile-Middleware, aktuelle Chatberechtigung einschließlich direkter Blockbeziehungen und aktuelle Beitrittshistorie. Fremde Chats, fremder Mandant und Nachrichten vor dem erlaubten Beitritt bleiben geschlossen. Gelöschte Nachrichten geben 404. Rechte- und Sitzungswiderruf werden bei jedem neuen Aufruf wirksam. Die Account-ID ist ausschließlich Identität/Paginierung der bereits sichtbaren Reaktion, kein Dossier- oder Kontaktlink.

## Verifikation

- Vollständige SQLite-Kommunikationsregression: **49 Tests / 768 Assertions**, einschließlich 2 neuer Teilnehmerfälle mit 47 Assertions (Pagination, minimierte Felder, eigener Marker, unveränderte Reaktionen, Validierung, Beitritt/Tenant/Block/Löschung/Widerruf).
- Echter PostgreSQL-/HTTP-Kernellauf gegen bestehendes Schema: **1 Test / 3 Assertions**, dazu **29 eigenständige Kernel-/Privatheitsprüfungen** aus demselben DEV-Probe-Code. Nur neue synthetische Mandanten/Konten/Sitzungen/Chats; vollständig zurückgerollt und Cleanup geprüft. Keine echten Nachrichten an Vereinskonten oder Provider.
- Eigenständiges DEV-Gate: `php deploy/check-mobile-chat-composer.php --synthetic-rollback`. Exakte DEV-Origin/API-/PostgreSQL-/Argumentgrenzen, externe Provider geschlossen, kein PHPUnit im Gate. `Mail::sent(Mailable::class)`/Queue-/HTTP-Fakes; zusammengesetzte Primärschlüssel verwenden `insert`, nicht `insertGetId`.
- Runtime-, Routen-, Test- und Gatequellen syntaxgeprüft, gezielter Diffcheck sauber. Keine Migration, Datenimporte, Providerfreigabe oder Toolzustellung.

Der kombinierte Text-/Terminversand verwendet weiterhin den bereits vorhandenen `ChatService::send`-Vertrag (`kind=event`, `text`, `event_id`); diese Ergänzung ändert dessen Regeln oder Berechtigungen nicht.


## Appverhalten und Bereitstellung

Die Flutter-App merkt den gewählten Teamtermin im Entwurf vor, statt ihn sofort
zu senden. Kommentar und Termin verwenden eine gemeinsame Versand-ID und einen
POST; bei Fehler bleiben beide erhalten. Direkter Kamerabutton, keine automatische
Aufnahme oder Übertragung: erst Vorschau, dann bestehendes Stage/Scan/Senden.
iOS-Kamera-/Fotoberechtigungstexte ergänzt, keine neue Paketabhängigkeit.

Blasen nutzen höchstens 80 % des tatsächlich verfügbaren Chatbereichs, mit
Ausrichtung und Farben aus dem bestehenden Markenthema. Sichtbare große
Reaktions-/Dreipunktaktionen entfallen zugunsten von langem Drücken. Antippen
eines vorhandenen Emojis öffnet die Namenliste und verändert keine Reaktion.

Gezielte Appnachweise:29 Chatfälle,10 Kamerahelperfälle und1 SDK-Vertragsfall
bestanden, vollständige Analyse ohne Befund. Native Aufnahme wurde unter Windows
nicht getestet; nach Pull auf dem Mac neu bauen, Kamera auf echtem iPhone prüfen.
Der Simulator allein ist kein Nachweis für native Kameraaufnahme.

Releasepaket enthält ausschließlich die drei geänderten Runtimequellen und das
neue eigenständige Gate. Gitblobs und aktive DEV-Baseline werden vor der
unveränderlichen Stagingkopie per SHA geprüft. Das neue Gate wird direkt als
CLI ausgeführt (seine einbindbare Testklasse darf nicht über den allgemeinen
php-r-Wrapper geladen werden). Kein Schema-/ENV-/Composer-/Providerwechsel.


## 04.11.2026: Chatentwurf, Kamera und Reaktionspersonen

DEV aktiv: `/var/www/svs-laravel/releases/20261104-clubunio-chat-composer-v1`,
Backendruntime `e894d6b`, App `32ecc4f`. Rückfall: vorheriges
`20261104-clubunio-mobile-team-access-v2`. Nur drei Runtimequellen und ein
neues Gate bereitgestellt; keine Schema-, ENV-, Composer- oder Provideränderung.
Alle bisherigen Gates sowie das neue Gate mit29 HTTP-/Privatheitsprüfungen
ausgeführt; synthetische Daten zurückgerollt. Rohdatei-SHAs vor/nach Aktivierung,
HTTPS200/401 und aktive FPM-Verknüpfung geprüft.

Termin wird vor dem Senden im Entwurf angezeigt und mit dem bearbeitbaren Text
als eine Nachricht gesendet. Kamerabutton mit kontrollierter Bildübernahme und
bestehendem Upload-/Scanablauf. Blasen bis80% verfügbarer Chatbreite, eigene und
fremde Nachrichten verschieden ausgerichtet/eingefärbt. Langes Drücken öffnet
Aktionen/Emoji-Auswahl; vorhandene Reaktion zeigt Namen statt sie umzuschalten.
Namenliste frisch autorisiert und paginiert, nur bei Bedarf, keine neuen
Namensabfragen im laufenden Polling.569/569 Fluttertests, Analyse ohne Befund;
Backend Kommunikation49/768, PG-Probe1/3 plus29 Kernelprüfungen grün.
Keine echten Vereinsnachrichten oder Provideraktionen für die Abnahme.
Mac nach Pull neu bauen; echte iPhone-Kameraaufnahme bleibt separat zu prüfen.
Details: deploy/MOBILE-CHAT-COMPOSER.md, App docs/CHAT-FEATURES.md und CHAT-CAMERA.md.
