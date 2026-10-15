# Busverwaltung und Teamplanung in ClubUnio

## Oberflaeche

Die Verwaltung nutzt die gemeinsame Filament-Seitenleiste, Kopfzeile,
Mandantenwahl und Hell-/Dunkeldarstellung. Kein iframe und keine zweite
Anmeldung. Die bisherigen Masken verwenden weiterhin dieselben Controller
und Fachservices; es gibt keine zweite Implementierung der Geschaeftsregeln.

- Busverwaltung: `/bus`, Detail `/bus/{reservation}`, Erfassung `/bus/new`.
- Busse und Reservierungsstatus: `/bus/options`.
- Teamplanung: `/planning`; Kalender `/planning/calendar`.
- Absenzen: `/planning/absences`; SFV-Spiele: `/planning/sfv`.
- KI-Trainingsplaene inklusive PDF: `/planning/training-plans`.
- Bisherige Panel-Einstiege `/bus-reservations` und `/team-planning` leiten weiter.

Der Teamkalender ist ausschliesslich in der Teamplanung verlinkt. Seine
optionale Busbelegungsanzeige bleibt fuer die Terminabstimmung erhalten.
Filter, Suche, Statuszaehler, Pagination, Formularvalidierung, Kalenderexport,
Serienbearbeitung, Freigaben, Umbuchung, Historienzuordnung und die bisherige
Schluesselkasten-Simulation verwenden ihre bestehenden Fachfunktionen.

## Mandanten und Sicherheit

Die neuen Verwaltungsrouten sind nur mit dem ClubUnio-Login und einer
berechtigten Admin-/Geschaeftsstellenrolle erreichbar. `ActiveTenant` liest
den gewaehlten Verein aus der Sitzung, nicht aus URL/Formularfeldern.
`UseClubWorkspace` stellt diesen Kontext vor dem Controlleraufruf bereit.
Jeder schreibende Formularaufruf prueft zusaetzlich zu CSRF einen verschluesselten
Nachweis des Mandanten und seiner Sitzungsrevision. Ein altes Formular nach
Mandantenwechsel wird mit 419 abgewiesen; die Datensatzrevision bleibt separat.
Livewire-Kopfzeile und Seitenleiste verwenden ebenfalls den bestehenden
signierten Mandantennachweis. Antworten sind `private, no-store`.

Alte Verwaltungs-Lesezeichen wie `/bus/sv-sissach` oder
`/planning/sv-sissach/calendar` leiten nur bei uebereinstimmendem aktivem
Mandanten weiter. Kein automatischer Mandantenwechsel durch Links.
Alte schreibende Verwaltungsformulare werden nicht weitergeleitet, sondern
fordern ein Neuladen. Bestehende Portalrollen behalten ihre eingeschraenkten
mandantengebundenen Routen und unveraenderten Fachrechte; sie bekommen dadurch
keinen Zugriff auf die Verwaltung. Oeffentliche Signage/Anmeldeseiten bleiben
bewusst mandantenbezogen und sind nicht Bestandteil dieser Umstellung.

## Migration und Deployment

Keine Datenbankmigration, keine Aenderung von Fachdaten oder Einstellungen.
Gezieltes Dateidelta auf den zuletzt aktiven ClubUnio-Release kopieren;
Config-/Routencache neu bauen, Blade-Ansichten kompilieren und
`deploy/check-tenant-navigation.php` mit aktiviertem Routencache pruefen.
Danach Release atomar umschalten und PHP-FPM neu laden.
Rollback durch Rueckschalten auf den zuvor protokollierten Release.
PHPMaker DEV/PROD, App-API, SaaS und externe Versandfunktionen bleiben unveraendert.

Bei einer spaeteren Ablosung diese Routen und Formularnachweise beibehalten;
die Portalrollen erst mit einer gesondert getesteten Rollen-/App-Migration
umstellen. Ein Entfernen der alten Portalrouten gehoert nicht zu diesem Paket.

## Pruefung

`TenantNavigationTest` prueft gemeinsame Navigation, saubere Links,
Mandantenwechsel, fehlende/manipulierte Formularnachweise und alte Lesezeichen.
Bus-, Teamplanungs-, KI-Trainings-, Pagination- und Schluesselkasten-Tests
pruefen weiterhin Fachregeln, Fristsperren, Datenschutz und Revisionen.
Testdaten entstehen nur in zurueckgerollten Transaktionen der ClubUnio-Datenbank;
Provideraufrufe werden in den jeweiligen Tests gemockt.

Abnahme am 15.10.2026: 51 Tests / 485 Assertions erfolgreich, einschliesslich
aktiviertem Routencache, PDF-Export, Kalenderexport und Livewire-Folgeaufrufen.
Ergaenzend Gast-/Deep-Link-Test1/14 erfolgreich: Verwaltungslinks fuehren zum
normalen /login; alte Portal-Planungslinks behalten ihren /portal/login.
Blade-Kompilierung und Pint erfolgreich. Auf ClubUnio Release68 aus Basis67
deployt; beide Listen und ein Busdetail angemeldet auf Desktop gesichtet,
altes Planungs-Lesezeichen leitet korrekt weiter. Die mobile Sichtpruefung
konnte wegen eines Abbruchs der Browsersteuerung nicht abgeschlossen werden.
