# Bereinigung allgemeiner Altparameter

03.11.2026, ausschliesslich ClubUnio.

Migration 2026_11_03_000091_retire_general_parameters entfernt genau diese
Schluessel im Bereich general: SommerWinter, StatusPlatz1, StatusPlatz2,
StatusPlatz3. Andere Bereiche und unbekannte Parameter bleiben unveraendert.

Ersatz: Aktiver Trainingsplan (general/SIGNAGE_PERIOD_ID) und
field_availabilities fuer den Anlagenstatus. SaisonID bleibt erhalten.
Die Quellspalte SommerWinter in Trainingszeiten, deren Lookupwerte und das
gleichnamige App-Antwortfeld sind keine Altparameter und bleiben bestehen.
Die verbliebenen SettingOptions-Altwertregeln erlauben die sichere Anzeige
eines gegebenenfalls bewusst wiederhergestellten historischen Bestands.

## Sicherung und Ausfuehrung

Vor jeder Loeschung schreibt die Migration die vollstaendigen Parameterzeilen
mit weiterhin verschluesselten Werten und die zugehoerigen setting_changes nach
storage/app/private/migration-backups/retired-general-<Zeit>-<Zufall>.json.
Schreiblaenge und SHA256 werden geprueft; bei Fehler keine Loeschung.
Danach werden Auditzeilen und Parameter innerhalb einer Transaktion entfernt.
Die Sicherung liegt ausserhalb des Webroots, wird nicht committed und bekommt
Dateirechte0600. Wiederholte Ausfuehrung ohne Altwerte ist wirkungslos.

Nur diese Migration ausfuehren:
`php artisan migrate --database=clubunio --path=database/clubunio/2026_11_03_000091_retire_general_parameters.php --force`

ClubUnio lokal und auf Pi teilen die DB; daher einmalige Ausfuehrung auf Pi.
Keine anderen ausstehenden Mobile-Migrationen ausfuehren. Kein Refresh/Import.
Der Erstimport in routes/clubunio-console.php importiert kuenftig nur SaisonID
aus iniParameter. Aktiver Trainingsplan bleibt unter Allgemein konfigurierbar.

## Rueckweg

Kein automatisches down: Bewusste Wiederherstellung aus der passenden privaten
Sicherung, zuerst settings dann changes, innerhalb einer DB-Transaktion.
Zuvor tenant_id, vorhandene IDs und (tenant_id,area,key) auf Konflikte pruefen;
keine bestehenden Werte ueberschreiben. APP_KEY muss zum verschluesselten Bestand
passen. Ein Code-Rollback allein benoetigt diese Altwerte nicht.

## Tests

RetiredGeneralParametersTest:1 Test/8 Assertions, ausschliesslich Rollback-Fixtures.
Allowlist, Bereichstrennung, Erhalt aktueller Parameter, Backupentschluesselung,
Audit-Sicherung, Loeschreihenfolge und wiederholte Ausfuehrung geprueft.
Keine Benutzerrechte, Trainingszeiten oder PHPMaker DEV/PROD geaendert.

## Deployment und Abnahme

Aktiviert:20261103-clubunio-retired-parameters, Basis native-planning-teams.
Migration000091 einmalig auf Pi ausgefuehrt, genau vier Parameter entfernt.
Sicherung: shared/storage/app/private/migration-backups/
retired-general-20261103212127-28e1985caced.json,1836Bytes,0600.
Nachpruefung fuer SV Sissach: general enthaelt nur SaisonID und SIGNAGE_PERIOD_ID;
aktiver Plan weiterhin Sommer. Config-/Route-/View-Cache erneuert, PHP-FPM geladen.
Grundpruefung, Rollenpruefung und Mobile-Saisonpruefung erfolgreich.
Rueckweg fuer Code: vorheriger Release; Datenwiederherstellung nur wie oben.
