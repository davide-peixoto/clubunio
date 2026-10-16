# ClubUnio: Dashboard und Detailperformance

Stand16.10.2026. Nur ClubUnio, keine Aenderungen an PHPMaker DEV/PROD,
API oder SaaS. Keine Datenmigration, externen Buchungen oder Bankzahlungen.

## Gemessener Ausgangspunkt

Read-only-CLI-Profil auf dem Pi, Datenbanktransaktion mit READ ONLY und
Rollback. Authentifizierter Admin im eigenen ClubUnio-Mandanten. Keine
Identitaeten, SQL-Bindings, Dokumente oder Secrets in der Ausgabe.

| Ansicht / Phase | ms vorher | Queries vorher |
| --- | ---: | ---: |
| Dashboard, zehn eigene Widgets nacheinander | 2030 | 63 |
| Davon Kennzahlen-Kachel | 504 | 16 |
| Rechnungsdetail GET | 1555 | 46 |
| Rechnungsdetail Livewire-Refresh | 885 | 31 |
| Mitgliederdetail GET | 800 | 21 |
| Spesendetail GET | 1044 | 32 |
| Mitgliederdossier: Rechnungen, Spesen, Dokumente | 744 | 23 |

Die Dossier-Stichprobe waehlt das nicht archivierte Mitglied mit den meisten
Rechnungen; Detail-Stichproben jeweils den neuesten Datensatz des Mandanten.
Diagnoseausgaben enthalten keine IDs. Widgets werden ueber den echten
Livewire-Mount mit deaktiviertem Lazy-Platzhalter geladen. Browser laedt diese
teilweise parallel; die sequenzielle Summe ist keine Bildschirm-Ladezeit.
Refresh verwendet einen signierten Snapshot aus dem vorherigen GET.
Netzwerk/Proxy und Browser sind nicht Teil dieser Lifecycle-Stichproben.

## Optimierungen

- DashboardService::overview laedt alle Kennzahlen in einer gemeinsamen
  SQL-Abfrage mit Unterabfragen. Vorher ein frischer aktiver Account und
  dessen Mandantenrecht. Bestehende aktive Mitglieder-/Austritts-, Anmeldungs-,
  Spesen-, Rechnungspositions-, Rundungs- und Rueckerstattungsregeln erhalten.
  Offene Betragsdezimalen werden nicht auf ganze Franken gekuerzt. Bestehende
  openSummary/refundableCash bleiben fuer andere Aufrufer separat geschuetzt.
- Statusdiagramme verbinden Zahlen und Statusname in einer Abfrage. Explizite
  Mandantenbindung des Joins; archivierte Mitglieder/inaktive Spesen wie bisher
  ausgeschlossen. Inaktive Lookupwerte bestehender Datensaetze und fehlender
  Status bleiben sichtbar. Statusfarben unveraendert.
- InvoiceDetailTotals buendelt vier Gutschrift-/Rueckerstattungsaggregate
  fuer die Rechnung in einer Abfrage. Wiederholte Zellen nutzen nur Attribute
  des bereits serverseitig geladenen Models; refresh/neue Hydrierung laedt neu.
  Kein Session-/globaler Daten- oder Berechtigungscache. Zusaetzliche explizite
  Tenantfilter auf Beleg und Status. Reine Anzeige, kein Zahlungsnachweis.
- Bexio-Aktionssichtbarkeit prueft zuerst den fachlichen Status, erst danach
  die unveraenderte frische Admin-/Dispatch-Berechtigung. Alle ausgefuehrten
  Aktionen behalten ihre bisherigen Fach-, Revisions- und Berechtigungssperren.

Mitglieder-/Spesen-Kopf und Untertabellen zeigten keine relevante
N+1-Abfragevervielfachung in diesen Stichproben. Dort keine pauschale
Eager-Loading-/Cache-Erweiterung und keine Entfernung frischer Rechtepruefungen.
Fehlerprotokollierung bleibt erhalten. Kein Memory-/Pool-/Netzwerk-Tuning.

