# Chatversand: Pushkandidaten vor Rechteprojektion begrenzen

Stand06.11.2026. Der gemeldete Versandversuch endete im Accesslog mit499,
weil die App vor der Serverantwort abbrach. Die zugehörige event-Nachricht
war danach bereits gespeichert. Keine Nachrichteninhalte/Anhänge wurden zur
Diagnose ausgegeben, keine künstliche Nachricht oder Push versendet.

## Reproduzierter Engpass

ChatService::send speichert die Nachricht und ruft bei aktivem Push
MobilePushOutbox::chatMessage auf. Diese Schleife prüfte alle aktiven Vereinskonten
über enqueue/targetAllowed, bevor Geräte/Bindings betrachtet wurden. Im betroffenen
Verein37aktiveKonten/1aktiveGerätebindung;36andereKonten wurden vorgeprüft.
Ein rein lesender Nachvollzug dieser bisherigen Berechtigungsprojektion brauchte
55199ms/2024SQL,15potentiellberechtigteKonten. Es handelt sich um die gemessene
Teiloperation, nicht um eine vollständige neu versendete Testnachricht.

## Änderung

Ein gemeinsamer Querybuilder hält die bisherigen Binding-/Device-/Sessionfilter
unverändert: nicht widerrufen, passende Sessionkontozuordnung, gültige Session,
Custom-Build-Tenantbindung und optionaler sourceCreatedAt-Grenzwert. chatMessage
prüft per EXISTS nur Vereinskonten mit mindestens einem passenden Gerät und
schliesst den Absender weiterhin aus. enqueue lädt Bindings zuerst und beendet
sich bei leerer Menge ohne aufwendige Fachrechteprojektion.

Ein registriertes Gerät ist KEINE Rechtefreigabe: Sobald ein Kandidat vorhanden
ist, gelten targetAllowed, aktuelle Vereins-/Chat-/Mitgliedrechte, Mute/Leave
und die bestehende unabhängige finale Workerprüfung weiter. Keine positive
Rechtecache-Persistenz. Worker, Provider, Outboxschlüssel, Revisionen und Registry
bleiben unverändert. Kein Schema-/Konfigurationswechsel.

Das bestehende client_id-/Inhaltsfingerprint-Replay schützt erneutes Senden
innerhalb desselben Entwurfs vor doppelten Nachrichten. Dies bleibt unverändert.
Die Korrektur benötigt keinen neuen Appbuild; sie liegt im Backend.

## Abnahme und Rollout

Synthetische Backendtests prüfen konstante Kandidatenabfrage bei4/37Konten,
leereBindings, echteQueue/Idempotenz, Revocation/Sessionzuordnung/CustomTenant,
Mute/Leave und entzogene Elternrechte.27gezielte Flutterfälle zu kombiniertem
Chatversand, unveränderter Retry-ID, Delta/Cache und Performance grün.
DE/EN-Katalogprüfung2806Schlüssel erfolgreich, keine neuen sichtbaren Texte.

DEV-Delta basiert auf 20261106-clubunio-assistant-v1 und ersetzt nur
MobilePushOutbox.php plus lesendes Gate. Komplette Konfiguration inklusive
Assistant-/Pushflags/privater Registry sowie ENV-/Composerbytes müssen erhalten
bleiben. Gate nutzt ausschliesslich eine bestehende Nachrichtenreferenz im
READ-ONLY-Transaktionskontext; keine Neuveröffentlichung oder Queuemutation.
Vollständige MobileCommunicationTest:55Tests/1019Assertions grün,3neue
Pushfälle/29Assertions separat grün. PHP-Lint/diffcheck sauber.
Rolloutmessung wird nach Durchführung ergänzt.
