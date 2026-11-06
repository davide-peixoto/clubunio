# Posteingang: Alle gelesen ohne vollständige Historienprüfung

Stand 06.11.2026. Fehlerbild: Die App meldet beim Markieren aller Mitteilungen
einen Timeout und entfernt die Liste. Die Serververarbeitung kann dabei noch
weiterlaufen und bereits erfolgreich speichern.

## Nachweis und Änderung

Lesende Messung am vorhandenen DEV-Konto: 26 historische Inboxzeilen, 14 verschiedene
Ziele (24 Planung, 2 Platzbelegung), inzwischen keine ungelesene Zeile. Allein die
ersten 24 bisherigen Zielprüfungen verursachten 1489 SQL-Abfragen und 40.160ms;
die Messung endete vor den letzten zwei Zeilen. Kein Schreibzugriff. Das ist eine
Messung der Berechtigungsprüfung, keine vollständige HTTP-Laufzeit.

Der bisherige Schreibpfad prüfte Ziele je Zeile, schrieb Inbox und Quelle einzeln
und führte anschließend `count()->visible()` über die gesamte Historie aus.
Die App schloss weitere Count-/Listenabrufe an. Dadurch konnte selbst eine
bereits abgeschlossene Markierung noch in einer langen Nachbearbeitung hängen.

Die Korrektur bündelt erlaubte Zeilen und deren Markerupdates. Gemeinsame frische
Rechtefakten werden ausschließlich während einer ausdrücklich gesperrten
Autorisierungsprojektion wiederverwendet: gleicher Actor, Session, Mandant und
aktive SQL-Transaktion, frische Session/Accountprüfung, Tenant-/Sessionsperren.
Der Frame endet vor den Markerwrites; andere Schreiboperationen erhalten keinen
allgemeinen Rechtecache. Ein Ereignis wird innerhalb dieser Projektion nur einmal
geprüft. Sourcebinding und fremde beziehungsweise widerrufene Ziele bleiben geschützt.

Der Antwortzähler prüft nur verbleibende ungelesene Ziele. Der reguläre Countpfad
prüft ebenfalls keine bereits gelesene Historie mehr. Materialisierung neuer
Quellmitteilungen und Synchronisation bestehender Lesemarker bleiben erhalten.

## Appvertrag

`through_id` bleibt die Obergrenze des bekannten Listenstands. Neuere Mitteilungen
werden nicht mitgelesen. Optional begrenzte `observed_ids` erlauben die Bestätigung
bereits andernorts gelesener sichtbarer Einträge, auch nach einem früheren Timeout.
ACK liefert `read`, `unreadCount`, `through_id`, `marked_ids` und `read_ids`.
`read_ids` bestätigt ausschließlich übermittelte, frisch autorisierte Beobachtungen.
Fehlende IDs dürfen nicht pauschal als gelesen oder widerrufen interpretiert werden.

Die App übernimmt den bestätigten Zähler und die bestätigten Einträge ohne erneute
Count-/Vollistenkaskade. Ladezustand bleibt an der Aktion sichtbar. Netzwerkfehler
erhalten die bestehende Liste; echte Sitzungs-/Berechtigungs-/Kontextverluste
schließen weiterhin den privaten Inhalt. Oberflächentexte bleiben lokal übersetzt.

Release und abschließende Prüfergebnisse werden nach Durchführung ergänzt.
