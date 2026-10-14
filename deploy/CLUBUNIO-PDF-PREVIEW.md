# ClubUnio: PDF-Vorschau und Vorbereitung Artikel/Steuern

14.10.2026, Release49. PHPMaker DEV/PROD, API, SaaS und Betrieb unveraendert.

## Abgeschlossen

- Rechnungs-SlideOver verwendet einen lokalen PDF.js-Renderer statt des
  browserabhaengigen PDF-Plugins. Seitenwechsel, Seiteneingabe, Zoom,
  automatische Breite und Download sind vorhanden.
- HTML-Vorschau und PDF bleiben authentifiziert, mandanten- und
  datensatzberechtigt. Keine Dokumente auf CDN, kein oeffentlicher PDF-Link.
- Bexio-PDF bleibt unabhaengig vom Aktivierungsparameter lesbar.
- PDF.js 6.4.299 aus dem offiziellen npm-Paket pdfjs-dist; Apache-2.0-Lizenz
  unter public/vendor/pdfjs/LICENSE. Worker, Fonts, CMaps und WASM lokal.
  Module unveraendert mit .js-Endung ausgeliefert, da Pi .mjs als
  application/octet-stream liefert; damit keine Nginx-Aenderung erforderlich.
- Renderer begrenzt Canvas-Pixel, deaktiviert PDF-Eval und zeigt Fehler ohne
  Providerantworten/Secrets. Bexio-PDF-Service mit Diagnose-Referenz bleibt.
- Keine Datenbankmigration, kein Parameter und kein neuer Job erforderlich.

## Pruefung

Webtest prueft authentifizierte Vorschau, SAMEORIGIN und ungueltige Quelle.
Sandbox-Regression: 19 Tests/145 Assertions bestanden.
Synthetisches zweiseitiges PDF im separaten localhost-Testserver sichtbar
gerendert; Seitenwechsel und Zoom geprueft, 390px und Desktop. Testserver
danach gestoppt; Test-PDF nicht deployed. Angemeldete Live-Abnahme des
Bexio-Dossiers noch offen, solange Browser auf Login steht.
Pi: Release49 aktiv, Ruecksprung48 erhalten; Renderer/Worker200 mit
application/javascript, unangemeldete Vorschau302 zum ClubUnio-Login.

## Noch offen im Fachpaket

Sandbox-Katalog read-only verifiziert: UEX0 aktiv, UN81/8.1 und UR26/2.6
aktiv, US38/3.8 inaktiv; Artikel derzeit leer. CLI-Diagnose
tools/clubunio-tax-catalog-check.php auf eigene DB und Sandbox/Admin begrenzt.
Artikel-GET in SandboxClient freigegeben, keine neuen Schreibpfade.

Steuerpflichtige Rechnungen bleiben bewusst gesperrt. Folgende Arbeiten sind
noch NICHT umgesetzt: pflegbare Artikel-/Steuerlookups, feste Steuersnapshots
pro Position, CHF-Fuenfrappenrundung, gemischte Positionen, eigene Testartikel,
Live-Validierung und Anpassung des Zahlungs-/Statusabgleichs. Bestehende
Brutto-minus-Rabatt-Endbetraege duerfen nicht durch aufgeschlagene Steuer
veraendert werden. Erst nach konsistenter Gesamtberechnung freigeben.
Unabhaengige Gutschriftanlage bleibt ebenfalls offen.

Referenz: https://mozilla.github.io/pdf.js/ und https://docs.bexio.com/.
