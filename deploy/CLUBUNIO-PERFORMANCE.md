# ClubUnio: gemessene Seiten- und PHP-Optimierung

Fortsetzung16.10.2026: verbleibende Menue-/Einstiegsabfragen auf bestehenden
Requestkontext vereinheitlicht; frische Schreibpruefungen unveraendert.
Messungen und Sicherheitsgrenzen: CLUBUNIO-DB-READ-PERFORMANCE.md.

## Befund16.10.2026

Pi:15GiB RAM, ca.12GiB verfuegbar, CPU zu99-100% untaetig, Load0.05-0.08.
Kein RAM-/CPU-Engpass zum Messzeitpunkt. PHP-FPM protokollierte um08:22:52
CEST das Erreichen von pm.max_children=4. Bisher ondemand mit20s Leerlauffrist;
ein Dashboard und parallele Livewire-Aufrufe koennen diese Grenze erreichen.
256MiB Speicherlimit pro ClubUnio-Anfrage, gemessene Listenspitze ca.60MiB.
Keine Memory-Exhausted-Fehlermeldung als Anlass fuer eine blinde Erhoehung.

Lesendes CLI-Profil auf dem Pi vor der Aenderung (einzelne Messungen):

| Seite | Aufbau ms | Abfragen | DB ms |
| --- | ---: | ---: | ---: |
| Mitglieder | 7915 | 278 | 7430 |
| Rechnungen | 4204 | 145 | 3862 |
| Spesen | 4275 | 146 | 3924 |

Die Mitgliederliste wiederholte dieselbe Mandanten-/Rollenpruefung219-mal.
Jeder Datenbank-Roundtrip zum bestehenden Hosting kostet rund27ms. Kein
Beleg fuer fehlende Hardwareleistung oder einen einzelnen langsamen Index.
Unangemeldeter Login bereits ca.0.10-0.18s vom Pi durch den Proxy.

## Gezielte Aenderungen

BackofficeReadContext wird nur im bereits authentifizierten Mandantenmiddleware
fuer GET aufgebaut. Frischer aktiver Benutzer und dessen eigene Admin-/Office-
Mandanten werden einmal gelesen, danach in den Request-Attributen verwendet.
Keine Redis-/Datei-/Session-/globale Berechtigungscaches. Kontext wird im
finally entfernt, auch nach Ausnahme. Andere Benutzer erhalten ihn nicht.
Neue Requests lesen die Rechte erneut; POST/PATCH/DELETE und Livewire-Aktionen
verwenden weiterhin die bisherigen frischen DB-Pruefungen. Dienste/Portale
ausserhalb dieses Middleware bleiben ungecached. Admin-Sonderpruefungen der
Bexio-Verbindung bleiben ausdruecklich unveraendert und frisch.
Ein laufender GET verwendet damit eine konsistente Rollen-Momentaufnahme;
ein paralleler Rechteentzug wird im naechsten Request wirksam, nicht zwingend
mitten im bereits laufenden Lese-Rendering. Keine Snapshotverwendung bei Writes.

Mitglieder- und Finanzlisten laden die Tenant-Beziehung gemeinsam statt pro
Zeile einzeln nach. Keine Datensaetze/Ergebnislisten cachen, kein Weglassen
von Mandantenfiltern und kein erweitertes Recht. Lokale Vergleichsmessung:
Mitglieder278 auf30 Abfragen, ca.7086 auf1201ms; Rechnungen33 Abfragen/1105ms.
Zeitwerte sind einzelne Diagnosemessungen, keine garantierten Antwortzeiten.

ClubUnio-FPM-Pool: dynamic statt ondemand, zwei vorgehaltene Prozesse,
min_spare2/max_spare4 und max_children8. memory_limit bleibt256M und
max_requests500. Benutzer, Socket, Dateirechte, Fehlerprotokollierung und
PHP-Endungsgrenze unveraendert. Keine pauschale RAM-/OPcache-Erhoehung.
Bis zu acht Prozesse koennen mehr Gesamtspeicher/DB-Verbindungen beanspruchen;
der Pool bleibt begrenzt. FPM-Reload betrifft den gemeinsamen PHP8.3-Dienst
kontrolliert, geaendert wird nur die ClubUnio-Pooldatei.

## Diagnose und Datenschutz

