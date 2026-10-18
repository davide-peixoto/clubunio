# ClubUnio: Serienaenderungen und Absenzen

18.10.2026. Nur ClubUnio-Web. API, SaaS, Betrieb und bestehendes PHPMaker
DEV/PROD unveraendert. Kein externer Versand oder reale Terminbearbeitung.

## Umsetzung und Grenzen

effectiveCount zaehlt aktive Kader-/Einladungsmitglieder direkt im SQL:
ANMELDEN nur explizit DABEI, ABMELDEN zusaetzlich Mitglieder ohne Teilnahme.
Explizite andere Status bleiben ausgeschlossen; Spieler/Staff-Doppelrolle
nicht doppelt zaehlen. Bestehendes Tenant-/Aktivmitgliederscope unveraendert.
Gastzaehlung weiterhin separat, nur DABEI und nicht geloescht. Ausnahme-Mitglied
nicht vom Gastbestand abziehen. Unique(tenant_id,event_id,member_id) bleibt
Voraussetzung. Zwei skalare Queries statt drei Queries mit ganzen Datensaetzen.

Serienvorschau verwendet einmal geparste, unveraenderliche Datumswerte fuer
Verschiebung und Vergleich erneut. Zurich-Zeitzone, Fristen, Ausnahmen,
Vergangenheitspruefung, Reihenfolge und Fingerprintformat unveraendert.
Apply sperrt weiterhin den Verein, erstellt eine frische Vorschau, prueft
Fingerprint und schreibt ueber saveEvent mit frischer Berechtigungspruefung.

saveAbsence laedt den neuen Zeitraum und alle aktiven Zeitraeume dieses
Mitglieds einmal unter dem bestehenden Vereinslock. Kein Cache ueber
Aufrufe hinweg. Kandidatentermine unveraendert breit lesen, damit verschobene
oder geloeschte Absenzen auch fruehere automatische Abmeldungen aufheben.
Kader-/Teilnahmereads je Termin auf betroffenes Mitglied begrenzen; bei
vollstaendigem Terminabgleich weiterhin frische Absenzreads fuer alle Mitglieder.
Lokales Datum inkl. Sommerzeit unveraendert; gesperrte Termine nur zaehlen,
nicht veraendern. Trainerentscheidungen/NICHT_AUFGEBOT, Ueberlappungen,
Wiederherstellung der verschluesselten Originalteilnahme, Revision und
privatsphaereschonender Audit unveraendert. Keine Absenzgruende protokollieren.

## Messung und Pruefung

tools/clubunio-series-absence-performance-check.php ist nur CLI, prueft die
exakte ClubUnio-Datenbank und erzeugt einen eigenen synthetischen Verein:
20 Mitglieder,12 Termine,240 Teilnahmen. Zehn Termine offen, zwei fristgesperrt.
Misst Serienvorschau mit Kapazitaetsaenderung sowie eine Absenzanlage.
HTTP gesperrt, Mail gefaked; alle Daten im finally zurueckrollen. PG-Sequenzen
koennen Luecken erhalten. Ausgabe nur aggregierte Zeiten/Queryzahlen.

Pi vorher: Serienvorschau992ms/37 Queries/941ms DB; Absenz1437ms/53 Queries/
1374ms DB. Nach Delta: Serienvorschau793ms/27 Queries/754ms DB; Absenz1215ms/
43 Queries/1171ms DB. Rund20% bzw.15% weniger Gesamtdauer in diesen
Einzelmessungen, kein Lasttest oder SLA. Alle Messdaten zurueckgerollt.

Tests: Referenzvergleich der bisherigen Kapazitaetslogik in beiden Modi,
Ausnahme-ID, Kader-Doppelrolle, Fremdteilnahme, geloeschte und offene Gaeste;
zwei skalare Queries. Absenz mit vier offenen und einem gesperrten Termin:
konstante zwei Absenzreads, mitgliedsbezogene Teilnahmereads und geschuetzte
Trainerentscheidung. Bestehende Tests fuer Ueberlappung, Wiederherstellung,
Eltern/Fremdmitglied, Fristsperre, Serienfingerprint und Sommerzeit mitpruefen.

Ergebnisse:18 bestehende Teamplanungstests/179 Assertions, zwei neue
Regressionstests/71 Assertions, vier SportsProfilePermissionTest-Faelle/
75 Assertions erfolgreich (getrennte Laeufe). Pint, PHP-Syntax, gecachte
Routen/Views, Tenant-/Dokumentguards und Profil-Aktivierungscheck erfolgreich.
Release20261018-clubunio-series-absence-performance aktiv; Ruecksprungbasis
20261017-clubunio-contribution-performance. Login HTTP200 nach Aktivierung.
Angemeldete Sichtpruefung noch offen: bestehende Browsersitzung abgelaufen,
erneute Anmeldung angefragt. Keine echten Termin-/Absenz-Schreibtests im Web.

## Migration und Rollback

Keine Tabellen-/Indexmigration, Parameter, Datenimporte oder Rollenwechsel.
Gezielter Delta-Release; vorherigen Release erst nach Symlink-Pruefung kopieren,
Caches und Tenant-/Routen-/Profilchecks vor Aktivierung. Ruecksprung per
vorigem Release-Symlink ohne DB-Rollback. Secrets/.env/Uploads/vendor und
Laufzeitdateien bleiben ausserhalb des Commits.
