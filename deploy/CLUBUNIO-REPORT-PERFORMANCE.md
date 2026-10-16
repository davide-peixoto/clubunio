# ClubUnio: Listen, Auswertungen und Exporte

16.10.2026. Nur ClubUnio. API, SaaS, Betrieb und PHPMaker DEV/PROD unveraendert.

## Befund und Umsetzung

Spieler-, Staff-, Familien-, Bank-, Schluessel-, Passivmitglieder- und
Ohne-Mannschaft-Listen luden den Vereinsdatensatz je Zeile erneut. Auch die
Offene-Rechnungen-Liste umging das tenant-Eagerloading der Basisressource.
Diese acht Ressourcen laden tenant jetzt gesammelt. Bestehende Abfragen,
Aktiv-/Austrittsregeln, Rollen und Policies bleiben erhalten; kein Rechtecache.

Report-/Finanzexporte klonen weiterhin die gefilterte/sortierte Tabellenabfrage,
entfernen aber reine Anzeige-Eagerloads. Nur die wirklich benoetigten
CSV-Relationen werden gesammelt nachgeladen. Spieler/Staff benoetigen z.B.
keine Mitgliederstatus-/Mitgliederart-Relation. Mitgliederprojektion und
verknuepfte Mitgliederdaten laden nur benoetigte Felder statt historischer
Bilder oder privater Zusatzdaten. Bankdaten bleiben maskiert, Kontakte nur
im ausdruecklichen Kontaktlistenexport. Ausgabespalten unveraendert.

Finanzexporte bereiten erlaubte CSV-Werte einschliesslich IBAN-Maskierung
vor dem Senden der Header vor. Der Response haelt nur skalare Ausgabefelder
statt eines vollstaendigen Eloquent-Objektgraphen. Keine weiteren DB-Reads
waehrend sendContent. Kein Hintergrundexport oder neuer oeffentlicher Endpunkt.

5000-Zeilen-Grenze bleibt: 5001 Datensaetze werden vor Audit/Download abgewiesen.
Keine unbeschraenkten Cursorstreams, bei denen Fehler nach Versand der Header
unbemerkt eine Teildatei erzeugen. CSV-Formelschutz, UTF8-BOM, Semikolon,
private/no-store und bestehendes Audit bleiben erhalten. Filter-/Sortierreihenfolge
und Auswahl werden nicht durch einen zweiten ID-Durchlauf ersetzt.

## Messung

tools/clubunio-performance-check.php: CLI-Seitenprofil, READ ONLY/Rollback,
keine HTML-/Binding-/Personenausgabe. Pi-Stichproben vor Delta:

| Ansicht | ms | Queries |
| --- | ---: | ---: |
| Mitgliederlisten | 881 | 20 |
| Spielerlisten | 1411 | 41 |
| Stafflisten | 1415 | 42 |
| Familien | 1427 | 44 |
| Bankkonten | 1370 | 42 |
| Offene Rechnungen | 1181 | 32 |
| Spesen zur Bezahlung | 886 | 22 |
| Schluessel | 1222 | 35 |
| Spots | 541 | 12 |
| Ohne Mannschaft | 1551 | 48 |
| Passivmitglieder | 1410 | 43 |

Der dominante Befund sind wiederholte Netz-Roundtrips, nicht nachgewiesene
fehlende Indizes. Keine spekulative Index-/Schemaaenderung vorgenommen.
Einzelmessungen ohne Browser/Proxy; kein Lasttest und keine SLA.

tools/clubunio-export-performance-check.php erfordert CLI und --rollback-audit.
Nur eigene ClubUnio-DB, vorhandener Admin; alle Audit-Testeintraege werden
transaktional zurueckgerollt. Keine CSV-Inhalte/Dateien ausgegeben oder gespeichert,
nur Bytes, Laufzeit, Queries und Speicher. Keine Bankzahlung/externer Versand.
Spielerprofil explizit aktuelle eindeutige Saison und aktiv; Staff/Familien aktiv.
Ungefilterte Spielerzahl ueberschreitet die bestehende Grenze und wird korrekt
abgewiesen. Kein Aufheben der Grenze fuer eine Performancezahl.

