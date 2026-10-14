# Laravel / Filament: lokaler DEV-Pilot

Stand: 10.10.2026. Zusaetzlicher Pi-Staging-Pilot eingerichtet:
`dev-my2.svsissach.ch`, Proxy/DNS und HTTPS geprueft. Siehe [deploy/PI.md](deploy/PI.md).
Bestehendes SmarterASP DEV/PROD unveraendert.

## Start

PHP 8.3 mit pdo_sqlsrv, Composer. Aus diesem Ordner:

```powershell
composer install --no-scripts
Copy-Item .env.example .env # nur bei der Ersteinrichtung, vorhandene .env behalten
php artisan key:generate
php artisan package:discover
php artisan filament:assets
php artisan serve --host=127.0.0.1 --port=8091
```

URL: http://127.0.0.1:8091/admin

Die lokale `.env` wurde bereits eingerichtet. Die DEV-Verbindung wird aus
`../application/.env.dev.local` gelesen. Optional kann `SVS_DEV_ENV_FILE` auf
eine andere lokale DEV-Konfiguration zeigen. Keine Zugangsdaten in Git.
Der Datenbankname muss `dev` enthalten; APP_ENV nur local/testing/staging.
Auf dem Pi werden die DEV-Zugangsdaten separat per SVS_DEV_DB_* konfiguriert.

## Aktueller Funktionsumfang

### Lokales Design (10.10.2026)

SVS-Logo, kompakte Navigation mit Finanzbereich, Statusfarben, Symbolaktionen
mit Tooltips und responsive Rechnungsdetails. Eigenes Stylesheet
`public/css/svs.css` wird ueber die Filament-Asset-API mit Dateiversion eingebunden.
Keine Vendor-Aenderungen oder zusaetzliche Build-Abhaengigkeiten.
Saison-/Statusfilter werden sofort angewendet (kein separater Anwenden-Schritt).
Filter und Suche bleiben zusammen in der lokalen Sitzung erhalten, auch bei
Rueckkehr zur Liste. Loeschen des Suchtexts entfernt keine Saison-/Statusfilter.
PDF-Slide-over nutzt die verfuegbare Bildschirmhoehe. Bestehende Spaltenauswahl
des Benutzers bleibt erhalten. Keine fachlichen Rechte oder Status geaendert.

- Laravel 12, Filament 5, getrennt von PHPMaker unter `backend/laravel`.
- Anmeldung mit bestehendem DEV-Benutzernamen und Passwort aus `Users`.
- Nur aktive, nicht gesperrte und nicht geloeschte Backoffice-Konten:
  Level -1 oder 1 bis 9 ausser 2, analog bestehendem Backoffice-Helfer.
- Lesende Rechnungsuebersicht mit Mitgliedernamen, Suche und Pagination.
- Seit 10.10.: Detailansicht, Saison-/Statusbezeichnungen und Lookup-Filter.
- Rechnungsdetails zeigen zugeordnete, nicht geloeschte Positionen mit
  Bezeichnung, Brutto, Rabatt in CHF, Netto, Statusbezeichnung und Bemerkung.
- PDF-Vorschau als Filament Slide-over von rechts, in Liste und Detailansicht.
  Der lokale Proxy verwendet den bestehenden DEV-PDF-Endpunkt. Beim ersten
  Oeffnen DEV-Passwort bestaetigen: Benutzername wird aus dem lokalen Login
  uebernommen, Passwort nicht gespeichert, JWT verschluesselt in lokaler Session.
  Abgelaufene DEV-Sitzungen verlangen eine neue Anmeldung. Keine Tokens in URLs.
  Lokale Endpunkte GET /invoices/{id}/pdf und POST /invoices/{id}/pdf/connect
  sind authentifiziert; POST hat CSRF und Rate-Limit. PDF-Antworten no-store.
- Keine Bearbeitung, keine direkten Azure-/Push-/Mail-Aufrufe. DEV uebernimmt
  Bexio-PDF-Abruf samt bestehenden Rechten und OAuth-Erneuerung. API-Login kann
  bestehende DEV-Login-Protokolle/Anmeldezustaende aktualisieren.
- Kein Remember-Me, kein Passwort-Rehash, Sessions und Cache lokal als Dateien.
- SQL-Schreibbefehle ueber die Legacy-Verbindung werden vor Ausfuehrung abgewiesen.
  Das ersetzt langfristig kein eigenes SQL-Konto mit ausschliesslichen Leserechten.

Kein `migrate`, `migrate:fresh`, Seeding oder `make:filament-user` gegen DEV!
Die Standard-Migrationsdateien wurden entfernt. Eine PROD-Kopie wurde nicht erstellt.

## Noch nicht migriert

Dies ist ein Pilot, kein Ersatz des bisherigen Backends. App/API-Routen,
JWT/Eltern-Login, vollstaendige Tabellenrechte, Trainer-/TL-Sichtbarkeit,
schreibende Workflows und Integrationen bleiben in PHPMaker.
Die PDF-Vorschau benoetigt eine aktive DEV-Bexio-Verbindung;
bei deaktivierter Schnittstelle oder fehlender Anmeldung antwortet DEV mit Fehler.
Diagnose 10.10.: DEV hat BEXIO_ACTIVE=0 (Aktiv=1). Der bestehende PDF-Endpunkt
liefert deshalb HTTP 503 auch fuer Lesezugriffe. Der Pilot zeigt diesen Grund
ausdruecklich an. Schalter nicht veraendert; keine schreibenden Bexio-Aufrufe
freigegeben. `php artisan svs:check-pdf-config` prueft nur die Aktivierungsschalter,
ohne Tokens auszugeben. Eine gesonderte Lesefreigabe ist noch nicht implementiert.
Die App wird nicht umgestellt. Auch bestehende Spezialregeln fuer Login und
Mitgliederaustritte muessen vor einer breiteren Freigabe vollstaendig portiert werden.
In DEV fehlt derzeit `Rechnung.NettoWert`; die Ansicht setzt dieses Feld nicht voraus.

PHPMaker generiert ausschliesslich `application/`. Der neue Ordner wird nicht
in die PMP eingebunden und darf nicht durch bestehende FTP-Deployments verteilt werden.

## Pruefung

```powershell
php artisan test
php artisan svs:check-dev
$env:SVS_TEST_DEV_READS = '1'
php artisan test --filter=DevInvoiceTest
```

Standardtests pruefen Zugriff, Passwortvalidierung und Schreibsperre ohne
Datenbankaenderungen. Der separat aktivierte Integrationstest rendert die
Rechnungsansicht mit echten DEV-Leseabfragen und einem synthetischen Admin im
Testprozess. Ein interaktiver Login mit einem echten DEV-Konto steht noch aus.
Positionen und Slide-over werden im DEV-Integrationstest geprueft; PDF-Transport,
Anmeldung, HTTP-500-Anzeige und Zugriffssperre mit simulierten HTTP-Antworten.
Ein echter authentifizierter PDF-Abruf steht noch aus. Keine echten Passwoerter
oder Tokens in Tests. Unauthentifizierter DEV-GET liefert korrekt HTTP 401.

Logs: `storage/logs/`. Keine Query-/Credential-Protokollierung aktiviert.
Bei spaeterer PROD-zu-DEV-Auffrischung vorher separat sichern und den Restore
explizit freigeben; dabei Mail, Push und Fremdsysteme in DEV deaktiviert halten.
