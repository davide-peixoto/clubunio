# ClubUnio

Neue Webmodule: [Dokumentenserien](deploy/CLUBUNIO-DOCUMENT-SERIES.md) und
[Sponsorenlauf](deploy/CLUBUNIO-SPONSOR-RUN.md), mit eigener
[Bexio-Abrechnung und Wiederaufnahme](deploy/CLUBUNIO-SPONSOR-BILLING.md).
Legacy-DEV/PROD und App-API bleiben unveraendert.
Sponsorenlauf im Web durchgehend in CHF, intern weiterhin ganzzahlige Rappen.
Echter [Azure-/Bexio-Abnahmenachweis](deploy/CLUBUNIO-SPONSOR-DOCUMENT-ACCEPTANCE.md)
mit synthetischen Daten und Testversand ausschliesslich an Admin.
Angemeldete Desktop-/Mobil-Sichtpruefung nachgefuehrt; Layoutkorrekturen auf
ClubUnio bereitgestellt, keine Aenderung am bestehenden PHPMaker DEV/PROD.

Saisonplanung: [Speicherbarer Wizard mit verbindlichem Abschluss](deploy/CLUBUNIO-SEASON-PLANNING.md).
Technische [Fachabnahme mit isolierten Testdaten](deploy/CLUBUNIO-SEASON-ACCEPTANCE.md)
inklusive verbindlichem Browserabschluss und vollstaendigem Ergebnisabgleich.

Finanztest: [Sandbox-Guthaben auf zwei Zielrechnungen](deploy/CLUBUNIO-FINANCE-ACCEPTANCE.md),
vorbereitet mit neuen Testbelegen; manuelle Bexio-Verrechnung noch offen.

Gesamtmessung: [Performance-Abnahme und Wiederholungspruefung](deploy/CLUBUNIO-PERFORMANCE-ACCEPTANCE.md).

Synchronisation: [Uebersicht und Protokollperformance](deploy/CLUBUNIO-SYNC-VIEW-PERFORMANCE.md).

Trainingsplaene: [KI-Webplanung und PDF-Performance](deploy/CLUBUNIO-TRAINING-PERFORMANCE.md).

Teamplanung: [Serienaenderungen und Absenzen](deploy/CLUBUNIO-SERIES-ABSENCE-PERFORMANCE.md).

Beitraege: [Stapelvorschau und interne Entwuerfe](deploy/CLUBUNIO-CONTRIBUTION-PERFORMANCE.md).

Saisonwechsel: [Uebernahme und Mehrfachaktionen](deploy/CLUBUNIO-SEASON-BULK-PERFORMANCE.md).

Rundnachrichten: [Empfaengerauswahl und Listenperformance](deploy/CLUBUNIO-ANNOUNCEMENT-PERFORMANCE.md).

Benutzerverwaltung: [Dynamische Modul- und Funktionsprofile](deploy/CLUBUNIO-DYNAMIC-ROLES.md),
inklusive Nur-Lesen-Vorlage und separater Download-/Exportrechte.

Formulare: [Lookup- und Speicherperformance](deploy/CLUBUNIO-FORM-PERFORMANCE.md).

PDF-Anzeige: [Performance und private Dokumentzugriffe](deploy/CLUBUNIO-PDF-PERFORMANCE.md).

Anzeigen und Reservationen: [Signage-/Busperformance](deploy/CLUBUNIO-SIGNAGE-BUS-PERFORMANCE.md).

Listen und CSV: [Performance und Zugriffsgrenzen](deploy/CLUBUNIO-REPORT-PERFORMANCE.md).

Performance: [Spesen, Anmeldungen und private Dokumente](deploy/CLUBUNIO-EXPENSE-DOCUMENT-PERFORMANCE.md).
Anmeldeliste ohne Mandantenabfrage pro Zeile, gebuendelte Positionsbutton-
Lesechecks und bedarfsgesteuerte Anhangvorschau; Schreibrechte unveraendert.

## Lokaler Projektordner

Seit16.10.2026 eigenstaendig unter `C:\ClubUnio`, eigenes Git-Repository:
https://github.com/davide-peixoto/clubunio.git. Der bisherige Ordner
`C:\Projects\SVS\backend\laravel` ist nur noch eine Verknuepfung hierher.
Lokaler Start: `powershell -ExecutionPolicy Bypass -File tools/start-local.ps1`.
Adresse: http://127.0.0.1:8093/login. Lokale Konfiguration/APP_KEY bleiben erhalten.
Keine Server-/Datenbankmigration durch den Verzeichniswechsel.
Details und getrennte PHPMaker-Importquellen: [Lokaler Umzug](deploy/CLUBUNIO-LOCAL-WORKSPACE.md).

Fachpaket69: Verwaltungs-Audit, Bexio-Verbindung in normaler Oberflaeche und
PDF-/Anhang-URLs ohne Vereinskennung. Details und bewusste oeffentliche/Portal-
Ausnahmen: [Workspace-Migrationsplan](deploy/CLUBUNIO-WORKSPACE.md).

