# Dauerhafter Chatversand und Push im Hintergrund

Stand06.11.2026. Ergänzt MOBILE-CHAT-SEND-PERFORMANCE.md: Pushkandidaten sind
bereits begrenzt; jetzt wird auch diese Berechnung aus dem Senderequest entfernt.

## Speicherung und Wiederholung

Die bestehende eindeutige (tenant_id,account_id,client_id)-Beziehung und der
Inhaltsfingerprint verhindern Duplikate derselben Operation. ChatService::send
verwendet eine gemeinsame Tenantsperre, eine exklusive Chatsperre und einen
UUID-bezogenen Advisory-Transaktionslock; es führt Nachricht sowie neuen
mobile_chat_publications-Auftrag in einer Transaktion aus. Bei Rollback existiert
keines von beiden. Ein UUID-Replay gibt die vorhandene Nachricht zurück; geänderter
Inhalt mit gleicher UUID bleibt409. Keine Empfängerberechnung/Provider-HTTP im
Senderequest. Fachliche Rechteprüfung, Announcement-Preview/-Inbox und DTO-Aufbau
bleiben synchron, deshalb keine garantierte Millisekunden-Netzwerkbestätigung.

GET /api/mobile/v1/clubs/{tenant}/chats/{chat}/messages/by-client/{uuid} gibt den
normalen DTO nur für die eigene Nachricht zurück. Strikt lesend: frisches
Chat-/view-Recht, bestehende nicht verlassene Mitgliedschaft und Joinfloor;
keine automatische Mitgliedschaft oder Wiederbeitritt.404 bedeutet nicht, dass
ein noch laufender Request niemals committen wird. Fremde client_id im DTO sind
null; alte oder fremde Ziele bleiben geschlossen.

## Hintergrundverarbeitung

Neue additive Tabelle mobile_chat_publications enthält ausschliesslich IDs,
Zustand, Versuche/Backoff und Zähler. ChatPushPublications beansprucht pending-
Jobs mit FOR UPDATE SKIP LOCKED und hält keine Tenant-/Conversation-Sperre
während der Empfängerprojektion. Frische Quell-/Autorrechte und Empfängerrechte,
Mute/Leave, gültige Bindungen/Sitzungen sowie Custom-Tenantgrenzen bleiben wirksam.
Später registrierte Geräte erhalten keine alten Chatpublikationen.

Fanout und done sind atomar; Teilfehler rollen die Outboxeinträge zurück.
Vorhandene eindeutige Outboxschlüssel verhindern doppelte Queueeinträge.
Fehler haben begrenzten Backoff, nach5Versuchen attention. Der bestehende explizite
Worker clubunio:deliver-mobile-push verarbeitet begrenzt Fanouts und danach
Provider-Outboxeinträge. --outbox bleibt ohne Nebensammlung. Vor Providerzustellung
wird jetzt auch die ursprüngliche Chatnachricht auf Löschung, Autor-/Joinrechte
und richtigen Chat/Kind geprüft. Bestehender Delivery-Shared-Tenantlock und die
Absicherung gegen ungewisse Providerwiederholung bleiben erhalten. Die gemeinsame
Tenantsperre beim Chat-POST ist damit kompatibel: Provider-HTTP hält das Speichern
nicht auf. Rechteänderungen/Edit/Delete behalten exklusive Tenantsperren und
serialisieren weiter gegen Send/Zustellung. Verschiedene Chats können parallel
speichern; dieselbe UUID bleibt auch chatübergreifend serialisiert.

Der vorhandene DEV-Timer läuft weiterhin alle60s nach Laufende mit Limit10,
kein neuer dauerhafter Prozess/Provider oder Flageingriff. Push ist zeitversetzt,
keine garantierte Sofortzustellung. Alte Nachrichten werden nicht nachträglich
als neue Publikationen eingereiht. Abgeschlossene Jobs bleiben als ID-Metadaten;
kein Nachrichteninhalt in der Queue.

## App

