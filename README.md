# ClubUnio

## DEV: Keycloak-Vorlagenabgleich korrigiert (25.11.2026)

Login/OTP mit passender Eckengeometrie, Annäherung von außen und Ausblendung
nach Kontakt. Karte exakt mittig; mobile Sprachauswahl ohne Überlagerung.
DEV CSS-v9/JS-v8 geprüft, Stage/PROD unverändert.
[Prüfung und Rückweg](deploy/CLUBUNIO-KEYCLOAK-GLASS-SOCIAL-SSO.md).

## Keycloak: Stage und PROD aktualisiert (25.11.2026)

Geprüftes Glasdesign CSS-v7/JS-v6 in den stabilen Themes aktiv. Ecken blenden
nach Kontakt aus; OTP-Automatik und Prüfstatus bleiben erhalten. Zehn DE/EN-
Login-/Assetprüfungen sowie Stage-/PROD-Browseransichten bestanden.
[Promotion, Sicherung und Rückweg](deploy/CLUBUNIO-KEYCLOAK-GLASS-SOCIAL-SSO.md).

## DEV: Glasecken abschließen (25.11.2026)

Login-/OTP-Ecken verschwinden nach dem Zusammentreffen. Prüfstatus bleibt
bis zur Antwort sichtbar. CSS-v7 auf DEV geprüft und aktiviert.
[Details und Rückweg](deploy/CLUBUNIO-KEYCLOAK-GLASS-SOCIAL-SSO.md).

## DEV: SFV automatisch nachführen, Garderoben erhalten (24.11.2026)

Neue eindeutig zugeordnete SFV-Heimspiele und Verschiebungen werden beim
Backend-Abgleich automatisch in Wettkämpfe, Plantafel und Mannschaftskalender
übernommen. Bestehende Wettkampf-ID, Heim-/Gastgarderoben und lokale Zusatzdaten
bleiben erhalten. Release `20261124-clubunio-daily-v2`, keine Migration.
[Verhalten, Fachprüfungen und Rückweg](deploy/CLUBUNIO-DAILY-WORKFLOWS.md).

## DEV: Keycloak-Glasdesign (24.11.2026)

Login v6 auf DEV: vollständige OTP-Codes werden automatisch bestätigt.
Login-/OTP-Animation und Prüfstatus bleiben bis zur Antwort/Weiterleitung aktiv. Paste und erfolgreiche Anmeldung vom Betreiber
bestätigt; generische OTP-Tools mit/ohne Input-Ereignis synthetisch geprüft.
DE/EN und Mobil geprüft. Social-Registrierungen und SSO-Umstellung bleiben offen.
[Betrieb, Rückweg und offene Schritte](deploy/CLUBUNIO-KEYCLOAK-GLASS-SOCIAL-SSO.md).

## DEV: einfache Alltagsabläufe (24.11.2026)

SFV-Abgleich mit eindeutiger Kalender-/Platzübernahme, Tagescockpit, Aufgabenboard,
Team-/Busalternativen, gruppierte Meldungen und Spesenentwurf mit privatem Beleg
auf DEV aktiviert. 69 lokale Tests / 874 Assertions und 39 isolierte DEV-Prüfungen.
Chrome Desktop/Mobil, DE/EN und getrennte Mandantendesigns geprüft; Browser-Belegupload
und physische Kamera als offene Prüfgrenze dokumentiert. Keine Schemaänderung.
[Verhalten, Abnahme und Rückweg](deploy/CLUBUNIO-DAILY-WORKFLOWS.md).

## DEV: sechs Alltagspakete (24.11.2026)

Benachrichtigungen mit Chaterwähnungen, berechtigte Objektsuche, freie
Planungsalternativen, geführte Erfassung, Teamaufgaben sowie Bedienungs- und
Ladeverbesserungen implementiert. Release `20261124-clubunio-work-hub-v3`;
vier additive Tabellen, Owner-Migration nach verschlüsselter DEV-Sicherung.
DE/EN, beide Designs, Fach- und isolierte Chrome-Tests erfolgreich;
Chrome/Desktop und responsive Handyansicht abgenommen; Konfliktbutton korrigiert.
[Umfang, Tests und Rückweg](deploy/CLUBUNIO-WORK-HUB.md).

