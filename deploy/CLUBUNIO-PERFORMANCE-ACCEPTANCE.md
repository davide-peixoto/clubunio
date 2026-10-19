# ClubUnio: uebergreifende Performance-Abnahme

19.10.2026. Nur ClubUnio-Web-Anwendung und CLI-Messungen. PHPMaker DEV/PROD,
API, SaaS und Betrieb unveraendert. Keine echten Buchungen, Bankzahlungen,
Nachrichten oder externen KI-/Bexio-/Azure-Aufrufe.

## Ergebnis und Entscheidung

Die Standardsichten liegen in den drei gemessenen Wiederholungen ueberwiegend
unter1,2 Sekunden im Anwendungs-Lifecycle. Die sequenziell gemessenen
Dashboard-Widgets liegen bei2,6 Sekunden; sie werden im Browser teilweise
parallel geladen. Die Summe ist keine Bildschirm-Ladezeit. Keine neue
Abfragevervielfachung in diesen Stichproben nachgewiesen; das ist kein Beweis
fuer alle Profile, Datenmengen oder denkbaren Filterkombinationen.

Keine weitere Fachcode-Aenderung allein aufgrund einer vermuteten Verbesserung.
Frische Rechtepruefungen machen einen relevanten Anteil der DB-Roundtrips aus;
sie werden nicht entfernt, zeitlich verlaengert oder ueber Requests gecacht.
Kein Memory-/Pool-/Proxy-/Netzwerk-Tuning. Keine neue Runtime-Telemetrie.

## Messmatrix Pi

Je drei separate PHP-Prozesse, gleicher Stand und Bestand; Median sowie
Min/Max. DB-Zeit clientseitig inklusive Transport/Warten/Treiber gemessen,
nicht identisch mit reiner SQL-Ausfuehrungszeit am PostgreSQL-Server.

| Szenario | Median ms | Min-max ms | Queries | DB ms |
| --- | ---: | ---: | ---: | ---: |
| Mitgliederliste | 890 | 875-961 | 21 | 560 |
| Mitglieder Filter und Suche | 476 | 459-477 | 13 | 339 |
| Rechnungsliste | 903 | 873-918 | 24 | 619 |
| Spesenliste | 899 | 879-937 | 24 | 604 |
| Anmeldeliste | 1012 | 1006-1033 | 23 | 664 |
| Mitglieder-Auswertung | 910 | 881-922 | 22 | 601 |
| Rechnungsdetail | 1109 | 1100-1189 | 33 | 848 |
| Rechnungsdetail Livewire-Refresh | 801 | 755-809 | 28 | 755 |
| Mitgliederdetail | 571 | 551-577 | 13 | 349 |
| Drei Dossier-Untertabellen nacheinander | 1159 | 1139-1183 | 41 | 970 |
| Monatskalender | 915 | 829-956 | 25 | 722 |
| Dashboard-Widgets nacheinander | 2606 | 2494-2814 | 93 | 2416 |

PHP-Allokationsspitzen dieser Prozesse54,5-62,5 MiB, keine Aussage zur
gesamten Serverauslastung oder zu gleichzeitig angemeldeten Benutzern.
Detail/Dossier-Auswahl wie im bestehenden Profiler: neuester Datensatz bzw.
Mitglied mit den meisten Rechnungen. Keine IDs/Namen in Diagnoseausgaben.

Separate synthetische Rollback-Szenarien, ebenfalls je drei Wiederholungen:

| Szenario | Median ms | Min-max ms | Queries | DB ms |
| --- | ---: | ---: | ---: | ---: |
| Mitglied intern speichern | 342 | 320-355 | 11 | 289 |
| Trainingsliste20 von25 Plaenen | 436 | 418-472 | 16 | 395 |
| Trainingsplan lesen | 77 | 76-81 | 3 | 76 |
| Trainings-PDF inkl. Rechte/Lesen | 316 | 308-326 | 4 | 97 |
| Private Trainingskopie ohne Provider | 521 | 510-573 | 18 | 448 |
| Dossierhistorie100 von110 Eintraegen | 305 | 287-307 | 2 | 296 |

Historienfixture mit rund20KB zusaetzlichem, nicht freigegebenem Inhalt je
Ereignis. Rueckgabe bleibt auf100 Eintraege und freigegebene Felder begrenzt;
Fixture-Zusatzinhalt erscheint nicht. Keine historischen Daten geloescht.
Alle Schreib-Fixtures in eigenen Vereinen und mit finally-Rollback.
PostgreSQL-Sequenzen koennen Luecken erhalten, keine Bestandsdaten geaendert.