Fachpaket68: [Busverwaltung und Teamplanung im gemeinsamen Arbeitsbereich](deploy/CLUBUNIO-WORKSPACE.md).
Verwaltungswege `/bus` und `/planning` ohne Vereinskennung, normale Seitenleiste
und Mandantenwahl. Teamkalender bleibt bei der Planung; bestehende Portalrechte
und Fachservices bleiben erhalten. Keine Datenbankmigration.

Fachpaket67: [Teilgutschriften und Kundenguthaben](deploy/CLUBUNIO-CUSTOMER-CREDITS.md).
Webjournal fuer manuell attestierte Kundengutschriften und frisch nachgewiesene
Bexio-Mehrrechnungsverrechnungen, Mitgliederdossier und Restbetragschutz.
Live-Teilkorrektur inzwischen als GS-00005 gegen RE-00013 bestaetigt.
Aktueller [Finanz-Abnahmestand](deploy/CLUBUNIO-FINANCE-ACCEPTANCE.md).

Fachpaket66: [Signage in Vereinsfarben](deploy/CLUBUNIO-SIGNAGE-DESIGN.md).
Kompakte TV-Ansicht; Logo, Farbe, Titel und Trainingsperiode je Verein unter
Signage-Vorschau > Vereinsdesign (Admin), gemeinsam fuer Vorschau und Display.

Fachpaket65: [Oeffentliche Signage und Mitgliederanmeldung](deploy/CLUBUNIO-PUBLIC-WEB.md).
Signage-Vorschau repariert; anonyme Display-/Testformularseiten mit privaten
Dokumenten, Lookups und Spam-/Doppelschutz. Kein Ersatz der bestehenden Website.

Fachpaket64: [An Bexio uebertragen direkt bei der Rechnung](deploy/CLUBUNIO-INVOICE-TRANSFER.md).
Admin-Webaktion nach interner Freigabe, nur Sandbox, separate Testkontakte auch
fuer weitere Mitglieder; kein E-Mail-Versand oder Bankzahlung.

Fachpaket63: [Konkreter Bexio-Gutschriftrueckfluss](deploy/CLUBUNIO-CREDIT-ALLOCATION.md).
RE-00009/GS-00004 abgeglichen, konkrete Voucher-/Betragspruefung statt nur Summen.

Fachpaket62: [Materialuebergaben und Empfangsbestaetigungen](deploy/CLUBUNIO-MATERIAL-RECEIPTS.md).
Mitgliederdossier/Portal, PDF-Quittung, historischer Azure-Nachweis und sichere
Altplanuebernahme. Keine externe Zustellung oder Bankzahlung.

Fachpaket61: [Ankuendigungen und Rundnachrichten](deploy/CLUBUNIO-ANNOUNCEMENTS.md).
Webentwurf, Empfaengervorschau und bestaetigte interne Zustellung; kein E-Mail/FCM.

Fachpaket59: [Mitgliederantraege und Dokumentpflege](deploy/CLUBUNIO-MEMBER-CHANGES-DOCUMENTS.md).
Web-Antraege mit Alt-/Neuvergleich, Genehmigung/Konfliktschutz und private
Azure-Mitgliederdokumente mit expliziter Portal-Freigabe. API/DEV/PROD unveraendert.

Fachstand58: [PMP-Abgleich und Abnahmegrenzen](deploy/CLUBUNIO-PMP-ACCEPTANCE.md),
[Abdeckungsmatrix](deploy/CLUBUNIO-PMP-COVERAGE.md). Dossier-/Stammdatenpflege
ergaenzt; neue offene Webthemen ausdruecklich ausgewiesen.

Fachpaket56: Trainingsaltbestand mit expliziter Zuordnung und Qualitaetssperre,
Bus-Schluesselbox-Simulation (keine echte Hardwareausgabe) und Instagram-Livecheck.
Siehe [Stand und offene Schritte](deploy/CLUBUNIO-TRAINING-KEYBOX-INSTAGRAM.md).

Vereinsverwaltung auf Laravel und Filament. Anmeldestand Release57,
Mitgliederanmeldungen/Wiedereintritt und private Dokumentverwaltung:
[Workflow und Migrationsschritte](deploy/CLUBUNIO-REGISTRATION-COMPLETION.md).

Finanzstand Release55:
Gutschriften und manuelle Rueckerstattungsnachweise, ausschliesslich
Bexio-Sandbox; keine echten Bankzahlungen. PHPMaker DEV/PROD bleibt parallel.
Siehe [Migrationsplan](deploy/CLUBUNIO-ROADMAP.md), [Pi-Stand](deploy/PI.md)
und [Gutschriften/Rueckerstattungen](deploy/CLUBUNIO-CREDITS-REFUNDS.md).

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[WebReinvent](https://webreinvent.com/)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Jump24](https://jump24.co.uk)**
- **[Redberry](https://redberry.international/laravel/)**
- **[Active Logic](https://activelogic.com)**
- **[byte5](https://byte5.de)**
- **[OP.GG](https://op.gg)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
# SVS: Local Laravel / Filament Pilot

Read [PILOT.md](PILOT.md) before running commands. Shared DEV database,
read-only; no migrations or production deployment.
