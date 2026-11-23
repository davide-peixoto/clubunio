# Umschaltbares DEV-Dashboard – 23.11.2026

DEV nutzt standardmässig ein Glasdesign, angelehnt an die lokale V42-Vorschau.
Oberhalb des Dashboards kann jederzeit zwischen **Glasdesign** und
**Bisheriges Design** gewechselt werden. Die Wahl gilt pro angemeldetem Konto
für die aktuelle Serversitzung. Nach Sitzungsende ist Glas wieder Standard.
Stage und PROD wurden nicht aktualisiert.

## Umfang und Daten

React 19.2 rendert die Begrüssung, Vereinskarte und im Glasdesign die
Diagramme. Kennzahlen, Datenabfragen, Tabellen, Verknüpfungen und
Sichtbarkeitsprüfungen bleiben bei den bestehenden Filament-Widgets.
Die neue Diagrammdarstellung übernimmt deren unveränderte Datensätze.
Keine neuen Datenabfragen, API-Tokens, Berechtigungen oder Datenbankänderungen.
Keine Beispielzahlen, erfundenen Trends oder Bestandsdatenersetzungen.
Das Raumfoto und die CRM-Demofunktionen des Templates werden nicht ausgeliefert;
der Hintergrund ist ein eigener CSS-Verlauf. Dies ist die erste Dashboard-
Integration, keine Umstellung der Fachmodule auf React.

Alle neuen Texte liegen in `lang/de/dashboard_design.php` und
`lang/en/dashboard_design.php`. Der Wechsel verwendet die bestehende
Livewire-Sitzung und einen vollständigen Seitenaufruf. Das bisherige Design
lädt weder das neue Stylesheet noch das React-Bundle. Bei fehlenden Assets
wird automatisch die ursprüngliche Filament-Seite verwendet. Ohne React
bleiben serverseitige Begrüssung und alle Widgets verfügbar.

## Build und Freigabe

`npm ci` und `npm run build:dashboard` erzeugen das eigenständige Bundle
`public/js/clubunio-dashboard.js`; `npm run build` baut es ebenfalls.
React, React DOM und esbuild sind in Paketdatei und Lockdatei festgelegt.
DEV benötigt `CLUBUNIO_DASHBOARD_GLASS_ENABLED=true`; Standard ist false.
Die Datei `config/clubunio-dashboard.php` liest das Gate.

Aktiver DEV-Release:
`/var/www/svs-laravel/releases/20261123-clubunio-dashboard-ci-v42`.
Basis/Rückweg:
`/var/www/svs-laravel/releases/20261123-clubunio-dashboard-glass-v1`.
Nur acht explizite Laufzeitdateien auf die vollständig erhaltene aktuelle
Basis gesetzt. Keine Composer-/Schema-/Stage-/PROD-Änderung. Privates
Umgebungsbackup unter `shared/dashboard-design-env-before-20261123`;
nur der neue Gateeintrag wurde in der releaseeigenen Umgebung ergänzt.

## Abnahme und Rückweg

- 13 lokale Dashboardtests mit 53 Assertions bestanden: DE/EN, Umschaltung,
  Kontotrennung der Präferenz, ungültige Eingaben, fehlende Assets und Gates;
  bestehende Reservierungsrechte eingeschlossen.
- `php tools/check-translations.php`: 3075 JSON-Schlüssel plus PHP-Gruppen
  und Platzhalter erfolgreich geprüft.
- Bundle erstellt, Bladevorlagen lokal und auf DEV kompiliert.
- `deploy/check-dashboard-design.php` rendert mit bestehendem DEV-Konto beide
  Designs in DE/EN in einer Nur-Lesen-Transaktion, vergleicht die Widgetliste
  und prüft das globale Gate. Keine persistenten Benutzerpräferenzen oder
  Datenbankänderungen. Akzeptanz nach Aktivierung erneut erfolgreich.
- CSS und JS über DEV-HTTPS jeweils 200, anonymer Dashboardaufruf 302,
  PHP-FPM aktiv. Visuelle Desktop-/Mobilabnahme bleibt offen: Browsersteuerung
  meldet Zugriff verweigert. Serverrendering ersetzt diese Sichtabnahme nicht.

Sofortiger persönlicher Rückweg: **Bisheriges Design** wählen.
Global: Gate auf false setzen und Konfigurationscache neu erstellen.
Vollständiger Release-Rückweg: current auf die oben genannte erhaltene Basis
setzen und PHP-FPM reloaden. Keine Datenbankrückmigration nötig.

## CI-Farben und V42-Diagramme – 23.11.2026

ClubUnio-Blau #164B8A und Hellblau #48A9E8 gemäss PanelProvider ersetzen
die grüne Palette der ersten Vorschau. Dunkelmodus und Glasflächen nutzen
entsprechende Blautöne. Keine Änderung gespeicherter Vereinsfarben.

Sieben Diagramme erhalten im Glasdesign die neue React-Darstellung:
Mitglieder-/Spesenstatus, Mitgliederalter und -monate, Spesen nach Saison,
Rechnungsstatus und Rechnungsbeträge nach Saison. V42-inspirierte gerundete
Verlaufsbalken, Auswahl per Maus/Tastatur/Touch, direkte Wertanzeige,
schlanke Donutringe mit Summen und Anteilen. Kein Nachbau erfundener
Zeitreihen oder KPI-Sparklines. Statusnamen und benutzereigene Lookuptexte
bleiben unverändert; die neue Darstellung verwendet eine blaue Serienpalette.

Jedes Diagramm bietet zusätzlich eine serverseitige Datentabelle. Ohne
JavaScript bleibt sie geöffnet. Klassisch wird weiterhin der originale
Filament-/Chart.js-Renderer verwendet. Dieselben canView-Prüfungen gelten
vor Ausgabe der Daten auch im neuen Renderer. Kein neuer API-Endpunkt.

16 lokale Tests/66 Assertions sowie neun React-SSR-Szenarien bestanden:
Nullen, negative Balken, grosse Werte, mehrere Serien, leere Daten und
sicher ausgegebene Labels. Sprachprüfung erfolgreich. DEV-Nur-Lesen-Abnahme
rendert jetzt auch jedes berechtigte Diagramm mit echten Daten in DE/EN.
CSS/JS-HTTPS-Prüfung erfolgreich. Visuelle Geräteabnahme weiterhin offen.

13 begrenzte Laufzeitdateien auf DEV übertragen. Das bestehende abweichende
DEV-Schemapräfix in ExpensesBySeason wurde beim Paketbau explizit erhalten.
ENV, Composer, Datenbank, Stage und PROD unverändert. Voriges DEV-Release
bleibt erhalten. Die Angaben zu acht Dateien und ENV-Ergänzung oben
beschreiben die ursprüngliche Einführung, nicht dieses Folgedelta.
