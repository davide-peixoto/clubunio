# Mobile API: Messung und gemeinsame Read-Optimierung (04.11.2026)

Ausloeser: Spesen laden laut Benutzer im iPhone-Simulator8-9 Sekunden; zusaetzlich
Teamplanung, My Teams und alle Module pruefen. Es handelt sich nach den ersten
Messungen tatsaechlich auch um Server-/Datenbankwartezeit, nicht allein Rendering.

## Messverfahren und Grenzen

benchmark-mobile-reads.php laeuft ausschliesslich auf ClubUnio-DEV und eigener
PostgreSQL-DB mit explizitem --synthetic-rollback. Ein zufaelliges temporaeres
Konto und eigene Sitzung erhalten innerhalb einer Transaktion Admin- oder
Technical-Lead-Rolle; letzteres nur mit einem echten vorhandenen aktiven
Member-/Teambezug. Alles wird zurueckgerollt. Keine echten Kontopasswoerter,
Tokens oder Fachinhalte werden ausgegeben, nur Status, Zeit, Queryzahl,
SQLzeit und Datenmenge. HTTP/Mail/Queue-Provider sind gesperrt/ersetzt.

Gemessen wird der echte Laravel-HTTP-Kernel mit bestehendem Tenantbestand,
Middleware und DTOs, zweimal je Route. Der Prozess/DB-Connect ist bereits warm;
PHP-FPM-Warteschlange, HTTPS/TLS/Internet und iPhone-Rendering sind nicht enthalten.
Erster select1 einschliesslich kaltem DB-Connect etwa0.38s, folgende Queries
rund26ms. Oeffentlicher HTTPS-Bootstrap von Windows:222/50/68ms, kein Nachweis
fuer das konkrete Simulatornetz. Zwei Samples sind ein gezielter Vorher-/Nachher-
Vergleich, kein Lasttest oder Geraetebenchmark.

## Erste reproduzierte Baseline (performance-v1)

| Serverroute / Rolle | Zeiten ms | SQL | Daten |
| --- | --- | --- | --- |
| Planung20/Admin |15836 /15871 |609 /608 |20 von26Terminen |
| Modulmenue/Admin |1322 /1331 |52 |11Module |
| Spesen/Admin |953 /917 |34 |61Belege |
| My Teams/Admin |460 /464 |18 |20 von28Teams |
| Modulmenue/Technische Leitung |5907 /5855 |241 |9Module |
| Spesen/Technische Leitung |1870 /1770 |73 |19Belege |

Die Menue-/Spesenkette mit Technical-Lead-Rechten kommt bereits serverintern
in die gemeldete Groessenordnung. Das ist ein nachgestellter Rollenpfad und
kein Nachweis, dass genau derselbe Ablauf beim Benutzer stattgefunden hat.

## Gemeinsamer Read-Rahmen

MobileReadContext teilt nur bereits frisch geladene Actor-, Membership- und
Moduleprofilfakten innerhalb genau eines authentifizierten Mobile-GETs.
Frame an Requestobjekt, Actor-ID und authentifizierte Session-ID gebunden;
Tenant-/Profil-Keys getrennt, null/false korrekt gecacht, Fehler nicht gespeichert.
Nested Loads erhalten ihre Fakten ohne gegenseitiges Ueberschreiben; nested
Frames haben eigene Scopeidentitaet. finally entfernt/restauriert den Frame.
Kein Singleton-/TTL-/Crossrequestcache, keine Record-/Parent-/Teamownershipcache.

POST/PATCH/PUT/DELETE und Methodwechsel zu POST verwenden den Rahmen nie.
Explizites authenticate sowie Tenant-/Sessionlocks in Mutationen bleiben
unveraendert. Ein Rollenwiderruf wird beim naechsten Request frisch gelesen;
innerhalb eines GETs gelten dessen konsistente Startfakten. Der Rahmen ist
kein Datenbank-ReadOnly-Modus: bestehende GET-Quellenmaterialisierung bleibt
separat fachlich geprueft. Zukuenftige Provider-/Fachaktionen gehoeren nicht
in diesen Readrahmen. Ein unabhaengiger Quellenreview bestaetigt Konto-/Tenant-/
Requestcopy-/Nested-/Writegrenzen; gezielte SQLite-/HTTPregressionen bestehen.

## Serveranteil im persoenlichen Diagnoseexport

