# Geteilte Teamtermine ohne vorgezogene Pushberechnung

Stand 05.11.2026. Termine aus Chat/Heute/Benachrichtigungen werden sofort als
Detailroute mit Ladeanzeige geöffnet. Ein autorisierter Detailabruf ersetzt
anschliessend die Ladeansicht; kein zweiter Detailabruf, keine ungeprüften
Platzhalterdaten. Fehler bleiben in dieser Route wiederholbar.

## API und Berechtigungen

GET /api/mobile/v1/clubs/{tenant}/planning/events/{event} akzeptiert optional
include_push_snapshot=0. Dies überspringt nur die teure Empfängerprojektion;
Roster und frische Capabilities einschliesslich CanSendSquadPush bleiben gleich.
Ohne Parameter bzw. mit 1 bleibt die bisherige vollständige Antwort erhalten.
Ungültige Werte liefern 422; Tenant-/Mitglieder-/Elternrechte bleiben wirksam.

Die persönliche Flutter-App lädt Details mit 0. Erst beim ausdrücklichen
Kader-Push wird der vollständige Snapshot vorbereitet, mit Ladeanzeige und
anschliessender Versandbestätigung. Veränderte angezeigte Termin-/Kaderdaten
verlangen ein erneutes Laden; ein neues Aufgebot wird nicht still bestätigt.
Bereits angezeigte vollständige Snapshots und deren Wiederholungs-ID bleiben
wirksam. Der Versand prüft serverseitig weiterhin frisch. Legacy-SVS unverändert.

## Prüfung und Messung

52 Backendtests / 990 Assertions grün. Neuer Vertragsfall prüft identischen
Roster, unveränderte Capabilities, Opt-out ohne Snapshot, alte Clients mit
Snapshot, ungültige Flags, fehlenden Termin und entzogene Rechte.
Lesender Vergleich desselben DEV-Termins: voller Abruf 12973 ms / 474 SQL,
leichter Abruf 1155 ms / 44 SQL, jeweils 22 Rosterpersonen und Pushrecht.
Keine Tokens/privaten Inhalte ausgegeben, keine Nachricht versendet.

Gezielte Flutterregressionen umfassen sofortige Navigation, genau einen
leichten Abruf, explizite Pushvorbereitung, Konflikt bei geändertem Roster,
Planungstransport, manuelle Versandbestätigung und Benachrichtigungsnavigation.
Neuer Appbuild/Neustart mit aktuellem Flutterstand erforderlich; Backend allein
ändert das Verhalten älterer Clients bewusst nicht.

## DEV-Delta

release-planning-detail-loading.sh kopiert die vorherige planning-push-list
und ersetzt ausschliesslich MobilePlanningController.php, MobilePlanningData.php
sowie das lesende Gate check-planning-detail-loading.php. Keine Migration oder
ENV-/Composer-/Provideränderung. Aktive Pushflags, Registry und Versandtimer
bleiben erhalten. Abnahme und Aktivierung werden nach Durchführung ergänzt.


## Auslieferung 05.11.2026

DEV aktiv 20261105-clubunio-planning-detail-loading aus 64d01be.
Lesendes Kandidatgate720ms/26SQL, Aktivierung679ms/26SQL, jeweils22Personen,
0Snapshots und frisches Pushrecht. Konfiguration einschliesslich privater
Registry/Pushflags identisch, ENV-/Composerbytes erhalten. FPM/Push-Timer aktiv,
Health200/context401. Manifest56ee4c35a0ab0d9868324ddd85946de02121b2c71614e9cb6378ed2243f00c91.
45 Flutterregressionen grün, zusätzlicher UI-Vertragsfall ergänzt.
