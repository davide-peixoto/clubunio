# Sprachauswahl im Seitenkopf

## Stand 24.11.2026

Die native DE-/EN-Auswahl ist optisch an die Kopfzeilenaktionen angeglichen:
Sprachsymbol, eigener dezenter Pfeil, kompakte Schrift, acht Pixel Eckenradius,
Vereinsfarben beim Hover/Fokus sowie Hell-/Dunkelmodus. Auf kleinen Bildschirmen
bleibt die Schrift bei 16 Pixeln, um ungewolltes iOS-Eingabezoom zu vermeiden.

Gemeinsame Gestaltung in `public/css/clubunio-language-switcher.css`, direkt
einmalig durch die gemeinsame Blade-Komponente eingebunden. Damit funktionieren
die Symbole auch im eigenstaendigen Portal ohne Filament-Stylesheet.
Keine zusaetzliche JavaScript-Menueimplementierung: native Tastatur-/Touchauswahl,
Label, POST, CSRF und vorhandene Sprachpraeferenzen bleiben unveraendert.

## Migration und Deployment

Keine Datenbankmigration, keine Datenkorrektur und keine Rechteaenderung.
Nur DEV auf Basis des aktiven Pi-Releases aktualisiert. Stage/PROD unveraendert.
Gezielter Patch vorher gegen die aktive Serverbasis mit `git apply --check`
geprueft; keine fremden Aenderungen ersetzt. View-Cache erneuert, FPM neu geladen.
Asset-Version `language-header-v4`, gemeinsames Sprach-CSS Version 1.

Rueckweg DEV: privates Backup im aktiven Release unter
`storage/app/private/language-header-v4-20261124-163428.tar` mit Provider,
vorherigem `svs.css` und Blade-Komponente. Diese drei Dateien gemeinsam
wiederherstellen, View-Cache erneuern und FPM neu laden. Das neue CSS wird dann
nicht mehr eingebunden und kann liegen bleiben. Kein Datenbank-Rollback notwendig.

## Pruefung

`MobileLanguageHeaderTest` und `LanguagePreferencesTest`: sieben Tests erfolgreich,
zwei opt-in-Datenbanktests ausgelassen. Geprueft: Optionen/Label, POST/CSRF,
sofortiger Sprachwechsel, DE-/EN-Login, dekorative Symbole und gemeinsames CSS.
`php tools/check-translations.php`: 3075 Schluessel in DE/EN erfolgreich.
Angemeldete Desktop-/Mobile-Sichtpruefung wartet auf erneute DEV-Anmeldung.