tools/clubunio-performance-check.php ist ausschliesslich CLI, keine Webroute.
Fest erlaubte GET-Seiten, eigener ClubUnio-DB-Guard. Waehlt einen vorhandenen
aktiven Admin zur internen Darstellung; keine Passwortabfrage oder Tokenanlage.
DB-Transaktion READ ONLY und Rollback, fluechtige Array-Session. Keine externen
Schreibaktionen. Ausgabe nur Status, Zeiten, Speicher, Queryanzahl sowie
SQL-Fingerprints/Tabellennamen, keine Bindings, HTML-Inhalte oder Identitaeten.
Normale Fehlerprotokollierung bleibt erhalten; kein Debug-Dauerlogging.
Die Diagnose bildet den serverseitigen ersten Seitenaufbau ab, nicht alle
nachgeladenen Widgets, Browserdarstellung oder Internetlatenz.

## Deployment / Ruecknahme

Keine DB-Migration. Appdelta auf Kopie des zuletzt aktiven Pi-Releases;
parallele Navigation erhalten, fremde lokale Finanzarbeiten nicht uebertragen.
Config-/Routen-/Viewcache neu erzeugen, Guards pruefen, dann atomar umschalten.
Ruecknahme auf vorherige Release-Junction und FPM graceful reload.
FPM-Quelle:deploy/php-fpm/svs-laravel.conf. Installer prueft bekannten
Ausgangs-SHA256, sichert Pooldatei privat, validiert php-fpm8.3 -t, rollt bei
ungueltiger Konfiguration zurueck und laedt kontrolliert neu. Ruecknahme:
gesicherte Pooldatei wiederherstellen, validieren, graceful reload. Kein
Neustart von Netzwerk/Nginx und keine Lockerung der Zugriffssperren.
PHPMaker DEV/PROD, API und SaaS bleiben unveraendert.

## Tests vor Bereitstellung

46 Tests/485 Assertions erfolgreich, davon ein optionaler realer Azuretest
ausgelassen. Neue Tests pruefen50 wiederholte Rollenabfragen ohne weitere
DB-Roundtrips, sofortige frische POST-Pruefung nach Rollenentzug, Entzug beim
naechsten GET, Benutzer-/Mandantentrennung und Cleanup nach Ausnahmen.
Zusaetzlich Navigation/Dossierpflege, Mitglieder-/Anmeldedokumente, Verwaltung,
Spesen-/Bankrechte und Bexio-PDF-Zugriff. Ein vorhandener PDF-Test verwendete
noch die alte URL mit erwarteter200-Antwort; er prueft jetzt ausdruecklich die
bereits vorgesehene Weiterleitung und danach die aktuelle Dokumentroute.
Keine produktive PDF-/Routingaenderung. Alle DB-Testfixtures per Rollback.

## Pi-Nachmessung und Abschluss

Bereitgestellt20261016-clubunio-73-performance auf Basis/Ruecksprung
20261016-clubunio-navigation-qa, deren Oberflaeche vollstaendig erhalten.
Keine laufenden fremden Finanzdateien in das Delta aufgenommen.

| Seite | Vorher ms | Nachher ms | Queries vorher/nachher |
| --- | ---: | ---: | ---: |
| Mitglieder | 7915 | 1072 | 278 / 30 |
| Rechnungen | 4204 | 1196 | 145 / 33 |
| Spesen | 4275 | 1173 | 146 / 33 |

Jeweils HTTP200 im identischen lesenden CLI-Profil. DB-Anteil nachher ca.748,
914 und895ms, PHP-Spitze58.5-60.5MiB. Das sind Stichproben, keine Lasttest-SLA
oder Browser-/Filtermessung. Neue FPM-Konfiguration validiert und um08:43 CEST
graceful geladen; zwei ClubUnio-Prozesse werden warm gehalten. Sicherung:
/var/backups/clubunio-fpm-performance/20261016T064300-4082078/svs-laravel.conf.
256M je Anfrage unveraendert, kein RAM-Zukauf/OPcache-Reset noetig.

Config-/Routen-/Viewcache und Mandanten-/Dokumentchecker erfolgreich;
195 verschluesselte Settings und46 Nachrichtenvorlagen weiterhin lesbar,
Outbound aus. Bestehender Nginx-Wiederanlaufschutz bleibt erhalten.
Oeffentliche /login und /up HTTP200, sieben Runtime-Dateien lokal/Pi SHA256
identisch. Keine Aenderung an Netzwerkfreigaben oder dem bestehenden Nginx-Drop-in.

## Filter und Suche: begrenzte Renderphase (16.10.2026)

