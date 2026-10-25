# API-Mandantentrennung (26.10.2026)

## Stand und Abgrenzung

Geprueft wird der lokale, noch parallel entwickelte API-v1-Arbeitsstand.
Keine pauschale Abnahme zukuenftiger Endpunkte. Der getrennte Sicherheitscommit
nimmt bewusst keine uncommittierten API-Dateien des anderen Chats mit.
Abos, SaaS-Abrechnung, PHPMaker DEV/PROD und Hosting bleiben unveraendert.
Kein Code-Deployment, keine echten Provideraufrufe oder Zahlungen.

## Befunde und Korrekturen

1. API-Bearertokens waren an ihren Verein gebunden, aber die Vereinsdomain war
   noch nicht geprueft. `BindApiTenantDomain` ergaenzt die bestehende exakte
   Hostfreigabe nach Authentifizierung, vor Controller/Idempotenz/Schreibzugriff.
   Ein gueltiger Token von A auf einer Domain von B erhaelt404. Unbekannte Hosts
   werden ebenfalls abgewiesen. Zentrale freigegebene Hosts erlauben weiterhin
   unterschiedliche Tokens; der Token bestimmt dort ausschliesslich den Verein.
   Keine Session, kein Queryparameter und kein X-Tenant-ID-Header waehlt ihn um.
   Der vorhandene Fehler-/Auditpfad protokolliert die Ablehnung beim Tokenverein,
   nicht beim Verein der angefragten Domain. Keine neuen Rechte oder Scopes.
2. `api_integrations.access_profile_id` hatte nur einen einfachen Fremdschluessel.
   Migration78 ergaenzt `(tenant_id,access_profile_id)` gegen
   `access_profiles(tenant_id,id)`. Bestehende Widersprueche brechen atomar ab;
   keine automatische Datenreparatur oder destruktive Rueckmigration.

Die Routeinbindung liegt im bestehenden TenantBoundaryServiceProvider, nicht in
den parallel bearbeiteten API-Dateien. Sie wird beim Routecache-Aufbau gespeichert.
Neue API-Routen muessen weiterhin `clubunio.api.*` heissen und den bestehenden
Authentifizierungs-Middleware verwenden. Vor einer spaeteren Routenumbenennung
diese Bindung mitpruefen; der Domain-Middleware allein authentifiziert nicht.

## Gepruefte Grenzen

- Alle aktuell registrierten Ressourcenabfragen: Tenantbedingung und gebundene ID.
- Listen/Suche, einzelne IDs, Schreibzugriffe und geschuetzte Tenantfelder.
- Lookup-/Fremdschluesselpruefungen ueber vorhandene Fachservices und Regressionen.
- Token/Profile/Integrationen frisch pruefen; widerrufene Rechte sperren auch Replay.
- Gleicher Idempotenzschluessel in zwei Vereinen liefert getrennte Ergebnisse.
- Dokument-Metadaten und Download fremder IDs scheitern vor Azure-Zugriff.
- Fremde Uploadquellen werden nicht gespeichert; eine andere Integration darf
  auch im gleichen Verein keine Uploadstatusdaten lesen oder Verarbeitung starten.
- Browseranmeldung ersetzt keinen API-Token; Kontextmanipulation scheitert.
- Reports/Exporte nutzen vorhandene tenantgebundene Queries und Rechte.

Die Querymatrix beweist die Basiseinschraenkung aller derzeit registrierten
Ressourcen, nicht jede denkbare Kombination aller Beziehungen/Workflowaktionen.
Kein externer Penetrationstest, keine RLS-/SaaS-Zertifizierung.

## Wiederholung und Integration

```powershell
$env:CLUBUNIO_TEST_DB='1'
php -d extension=pdo_pgsql vendor/phpunit/phpunit/phpunit tests/Feature/ApiTenantBoundaryTest.php
```

Nur synthetische Vereins-/Kontodaten in einer aeusseren Rollbacktransaktion.
Migration78 wird im Constraint-Test ausschliesslich innerhalb dieses Rollbacks
ausgefuehrt, falls sie noch nicht installiert ist. Kein implizites DB-Upgrade.

Nach Freigabe des Nachlaufs am26.10.2026 ist Migration78 als installiert bestaetigt:
Der gezielte Migrationsaufruf meldet Nothing to migrate, migrate:status zeigt
Ran (Batch77), und das read-only Schemaaudit bestaetigt die validierte Absicherung.
Sie war beim Nachlauf bereits installiert; kein erneutes up(), keine Datenreparatur.
API-Basismigration76 und deren Quellen weiterhin separat committen/integrieren.
Nicht diesen Sicherheitscommit allein als vollstaendiges
API-Release deployen: Er enthaelt absichtlich nicht die parallelen API-Quellen.
Nach Abschluss der parallelen Entwicklung gesamte API-Regressionsgruppe sowie
Domainpruefungen mit neu gebautem Routecache erneut ausfuehren.