## Tests und Migration

DashboardDetailPerformanceTest: gleiche Zaehler/Austrittsregeln, unveraenderte
Nachkommabetrage/Guthaben, ein Finanzaggregate-Query trotz vier Aufrufen,
leere Belege, neue Daten nach Model-Refresh, korrekte Statuslabels und Farben,
Mandantentrennung, Rollenentzug und gesperrte fremde Detail-URLs.
Zusaetzlich bestehende Dashboard-, ReadPerformance-, Dossierfinanz- und
Gutschrift-/Rueckerstattungsworkflows. Testdaten nur in Rollback-Transaktionen;
externe HTTP-Aufrufe in Tests gesperrt oder gemockt. Keine DB-Migration.

Bei spaeterer Migration dieselben Status-/Belegzuordnungen und Betragsregeln
erhalten; keine produktiven Importdaten durch diesen Performance-Release.

## Diagnose und Ruecknahme

`php tools/clubunio-performance-check.php / widgets`

`php tools/clubunio-performance-check.php /invoices/detail`

`php tools/clubunio-performance-check.php /invoices/detail detail-refresh`

`php tools/clubunio-performance-check.php /members/dossier relations`

Ausserdem /members/detail und /expenses/detail. Ausfuehrung nur CLI, eigene DB
und lesende Transaktion; keine neue Webroute oder Authentifizierungsumgehung.
Releaseinstaller prueft den aktuellen Ruecksprung vor Kopie und Aktivierung.
Ruecknahme ueber den vorherigen current-Symlink plus graceful PHP-FPM reload;
keine Schema-Rueckmigration notwendig.

## Bereitgestellt und nachgemessen

Release `20261016-clubunio-dashboard-details-performance`, Basis/Ruecksprung
`20261016-clubunio-dossier-lifecycle`. Neuere Dossierdialoge, Kopier- und
Aktivstatusaktionen aus parallelen Fachpaketen erhalten. Ausschliesslich
die hier beschriebenen Runtime-Dateien und Dokumentation als Delta.

| Ansicht / Phase | ms vorher/nachher | Queries vorher/nachher |
| --- | ---: | ---: |
| Zehn eigene Dashboard-Widgets sequenziell | 2030 / 1586 | 63 / 51 |
| Kennzahlen-Kachel | 504 / 202 | 16 / 6 |
| Rechnungsdetail GET | 1555 / 1221 | 46 / 37 |
| Rechnungsdetail Livewire-Refresh | 885 / 635 | 31 / 22 |
| Mitgliederdetail GET | 800 / 773 | 21 / 21 |
| Spesendetail GET | 1044 / 1062 | 32 / 32 |
| Mitgliederdossier, drei Untertabellen | 744 / 800 | 23 / 23 |

Identische lesende Lifecycle-Stichproben, keine Lasttest-/Browser-SLA.
Bei unveraenderten Ansichten normale Laufzeitschwankung, kein behaupteter
Geschwindigkeitsgewinn. PHP-Speicherspitze54.5-62.5MiB. Keine RAM-Erhoehung.

Neue Suite6 Tests/30 Assertions; ReadPerformance8/197; bestehende Dashboard-
und Rechtepruefungen9/73; Saison-/Dossierfinanzen und Gutschriften31/334:
insgesamt54 erfolgreiche Tests/634 Assertions. Alle Fixtures zurueckgerollt.
Config-/Route-/Viewcache erneuert, PHP-FPM graceful geladen. Guardchecker,
195 verschluesselte Settings und46 Nachrichtenvorlagen erfolgreich.
Oeffentlicher Login und Healthcheck jeweils HTTP200. Keine echten Zahlungen,
kein Finanzversand, keine Aenderung am bestehenden PHPMaker DEV/PROD.
