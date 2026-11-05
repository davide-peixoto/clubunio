# Mobile Sprachwahl in der Web-Kopfzeile

05.11.2026, lokal implementiert; kein Deployment oder Commit in diesem Teilauftrag.

## Aenderung

`resources/views/clubunio/language-switcher.blade.php` verwendet eigene
`cu-language-switcher`-Klassen aus dem bestehenden Shell-Asset `public/css/svs.css`.
Die bisher verwendeten Utility-Klassen, insbesondere `sr-only`, sind im
ausgelieferten `public/css/filament/filament/app.css` nicht verfuegbar.
Das Label wird jetzt explizit nur visuell verborgen; Form und Auswahl erhalten
eigene Abmessungen, native Auswahlpfeile und einen sichtbaren Tastaturfokus.
Nur innerhalb der Topbar kommen12px Abstand zum vorangehenden Profilbereich hinzu.
Kein Tailwind-Build und keine Pitch-Aenderung.

Nachlauf nach Parent-Livebefund: Der12px-Formabstand allein genuegte nicht,
weil der32px-Avatar aus seinem auf20.25px geschrumpften Menue herausragte.
Nur Topbars mit direktem Sprachwaehler erhalten nun ein nicht schrumpfbares
Profilmenue mit mindestens32px Breite sowie `min-width:0` fuer den rechten
Flexbereich, Suchcontainer und dessen inneres Suchfeld. Login und andere
Topbars bleiben davon unberuehrt. Die erste synthetische Suchbutton-Fixture
hatte diesen Platzdruck nicht abgebildet; sie wurde durch echte Filament-
Such-/Input-/Dropdown-Klassen mit153.75px Suchbreite ersetzt.

Sofortiger `requestSubmit()` beim Wechsel, CSRF-POST, aktivierte Sprachoptionen,
uebersetztes Label/ARIA-Label, Login-Nutzung und DE/EN bleiben erhalten.
Die Auswahl hat mindestens40px Hoehe und16px Schrift; Dark Mode ist beruecksichtigt.
Keine neuen Sprachschluessel, Daten-/Rechte-/API-Aenderungen oder Migrationen.

## Pruefung

- `php artisan test --filter='MobileLanguageHeaderTest|LanguagePreferencesTest|LanguageCatalogIntegrityTest'`:
  11 bestanden,19577 Assertions; zwei bestehende opt-in DB-Tests bewusst ausgelassen.
- `php tools/check-translations.php`:2670 Schluessel DE/EN, kein offener Literal-Backlog.
- `node --test tests/Frontend/mobile-language-header.browser.test.mjs`:
  16 bestanden, jeweils Header und Login; DE/EN, hell/dunkel,320/390/768/1440px.
- Browserpruefung: mindestens12px Avatarabstand, kein horizontaler Ueberlauf,
  erreichbare Auswahl, visuell verborgenes aber benanntes Label, Fokus per Tab,
  native Pfeiltasten, sofortiger einmaliger Submit mit Sprachwert und CSRF-Feld.
- Nachlauf:16 Browsertests erneut bestanden; zusaetzlich mindestens32px
  Menuebreite, Avatar innerhalb des rechten Flexbereichs, Suchfeld innerhalb
  seines Containers und keine Suche-/Avatar-Ueberlappung geprueft.
  Die erweiterte320px-Fixture scheiterte vor den drei neuen CSS-Regeln und
  besteht danach. Exakte Live390px-Abnahme bleibt beim Parent.
- Screenshots unter `%TEMP%/clubunio-mobile-language-header/`;390px DE hell,
  EN dunkel und320px Login zusaetzlich visuell geprueft. Keine Screenshots in Git.
- `git diff --check`: ohne Fehler.

Browserlauf nutzt `PLAYWRIGHT_MODULE` fuer eine vorhandene `playwright/index.mjs`
und optional `PLAYWRIGHT_CHANNEL` (Standard `chrome`). Separater headless Browser
mit frischem Profil; saemtliche Netzwerkanfragen blockiert. Blade wird lokal
gerendert, die vorhandenen kompilierten Shell-Styles werden unveraendert geladen.
Avatar/Suche und Login-Rahmen sind synthetisch, keine echte angemeldete Seite,
kein Livewire-/Alpine-Ende-zu-Ende-Test und kein nativer iOS-Nachweis.

## Uebergabe

Parent uebernimmt Live-Abnahme, Release/Cache-Nachlauf und Gesamtplan.
Beim Release Blade und `svs.css` gemeinsam bereitstellen und frisches CSS im
Browser sicherstellen; Parent hat die Asset-Version auf `language-header-v3`
angehoben und den entsprechenden PHP-Test angepasst.
Rueckweg ist das gemeinsame Zuruecknehmen dieser beiden Runtime-Dateiaenderungen.
PHPMaker DEV/PROD, Benutzersitzung und Fachdaten wurden nicht veraendert.