## DEV: Desktop- und Mobilabnahme abgeschlossen (24.11.2026)

Persönliche Arbeitsabläufe in Chrome und responsiver Handyansicht geprüft.
Menüzuordnung, Spesen-Schnellaktion, Favoritenauswahl und mobile Vorschau
korrigiert; aktiv ist `20261124-clubunio-personal-workflows-v4`.
[Abnahme und Prüfgrenzen](deploy/CLUBUNIO-PERSONAL-WORKFLOWS.md).

## DEV: persönlicher Arbeitsbereich und Freigaben (24.11.2026)

„Mein Verein“, Freigabezentrale, Mitglieder-Zeitleiste, Planungskonflikte,
mobile Schnellaktionen sowie persönliche Favoriten/Ansichten auf DEV aktiv.
Bestehende Fachservices und Rechte; verschlüsselte Ablage je Benutzer und
Verein, DE/EN und vorhandenes Mandantendesign. Keine Migration.
[Bedienung und Abnahme](deploy/CLUBUNIO-PERSONAL-WORKFLOWS.md).

## DEV: sechs Modulpakete im Vereinsdesign (24.11.2026)

Training/Saison, Mitgliederdossiers, Rechnungen/Spesen, Kampagnen/Social Media,
Chat/Arbeitskorb und Synchronisation verwenden modulspezifische Arbeitsansichten.
Der bestehende React-Parameter steuert alle Ansichten; Mandantenfarben und
klassische Darstellung bleiben zentral. DEV: `20261124-clubunio-module-workspaces-v1`.
[Umfang und Abnahme](deploy/CLUBUNIO-MODULE-WORKSPACES.md).

## Einheitliches Backend mit Vereinsdesign – 24.11.2026

Gemeinsame Tabellen, Formulare, Dialoge, Tabs und Arbeitsbereiche auf DEV;
Dashboard, Navigation und Planung verwenden Farben und Logo des ausgewählten
Vereins. Dark Mode und kontrastabhängige Schriftfarben, zentraler React-Rückweg.
[Designquelle, Umfang und Abnahme](deploy/CLUBUNIO-BACKEND-THEME.md).

## Zentraler React-Parameter und Menü – 24.11.2026

Ein Vereinsparameter **React Ja/Nein** steuert Dashboard, Menü, Team- und
Anlagenplanung. Einzelne Designschalter entfallen. Neues CI-Menü mit
React-Suchpalette, Ctrl/⌘+K und mobiler Bedienung auf DEV ausgeliefert.
[Bedienung und Abnahme](deploy/CLUBUNIO-REACT-UI.md).

## Teamplanung auf DEV – 24.11.2026

Neue CI-Oberfläche für Terminliste und Teamkalender, mobile Agenda und
jederzeit umschaltbare bisherige Ansicht. DE/EN, gezielte Tests und
Browserabnahme erfolgreich. [Stand und Rückweg](deploy/CLUBUNIO-TEAM-PLANNING-UI.md).

## Interaktive DEV-Anlagenplanung – 24.11.2026

Neue Tages-/Wochen-/Monatsplanung in ClubUnio-Farben mit Anlagen-, Status-
und Konfliktfiltern, Zeitauswahl, Verschiebevorschlägen und mobiler Liste.
Bisherige Ansicht bleibt umschaltbar. Bestehende Rechte und Bestätigungen,
keine Migration oder Stage-/PROD-Änderung.
[Bedienung, Abnahme und Rückweg](deploy/CLUBUNIO-FACILITY-UI.md).

## DEV-Dashboard: Begrüssung und Animationen – 24.11.2026

