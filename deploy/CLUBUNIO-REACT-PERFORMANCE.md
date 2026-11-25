# React-Ladeoptimierung auf DEV – 25.11.2026

Benutzerfreigabe: zuerst DEV messen, danach gemeinsame React-Laufzeit,
bedarfsgerechte Diagramme und Serverabfragen optimieren; Design/Animationen erhalten.
Aktiv: `20261125-clubunio-react-performance-v1` auf dem Pi.
Basis/Rückweg: `20261125-clubunio-clubhouse-ui-v1`. Stage und PROD unverändert.

## Ausgangsmessung und Ergebnis

Native CLI-Messung am gleichen DEV-Datenbestand und Administratorkonto:
ein Aufwärmlauf, danach fünf Messungen je Fall; Tabellenwerte sind Mediane.
Queryzahlen zählen den jeweiligen Fachablauf einschließlich Rechteabfragen.

| Messung | Vorher | Nachher |
| --- | ---: | ---: |
| Dashboard-Kennzahlen | 1592,14 ms / 4 Queries | 167,36 ms / 4 Queries |
| Ungelesen-Zähler, 24 Testaufgaben | 1042,93 ms / 27 Queries | 127,44 ms / 3 Queries |
| Ungelesen-Zähler, tatsächlicher Bestand | 121,99 ms / 3 Queries | 127,12 ms / 3 Queries |
| Initiales Menü + Dashboard, JavaScript roh | 402024 Bytes | 205407 Bytes |
| Gleiche Dateien mit gzip, Buildvergleich | 125971 Bytes | 66230 Bytes |
| Tatsächliche Übertragung auf DEV, Accept-Encoding gzip | 402024 Bytes | 66188 Bytes |

Das entspricht rund 89 % weniger Zeit für die Kennzahlenabfrage, 88 % für den
Test mit 24 Aufgaben und 47 % weniger komprimiertem JavaScript durch Aufteilung
und gemeinsame Laufzeit. Der zusätzliche tatsächliche DEV-Übertragungsgewinn
von rund 84 % umfasst die vorher fehlende Komprimierung dieser Dateien.
Der bereits leere Meldungszähler wird dadurch nicht schneller; seine Kosten
wachsen für Aufgaben jetzt nicht mehr mit jeder einzelnen Aufgabe.

Browsermessung am angemeldeten DEV-Dashboard mit normalem Chrome-Cache:
vorher FCP 2636/2584 ms und LCP 4104/3396 ms. Neuer erster Modulaufruf:
FCP 3228 ms, LCP 4168 ms; warmer Aufruf 2440/3440 ms, abschließender Aufruf
2680/3992 ms. Die Schwankungen erlauben keine belastbare pauschale Aussage zur
gesamten Seitenladezeit. Es gab keine kontrollierte Cacheleerung, CPU-/4G-Drosselung
oder reale Handymessung. Der HTML-TTFB bleibt ein wesentlicher Anteil.
Die Fachabfragen und übertragenen Dateigrößen sind separat klar verbessert.

Maschinenlesbare Belege: [Messdaten](evidence/react-performance-20261125/).
Sie enthalten nur Zeiten, Größen, Abfragezahlen und statische Assetpfade,
keine SQL-Bindings, Sitzungsschlüssel oder Geschäftsdaten.

## 1. Gemeinsame React-Laufzeit

`npm run build:react-ui` erstellt ESM-Einstiege für Navigation, Dashboard und
Anlagenplanung mit esbuild-Splitting. React 19.2 bleibt unverändert. React DOM
ist genau einmal in einem gemeinsamen Chunk enthalten. Der Hauptbuild ruft den
gemeinsamen Build statt drei separaten IIFE-Builds auf. Die bisherigen einzelnen
Buildbefehle/Dateien bleiben als Legacy-Rückweg verfügbar.

Dateinamen tragen Inhalts-Hashes. Ein serverseitiges Manifest wählt nur lokale,
vorhandene und validierte Dateien aus; fehlende/ungültige Einträge verwenden die
alten Skripte. Gemeinsame statische Chunks werden einmal pro Request per
`modulepreload` vorgeladen, ohne den dynamischen Diagramm-Chunk vorab anzufordern.
Der Browser nutzt die bereits geladene React-Laufzeit auch in der Anlagenplanung.

Bestehende, parallel bearbeitete Navigationsquellen wurden nicht pauschal
mit übernommen. Der verwendete Navigations-Build entspricht nachweislich dem
bereits ausgelieferten DEV-IIFE: SHA-256
`e246ce7da7b01397c01f4d757e3ea783b1746e72b65555813dbc4384da12a29f`.

## 2. Diagramme bei Bedarf

