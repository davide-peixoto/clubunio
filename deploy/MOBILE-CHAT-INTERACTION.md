# Chat: freier Composer, Reaktionen und Wischantworten

Stand 06.11.2026. Fortsetzung von MOBILE-CHAT-ASYNC-SEND.md.

Die App soll den Composer beim Übernehmen eines Sendeversuchs sofort freigeben.
Weitere Nachrichten erhalten unabhängige UUIDs und Zustände; die Bestätigung
einer älteren Nachricht darf keinen neuen Entwurf löschen. Verschlüsselte
vorbereitete POSTs, Own-UUID-Abgleich nach Timeout und unveränderte Wiederholung
bleiben verbindlich. Pushfanout und Provider bleiben im bestehenden Worker.

Reaktionen zeigen den gewünschten Zustand sofort lokal an. Der bestätigte DTO
bleibt separat; Änderungen, Speicherstatus und Fehler sind sichtbar, ohne die
übrige Unterhaltung zu blockieren. Wiederholungen setzen den gewünschten Zustand
und dürfen keine zusätzliche Toggle-Operation erzeugen.

## Antworten

Additiver Vertrag `reply_to_message_id` im Sendbody, nullable Referenz auf eine
Nachricht desselben Mandanten. Der Sendservice prüft unter der Chatsperre die
gleiche Unterhaltung, aktuelle Mitgliedschaftshistorie und ungelöschte Quelle.
Die Referenz ist Teil des unveränderlichen Idempotenzfingerprints. Ein Replay
einer bereits gespeicherten Nachricht bleibt auch nach Quellenlöschung ein Replay.

DTO `reply` enthält eine begrenzte Livevorschau, keine kopierte Anhangsdatei.
Quelle und eigene Join-Grenze werden frisch geprüft. Gelöschte/unsichtbare Quellen
liefern einen Tombstone ohne Autor oder Inhalt. Projektion erfolgt gebündelt;
Edit/Delete erzeugt Deltaeinträge für bestehende Antworten, damit zwischengespeicherte
Zitate nicht unverändert stehen bleiben. `reply_supported` kennzeichnet den Vertrag.

Migration `2026_11_06_000300_chat_replies` ergänzt nullable Spalte, Tenant-Fremdschlüssel,
Ordnungscheck und begrenzten Index. Bestehende Inhalte bleiben erhalten. Kein
Refresh, kein Import. Ein Code-Rollback lässt die additive Spalte und Referenzen
erhalten; alte Clients zeigen die normale Nachricht ohne Antwortvorschau.

## Auslieferung

DEV-Kandidat `20261106-clubunio-chat-interaction` basiert auf
`20261106-clubunio-chat-async-locks`. `release-chat-interaction.sh` trennt Stage,
genau eine Migration und Aktivierung, prüft Rohdateihashes, Baseline, unveränderte
ENV/Composer-Konfiguration und vollständige Konfigurationsgleichheit. Die
private Firebase-Registry und Push-/Assistantflags bleiben erhalten.

`check-chat-interaction.php` prüft Schema/Fremdschlüssel und den bestehenden
Own-UUID-Lookup rein lesend. Es erzeugt keine Nachricht und keinen Push.
Appdetails: mobile/docs/CHAT-COMPOSER-INTERACTION.md.

Tests und tatsächlich ausgeführte DEV-Abnahme werden nachgeführt.