Doppelte Begrüssung entfernt; V42-inspirierte Titel-, Karten-, Kennzahl- und
Diagrammanimationen ergänzt. Eigener DE/EN-Animationsschalter berücksichtigt
die beim ersten Aufruf geltende Bewegungseinstellung. DEV und Browserprüfung
erfolgreich; klassischer Rückweg bleibt erhalten.
[Abnahme und Migrationsstand](deploy/CLUBUNIO-DASHBOARD-DESIGN.md).

## DEV-Dashboard: CI und V42-Grafiken – 23.11.2026

ClubUnio-Blau/Hellblau und neue React-Balken-/Donutdiagramme auf DEV aktiv.
Echte Daten und bestehende Rechte, Datentabellen als Fallback; klassisches
Design jederzeit auswählbar. 16 Tests/66 Assertions und 9 Diagrammszenarien
erfolgreich. [Stand und Rückweg](deploy/CLUBUNIO-DASHBOARD-DESIGN.md).

## Umschaltbares DEV-Dashboard – 23.11.2026

Glasdesign mit jederzeit erreichbarem bisherigen Design auf DEV aktiviert.
React-Begrüssung, bestehende Filament-Kennzahlen und Rechte, DE/EN; keine
Migration. Stage/PROD unverändert. 13 Tests/53 Assertions sowie beide Designs
serverseitig geprüft; visuelle Browserabnahme offen.
[Umfang, Abnahme und Rückweg](deploy/CLUBUNIO-DASHBOARD-DESIGN.md).

Vereins-KI: [Mandantenbezogene OpenAI-Zugaenge, Wissensspeicher und Migrationsplan](deploy/CLUBUNIO-TENANT-AI.md).

Identitätsdienst: [Keycloak-Betrieb, Realms und Login-Theme](deploy/CLUBUNIO-KEYCLOAK.md).

Installation und Releases: [Statische Livewire-Dateien veroeffentlichen und pruefen](deploy/CLUBUNIO-INSTALL-ASSETS.md).

Kommunikationsmodul fuer die Flutter-App: [API und Entwicklungsstand](deploy/CLUBUNIO-COMMUNICATION-API.md),
[KI-/Azure-Betrieb](deploy/CLUBUNIO-COMMUNICATION-AI.md). Noch deaktiviert; kein Serverrelease.

Zentrale [Plattformverwaltung fuer Vereine, Domains, Design und Benutzerzuordnungen](deploy/CLUBUNIO-PLATFORM-ADMINISTRATION.md)
unter `/platform`, eigene Anmeldung `/platform/login`. Nur separat freigeschaltete
Plattformadmins, keine automatische Fachdatenberechtigung oder SaaS-Abrechnung.
Mandantenbezogener [Dokumentenspeicher und Logo-Upload](deploy/CLUBUNIO-TENANT-STORAGE.md)
mit Azure Blob, Amazon S3, Google Workspace, Microsoft 365 und privatem ClubUnio-Speicher.

Persoenlich freigeschalteter [Nur-Lesen-Supportmodus](deploy/CLUBUNIO-IMPERSONATION.md)
unter `/support/impersonation`, ohne automatische Freischaltung bestehender Konten.

Eigene [API-Plattform mit Admin-Tokens und geschuetzter Swagger-Doku](deploy/CLUBUNIO-API.md).
API-Basis `/api/v1`, Adminverwaltung `/api-integrations`, Dokumentation `/api-docs`.
Lesender Webseite-Zugang ist vom Mitgliederdaten-/Fachzugriff getrennt.
Die bestehende App und PHPMaker DEV/PROD werden dadurch nicht umgestellt.
Spezialisierte [Bexio-, SFV- und KI-API-Auftraege](deploy/CLUBUNIO-API-PROVIDERS.md)
mit dauerhafter Ergebnisnachverfolgung und bestehender Admin-Freigabe fuer Bexio.

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