## Pruefnachweis

- Neue Grenzgruppe zunaechst9 Tests/195 Assertions erfolgreich (ohne Routecache).
- Breiter Zwischenlauf75 Tests/3124 Assertions: ein eigener unvollstaendiger
  Dokumenttyp-Fixture und vier Fehler im parallelen API-Arbeitsstand. Der Fixture
  wurde korrigiert. Drei Fehler betrafen die JSON-Konfliktantwort beim Replay nach
  Rechteentzug (Zugriff selbst bereits409, keine Datenfreigabe), einer eine noch
  fehlende Lookup-Testzuordnung. Deren Korrektur erfolgte im parallelen API-Chat;
  diese fremden Aenderungen sind nicht Bestandteil dieses Sicherheitscommits.
- Wiederholung aller damals fehlgeschlagenen API-Faelle zusammen mit den neuen
  Grenztests und eigenem aufgebautem Routecache:13 Tests/321 Assertions bestanden.
- Finale erweiterte eigene Grenzgruppe mit aufgebautem Routecache:
  10 Tests/245 Assertions bestanden, einschliesslich verbindlicher Pruefung jeder
  API-v1-Route auf Authentifizierung vor Domainbindung. Testgruppen ueberlappen;
  Ergebnisse nicht zu einer angeblich eindeutigen Testgesamtzahl addieren.
- PHP-Syntaxpruefung fuer Domain-Middleware, Migration und Grenztests erfolgreich.

Der Routen-Testcache liegt unter einem eigenen APP_ROUTES_CACHE-Pfad und wird
im finally-Block entfernt. Der normale lokale Routen-Cache wird nicht ersetzt.
Der unten dokumentierte Nachlauf ersetzt den zuvor noch offenen Gesamtlauf
fuer den getesteten lokalen Stand. Die gemeinsame Releaseabnahme bleibt bei
weiteren parallelen Aenderungen erneut erforderlich.

## Nachlauf nach Freigabe (26.10.2026)

- Migration78: installiert, Batch77, kein erneuter Schemaeingriff notwendig.
- Schemaaudit:128 Tabellen mit tenant_id, keine ungesicherten Tenant-FK-Paare.
  Das Audit verlangt validierte zusammengesetzte Fremdschluessel; reine
  Schema-Metadaten ersetzen keine Anwendungstests.
- API-Konfigurationspruefung:70 Ressourcen,209 dokumentierte Pfade,128 Scopes;
  Bearer-only und private Dokumentation bestaetigt, technische Konten0.
- Komplette API-Testgruppe einschliesslich Dokumentation und Verwaltungs-UI:
  **119 Tests/26554 Assertions bestanden**, keine Fehler, Laufzeit6:09,
  mit separatem aufgebautem Routen-Cache. Cache im finally-Block entfernt.
  API-Quellen/Controller/Router/Bootstrap blieben zwischen dem fruehen
  Quellhash-Snapshot im Lauf und dessen Ende unveraendert. Geprueft ist dieser
  lokale Stand, nicht jede spaeter ergaenzte API-Funktion.

Wiederholbarer Gesamtlauf (lokales Windows-Projekt; relativer Cachepfad, damit
Laravel keinen zweiten Laufwerksnamen voranstellt):

```powershell
$env:CLUBUNIO_TEST_DB='1'
$previous=$env:APP_ROUTES_CACHE
$env:APP_ROUTES_CACHE='storage/framework/cache/api-full-tenant-review-routes.php'
if (Test-Path -LiteralPath $env:APP_ROUTES_CACHE) { throw 'Auditcache bereits vorhanden' }
try {
    php -d extension=pdo_pgsql artisan route:cache
    if ($LASTEXITCODE -ne 0) { throw 'Routecache fehlgeschlagen' }
    $filter='ApiDocumentationTest|ApiIntegrationsUiTest|ApiModulesTest|ApiPlatformTest|ApiSecurityRegressionTest|ApiTenantBoundaryTest|AuxiliaryApiModulesTest|WorkflowApiModulesTest'
    php -d extension=pdo_pgsql vendor/phpunit/phpunit/phpunit --filter=$filter
    if ($LASTEXITCODE -ne 0) { throw 'API-Regression fehlgeschlagen' }
} finally {
    php -d extension=pdo_pgsql artisan route:clear
    $env:APP_ROUTES_CACHE=$previous
}
```

Der parallele API-Arbeitsstand bleibt bewusst ausserhalb dieses Doku-Commits.
Keine Installation/Umstellung der API auf dem Server durch diesen Nachlauf,
keine PHPMaker-DEV-/PROD-Aenderung, keine SaaS-Abrechnung oder Provider-Writes.