Die Anschlussoptimierung erweitert den Kontext ausschliesslich auf die
Livewire-Renderphase im ClubUnio-Panel mit ausgewaehltem Mandanten. Frischer
aktiver Account und Admin-/Office-Zuordnungen werden pro Renderdurchlauf
gelesen. Keine Ergebnis-, Session- oder requestuebergreifenden Rechtecaches.

BackofficeHandleComponents erweitert den installierten Livewire-Handler,
ohne Vendor-Code zu kopieren: parent::render laeuft innerhalb eines
try/finally-Kontexts. parent::update und parent::mount suspendieren einen
eventuellen aeusseren POST-Kontext. Damit bleiben Hydrierung, Property-Hooks,
Aktionen, verschachtelte Mounts und Dehydrierung ausserhalb des Snapshots.
Nach normaler Darstellung und Exceptions wird der vorige Kontext restauriert
oder entfernt. GET-Verhalten aus Performance73 bleibt erhalten. Bestehende
Policies, Fachservices, Mandantenfilter, Formrevisionen und CSRF unveraendert.
Bei Livewire-Upgrades sind Handler-Signaturen und Lifecycle-Grenzen mit den
Integrations-/Berechtigungstests erneut zu pruefen.
Gepruefte installierte Version: Livewire4.4.7. Fehlerprotokollierung bleibt
unveraendert; Diagnoseausgaben enthalten weiterhin nur aggregierte SQL-Metriken.

Diagnose: `php tools/clubunio-performance-check.php /members search` bzw.
`/members filter-search`, ausserdem `/invoices search` und `/expenses search`.
Nach dem initialen GET wird ein signierter Snapshot durch den echten
Livewire-update-Lifecycle verarbeitet; nur dessen Laufzeit/Queries werden
gemessen. Suche nach `a`, bei filter-search zuerst ein vorhandener Status.
Nur CLI, eigene DB, READ ONLY plus Rollback; kein HTML, Bindings, Token oder
Identitaeten in der Ausgabe. Kein HTTP-/Proxy- oder Browser-End-to-End-Lasttest.

Pi-Vergleich vor Deployment: Mitglieder-Suche4471ms/160 Queries,
Statusfilter plus Suche4313ms/161, Rechnungen1138ms/42, Spesen1255ms/43.
Lokal optimierte Mitgliedersuche328ms/11 Queries als erste Stichprobe.
Finale Pi-Nachmessung und Release siehe folgenden Abschluss.

### Bereitstellung und Nachmessung

Release `20261016-clubunio-filter-performance`, Basis/Ruecksprung
`20261016-clubunio-dossier-finance`. Vorhandene Dossier-Finanzdialoge erhalten;
parallel bearbeitete andere Fachdateien nicht in das Delta aufgenommen.
Keine DB-Migration, Netzwerk-/FPM-Poolaenderung oder PHPMaker-Bereitstellung.
Config-/Route-/Viewcache erneuert, PHP-FPM graceful reload. Mandanten- und
Dokumentchecker erfolgreich;195 verschluesselte Settings/46 Templates lesbar.

| Livewire-Aktion | Vorher ms | Nachher ms | Queries vorher/nachher |
| --- | ---: | ---: | ---: |
| Mitglieder suchen | 4471 | 410 | 160 / 11 |
| Mitglieder filtern, danach suchen | 4313 | 428 | 161 / 12 |
| Rechnungen suchen | 1138 | 452 | 42 / 14 |
| Spesen suchen | 1255 | 448 | 43 / 15 |

Einzelne identische CLI-Lifecycle-Stichproben, ohne Netz-/Browserlaufzeit,
kein Lasttest. GET-Mitglieder weiterhin1069ms/30 Queries. /login und /up
oeffentlich HTTP200. PHP-Speicherspitze bei Suchprofilen58.5-62.5MiB.

Neue Suite ReadPerformanceTest:8 Tests/197 Assertions erfolgreich. Prueft
Render-Buendelung, frische POST-/Aktionsrechte, verschachtelten Cleanup,
Renderausnahmen, echten Livewire-Lifecycle, Filter/Suche, Mandantentrennung
und Rollenentzug. Zusaetzlich Navigation/Workspace und Saison-/Dossierfinanzen
erfolgreich;6 Kernberechtigungstests/41 Assertions und Mitglieder-/Anmeldungs-
dokumente25 Tests/199 Assertions (1 optionaler Azuretest ausgelassen).
Alle DB-Testfixtures transaktional zurueckgerollt; kein Versand/Bankauftrag.
