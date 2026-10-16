# ClubUnio: Teamplanung und Kalenderperformance

16.10.2026. Ausschliesslich ClubUnio. PHPMaker DEV/PROD, API und SaaS
unveraendert. Keine Datenmigration, echten Nachrichten oder Bankzahlungen.

## Befund und Aenderungen

Kalender pruefte fuer jedes Terminpaar und jede Tageszelle wiederholt dieselben
Datumswerte mit Carbon. PlanningCalendarTimeline berechnet Zeitgrenzen einmal
pro Datenmenge und vergleicht Integer-Mikrosekunden. Keine Datenbankzugriffe
oder Berechtigungscaches in dieser reinen Berechnung. Eingabedaten bleiben
unveraendert. Termin-/Bus-IDs werden getrennt behandelt.

Die bisherige Auswahl sichtbarer Termine und Konfliktkandidaten bleibt im
PlanningCalendarService, einschliesslich Mandanten-, Rollen-, Einladungs- und
Mannschaftsgrenzen. Konflikte weiterhin unabhaengig vom Anzeigefilter, aber
nicht von der Sichtbarkeit; abgesagte/fremde Teams und aneinandergrenzende
Termine unveraendert behandelt. Bestehende Limits1000 Termine/5000 Kandidaten
und Buslimit erhalten. Kein neuer Export oder anderer Berechtigungsweg.

Tagesgrenzen werden weiterhin in Europe/Zurich gebildet, nicht als fixe24h
berechnet. Mikrosekunden, Mitternacht, mehrtaegige Termine, Sommer-/Winterzeit
und Busreservierungen bleiben zum bisherigen Algorithmus identisch.

Controller verwendet die vom Kalenderservice frisch autorisierten
Mannschaftsbeschriftungen, statt dieselbe Liste erneut zu laden. Eigene
Mitglieder und verwaltbare Mannschaften werden weiterhin separat geprueft;
Labels erteilen keine Verwaltungsrechte. Liste und Termindetail laden
zusaetzliche Teamlabels nur, wenn diese noch fehlen (z.B. Einzeleinladungen).
Diese Daten kommen nur aus internen Service-Ergebnissen, nie aus Clientdaten.
Schreib-, Frist-, Kapazitaets-, Revisions- und Elternpruefungen unveraendert.
Bestehende Fehlerprotokollierung bleibt erhalten.

## Messmethoden

`php tools/clubunio-performance-check.php /planning`

`php tools/clubunio-performance-check.php /planning/detail`

`php tools/clubunio-performance-check.php /planning/calendar calendar-week`

`php tools/clubunio-performance-check.php /planning/calendar`

Nur CLI, bestehende eigene ClubUnio-DB, Admin im Mandanten sv-sissach, READ
ONLY-Transaktion plus Rollback. Kalender/Detail am zuletzt beginnenden Termin
des Mandanten verankert. Keine IDs, Bindings, HTML, Accounts oder Secrets in
der Ausgabe. Gemessen wird der echte GET-Serveraufbau, nicht Proxy/Browser.

Vorher auf dem Pi: Liste1031ms/33 Queries, Termindetail1229ms/39,
Wochenkalender1123ms/37, Monatskalender1229ms/37. Einzelstichproben mit dem
vorhandenen Datenbestand, kein Lasttest oder garantiertes Antwortzeitbudget.

`php tools/clubunio-calendar-timeline-check.php`

Separater synthetischer CPU-Vergleich:500 Termine,42 Tage,20 Busintervalle,
fuenf Mannschaften. Originalalgorithmus gegen neue Berechnung, Ergebnis-Hash
muss identisch sein. Keine DB, Benutzer, HTTP-Anfragen oder echten Termine.
Pi-Ausgangswert fuer den Originalalgorithmus842.9ms. Lokaler Vergleich
1203.37->29.41ms; keine Behauptung ueber eine komplette Browser-Ladezeit.

## Tests und Migration

Neue reine Referenztests vergleichen dichte Kalender, Tageslisten und
Konflikte mit dem Originalalgorithmus; Mikrosekunden, leere Daten und beide
Zeitumstellungen eingeschlossen. Keine unsicheren Laufzeitschwellen im Test.

Zusaetzlicher Portaltest: eingeladener Spieler sieht das richtige Teamlabel,
aber weder fremde Termine noch Verwaltungsaktionen; nach Einladungsentzug
ist auch der bislang sichtbare Termin sofort gesperrt. Bestehende Tests fuer
Kalender, Filter, Export, Busopt-in, Fristsperren, Eltern, Privatgruende,
Gastspieler, Absenzen, Serien und Rollen weiterhin massgeblich.

Keine DB-Migration. Bei spaeterem Import die bestehenden Status-, Team-,
Einladungs-, Zeit-/Zeitzonen- und Berechtigungszuordnungen erhalten.
API-/SaaS-/Betriebspakete werden hier nicht erweitert.

## Bereitgestellt und nachgemessen

Release `20261016-clubunio-planning-performance`, Basis/Ruecksprung
`20261016-clubunio-dashboard-details-performance`. Bestehende Dashboard-,
Finanz- und Dossierfunktionen erhalten. Nur gezieltes Quelldelta, keine
DB-Migration oder Aenderung an RAM, Pool, Netzwerk oder PHPMaker DEV/PROD.

| Pi-Stichprobe | ms vorher/nachher | Queries vorher/nachher |
| --- | ---: | ---: |
| Planungsliste | 1031 / 941 | 33 / 32 |
| Termindetail | 1229 / 1103 | 39 / 38 |
| Wochenkalender | 1123 / 1175 | 37 / 35 |
| Monatskalender | 1229 / 1087 | 37 / 35 |

Beim vorhandenen, kleinen Kalenderbestand bleibt die Laufzeit durch
DB-Roundtrips gepraegt. Insbesondere die Wochenstichprobe ist trotz weniger
Queries nicht schneller; normale Mess-/Netzschwankung, keine Lasttest-SLA.
Keine pauschale Beschleunigung jeder Seite behauptet.

Synthetischer Pi-CPU-Test nach Deployment: Original860.97ms,
optimiert32.75ms, identischer Ergebnis-Hash fuer500 Termine/42 Tage/20 Busse.
Das ist nur die Konflikt-/Tagesberechnung, keine vollstaendige Webanfrage.

32 Tests/423 Assertions erfolgreich: Planung/Absenzen/Kalender/Pagination,
Buskalender, neuer Portal-Entzugstest, vier reine Timeline-Referenztests und
acht bestehende ReadPerformance-Tests. DB-Fixtures vollstaendig per Rollback.
Config-/Route-/Viewcache erneuert, PHP-FPM graceful reload. Guardchecker,
195 verschluesselte Settings und46 Nachrichtenvorlagen lesbar. Planungs-
Stichproben, oeffentlicher Login und Healthcheck HTTP200.
