# Mandantenlose Verwaltungspfade

14.10.2026, Release54-tenant-navigation, Basis Release53-bexio-domain.
Nur ClubUnio/Pi. Keine DB-Migration, kein PHPMaker-DEV-/PROD-Deployment.

## Verhalten

Login /login, Dashboard /, Mitglieder /members, Rechnungen /invoices usw.
Kein /clubunio/{slug} mehr in den Verwaltungslinks. Bestehende Assetpfade
/clubunio/pdf-viewer.* bleiben unveraendert. Bexio-Callback unveraendert
/integrations/bexio/callback, APP_URL https://dev.clubunio.ch.

Nach Anmeldung wird aus den aktuellen Admin-/Office-Berechtigungen der erste
Mandant nach ID gewaehlt. Ein bereits gewaehlt und weiterhin berechtigter
Mandant bleibt in derselben Sitzung aktiv. Bei mehreren Mandanten erscheint
oben in der Seitennavigation ein Vereinsdropdown, sonst der Vereinsname.
Der Wechsel erfolgt mit POST, CSRF und Kontextrevision; anschliessend /.
Ein entzogenes Membership wird nicht stillschweigend durch einen anderen
Mandanten ersetzt: der Request scheitert geschlossen mit403.

Alte GET-Verwaltungslinks werden nach Anmeldung nur dann auf den kurzen
Pfad weitergeleitet, wenn ihr Mandant dem aktiven entspricht. Sonst404,
kein unbemerkter Vereinswechsel. Login-/Admin-Lesezeichen bleiben nutzbar.
Alte Mutationen werden nicht weitergeleitet. Offene alte Tabs neu laden.

## Zugriffsschutz

Filament tenant() und dessen Modelscoping bleiben aktiv. UseActiveTenant
setzt den Mandanten vor dem Rendern und als persistente Livewire-Middleware.
Session-Kontext ist benutzergebunden. Das signierte Komponenten-Memo bindet
Tenant-ID und zufaellige Kontextrevision. Nach Checksum-Pruefung, vor
Middleware/Model-Hydration wird der Kontext erneut geprueft; alte Tabs
erhalten419. Beim Vereinswechsel wird auch CSRF erneuert, damit alte
klassische Formulare nicht unter neuem Kontext weiterarbeiten.
Fremde IDs bleiben durch Scoping/Policies gesperrt. Gespeicherte Ansichten
bleiben nach Benutzer und Tenant getrennt.

## Framework-Einbindung

SessionPanel passt Panel-Home-URLs an. TenantNavigationServiceProvider
adaptiert ausschliesslich generierte filament.clubunio-Routen, inklusive
Action-Prefix fuer Laravels Routen-Cache. Keine Vendor-Dateien geaendert.
SessionUrlGenerator entfernt den von Filament intern uebergebenen Tenant
nur aus diesen Verwaltungs-URLs. Andere Routen/Signaturen bleiben erhalten.
Beim Framework-Upgrade zwingend Tests MIT und OHNE route:cache ausfuehren.
check-tenant-navigation.php prueft gecachte Pfade und Tenant-Middleware vor
Aktivierung des Releases; Route-/View-/Config-Caches werden erneuert.

## Abgrenzung

Standalone Portal-/Planungs-/Bus-/Dokument- und Kalenderlinks behalten ihre
explizite Mandantenbindung und Berechtigungspruefung. Das hier ist der
Verwaltungsbereich, keine automatische Kundendomain-Freischaltung und keine
API-Umstellung. Externe Links sollen nie allein per Host autorisiert werden.

## Tests und Rollback

Neue Integrationstests: automatische Auswahl, kurze Links, echte Mitglieder-
listen und Fremd-ID404, berechtigter/unberechtigter Wechsel, stale Form419,
entzogenes Membership403, gueltige Livewire-Anfrage sowie altes Snapshot419.
DB-Fixtures werden zurueckgerollt; keine echten Daten veraendert.
Lokale angemeldete Sichtpruefung: Dashboard /, Mitglieder /members und
Livewire-Suche erfolgreich. Dropdown mit zwei Testmandanten per HTTP getestet.
Das echte Benutzerkonto besitzt nur einen Mandanten.

Gezielte Regression mit aktivem Routen-Cache: 18 Tests, 158 Assertions.
Standardlauf ohne DB: 22 ausgefuehrte Tests, 142 Assertions; 304 DB-Tests
bewusst uebersprungen. ExampleTest separat: 2 Tests, 12 Assertions.
Release54 auf dem Pi aktiviert, Route-/Config-/View-Cache neu aufgebaut.
Oeffentlich geprueft: /, /members, /clubunio/login und dev.clubunio.com
fuehren unangemeldet zu https://dev.clubunio.ch/login (200).
/integrations/bexio fuehrt ebenfalls zur Anmeldung; PDF-Viewer-JS liefert200.
Vollstaendige DB-Regression in zwei Laeufen abgedeckt: zuerst280 Tests
erfolgreich, dann Abbruch an noch geladener alter ExampleTest-Erwartung
(/clubunio statt /login). Aktuelle ExampleTest-Version plus alle restlichen
Tests: 46 Tests/364 Assertions erfolgreich. Somit326 aktuelle Tests abgedeckt.
Quellhashes der Routing-/Kontextdateien auf dem Pi identisch mit lokal.

Vorversion53 bleibt erhalten. Rollback: current auf Release53 setzen und
FPM neu laden; APP_URL/ENV/DB muessen nicht zurueckgewechselt werden.
