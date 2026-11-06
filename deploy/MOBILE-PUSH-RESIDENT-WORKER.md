# Verbleibende Chatpush-Latenz

Stand 07.11.2026. Benutzer misst 10–12 Sekunden vom Absenden bis zur Anzeige auf
dem iPhone. Zwei neue bereits akzeptierte Chatpushs nach dem vorigen Release
brauchten 8 und 9 Sekunden von Quellerfassung bis Firebase-Annahme. Davon jeweils
4 Sekunden ab Outboxerfassung; die Phase davor umfasst Workerwartezeit und
Empfängerberechnung. Datenbankzeitstempel sind sekundengenau, keine präzise
Provider-/Netzwerk- oder Gerätezeit. Der Rest der iPhone-Zeit ist nicht separat
gemessen und darf nicht pauschal Apple zugeschrieben werden.

## Änderung

DEV verwendet einen residenten Worker statt eines neu gestarteten CLI-Prozesses
je Timerlauf. Der Worker prüft festgeschriebene Chatpublikationen/Outboxeinträge
mit 250ms Ruhepause zwischen Zyklen. Datenbankprüfungen und laufende Zustellungen
kommen zur Pause hinzu; keine garantierte250ms-Ende-zu-Ende-Zustellung.

Der bestehende systemd-Dienst bleibt ein einzelner durch flock geschützter
Worker, jetzt Type=simple und Restart=always. Er erneuert sich nach ungefähr
300 Sekunden an der nächsten Zyklusgrenze; Neustartpause 1 Sekunde. Enable für
multi-user.target; der bisherige Timer wird deaktiviert, als Rollbackweg bleibt
seine Unit erhalten. TimeoutStopSec45 schützt vor unbegrenzt hängendem Stop.
Systemd-Restart ersetzt keinen erneuten Versuch eines unklaren FCM-Versands:
bereits beanspruchte unsichere Zustellungen bleiben attention, keine Dopplung.

Chatversand läuft vor der periodischen Sammlung bestehender Fachmitteilungen;
diese bleibt aktiv, höchstens alle zwei Sekunden nach Abschluss der Sammlung.
Budget je Zyklus 100 Fanouts/100 Zustellungen, HTTP-Providerlimits unverändert.
Ein neuer Requestkontext je Zyklus verwirft HTTP-, Saison- und Rechtefakten.
Keine Tokens oder Nachrichtentexte in zusätzlichen Logs oder Messdaten.

Quell- und Empfängerprüfung verwenden jeweils einen neuen lokalen Chat-Leserahmen.
Die Quellprüfung prüft das Senderecht rein lesend; sie ist keine Chatmutation.
Wiederholte enter/profile-Fakten innerhalb genau dieser Prüfung werden nur dort
wiederverwendet. Der Rahmen endet auch bei Fehlern; nächste Quelle, nächster
Empfänger und letzte Zustellprüfung bleiben frisch. Keine Prozess-/TTL-Rechtecaches.
Nachrichten-POST, echte Schreibberechtigungen und Zustellsperren unverändert.

## Messung und Prüfung

Gleicher bestehender DEV-Chat und Empfänger, reine Leseprojektion ohne Provider:

| Teilprüfung | Vorher | Inaktiver Kandidat |
| --- | --- | --- |
| Empfänger/Zielrecht |65SQL /1702ms |47SQL /1255ms |
| Quellrecht |37SQL /901ms |12SQL /329ms |

Dies misst einzelne Prüfungen, keine neue iPhone-Ankunftszeit. Kein künstlicher
Push oder neue Fachnachricht zur Abnahme. Releasegate nutzt READ ONLY und
Http::preventStrayRequests. Vollständige Konfiguration, private Registry,
Push-/Assistantflags, ENV und Composer bleiben erhalten; keine Migration.

98 lokale Regressionen mit 2481 Assertions grün, einschließlich neuer Tests für
mehrfaches Polling ohne erneute Sammlung, Kontextrücksetzung, Watchgrenzen,
Verbot von Watch plus ausgewählter Zustellung und Rechtewiderruf zwischen
aufeinanderfolgenden Quell-/Empfängerprüfungen. OAuth/FCM in Tests ausschließlich
gefälscht. Zusätzlich 18 PostgreSQL-Pushfälle mit 219 Assertions bestanden,
TEMP-Fixtures und Rollback, kein echter Provider. Insgesamt 116 Fälle mit
2700 Assertions grün. Sprachprüfung 2806 DE/EN plus PHP-Gruppen ohne Backlog. Physische
iPhone-Latenz nach Aktivierung separat durch eine neue Nachricht prüfen.

Release: `20261107-clubunio-push-worker`, Basis `20261106-clubunio-push-preview`.
Rollout `release-push-worker.sh`; Manifest
`cbbe1480e8b7217efaf5bbb622b44490720c4ee3ca7a8488cca1df83d3fb471d`.

Aktivierungsgate ebenfalls grün: Ziel47SQL/1290ms, Quelle12SQL/308ms. Vollständige
Config einschließlich privater Registry/Flags unverändert, ENV/Composer bytegleich.
FPM aktiv, neuer Worker active/running und für Neustarts aktiviert; alter Timer
disabled/inactive. Health200/context401. Keine neue Chatnachricht/Push zur Abnahme.