Elternlogins: [Altersabhaengige Freigabe und Profilverwaltung](deploy/CLUBUNIO-PARENT-ACCESS.md).


Igloohome-App: [SVS-Abgleich, Notfallfunktion und Betriebsstand](deploy/CLUBUNIO-IGLOOHOME-APP.md).


[Chatuebersetzung: eigene24-Sprachen-Freigabe](deploy/CLUBUNIO-CHAT-TRANSLATION-LANGUAGES.md), unabhaengig von der DE/EN-Oberflaeche.


## Support-Wissensbasis – 23.11.2026

Support auf DEV, Stage und PROD aktiviert; 18 kuratierte Hilfethemen DE/EN,
Titel-/Inhaltssuche mit bestehenden Zugriffsgrenzen. App öffnet Hilfe zuerst,
mit Leerzustand und Wiederholen; neuer App-Build erforderlich.
76 Live-Prüfungen je Umgebung, 6 Backendtests/120 Assertions und 15 Fluttertests grün.
Details: [Supportartikel und App-Wissensbasis](deploy/CLUBUNIO-SUPPORT-HELP-APP.md).


## Wiki für App und Web – 23.11.2026

25 App- und44 Web-Anleitungen jeweils DE/EN, Schritte/Bilder,138 vertonte
Schrittvideos mit Untertiteln; zusätzlich echte Android-Beispiele für vier Module.
DEV/Stage/PROD mit87 Live-Prüfungen je Umgebung aktualisiert. Native Anzeige
erfordert neuen App-Build. [Inhalt, Medien, Pflege und Abnahme](deploy/CLUBUNIO-WIKI.md).


## Wiki ohne Videos – 23.11.2026

DEV und Stage: Anleitungen, Schritte und 146 Bilder/Screenshots bleiben aktiv;
Videos/Player/Untertitel privat archiviert und nicht mehr verlinkt. Je 91 Live-
Prüfungen erfolgreich. PROD auf ausdrücklichen Wunsch unverändert.
[Freigabestand und Rückweg](deploy/CLUBUNIO-WIKI.md).


## 24.11.2026: fertige DEV-Pakete auf Stage und PROD

Dashboard, Navigation, Backend-Arbeitsansichten, Alltagsablaeufe und SFV-Nachfuehrung
auf Stage/PROD aktiviert; Login-Themes V6 fuer vier Clients uebernommen.
Vier additive Work-Hub-Tabellen nach gepruefter verschluesselter Sicherung.
53 native Stage-/49 PROD-Pruefungen, bestehende Sicherheits-/Integrationskonfiguration
erhalten. Keine Datenauffrischung. Abnahme, Migrationsplan und Rueckweg: [Releaseprotokoll](deploy/CLUBUNIO-PROMOTION-20261124.md).


## App: Datumskacheln und reguläre Trainings – 24.11.2026

Heute: einheitliche Datumskacheln und lokale Datumsformatierung. My Teams zeigt
auch reguläre/Hallenzeiten ohne Sperrflags innerhalb des aktiven Plans. Backend
auf DEV/Stage geprüft; PROD unverändert. Stage Tenant1: Planwahl 0, Winterfreigabe
noch offen. Neuer App-Build für Kacheln erforderlich.
[Ursachen, Konfiguration und Abnahme](deploy/CLUBUNIO-MOBILE-TODAY-TEAM-TRAININGS.md).


## Winter Stage und App-Fixes auf PROD – 24.11.2026

Stage SV Sissach: Winterplan mit Audit aktiviert. PROD: Trainingsfilter und
Bild-only-Wiki gezielt nachgezogen, Videos privat archiviert. Bestehender Gmail-
Zugang zeigt zwei Wintertrainings beim Ba-Team.91 Wiki- und5 Trainingsprüfungen
auf PROD grün; kein neuer nativer Build.
[Freigabe, Prüfungen und Rückweg](deploy/CLUBUNIO-MOBILE-TODAY-TEAM-TRAININGS.md).
