# Benachrichtigungen gesammelt als gelesen markieren

Ausgangsbefund06.11.2026: Der alte POST prüfte jede ungelesene Zeile separat,
schrieb je Inbox-/Originalzeile und rief danach `count()->visible()` auf. Dabei
wurden auch bereits gelesene historische Ziele erneut autorisiert. Roots
lesende DEV-Probe brach nach24 Prüfungen mit1489 SQL und40,160 Sekunden ab;
der ungelesene Bestand war zu diesem Zeitpunkt bereits0. Ein Clienttimeout
konnte daher nach erfolgreicher Speicherung auftreten.

Die neue Operation hält weiterhin die exklusiven Tenant- und gemeinsamen
Sessionsperren und authentifiziert frisch. Nur die anschließende lesende
Autorisierungsprojektion erhält einen expliziten transaktionsgebundenen
`MobileReadContext`. Der Helper erwirbt selbst die Tenant-/Session-/Actorsperren,
prüft Sessionablauf und Kontostatus und bindet den Frame an dasselbe
Requestobjekt, Konto, Session, Tenant und die laufende Transaktion. Die
Konfiguration der aktuellen Saison wird für diese Projektion frisch gelesen
und innerhalb derselben Projektion wiederverwendet. Allgemeine POSTs und
Zustellworker erhalten diese Ausnahme nicht.

Der Frame endet garantiert vor den gesammelten Readmarker-Updates. Pro
kind/object-Ziel wird innerhalb der Operation nur einmal autorisiert. Die
Originalnotification wird nur bei übereinstimmender eigener Account-/Tenant-,
Source-ID-, Object-ID- und Kindbindung synchronisiert. `through_id` begrenzt
die Auswahl weiterhin; später erzeugte Nachrichten bleiben ungelesen.
`count()` prüft ausschließlich ungelesene Ziele; das Watermark ist die höchste
eigene Inbox-ID. Es gibt keine angenäherten Zähler oder pauschalen Updates
fremder beziehungsweise nicht mehr berechtigter Ziele.

Der bestehende ACK `read`/`unreadCount` bleibt erhalten. Optional darf der Client
bis100 eindeutige positive `observed_ids` senden. Zusätzlich liefert der ACK
`through_id`, neu markierte `marked_ids` und `read_ids`: ausschließlich frisch
autorisierte angefragte eigene IDs bis zum Watermark, die nach der Operation
gelesen sind, einschließlich bereits vor dem POST gespeicherter Marker.
Fehlende IDs bedeuten keinen bewiesenen Rechteentzug und keine bestätigte
Lesemarkierung. Ein neuer vollständiger Verlauf oder Count-GET ist für diesen
ACK nicht erforderlich. Die vollständige Seitenliste behält ihre bisherigen
Sichtbarkeitsregeln; deren gesamte Historienprojektion wird hier nicht optimiert.

Prüfung: neue SQLite-Performancefälle und bestehende ReadContext-Fälle9/139 grün;
bestehende PostgreSQL-Notifications-Regressionen3/44 grün mit synthetischen
Rollbackfixtures. Gleiche23 Ziele benötigen weniger als30 SQL,23 verschiedene
Announcementziele weniger als85 SQL; zwei Zugriffe auf120 gelesene historische
Ziele lösen keine historischen Zielprüfungen aus. Geprüft sind beobachtete
ACK-IDs nach bereits erfolgtem Read, verspätete Nachricht, fremde/ungültige Ziele,
Sourcebindung, kopiertes Requestobjekt, widerrufene Session sowie Framecleanup
auch bei Fehlern. Keine Migration oder Providerzustellung erforderlich.
DEV-Auslieferung und echte Controller-Messung (26Ziele,130SQL,3.558s) nachgeführt
in MOBILE-NOTIFICATIONS-READ-ALL.md. Die Gate-Szenarien wurden vollständig zurückgerollt.
