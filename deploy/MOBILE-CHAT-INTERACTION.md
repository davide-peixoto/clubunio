# Chat: freier Composer, Reaktionen und Wischantworten

Stand 06.11.2026. Fortsetzung von MOBILE-CHAT-ASYNC-SEND.md.

Die App gibt den Composer beim Übernehmen eines Sendeversuchs sofort frei.
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
Bei einem Rückbau auf Code ohne Reply-Changefeed müssen bereits verwendete
App-Chatcaches vollständig neu abgeglichen werden, bevor alte Zitate weiter
angezeigt werden. Den schema- und replyfähigen Worker/Service bevorzugt erhalten.

## Auslieferung

DEV-Kandidat `20261106-clubunio-chat-interaction` basiert auf
`20261106-clubunio-chat-async-locks`. `release-chat-interaction.sh` trennt Stage,
genau eine Migration und Aktivierung, prüft Rohdateihashes, Baseline, unveränderte
ENV/Composer-Konfiguration und vollständige Konfigurationsgleichheit. Die
private Firebase-Registry und Push-/Assistantflags bleiben erhalten.

`check-chat-interaction.php` prüft Schema/Fremdschlüssel und den bestehenden
Own-UUID-Lookup rein lesend. Es erzeugt keine Nachricht und keinen Push.
Appdetails: mobile/docs/CHAT-COMPOSER-INTERACTION.md.

## DEV-Abnahme

Runtime `d887662` auf DEV als `20261106-clubunio-chat-interaction` aktiv.
Genau Migration000300 und Ledger atomar angewendet. Beide lesenden Gates erkennen
die eigene vorhandene Nachricht, nullable Spalte, Fremdschlüssel, Ordnungscheck
und Index. Lookup81SQL/2224ms vor Aktivierung beziehungsweise2201ms beim
Aktivieren; das ist ein nicht memoisiertes CLI-Lesegate, keine Sendezeitmessung.
FPM und Pushtimer aktiv, letzter Worker erfolgreich, `/up`200 und anonymer
Context401. Vollständige Konfiguration einschließlich privater Registry erhalten.
Keine künstliche Fachnachricht oder Providerzustellung erzeugt.

80 Backendtests mit 1453 Assertions grün. Gesamte Flutter-Testsuite: 855 Fälle grün,
darunter Wisch-/Vorschauwidgets in fünf Sprachen, schmale Anzeige mit größerer
Schrift, mehrere Nachrichten bei wartendem POST, Timeoutabgleich und Reaktionen.
Analyse von neun betroffenen App-/Testdateien sauber; 2042 Texte in fünf lokalen
Appkatalogen und 2806 Backendtexte DE/EN einschließlich Literalprüfung erfolgreich.
Nach der letzten Korrektur für echte401-Antworten nach SDK-Sitzungsablauf nochmals
46 gezielte Fälle grün: POST-/Lookup401, Mehrfachversand, Timeout-Retry,
Wischantworten und alle zehn Reaktionsfälle. Keine neuen nativen Abhängigkeiten;
neuer Appstand erforderlich.

Paketmanifest `ae2118a3f7ed0da20878698fbd381a0cc67366286936c40677b0b510a9f39a26`,
Releasescript `9bf09ccc39b5cf365e4e6ce07b24b07c199f03f7deab25a173f854d70df3b64a`.