MobileMiddleware liefert nur Server-Timing:app;dur=<Zahl>. Der Messbeginn liegt
im Mobile-Middleware-Einstieg: PHP-Bootstrap vor dieser Stelle und Netzwerk
liegen ausserhalb. Der SDK-Diagnosepuffer behaelt optional nur die streng
validierte numerische serverMilliseconds-Zahl neben der gesamten SDK-Zeit.
Keine Headertexte, URLs, Bindings oder privaten Daten werden exportiert;
mehrfache/ungueltige/zu grosse Werte werden verworfen. Reset-/Kontogrenzen
bleiben erhalten. So lassen sich bei erneuten8-9Sekunden die eigentlichen
API-Anteile von restlicher Laufzeit unterscheiden. Kein neuer technischer
Dialog im normalen Produktablauf.

## Vollstaendiger Admin-Read-Sweep vor dem Delta

| Einstieg | Zwei Samples ms | SQL |
| --- | --- | --- |
|bootstrap|414 / 390|17 / 17|
|modules|1188 / 1199|52 / 52|
|expenses-100|840 / 801|34 / 34|
|expenses-options|589 / 529|22 / 22|
|planning-20|14286 / 14926|609 / 608|
|my-teams-20|461 / 442|18 / 18|
|members-20|336 / 345|14 / 14|
|registrations-20|448 / 415|18 / 18|
|documents-20|681 / 674|27 / 27|
|training-20|298 / 288|12 / 12|
|sponsor-20|457 / 466|19 / 19|
|bus-20|629 / 624|26 / 26|
|facilities|303 / 283|12 / 12|
|notifications-20|299 / 301|12 / 12|

Leere persoenliche Quellen (z.B. frische synthetische Notifications) bilden keine grosse Inbox ab. Dazu dienen separate gefuellte Paritaets-/Querycountfixtures; alle Statusantworten200.

Abschluss vor DEV: gemeinsame HTTP-/Readrahmenregression47 Tests/744 Assertions
gruen, globale ModulePermission-PG-Regression13/80 gruen. Vollstaendiger
Appnachlauf500/500 gruen, Dartanalyse ohne Befund; Root misst nach Bereitstellung
dieselben Admin-/TL-Kernelrouten erneut.

## Erfolgreiche Nachmessung auf DEV

Aktiv:20261104-clubunio-mobile-modules-performance-v1, Quelleacfda56.
17 Git-/SHA-gepruefte Dateien, bestehende15 unveraenderte Baselines und2neue Dateien.
Alle bisherigen Freigabegates bestanden; Env/Composer/Schema/Providerflags gleich.
Die folgenden Zeiten sind Mittelwerte aus jeweils2 warmen Kernelaufrufen.

| Rolle / Einstieg | Vorher ms | Nachher ms | SQL vorher → nachher |
| --- | --- | --- | --- |
|admin /bootstrap|402.1|214.8|17 →8|
|admin /modules|1193.5|285.3|52 →12|
|admin /expenses-100|820.2|538.2|34 →20|
|admin /expenses-options|559.0|424.2|22 →16|
|admin /planning-20|14605.9|784.1|608/609 →29/30|
|admin /my-teams-20|451.2|245.8|18 →10|
|admin /members-20|340.4|224.5|14 →9|
|admin /registrations-20|431.9|295.5|18 →12|
|admin /documents-20|677.7|255.4|27 →10|
|admin /training-20|293.0|239.1|12 →10|
|admin /sponsor-20|461.7|279.4|19 →11|
|admin /bus-20|626.2|384.6|26 →16|
|admin /facilities|293.1|258.3|12 →10|
|admin /notifications-20|300.0|236.5|12 →9|
|technical_lead /bootstrap|657.0|255.8|26 →9|
|technical_lead /modules|5881.4|731.5|241 →26|
|technical_lead /expenses-100|1819.6|628.7|73 →22|
|technical_lead /expenses-options|731.9|512.1|28 →17|

Bekannte Datensatzanzahlen unveraendert:61 Adminspesen,19 TL-Spesen,20Kalendertermine,20Teams/28gesamt. Keine Datenreduktion als Performanceersatz. Kleine bzw. leere Quellen sind keine Aussage ueber sehr grosse persoenliche Archive; gefuellte Paritaets-/Querycountfixtures pruefen diese Skalierung separat.
Nachmessungen haben Rueckrollen und Abwesenheit ihres eigenen Kontos/Session explizit geprueft. HTTPS-Nachlauf:Login200/PublicBootstrap200, geschuetzteSpesen/Planung/MyTeams401 mit numerischem Server-Timing/no-store; geschlossene Identity503. PHP-FPM aktiv,17 Runtimehashes erneut bestaetigt. Kein nativer iPhone-Simulatorlauf.
