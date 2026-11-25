# Sprachauswahl im Seitenkopf

## Stage und PROD – 25.11.2026

Der unveraenderte Design-Fix aus `823b5a41` ist jetzt ebenfalls auf Stage und PROD
aktiv. Nur die vier zugehoerigen Laufzeitdateien gepatcht: gemeinsame Blade-View,
neues Sprach-CSS, Entfernung der alten Sprachregeln aus `svs.css` und gezielter
Asset-Versionswechsel im jeweiligen vorhandenen Panel-Provider. Keine fremden
Provider-Aenderungen, keine Authentifizierungs- oder Keycloak-Anpassung.

Vorab Patch gegen jede aktive Serverbasis geprueft und private Sicherung erstellt:
`/root/clubunio-releases/language-header-stage-20261125` beziehungsweise
`/root/clubunio-releases/language-header-prod-20261125`. Originaldateien in
`source.tar`, Vorher-/Nachherhashes und Abnahmeprotokoll dort. Konfiguration und
Composer-Lock identisch. Die betroffenen Dateien behalten lesbare Webrechte;
erste Stage-Anwendung bei fehlerhafter Dateiberechtigung automatisch restauriert,
anschliessend gezielt korrigiert und erfolgreich erneut geprueft.

Je Instanz DE/EN-Rendering, native Auswahl, Sprachsymbol/Pfeil, POST/CSRF und
versionierter CSS-Verweis erfolgreich. CSS per HTTPS heruntergeladen und gegen
die lokale Laufzeitdatei bytegenau verglichen. Veo und andere zuletzt deployte
Module bleiben unberuehrt. Nur gemeinsame Sprach-View kompiliert, FPM neu geladen;
kein globales Kompilieren aller fremden Views. Keine Datenbankmigration und keine
Benutzer-, Rollen- oder Sprachpraeferenzen geaendert. DEV nicht erneut deployt.

Rueckweg: drei vorherige Dateien aus `source.tar` wiederherstellen, die neue
Sprach-CSS-Datei entfernen, gemeinsame Sprach-View neu kompilieren und FPM laden.
Der Deployhelfer `promote-language-header.sh` fuehrt dies bei Fehler automatisch
aus; fertige Laeufe werden nicht erneut angewandt. Native Pruefung durch
`check-language-header.php`. Lokal sieben Tests / 84 Assertions erfolgreich,
zwei opt-in-DB-Tests ausgelassen. Angemeldete Desktop-/Mobile-Sichtpruefung bleibt
separat offen; kein erfolgreicher visueller Browser-Test behauptet.

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