| Export vor Delta | ms | Queries | Peak MiB | CSV Bytes |
| --- | ---: | ---: | ---: | ---: |
| Mitglieder | 639 | 7 | 58.5 | 113390 |
| Spieler aktuelle Saison | 373 | 9 | 48.5 | 29783 |
| Staff aktiv | 403 | 10 | 46.5 | 35643 |
| Familien aktiv | 447 | 12 | 46.5 | 23095 |
| Offene Rechnungen | 263 | 6 | 46.5 | 9486 |
| Spesen zur Bezahlung | 354 | 12 | 44.5 | 1390 |

## Migration und Ruecknahme

Keine DB-Migration, kein Datenimport, keine neue Konfiguration. Bestehende
Rollen, Verknuepfungen, Austritts-/Aktivdaten und Auditdaten beim spaeteren
Import unveraendert erhalten. Vorheriger Release wird vom Installer gesichert;
Ruecknahme per Symlink und graceful FPM reload, kein Schema-Rollback.

## Bereitgestellt und nachgemessen

Release `20261016-clubunio-report-performance`, Basis/Ruecksprung
`20261016-clubunio-dossier-comfort`. Vorhandene Dossier-Komfortfunktionen
erhalten; parallel entstehende Auswahlaktionen nicht pauschal uebernommen.

| Pi-Liste | ms vorher/nachher | Queries vorher/nachher |
| --- | ---: | ---: |
| Spieler | 1411 / 687 | 41 / 17 |
| Staff | 1415 / 751 | 42 / 18 |
| Familien | 1427 / 817 | 44 / 20 |
| Bankkonten | 1370 / 880 | 42 / 18 |
| Offene Rechnungen | 1181 / 878 | 32 / 23 |
| Schluessel | 1222 / 658 | 35 / 16 |
| Ohne Mannschaft | 1551 / 958 | 48 / 24 |
| Passivmitglieder | 1410 / 806 | 43 / 19 |

| Pi-Export | ms vorher/nachher | Queries vorher/nachher | Peak MiB vorher/nachher |
| --- | ---: | ---: | ---: |
| Mitglieder | 639 / 424 | 7 / 6 | 58.5 / 50.5 |
| Spieler aktuelle Saison | 373 / 288 | 9 / 7 | 48.5 / 46.5 |
| Staff aktiv | 403 / 313 | 10 / 8 | 46.5 / 46.5 |
| Familien aktiv | 447 / 255 | 12 / 8 | 46.5 / 46.5 |
| Offene Rechnungen | 263 / 237 | 6 / 6 | 46.5 / 46.5 |
| Spesen zur Bezahlung | 354 / 324 | 12 / 11 | 44.5 / 44.5 |

Alle Profile HTTP200; CSV-Bytezahlen je Export vor/nach identisch. Dies ersetzt
keine Inhaltspruefung: Exporttests decken Feldwerte, Reihenfolge/Filter,
Maskierung, Formelschutz und Audit ab. Keine Browser-/Lasttest-SLA.

36 gezielte Tests/623 Assertions erfolgreich: 23 Listen-/Reporttests,
2 Finanzexporttests und 11 Readkontext-/Berechtigungstests. Neue Regressionen
pruefen tenant-Eagerloading ohne Zeilenqueries, Entfernung von Anzeige-
Eagerloads auf einem Klon und keine Queries waehrend CSV-sendContent.
5001er-Auswahl, fremde Mandanten, Trainer/Spieler/TL, archivierte Mitglieder,
Familienregeln, Saisonfilter und gespeicherte Filter bleiben getestet.
DB-Fixtures und Export-Audits ausschliesslich transaktional zurueckgerollt.
Formatcheck erfolgreich; Caches/Workspace-Guards und195 verschluesselte
Settings/46 Vorlagen auf Pi geprueft. Login/Health HTTP200.

Zusaetzliche echte Livewire-Such-Lifecycle-Stichproben auf Pi:
Spielerliste344ms/10 Queries, offene Rechnungen521ms/16 Queries, jeweilsHTTP200.
Keine Baseline fuer diese beiden Suchpfade behauptet.
