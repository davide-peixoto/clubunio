# Persönliche manuelle App-Mitteilungen (04.11.2026)

Quellpaket1000, noch keine Schemaanwendung oder DEV-Aktivierung durch diesen Agenten.
Der bestehende SVS-PushAdminScreen bleibt die Arbeitsoberfläche: Titel/Text,
alle Konten, Geschäftsstelle, Trainer/Geschäftsstelle, einzelnes Konto,
MitgliederID und Vereinsrolle. Historische UserLevel-Zahlen werden nicht auf
neue Rollen umgedeutet. Konten-/Mitgliedersuche bleibt im aktuellen Verein und
liefert nur ID/Name. Vor Speichern zeigt die echte serverseitige Vorschau
Vereinsname und Zahl aktuell berechtigter Konten; ausdrückliche Bestätigung.

## Fachvertrag und Grenzen

`native_push_admin_enabled` bleibt standardmäßigfalse. Nur echte aktuelle
Admin-/Geschäftsstellenkonten mit persönlichem Appzugang dürfen schreiben.
Öffentlicher Bootstrap gibt `pushAdminAvailable=false`; persönlich ist der Wert
Flag UND derselben echten Administratorpolicy. Fehlender Clientwert bedeutetfalse.
Profil und Originalscreen benötigen keine Legacy-Session/Provider.

GET `clubs/{tenant}/push-admin/options`, GET `push-admin/lookup?type=account|member&q=…`,
POST `push-admin/preview`, POST `push-admin/notices`,
GET `push-admin/notices/{id}` (ausschließlich ursprünglicher, weiterhin berechtigter
Absender) sowie GET `admin-notices/{id}` (tatsächlicher, weiterhin passender Empfänger).
Alle Pfade liegen in persönlicher Session-/Mandantenmiddleware plus Readiness.
Titel maximal120, Text1000 Zeichen, nur bekannte Felder und echte bool-Bestätigung.

Vorschauversion bindet Verein, Absender/aktuelle Rolle/Revision, exakten Inhalt
und tatsächlich ermittelte aktuelle Empfänger/Rollenrevisionen. Mitgliederauswahl
erreicht genehmigte Selbstzuordnung und derzeit berechtigte Eltern eines
minderjährigen Mitglieds; Namenssicht erweitert keine privaten Fachrechte.
Payload und Empfänger-ID-Snapshot bekommen SHA256; Titel/Text verschlüsselt.
UUID ist an Verein/Absender/Payload gebunden. Exakte Wiederholung erzeugt
denselben Beleg, geänderter Inhalt oder veraltete Vorschau409. Nach unklarer
Antwort ist nur ausdrückliche Wiederholung mit derselben UUID vorgesehen.

Snapshotempfänger UND frische persönliche Zielgruppenberechtigung sind bei
Inhalt, Pushresolve und Zustellung erforderlich. Später hinzugefügte Konten
erhalten keine alten Quellen. Widerrufene Absender dürfen nicht nachliefern.
FCM enthält weiterhin nur generische Meldung und typisierte IDs (`admin_notice`),
keinen freien Titel/Text; Inhalt erscheint nach frischer Autorisierung in der
Originalbenachrichtigung als Informationsdialog.

Speichern legt Quelle/Snapshot und persönliche Inbox atomar an; keine Provider-
oder Mailaufrufe. Nur vorhandener ausdrücklich gestarteter operatorgeschützter
Pushworker verarbeitet die jüngsten24h. Geräte müssen schon bei Quellanlage
gebunden sein, Custom-Builds passen ausschließlich zum festen Mandanten.
`queued`, `disabled`, `noDevices`, `attention`, `accepted`, `discarded` werden
ehrlich getrennt; Providerannahme behauptet keine Endgerätezustellung.

## Schema und Freigabe

Migration `2026_11_04_001000_mobile_admin_notices.php` erzeugt zwei neue Tabellen
mit Composite-FKs und Größenbounds; erweitert gezielt den bestehenden500-
Outbox-Kindcheck um `admin_notice`. Keine bestehenden Nachrichtenfelder ändern,
keine Importe. Vor späterer Anwendung deshalb auch Outbox-Metadaten/Constraint
sichern und überprüfen; Root erstellt den getrennten geprüften Schemahelper.
Noch keine permanente1000-Tabelle durch Tests/Tools angelegt.

`deploy/check-mobile-admin-notices.php --synthetic-rollback --foundation|--enabled`
ist ein eigenständiger DEV-HTTP-Kernelgate ohne PHPUnit, mit tatsächlichen
Session-/Fachrouten und vollständigem Rollback. Foundation prüft alle sechs
geschlossenen Endpunkte ohne1000-Abfrage. Enabled erfordert bereits bestehende
1000-Ledger/Tabellen, prüft Vorschau/Quelle/Replay/Privatinhalt/Widerruf.
Providerzustellung muss ausgeschaltet sein; HTTP/Mail/Queue gesperrt/fake.
Quelle und Syntax vorbereitet; Gateausführung und Aktivierung bleiben Root.

## Nachweise

Vier synthetische PostgreSQLfälle100 Assertions grün; ergänzender Rollenfall
mit drei Bootstrapcap-Prüfungen1/37 grün (finaler Vierfallvertrag103 Assertions).
Neue1000-DDL und jede FK referenzieren ausschließlich eigene TEMP-Parents,
Pushgeräte/-bindungen/-outbox und Inbox sind TEMP; Konten/Sessionfixtures
zurückgerollt. Kein Provideraufruf, keine permanente DDL.

Flutter prüft echten SDK/Whitelist/Rollen, originalen Empfängersucher,
Bestätigung/Abbruch, unklaren Auftrag/UUID, Konto-/Mandantenwechsel,
Belegstatus und frisch gebundenen privaten Benachrichtigungsinhalt. Zusätzliche
Shellfälle beweisen tatsächlichen Profil-/Spielmenü-/Mitgliederlangdruckeinstieg
und geschlossenes Verhalten. Native Kamera/FCM/APNs/Coldstart bleiben getrennte
Geräteabnahme, keine behauptete Gesamt-Appabnahme.