Die App zeigt den eigenen Sendeversuch sofort optimistisch, mit Status bis zur
Speicherbestätigung. Vor POST wird die unveränderliche UUID samt vorbereitetem
Body verschlüsselt im Geräte-Secure-Storage gespeichert, jeOrigin/Konto/Verein/Chat.
Dateiinhalte/Tokens stehen dort nicht; hochgeladene Anhangs-IDs und Termin-/Poll-
Metadaten gehören zum festen Body. Pro Chat ein ungeklärter Versuch; kein neuer
UUID für denselben Retry. Nach Timeout/Neustart zuerst own-GET-Abgleich, bei404
unveränderten Versuch behalten. Bewusster Retry sendet genau den ursprünglichen
Body/UUID. Poll/POST/Lookup werden über own client_id zusammengeführt.

Geänderte Entwürfe überschreiben keinen laufenden Versuch. Positive Bestätigung
bereinigt nur den passenden Versuch; verspätete Antworten können keinen neueren
löschen. Scope-/Rechteverlust verhindert alte Antworten, Logout/Kontolöschen
bereinigt lokale Outboxdaten. Normales Routen-/Vereinswechseln erhält verschlüsselte
Metadaten zur späteren berechtigten Rückkehr. Verwerfen bedeutet ausdrücklich
kein Zurückrufen einer eventuell bereits gespeicherten Nachricht. Verschiedene
bewusst neu angelegte Sendevorgänge bleiben verschiedene Nachrichten; keine
inhaltliche Zusammenfassung identischer Texte.

## Schema, Abnahme und Rückweg

Genau die neue Migration2026_11_06_000200_mobile_chat_publications wird auf DEV
additiv und atomar mit Ledger angewendet. Kein Refresh/Import, keine Änderung
bestehender Fachzeilen. stage/migrate/activate getrennt und feste Rohdateihashes,
Baselines und vollständige Konfigurationsgleichheit. Flags/Registry/ENV/Composer
bleiben erhalten. Worker bezieht den aktuellen Releasepfad.

76Backendtests/1404Assertions grün: atomarer Rollback, Lookup rein lesend und
privat, UUID-Replay/409, Worker-Retry/-Rollback/-Idempotenz, widerrufene oder
gelöschte Quellen, Geräte-/Mandantengrenzen. Keine künstliche Providerzustellung.
Appprüfungen und DEV-Abnahme werden nach Durchführung ergänzt.

Rückweg: additive Tabelle/Jobs erhalten; nicht ungeprüft droppen. Ein vollständiger
Code-Rollback auf einen alten Worker verarbeitet neue pending-Publikationen nicht.
Vor Rollback Jobs geordnet abschliessen oder kompatiblen neuen Fanoutworker
beibehalten. Nachrichtspeicherung bleibt erhalten. Unsichere Provider-Sendeversuche
werden weiterhin nicht blind erneut abgesendet.


## Ergänzung: Sendesperren und vollständige Appprüfung

Weiterer kleiner Backenddelta: MobileController/ChatService teilen den Tenantlock
nur für send. Die App kann deshalb auch während eines Providerrequests speichern.
PostgreSQL-Advisorylock nutzt tenant/account/normalisierteUUID als gebundene Werte,
Chatsperre serialisiert Mitgliedschaft und Nachrichteneinfügung. Andere Schreib-
operationen unverändert exklusiv.71Communication-/ChatAccessTests/1405Assertions
grün nach dieser Ergänzung; vorherige FCM-Regressionen unverändert.

Gesamte App-Testsuite830Fälle grün.26gezielte Pending-/SDK-/Logout-/Composerfälle
separat erfolgreich, inklusive Commit+Timeout ohneResend, eigenerUUID-Abfrage,
404Retry mit gleichemBody, verschlüsselter Wiederaufnahme, unmittelbarerAnzeige,
Storagefehler/403/History-/Kontowechsel, isoliertem Logout und Compare-and-remove.
DE/EN Backend2806Schlüssel, App2029Nachrichten in5Katalogen ohne Literalbefund.
