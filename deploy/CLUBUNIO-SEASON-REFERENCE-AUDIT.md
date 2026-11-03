# Saisonverknuepfungen: Migrationspruefung03.11.2026

## Ergebnis

Keine fehlerhafte Saison-ID-Uebersetzung festgestellt. Daten wurden weder
korrigiert noch neu importiert. Bestehendes PHPMaker DEV/PROD unveraendert.
Verglichen wurde ClubUnio gegen den bereits uebernommenen PROD-Snapshot
`legacy_prod` in derselben PostgreSQL-Datenbank, nicht gegen laufendes MSSQL.
Der Snapshot ist der Nachweis fuer die Migration, kein Live-Abgleich spaeterer
PHPMaker-Aenderungen. Alle Abfragen liefen in einer REPEATABLE READ / READ ONLY
Transaktion; keine Nebenwirkungen oder Schemaaenderung.

Nachweis: [aggregierter Pruefbericht](evidence/season-references-20261103.json).
Keine Personennamen, Adressen, Beleginhalte, Schluessel oder Zugangsdaten enthalten.

| Pruefung | Ergebnis |
| --- | --- |
| Direkte Saison-/TeamSaison-Felder |23; keine verwaisten/mandantenfremden Referenzen, alle mit validiertem Mandanten-FK |
| Import-Zuordnungsgruppen |17, insgesamt26746 Referenzvergleiche; keine abweichende/falsch aufgeloeste Referenz |
| Fehlende Quell-/Zieldatensaetze in diesen Gruppen |0 |
| Saisonkatalog |37 uebernommene Saisons, keine abweichende Bezeichnung oder fehlende Zielsaison |
| Mannschaft/Saison-Auswahllabel |keine Abweichung zur verknuepften Mannschaft und Saison |
| Historische Trainingsplaene |20 final zugeordnet; keine Abweichung zum freigegebenen Staging-Team |
| Urspruengliche Planungsteilnahmen |451 Event-/Mitgliedspaare vorhanden; keine fehlende Zuordnung |
| PostgreSQL-Views |eine View spots_recipients, ohne Saison-/Legacy-Abhaengigkeit |
| Aktueller App-Lookup |general/SaisonID38 -> legacy_id38 -> interne id37, 2026/2027 |

Referenzvergleiche sind keine Anzahl verschiedener Personen/Belege: zum Beispiel
Spesen haben sowohl eine direkte Saison als auch eine TeamSaison-Verknuepfung.
Native ClubUnio-Datensaetze ohne Legacy-ID werden nicht faelschlich als fehlende
Migration gewertet; ihre direkten Saison-FKs wurden trotzdem kontrolliert.

## Abdeckung

TeamsSaison, Spieler, Staff, technische Leiter, Trainingszeiten, Anmeldungen,
Beitragsregeln, Rechnungen, Spesen, Veranstaltungen, Teamtermine, Busreservierungen
und Trainingsplan-Staging gegen die originalen Fremdschluessel geprueft. Zusaetzlich
Rechnungs-/Spesenpositionen und Veranstaltungsteilnehmer gegen ihre migrierten
Elternobjekte; damit bleibt auch deren abgeleitete Saison korrekt. Alle derzeitigen
Saisonfelder einschliesslich nativer Saisonplaene, Sponsorenlauf, Chat und
Registrierungsjournal ueber Datenbankmetadaten/FKs erfasst.

SFV sfv_schedule.season_id ist bewusst eine externe Verbands-Saisonnummer und
darf nicht auf clubora.seasons.id umgeschrieben werden. Saison- und TeamSaison-
Dropdowns verwenden intern die nativen IDs und zeigen die Namen/Labels der
verknuepften Datensaetze; legacy_id ist nur fuer Import-/Parameteraufloesung da.
Kontrolliert: Season-/TeamSeason-Resources, saisonbezogene RelationManager,
Beitrags-/Registrierungs-/Finanzservices sowie persoenliche App-Lookups.
Gezielte vorhandene API-Tests bestaetigen Parameter-/Native-ID-Kollision,
Mandantengrenzen und saisongefilterte Finanzlookups.

## Fachlicher Altbestand:14 Spesenbelege

Bei14 Belegen ist expenses.season_id verschieden von der Saison ihrer verknuepften
Mannschaft. Genau dieselben14 Belege weisen diesen Widerspruch bereits zwischen
Spesenbelege.SaisonID und TeamsSaison.SaisonID im Quell-Snapshot auf. Beide
Referenzen wurden jeweils korrekt migriert; dies ist kein verschobener Import-ID-
Fehler. Die Schnittmenge der beiden Fehlermengen wurde ausdruecklich geprueft.

Nicht automatisch umgebucht: Es ist fachlich zu entscheiden, ob Belegsaison oder
Mannschaft falsch gepflegt wurde. Besonders bei alten Finanzbelegen keine
Datumauswertung oder pauschale Angleichung. Der App-Listenfilter richtet sich
weiterhin nach der Belegsaison gemaess SaisonID. Einzelkorrekturen waeren ein
separater Auftrag mit Sicherung, Protokoll und fachlicher Freigabe.

## Wiederholen

```text
php deploy/audit-season-references.php --tenant=sv-sissach
```

Alternativ `--root=/var/www/svs-laravel/current`, wenn das Werkzeug getrennt von
der aktiven Releasekopie abgelegt ist. Auf dem Pi unter shared/season-audit
ausgefuehrt; kein Runtime-Release oder PHP-FPM-Neustart erforderlich. Werkzeug
bricht bei falschem Treiber/Datenbank/Verein ab, fuehrt nur bekannte
Import-Zuordnungen aus und erzwingt READ ONLY. Ausgabe sind nur aggregierte
Pruefergebnisse. Neue Saisonfelder werden beim naechsten Lauf automatisch erfasst;
neue Importobjekte muessen in der expliziten Mappingliste ergaenzt werden.

Tests: vier isolierte Audit-Tests fuer verschobene/native kollidierende IDs,
NULL-Werte, Mandantenfehler und fehlende/ueberzaehlige Datensaetze; SQLite-Fixture
waehrend der Pruefung query_only. Zusammen mit drei bestehenden App-Parameter-/
Finanzlookup-Regressionen sieben Tests/49 Assertions erfolgreich.
