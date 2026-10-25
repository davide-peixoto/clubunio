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

Migration78 ist fuer das gemeinsame API-Release vorbereitet, noch nicht dauerhaft
angewendet. Zuerst API-Basismigration76 und deren Quellen committen/integrieren,
dann diese Sicherheitskorrektur gemeinsam pruefen und Migration78 gezielt ueber
das Migrationsjournal ausfuehren. Nicht diesen Commit allein als vollstaendiges
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
Die gesamte75er-Gruppe ist nach den parallelen Aenderungen nicht erneut als
vollstaendig gruener Lauf bestaetigt. Diese gemeinsame Endabnahme bleibt beim
fertigen API-Release erforderlich.