## Netz-/Browsergrenzen

Drei anonyme HTTPS-Login-Aufrufe vom lokalen Rechner: alle HTTP200,
Gesamt86/104/229ms, Median104ms. Diese Werte enthalten DNS/TCP/TLS/Proxy/
Anwendung des oeffentlichen Logins; sie duerfen weder als private Seitenzeit
noch als isolierte Netzwerkzeit interpretiert oder von obiger CLI-Matrix
subtrahiert werden. Browserdarstellung/Livewire-Parallelitaet nicht enthalten.

Die native Browsersteuerung wurde aus Sicherheitsgruenden beendet, weil sie
die aktuelle Browser-URL nicht zuverlaessig bestimmen konnte. Keine Umgehung,
keine Cookies/Tokens ausgelesen und keine Sitzung im Terminal nachgebaut.
Eine erneute angemeldete Browsermessung und die noch offene visuelle Pruefung
eines bestehenden Trainings-PDFs bleiben ausdruecklich offen. Kein kompletter
Last-/Mehrbenutzer-/Browser-p95-Test und kein SLA aus drei Messpunkten.
Externe KI-/Bexio-/Azure-Zeiten bewusst nicht durch neue Echtaufrufe getestet.

## Wiederholung

Nur CLI, keine neuen Webrouten:

```text
php tools/clubunio-performance-suite.php 3 all
php tools/clubunio-performance-suite.php 3 read
php tools/clubunio-performance-suite.php 3 synthetic
```

3-5 Wiederholungen erlaubt, nur diese fest definierten Szenarien/Child-Tools.
Windows-Childprozesse laden pdo_pgsql explizit; Pi verwendet PHP-Konfiguration.
Lesende Childprozesse pruefen exakte ClubUnio-DB und setzen READ ONLY mit
Rollback; synthetische Childprozesse pruefen dieselbe DB und rollen eigene
Fixtures zurueck. Ausgehende Laravel-HTTP-Aufrufe gesperrt. Keine automatischen
Sync-Wiederholungen, echten Rechnungen oder Bankzahlungen.

Suite gibt nur Szenarioname, Zeiten, Queryzahlen, optionale PHP-Allokation und
Messumfang aus. Keine SQL-Bindings, HTML, Dokumente, Identitaeten oder Secrets.
Fehlende/ungueltige Child-Ergebnisse oder HTTP-Status ungleich200 brechen mit
generischer Szenariomeldung ab. Ergebnisse nicht blind als Geschwindigkeit
des gesamten Bildschirms ausgeben; gleiche Szenarien/Bestandsbasis vergleichen.

20 gezielte Tests/343 Assertions erfolgreich: ReadPerformanceTest,
DashboardDetailPerformanceTest und zwei SeasonFinance-Faelle fuer Historie/
Dialogaktualisierung. Rechteentzug, Tenantgrenzen, Renderkontext, Kennzahlen/
Finanzsummen, Nullwerte und Feldfreigaben enthalten. CLI-Argumentgrenzen und
ungueltiger Scope ohne Datenbankaufruf abgewiesen; Pint/Syntax geprueft.
Bekannte abweichende Bexio-Menueassertion aus SyncWorkTest bleibt unveraendert;
kein Performancefehler und kein Anlass, produktive Navigation umzustellen.

## Migration und Ruecknahme

Release20261019-clubunio-performance-acceptance basiert auf
20261019-clubunio-caretaker und behaelt dessen schmale Platzwartrechte.
Nur CLI-Werkzeuge und Dokumentation, kein Anwendungs-/Fachcode geaendert.
Release aktiviert; Konfigurations-/Routen-/Viewcache und Tenant-/Dokument-/
Profilchecks erfolgreich. Historien-Rollbackpruefung im neuen Release283ms,
zwei Queries. Kein weiterer Profilupdate oder Import beim Deployment.
Keine Schema-/Indexmigration, Parameter, Importe oder Rollenwechsel.
Ruecknahme per vorigem Release-Symlink ohne DB-Rollback. Keine .env, Secrets,
Uploads, vendor oder Laufzeitdateien im Commit. Migrationsplan fortschreiben.
