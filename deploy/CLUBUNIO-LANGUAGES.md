# Deutsch und Englisch

04.11.2026, ClubUnio lokal/Pi. PHPMaker DEV/PROD unveraendert.

## Gelieferte Sprachbasis

- Aktivierte Sprachen in config/clubunio-languages.php: de, en; Standard de.
- Sprachwahl am Login und in der Kopfleiste, CSRF-geschuetzter POST /language.
- Gastwahl in Sitzung, angemeldete Wahl ausschliesslich am eigenen Konto.
- accounts.preferred_locale und tenants.default_locale durch additive Migration
  2026_11_04_001200_language_preferences. Nullwerte erhalten bisheriges Deutsch.
- Aufloesung: persoenliche Sprache, explizite Sitzungswahl, Vereinssprache, de.
  Unbekannte/deaktivierte Sprachen nicht ausfuehren. Admins koennen die
  Vereinssprache unter Einstellungen / Vereinssprache mit Begruendung setzen;
  Revisionsvergleich und Audit, keine fremden Vereinsrechte.
- Filament-Standardtexte nutzen dessen DE-/EN-Kataloge; eigene Navigation,
  Login, gemeinsame Feld-/Aktionsbeschriftungen, Ressourcentitel und Dossiertabs
  nutzen lang/de.json und lang/en.json. Namen/Freitexte bleiben unveraendert.
- Keine Aenderung an Status-IDs, URLs, API-Feldnamen, Rollen, Buchungen oder CHF.
- Sprachwahl beeinflusst nur die Darstellung, niemals einen Zugriffsscope.
  Simulationsschreibschutz bleibt aktiv. Livewire setzt die Sprache erneut.

## Verbindlich fuer weitere Entwicklung

AGENTS.md schreibt alle aktivierten Sprachen vor. Neue Oberflaechentexte mit
__() bzw. Blade-@lang pflegen, variable Teile als benannte Platzhalter.
Filament-label() wird zentral uebersetzt. Tooltip/Helper/Placeholder, JavaScript,
Validierungsfehler und eigene HTML-Views brauchen explizite Uebersetzungsaufrufe.
Keine fertigen HTML-Antworten oder Benutzereingaben pauschal uebersetzen.

php tools/check-translations.php prueft Kataloggleichheit, fehlende Werte,
Platzhalter und neue nicht katalogisierte PHP-Labels. --sync-de aktualisiert den
DE-Quellkatalog aus den deutschen Schluesseln. Keine vollstaendige HTML-/JS-
Spracherkennung: neue Views und Meldungen auch manuell pruefen.
Die einmalige Bestandsliste lang/legacy-label-backlog.json ist keine Freigabe
fuer neue Luecken. --baseline nicht zum Umgehen eines fehlgeschlagenen Checks
verwenden. Bestandsuebersetzungen reduzieren den Rueckstand schrittweise.

## Noch nicht vollstaendig migriert

Dies ist KEINE Behauptung einer vollstaendig englischen Fachanwendung.
Die erste Inventur enthaelt514 unterschiedliche vorhandene PHP-Label-/Tooltip-
Texte ohne englische Uebersetzung. Zusaetzlich sind rohe Blade-/JS-Texte,
Fachfehlermeldungen, Exporte/PDFs und laengere Fachdialoge systematisch umzubauen.
Der sichtbare Rueckfall bleibt Deutsch, statt Texte zu verbergen.

Mandanten-eigene Lookupwerte und E-Mail-/Push-/Dokumentvorlagen brauchen
Sprachvarianten und Empfaengersprache; diese Daten wurden NICHT automatisch
ueberschrieben oder aus einem Fremddienst uebersetzt. Bestehender Versand bleibt
unveraendert, keine echten E-Mails/Pushs zum Testen. Die Flutter-App benoetigt
weiterhin ihre eigenen ARB-Kataloge; dieser Webrelease aendert sie nicht.
Prioritaet fuer die Folgepakete: Mitglieder/Anmeldungen, Finanzen, Teamplanung,
Bus/Anlagen, Kommunikation/Vorlagen, restliche Auswertungen und PDFs.

## Migration und Rueckweg

Nur001200 gezielt mit --database=clubunio --path=database/clubunio/
2026_11_04_001200_language_preferences.php --force. Keine Sammelmigration,
kein Neuimport/Auffrischen. Lokale Instanz und Pi teilen die ClubUnio-DB.
Keine Bestandskonten aktiv auf Englisch umgestellt. Rollback per vorherigem
Release moeglich; additive Spalten belassen, down verlangt gepruefte Sicherung.

## Tests

LanguagePreferencesTest, TenantNavigationTest, FamilyNavigationTest:
17 Tests/205 Assertions erfolgreich, transaktionale Testkonten zurueckgerollt.
Abgedeckt: DE-/EN-Loginantwort, Content-Language, Auswahlvalidierung, sicherer
Ruecksprung, persoenliche Speicherung ohne Rollenwechsel, Adminpflicht bei
Vereinssprache, veraltete Aenderung, Audit und bestehende Mandantennavigation.
Vollstaendige visuelle Fachabnahme und englische Vorlagenabnahme noch offen.

## Release-Abnahme

e4fa1e7 auf20261104-clubunio-languages, basierend auf chat-composer-v1.
Vorab Patch-Anwendbarkeit gegen laufenden Release geprueft; nur eigener Delta,
Env/Composer/Shared-Storage identisch. Vier Servergates erfolgreich,272 Schluessel
in beiden Katalogen geprueft; Migration1200 bereits auf gemeinsamer DB vorhanden.
Config-/Route-/View-Caches neu aufgebaut und FPM neu geladen.
deploy/check-languages-http.php bestaetigt anonymen CSRF-geschuetzten Wechsel
en/de mit HTTP200 und HTML-lang. Kein bestehendes Konto geaendert.
IAB nach Neuinitialisierung verfuegbar: Login mit englischen Framework-/Feldtexten
visuell auf1280x720 und390x844 ohne Ueberlappung; zurueck auf Deutsch gewechselt.
Screenshots lokal storage/app/languages-login-en.png und
languages-login-en-mobile.png, nicht versioniert. Angemeldete Fachmasken bleiben
als visuelle Restabnahme offen; keine pauschale Vollstaendigkeitsbehauptung.