Der Diagramm-Renderer wird erst in Sichtweite eines Diagramms dynamisch importiert.
Ein IntersectionObserver steuert die Initialisierung (80 px Vorlauf). Außerhalb
des sichtbaren Bereichs erfolgen keine vorsorglichen React-Renderings. Datenänderungen
werden spätestens beim erneuten Sichtbarwerden angewendet. Vorhandene Roots werden
wiederverwendet; fehlgeschlagene Imports lassen die vollständige Datentabelle sichtbar.

SVG-Komponenten, Farben, Werte, Detailtabellen und Animationsfunktionen bleiben
unverändert. Der MutationObserver reagiert nur noch auf relevante Dashboard-Knoten
und Datenänderungen; Aktualisierungen werden pro Frame zusammengefasst.
Die bestehenden Bewegungspräferenzen und reduzierte Bewegung bleiben maßgebend.

Chrome: vorher alle sechs anfangs vorhandenen Diagramme sofort bereit; danach
zunächst 0 beziehungsweise 2 je sichtbarem Ausschnitt. Beim schrittweisen Scrollen
2 → 4 → 7 von 7 Diagrammen bereit, ohne Konsolenfehler. Menüsuche und Anlagenplaner
funktionieren mit der gemeinsamen Laufzeit. Englische Planung bei 390 px geöffnet;
Screenshoterstellung dabei technisch unterbrochen, keine vollständige mobile
Leistungsabnahme behauptet. Abschluss wieder Deutsch, normale Fenstergröße und
Dashboard ohne Diagnoseparameter, Animationen `full`.

## 3. Serverabfragen

Die offenen Rechnungssummen werden mit einem gruppierten Durchlauf über die aktiven
Positionen ermittelt, statt je Rechnung korrelierte Betragsabfragen auszuführen.
Vorhandene Bexio-Salden haben weiterhin Vorrang, auch wenn sie null/ausgeglichen
sind. Aktive Belege, offene Positionsstatus, Rundung und Mandantenbezug bleiben
erhalten. Die unveränderte native Rechnungsliste dient als Referenz; reale
DEV-Summen und ein separater Test mit gemischten Status, negativen Beträgen,
Rundung, inaktiven Positionen und fremdem Mandanten stimmen überein.

Der Meldungszähler nutzt die native `WorkTaskService::visible`-Sicht als SQL-Unterabfrage.
Er lädt und entschlüsselt nicht mehr jede einzelne Aufgabe. Andere Objekttypen
behalten ihre bestehenden Sichtbarkeitsprüfungen. Gruppierte Meldungsinhalte für
die aktuelle Seite werden gemeinsam statt einzeln geladen. Rechteentzug,
Empfänger-/Mandantentrennung und neue ungelesene Meldungen bleiben geprüft.
Kein zeitbasierter Daten-/Rechtecache und keine verzögerten Salden. Das bestehende
30-Sekunden-Polling nur sichtbarer Seiten bleibt unverändert.

## Auslieferung und Rückweg

20 Runtime-Dateien per SHA-256 geprüft, vorher inaktiven Kandidaten erstellt.
Private Konfiguration, Composer-Lock und gemeinsame Ablage erhalten. Keine
Schemaänderung/Migration und keine dauerhaft gespeicherten Benchmark-Datensätze.
Die Mess-Fixtures werden zurückgerollt, HTTP/Mail/Queue sind isoliert.
59 lokale Tests / 757 Assertions, neun Diagrammszenarien und 41 native
DEV-Fach-/Renderprüfungen bestanden. Nach der reinen Preload-Ergänzung zusätzlich
gezielte Renders und Browser geprüft. DE/EN: 3075 Katalogschlüssel geprüft.

Nur im bestehenden DEV-Nginx-Serverblock gilt für `/js/react-ui/` gzip Level 6,
Vary und ein Jahr Cache. Die Dateinamen sind inhaltsadressiert; keine privaten
Antworten werden gecacht. Bestehende Sicherheitsheader werden geerbt und wurden
über HTTP geprüft. Snippet: `deploy/nginx-react-ui-dev.conf`.
Sicherung vor Änderung: `shared/nginx-react-performance-before-20261125.conf`.

Anwendungsrückweg: `current` atomar auf den oben genannten Basisrelease setzen
und PHP-FPM neu laden. Alte Assets bleiben verfügbar. Nginx-Rückweg: gesicherte
DEV-Site wiederherstellen, `nginx -t`, kontrolliert neu laden. Kein Datenrestore.

Diagnose ist ausschließlich auf dem authentifizierten DEV-Dashboard bei
`?ui_perf=1` aktiv. Sie schreibt Messwerte in ein verborgenes DOM-Element;
normale Seiten enthalten keinen Mess-Timer und keine zusätzlichen UI-Texte.
