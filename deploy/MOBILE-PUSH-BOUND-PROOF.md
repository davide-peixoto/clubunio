# Sitzung innerhalb einer Chatpush-Prüfung wiederverwenden

Stand 07.11.2026. Nach dem residenten Worker zeigen die letzten zwei neuen
Chatpushs jeweils 5 Sekunden von Quellerfassung bis Firebase-Annahme: ungefähr
2 Sekunden bis Fanoutabschluss und 3 Sekunden ab Outboxerfassung. Die Zeiten
sind sekundengenau und messen weder den Klick in der App noch die Ankunft auf
dem iPhone. Der Nutzer meldet weiterhin einige Sekunden bis zur Anzeige.

## Verbleibende wiederholte Abfragen

Bei der bisherigen Empfängerprüfung wurden Konto, Vereinsmitgliedschaft und
Modulrechte mehrfach gelesen, weil im CLI die verifizierte Sitzung noch nicht
an den vorhandenen lokalen Leserahmen angebunden war. Identischer realer
DEV-Empfänger und Chat im READ-ONLY-Gate:

| Prüfung | SQL | Dauer |
| --- | --- | --- |
| Bisheriger direkter Zielcheck |47 |1084ms |
| Neuer sitzungsgebundener Zielcheck |11 |268ms |
| Unveränderte Quellprüfung |12 |265ms |

Das ist keine gemessene neue Ende-zu-Ende-Pushzeit. Die Optimierung betrifft
die Berechnung und die letzte Empfängerprüfung vor dem Provideraufruf.

## Grenzen der Wiederverwendung

Privater CLI-Adapter ausschließlich für chat/announcement, ausschließlich bei
einem GET-Lesekontext des Workers. Er liest eine aktuell aktive, nicht
widerrufene Sitzung des konkreten Empfängers erneut aus der Datenbank. Die
Sitzungs-IDs kommen aus den realen Gerätebindungen, bei der Auslieferung aus
der unter Sperre erneut geprüften Bindung. Keine künstliche Sitzung oder
vorgegebene Benutzeridentität, keine abgeschlossene Rechtecache-/TTL-Ablage.
Bei mehreren Geräten kann jede noch gültige zugehörige Sitzung den einzelnen
Kontorechtecheck tragen; jedes Gerät bleibt bei der Auslieferung separat geprüft.

Genau während targetAllowed werden die bereits geprüften Werte an den
bestehenden MobileReadContext gebunden. Der Callback berechnet nur Rechte.
Alle drei Attribute werden im finally wiederhergestellt oder entfernt; der
Leserahmen endet vor Outboxwrites, Quellprüfung und Provideraufruf. Nächster
Empfänger, nächste Publikation und endgültige Zustellung beginnen neu.
HTTP-Aufrufe, Schreibberechtigungen, nicht zum Chat gehörende Ziele, Token-/
Geräte-/App-/Quellprüfungen, Sperren und Umgang mit unklaren Sendungen bleiben
unverändert. Bei fehlender, fremder, widerrufener oder abgelaufener Sitzung
gibt der Worker keine Rechte frei. Gerätebindung ersetzt kein Objektzugriffsrecht.

## Prüfung und DEV

104 lokale Fälle mit 2593 Assertions bestanden, einschließlich SQL-Budget,
Kontenbindung, Ablauf/Widerruf, frischer fachlicher Rechte nach dem vorherigen
Callback und bereinigtem Kontext. Reale Outboxauslieferung im CLI-Lesepfad mit
aktualisiertem Text, Joinfloor, gelöschter Quelle und widerrufenen Elternrechten
ebenfalls abgedeckt. OAuth/FCM ausschließlich gefälscht. Sprachprüfung 2806 DE/EN
plus PHP-Gruppen grün. Keine neue UI, Appänderung oder Migration.

Zusätzlich 18 PostgreSQL-Pushfälle mit 219 Assertions bestanden; synthetische
TEMP-Tabellen und Rollback, keine echten Provideraufrufe. Insgesamt 122 Fälle
mit 2812 Assertions grün.

Release `20261107-clubunio-push-bound`, Basis `20261107-clubunio-push-worker`.
Ein Runtimefile und READ-ONLY-Gate; vollständige Config einschließlich privater
Registry/Flags, ENV und Composer erhalten. Residenter Dienst wird zur Aktivierung
neu gestartet. Manifest
`217585196c29b306be750ac1bb36d8393e1b1368fc6b56c9ca639e6418229a19`.

DEV aktiv. Aktivierungsgate: direkter Check47SQL/1210ms, sitzungsgebunden
11SQL/295ms, Quelle12SQL/302ms. FPM und Worker aktiv/running, keine
Restartschleife, Health200/context401. Keine künstliche Nachricht erzeugt.

Keine neue Testnachricht oder künstliche Providerzustellung zur Abnahme.
Eine sofortige iPhone-Pushanzeige kann weiterhin nicht zugesichert werden:
[Apple beschreibt APNs als Best-Effort-Dienst](https://developer.apple.com/documentation/usernotifications/sending-notification-requests-to-apns).
Die neue physische Ankunftszeit wird nicht aus den Serverteilmessungen abgeleitet.
