# Chatvorschau und zeitnaher Pushversand

Stand 06.11.2026. DEV aktiv: `20261106-clubunio-push-preview`, Basis
`20261106-clubunio-mobile-hotpaths`. Keine Migration oder Appänderung.

Der FCM-Transport setzte bisher immer einen allgemeinen deutschen Titel/Text.
Der DEV-Timer startete erst 60 Sekunden nach Abschluss des vorherigen Workers,
zuzüglich bis zu fünf Sekunden Timerungenauigkeit. Zwei bestehende Chatpushs
benötigten von Quellerfassung bis Providerannahme durchschnittlich 35,5 und
maximal 48 Sekunden. Das ist keine gemessene Geräteankunft.

Der Timer wartet nun zwei Sekunden nach Abschluss, Genauigkeit eine Sekunde.
Das Journal bestätigt leere erfolgreiche Läufe etwa alle drei Sekunden.
Budget bis 100 statt zehn Outboxeinträge; bestehende Auslieferungen zuerst,
anschließend jede Chatpublikation unmittelbar ausliefern, bevor die nächste
Empfängergruppe berechnet wird. Keine Pushberechnung im Nachrichten-POST.
Keine parallelen Worker: oneshot plus bestehender flock. Rückstau, langsame
Provider oder große Empfängergruppen können weiterhin länger dauern.

Sichtbare Pushs verwenden Android high sowie APNs alert/priority10. Vgl.
[FCM-Priorität](https://firebase.google.com/docs/cloud-messaging/android-message-priority)
und [FCMv1-Referenz](https://firebase.google.com/docs/reference/fcm/rest/v1/projects.messages).
Apple/Google bestätigen damit keine Ankunftsfrist; keine unsicheren Retries.

## Vorschau und Datenschutz

Vom Benutzer gewünschte Änderung zum früheren generischen Pushvertrag:
Chatquellen `chat.message:*` liefern den aktuellen Absendernamen (maximal 80)
und Nachrichtentext (maximal 180 Zeichen). Unveränderter Freitext, keine
automatische Übersetzung. Steuer-/Bidi-Zeichen und Zeilenumbrüche werden für
die einzeilige Anzeige bereinigt. Leerer Inhalt erhält einen allgemeinen
Hinweis auf geteilte Inhalte; keine Bilddateien, Dateinamen oder Downloads.

Die Vorschau entsteht unmittelbar vor dem Provideraufruf, nach aktueller
Quell-, Empfänger-, Sitzungs-, Geräte-, Mandanten-, Mute- und Domainprüfung.
Gelöschte oder nicht mehr berechtigte Quellen werden verworfen. Ohne aktive
Empfängermitgliedschaft oberhalb der Joinfloor bleibt die Vorschau generisch.
Verschlüsselungsfehler geben keine Inhalte frei. Keine zusätzlichen
Klartextspalten, Logeinträge oder Kopien in der Outbox. Der Routingumschlag
bleibt unverändert auf IDs beschränkt. Andere Pusharten bleiben generisch.

Absender und Kurztext werden dadurch an Firebase/APNs übertragen und können
auf dem Sperrbildschirm erscheinen. iOS-/Android-Mitteilungseinstellungen
steuern deren Sichtbarkeit. Kein separater App-Vorschauschalter durch dieses
Paket. Systemtexte verwenden die zentral freigegebene Sprache aus der
persönlichen Apppräferenz, Fallback zentrale Standardsprache. DE/EN-Kataloge
ergänzt; verfasste Nachrichten behalten ihre Originalsprache.

## Prüfung und Betrieb

87 lokale Regressionen mit 2367 Assertions grün, einschließlich realem
Outbox-/Chatdienst mit SQLite, aktualisierter Vorschau, Joinfloor,
Quelllöschung, Rechtewiderruf, Versandbudget/-reihenfolge, FCM-Payload,
Timeout ohne Retry und unverändertem Routing. OAuth/FCM ausschließlich gefälscht.
Sprachprüfung 2806 DE/EN-Schlüssel plus PHP-Gruppen ohne Backlog.

Zusätzlich 18 PostgreSQL-Pushfälle mit219Assertions grün: synthetische
TEMP-Tabellen und Rollback, keine echten Providerkontakte. Direkter PHPUnit-
Aufruf mit explizitem PDO-PG-Treiber; normaler CLI-Aufruf lädt diesen nicht.
Insgesamt105Tests mit2586Assertions bestanden.

Echtes PostgreSQL-DEV-Lesegate: READ ONLY, aktuelle Zustellzustände und
Quell-/Providerzeiten; keine fachlichen Testnachrichten oder zusätzlichen
Provideraufrufe. Synthetische Vorschauen DE/EN im Releasegate getestet, ohne
Inhalte aus echten Chats auszugeben.

Rollout über `release-push-preview.sh` und Rohdateimanifest; komplette Config,
Firebase-Registry, Flags, ENV und Composer unverändert. FPM und Timer aktiv,
Worker Result success/Exit0, Health200/context401. Vorheriger Release bleibt
erhalten. Physische Ankunftszeit einer neuen Nachricht separat prüfen;
der normale Worker versendet weiterhin echte bereits autorisierte Pushs.
