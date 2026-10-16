# ClubUnio: verbleibende DB-Leseabfragen

16.10.2026. Nur ClubUnio, PHPMaker DEV/PROD unveraendert. Keine DB-Migration,
Bankzahlungen, externe Buchungen oder API-/SaaS-Erweiterung.

## Befund und Umsetzung

Bei normalen GET-Seiten entfallen zahlreiche Roundtrips auf dieselben
Konto-/Rollendaten fuer Navigations- und Seitensichtbarkeit. Der bereits
vorhandene BackofficeReadContext liest aktiven Account und dessen Admin-/
Office-Mandanten am Anfang frisch. allowsReadPage nutzt genau diesen Kontext,
einschliesslich der konkreten Admin-Rolle im ausgewaehlten Mandanten.
Keine neue Session-, globale oder requestuebergreifende Rechteablage.

Settings, Signage, Schluessel-/Spotslisten und Bexio-Verwaltungsseiten verwenden
den gemeinsamen Lesecheck. Ausserhalb des vorhandenen Kontexts wird der Account
weiter frisch geladen und die bisherige Rollenpruefung ausgefuehrt. Admin-only
bleibt Admin-only: Office in einem Verein wird nicht durch Admin-Rechte in
einem anderen Verein aufgewertet. BexioConnectionService::canManage und alle
Fach-/Schreibautorisierungen bleiben unveraendert.

MemberAccessService::canEnter verwendet bei bereits bestaetigten Admin-/
Office-Lesezugriffen denselben Requestkontext, statt die identische Rolle
erneut abzufragen. Die eigentliche membership-Methode sowie Spieler-/Eltern-/
Trainer-/TL-Pruefungen ausserhalb dieses Kontexts bleiben frisch. Keine
Mitglieder-, Eltern- oder Mannschaftszuordnungen werden gecacht.

Der Kontext gilt weiterhin nur fuer GET bzw. die begrenzte Livewire-
Renderphase. Hydrierung, Property-Hooks, Aktionen, verschachtelte POST-Mounts
und nachfolgende Requests bleiben ausserhalb. Cleanup im finally unveraendert.
Wie bisher koennen gleichzeitige Aenderungen nach einem GET-Snapshot erst
beim naechsten Request sichtbar werden; keine Wiederverwendung beim Schreiben.

Teamplanung baut eigene Mitglieds- und Einladungsfilter als SQL-Unterabfragen
statt IDs vorher in PHP zu laden. Aktivstatus, Eintritt/Austritt, Elternalter,
genehmigte Zuordnung, Mandant und Account bleiben im Unterquery enthalten.
Beim reinen Verwaltungs-Teamfilter entfaellt eine unbenutzte Mitgliedsliste;
die frische Staff-/TL-Verantwortlichkeitspruefung bleibt erhalten. Daten-
und Filterergebnisse werden nicht ueber Requests gecacht.

Fehlerprotokollierung, Formularrevisionen, Workspace-Wechselschutz,
Mandantenfilter und CSRF unveraendert. Kein Pool-/RAM-/Netzwerk-Tuning.

## Messung und Tests

Bestehendes CLI-Profil tools/clubunio-performance-check.php, eigene ClubUnio-
DB, Admin im Mandanten sv-sissach, READ ONLY mit Rollback. Keine Bindings,
Accounts, IDs, HTML oder Secrets in der Ausgabe. Echter GET-Serveraufbau,
ohne Browser-/Proxy-Laufzeit, kein Lasttest und keine SLA.

Pi-Ausgangswerte vor diesem Delta:

| Ansicht | ms | DB-Abfragen |
| --- | ---: | ---: |
| Mitgliederliste | 1154 | 30 |
| Teamplanung | 1065 | 32 |
| Kalenderwoche | 1098 | 35 |
| Rechnungsdetail | 1199 | 37 |

Neue Tests: zwanzig wiederholte Seiten-/Einstiegspruefungen ohne neue
DB-Abfrage, Office/Admin-Trennung, fremder Mandant, inaktiver/fehlender Actor,
Rollenentzug zwischen Requests und sofortiger Entzug innerhalb eines POST.
Bestehende Render-/Aktions-Cleanup-, Navigations-, Verwaltungs-, Eltern-,
Einladungs-, Teamplanungs-, Frist-, Spesen- und Bankberechtigungstests bleiben
massgeblich. DB-Fixtures nur in Rollback-Transaktionen; kein echter Versand.

## Migration und Ruecknahme

Keine Tabellen-/Indexmigration und kein Datenimport. Bestehende Rollen,
Mitglieder-/Elternlinks und Teamzuordnungen beim spaeteren Import unveraendert
erhalten. Ruecksprung auf den vom Installer gesicherten vorherigen Release
plus graceful PHP-FPM reload; kein Schema-Rollback notwendig.

## Bereitgestellt und nachgemessen

Release `20261016-clubunio-db-read-performance`, Basis/Ruecksprung
`20261016-clubunio-relation-actions`. Parallel ergaenzte Dossieraktionen
vollstaendig erhalten; ausschliesslich das eigene Runtime-/Dokudelta geladen.

| Pi-Stichprobe | ms vorher/nachher | Queries vorher/nachher |
| --- | ---: | ---: |
| Mitgliederliste | 1154 / 833 | 30 / 19 |
| Teamplanung | 1065 / 650 | 32 / 17 |
| Kalenderwoche | 1098 / 662 | 35 / 17 |
| Rechnungsdetail | 1199 / 961 | 37 / 26 |

Einzelne lesende Server-Lifecycle-Stichproben, ohne Browser/Proxy, keine
Lasttest-SLA. Verbleibende Queries fuer Daten, aktive Mitgliederlinks und
frische Admin-/Schreibpruefungen bewusst nicht entfernt. Kein Memory-Zukauf.

49 Tests/683 Assertions erfolgreich: ReadPerformance11/285,
Workspace-/Navigation12/147, Planung/Pagination/Buskalender20/210,
zentrale Rechte-/Verwaltungs-/Spesen-/Banktests6/41. Einschliesslich echtem
Mehrmandantenfall: Admin anderswo schaltet keine Adminseite im Office-Verein
frei. DB-Testfixtures zurueckgerollt; keine echten Nachrichten/Bankzahlungen.
Config-/Route-/Viewcache erneuert, PHP-FPM graceful reload. Guardchecker,
195 verschluesselte Settings und46 Vorlagen weiterhin lesbar. Login und
Healthcheck oeffentlich HTTP200, alle gemessenen Ansichten HTTP200.
