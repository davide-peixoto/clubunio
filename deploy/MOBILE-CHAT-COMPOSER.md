# Chat: Reaktionspersonen bei Bedarf

04.11.2026. Backend-Ergänzung ohne Schemaänderung; die vorhandene Tabelle `chat_reactions` aus 1100 wird weiterverwendet. Appdialog, Freigabe, DEV-Auslieferung und Commit/Sync werden separat integriert.

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
