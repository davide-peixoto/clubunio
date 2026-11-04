# Historical SFV Competition Team Mapping

Stand: 04.11.2026. Lokales Inkrement, kein Release und kein Bestands-Apply.
Eigenes Paket fuer die historische Zuordnung in der Platzplanung; keine
Aenderung an `PitchPlanningService` oder dessen neuem SFV-Synchronisationsweg.
Uebergeordneter Migrationsplan: [CLUBUNIO-PITCH-PLANNING.md](CLUBUNIO-PITCH-PLANNING.md).

## Vertrag

`App\ClubUnio\Services\PitchCompetitionTeamMapping` bietet `preview` und
`apply`; der Konsolenadapter ist `clubunio:pitch-team-mapping`.
Die Standardausfuehrung ist ausschliesslich lesend. Kein SFV-Netzwerkabruf,
kein Import, kein Anlegen/Loeschen/Aktualisieren eines Wettkampfs und keine
nachtraegliche Zustellung historischer Nachrichten.

Vorschau und Anwendung verlangen ein frisch geprueftes aktives Adminkonto
des explizit angegebenen Vereins. Office, Trainer, dynamische Profile,
API-only-Konten und Supportsimulation sind nicht berechtigt. Die CLI ist ein
privilegiertes lokales Administrationswerkzeug; `--actor` ersetzt keinen
Weblogin und darf nicht als oeffentlicher Endpunkt exponiert werden.

Deterministische Bedingungen:

- Bestehender aktiver Wettkampf im expliziten Datumsvon/bis-Bereich; maximal
  1000 Wettkaempfe pro Vorschau, ohne stilles Abschneiden.
- Bestehende positive `association_match_id` entspricht exakt einem gespeicherten
  `sfv_schedule.match_id` desselben Vereins. Auch archivierte oder ausserhalb
  des Vorschauzeitraums liegende doppelte Wettkampfverknuepfungen sperren.
- Das SFV-Quelldatum in `Europe/Zurich` entspricht `competitions.plays_on`.
- Beide Quellteams haben verschiedene positive numerische SFV-Team-IDs.
  Ausschliesslich `teams.sfv_team_id` wird verglichen, niemals ein Name,
  Titel, Vereinsname, Saisonlabel oder eine ungepruefte Freitextquelle.
- Pro lokal bekanntem SFV-Team genau eine aktive `team_seasons`-Zuordnung
  zu aktivem Team und aktiver, datierter Saison am Spieltag; Datumsgrenzen
  inklusive. SFV-`season_id` ist nur Evidenz, nicht die lokale Saison-ID.
- Ein externer Gegner ohne lokale SFV-ID ist normal. Ein lokal bekanntes
  Team ohne gueltige Saisonzuordnung ist dagegen ein Fehler. Zwei lokale
  Teams werden beide zugeordnet; keine teilweise Anwendung bei Zweifeln.

Vorhandene Teamverknuepfungen werden immer uebersprungen, unabhaengig von
ihrer Herkunft. Ebenso alle bestehenden `pitch_competition_settings`:
Damit bleiben auch manuell bewusst geleerte Teamzuordnungen geschuetzt.
Dies ist absichtlich konservativ: lokal konfigurierte, aber unzugeordnete
Spiele brauchen weiterhin eine explizite manuelle Fachpruefung.
Fehlende Quelle/Match-ID oder rein externe Teams erscheinen als uebersprungen;
mehrdeutige IDs, widerspruechliche Daten und fehlende lokale Saisonzuordnungen
als Fehler. Ein Fehler sperrt den ganzen ausgewaehlten Apply-Bereich.

## Vorschau Und Freigabe

Nur nach Bereitstellung des bestehenden Platzplanungsschemas lauffaehig.
Der Dienst legt dieses Schema nicht an. Beispielparameter vor Verwendung
durch den tatsaechlich freigegebenen Verein, Admin und Zeitraum ersetzen:

```powershell
php -d extension=pdo_pgsql artisan clubunio:pitch-team-mapping --tenant=TENANT_ID --actor=ADMIN_ID --from=2026-01-01 --to=2026-06-30 --locale=de --json
```

Der Report enthaelt IDs, Datum, Quell-/Saisonbezug, Ziel-Teamsaisons, stabile
Status-/Grundcodes, DE/EN-Meldungen, Summen und einen SHA-256-Fingerprint.
Keine Namen oder Mitgliederdaten werden ausgegeben. JSON kann vom Betreiber
als privater Nachweis gespeichert werden, nicht ins Repository einchecken.
Vorschau mit Fehlern liefert Exitcode1; ungueltige Parameter Exitcode2.

