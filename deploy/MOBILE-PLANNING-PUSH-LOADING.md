# Teamtermine: Listenperformance bei aktivem Push

Stand05.11.2026. Nach der ausdrücklichen Pushfreigabe wurde ein zuvor
inaktiver Lastpfad sichtbar: jede Zeile der Kalender-/Heute-Projektion
berechnete das komplette Aufgebot samt berechtigter Empfängerliste.

## Reproduzierter Befund

Lesende DEV-Messung im echten HTTPS-/personal-push-v1-Kontext, aktuelles
Datum und tatsächlicher Nutzer-/Vereinsscope; keine Tokens oder privaten
Termininhalte ausgegeben. Teams618ms/21SQL. Ein Termin13.217s/475SQL, davon
Snapshot11.934s/430SQL; zwei Termine25.328s/906SQL, davon2Snapshots24.018s/860SQL.
Diese Auswertung erklärt auch die rund22–25s langen realen Appanfragen.
Hostlast und Pushworker waren unauffällig; keine Warteschlange/CPUknappheit.

## Korrektur

Die Listenprojektion schaltet ausschliesslich CanSendSquadPush aus und lädt
weder Empfängerfingerprint noch Aufgebotssnapshot. MobilePushReady wird dort
nicht pro Zeile aufgerufen. Andere Rechte/Teilnahmen/Einladungen und vorhandene
Batchprojektion bleiben erhalten; keine persistente Cache-/Rechteaufweichung.
Detail öffnet weiterhin frisch und berechnet den autorisierten Snapshot;
Veröffentlichen prüft ihn erneut. Der langsamere Detail-/Empfängerpfad wird
mit diesem gezielten Listenfix nicht als zusätzlich optimiert behauptet.
Push bleibt serverseitig eingeschaltet; der Versanddienst läuft unverändert.

## Auslieferung und Abnahme

Schmaler DEV-Delta: MobilePlanningCapabilities.php, MobilePlanningData.php und
ein lesendes Servergate. Basis chat-image-compression; keine neue DB-/ENV-/
Composer-/App- oder Providerkonfiguration. Gate verlangt aktive Pushflags,
frisches Zugriffsrecht, vorhandene Termine und0 Squadprojektionen im Listenabruf;
Querybudget200. Alle privaten Firebase-/Pushwerte werden über den vollständigen
Konfigurationsvergleich erhalten. Keine Testnachricht/realer Versand für Abnahme.

25 Flutter-Regressionen zu Planungstransport, Rechten, Aufgebotsversand,
kompaktem Kalender und Notificationdetail erfolgreich. Keine Appänderung oder
neuer Build für diesen Backendfix nötig; Teamplanung neu öffnen/aktualisieren.


## Nachmessung und Regression

Isoliertes Sourceoverlay im lesenden DEV-Prozess (kein vorweggenommener Deploy):
1Termin1369ms/45SQL/0Snapshots,2Termine1322ms/46SQL/0Snapshots, Listenlimit20
mit denselben2Terminen1317ms/46SQL/0Snapshots. Vorher1Termin13217ms/475SQL,
2Termine25328ms/906SQL. Komplette MobileCommunicationTest52Tests/963Assertions
grün, darin Push-available-Regression für Listen1/20, Detail und Elternrechte;
4 gezielte Planungsfälle120Assertions separat grün. PHP-Lint/diffcheck sauber.