Eine spaetere, separat freigegebene Anwendung verwendet denselben Bereich
und den tatsaechlich zuvor geprueften Fingerprint:

```powershell
php -d extension=pdo_pgsql artisan clubunio:pitch-team-mapping --tenant=TENANT_ID --actor=ADMIN_ID --from=2026-01-01 --to=2026-06-30 --locale=de --apply --confirm=REVIEWED_SHA256
```

`--apply` allein reicht nicht. Kein `--force`, kein automatisches Ja und
kein Uebernehmen eines frisch berechneten Fingerprints anstelle der Pruefung.
Der Service verlangt zusaetzlich den expliziten booleschen Bestaetigungswert.
Er berechnet die Vorschau innerhalb einer Transaktion nach Tenant-Lock und
frischen Konten-/Mitgliedschaftschecks neu, sperrt gelesene Quell-/Zielzeilen
und vergleicht alle relevanten Fakten. Bestehende Fachworkflows verwenden
denselben Tenant-Lock. Veraltete Vorschauen werden mit409 abgewiesen.

Geschrieben werden ausschliesslich neue `pitch_competition_teams`, neue
Default-`pitch_competition_settings` mit Revision1 und `object_changes` mit
Akteur, Quellbezug, Zielen und Vorschau-Fingerprint. Die neue Settingsrevision
invalidiert bereits offene Platzplanungseditoren. Wettkampfzeilen,
Garderoben, Plaetze, Anspielzeiten, Match-IDs und SFV-Quellen bleiben identisch.
Ein spaeter Schreibfehler rollt alle drei Schreibgruppen zurueck.
Erneute Vorschau nach Erfolg ueberspringt die Zuordnungen; kein Doppel-Audit.

## Staff-Benachrichtigungen

Die bestehende Platzplanungsprojektion liest die neuen Teamverknuepfungen.
Damit kann die bestehende Empfaengerauswahl bei einer spaeter gespeicherten
Verschiebung die aktuell berechtigten aktiven Staffkonten dieser Teams
bestimmen. Keine Erweiterung von Rollen, Portalrechten, Staffzuordnungen,
Vorlagen oder Push-Freigaben. Keine Neuerstellung alter Events, kein Replay
alter Nachrichten und kein Versand beim Mapping selbst. Nicht deterministisch
zuordenbare oder bewusst uebersprungene Spiele bleiben fachlich offen.

## Pruefung Und Migrationsgrenze

```powershell
php vendor/bin/phpunit tests/Feature/PitchCompetitionTeamMappingTest.php
$env:CLUBUNIO_TEST_DB = '1'
php -d extension=pdo_pgsql vendor/bin/phpunit tests/Feature/PitchCompetitionTeamMappingPostgresTest.php
php tools/check-translations.php
```

Der Standardtest verwendet ausschliesslich SQLite im RAM. Der opt-in
PostgreSQL-Test klont nur Tabellenstrukturen nach `pg_temp`, fuehrt die echte
Platzplanungs-DDL umgeschrieben in temporaere Tabellen aus und rollt alle
synthetischen Daten zurueck. Eine SQL-Sperre verweigert versehentliche Writes
nach `clubora`. Keine dauerhaften Testkonten, Migrationen oder Fixtures.

Abnahmenachweis dieses Inkrements: 53 Tests/330 Assertions fuer den neuen
Mappingvertrag plus bestehende Benachrichtigungs-/Kalenderregressionen;
derselbe Mappingvertrag zusaetzlich auf PostgreSQL mit19 Tests/169 Assertions
gruen. Insgesamt72 Testausfuehrungen/499 Assertions. PHP-Syntaxpruefung und
`php tools/check-translations.php` erfolgreich:2589 JSON-Schluessel in DE/EN,
PHP-Kataloggruppen und Platzhalter ohne Fehler. Kein echter Bestandsreport
oder Zwei-Verbindungen-Renntest in diesem Paket; keine echte Zustellung.

Vor einem spaeteren Bestands-Apply: getrennte Freigabe, gepruefte Sicherung,
lesenden Report fachlich pruefen und Fehler/Skips ausweisen. Danach gezielter
Apply mit diesem Fingerprint, frischer Nachreport und Vergleich der
unveraenderten Wettkampf-/Garderoben-/Match-ID-Daten. Kein pauschaler Import,
kein Refresh, kein Loeschen. Ein Rueckweg muss die konkret protokollierten
neuen Link-/Settingszeilen separat pruefen; kein automatisches Down-Migrate.
PHPMaker DEV/PROD bleibt unberuehrt. Kein Deployment, Commit oder Sync in
diesem Arbeitspaket.
