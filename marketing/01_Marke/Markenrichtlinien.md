# ClubUnio: verbundenes U, Blau v1

15.10.2026. Handwerklich neu aufgebaute Vektorfassung des gewaehlten
Logoentwurfs 02. Keine eingebetteten Rasterbilder, keine Verlaeufe.
SVG-Wortmarken sind reine Pfade und brauchen keine installierte Schrift.
Die drei Symbolformen sind separat editierbar.

## Dateien

- clubunio-logo.svg/png: gestapelte Wort-Bild-Marke, transparent.
- clubunio-logo-horizontal.svg/png: horizontale Wort-Bild-Marke, transparent.
- clubunio-logo-horizontal-white.svg/png: horizontale weisse Dark-Mode-Variante.
- clubunio-symbol.svg/png: separates U, transparent.
- clubunio-logo-monochrome.svg/png: einfarbig Graphit, transparent.
- clubunio-logo-white.svg/png: einfarbig Weiss fuer dunkle Flaechen, transparent.
- clubunio-app-icon.svg/png: quadratische Iconvorlage, 1024px PNG.
- clubunio-app-icon-{512,192,48,32}.png: weitere Rastergroessen.
- clubunio-preview.svg/png: weisse Vergleichsflaeche, nicht das transparente Original.

## Farben und Verwendung

Hauptblau #164B8A, Verbindungsblau #48A9E8, Wortmarke #202C3B.
Icon-Hintergrund #F4F8FD. App-Icon absichtlich deckend und ohne eingebaute
runde Ecken: die Plattform maskiert die quadratische Vorlage selbst.
Die Iconvorlage ist kein vollstaendiges Android-Adaptive-Icon-Ressourcenpaket.
Abstaende nicht beschneiden; fuer kleine Darstellungen nur das Symbol nutzen.
Monochrome Variante fuer Stempel und einfarbigen Druck verwenden.

Wortmarkenbasis: Inter, Gewicht650 (The Inter Project Authors), als Pfade
aus der bereits im Projekt vorhandenen Schrift erzeugt. Keine Schriftdatei
im Lieferpaket. Zeichenabstand normal, kein kuenstliches Stauchen.

## Reproduzierbarkeit und Status

build.py benoetigt fontTools/Brotli; render.cjs benoetigt sharp.
SVG-Master entstehen aus editierbaren Bezierpfaden; PNGs werden daraus gerendert.
ClubUnio verwendet diese Identitaet ab Release60 (15.10.2026). publish.cjs
stellt die Laufzeitdateien unter public/brand/blue-v1 bereit. Die native App
und PHPMaker DEV/PROD bleiben unveraendert. Markenrechtliche Eigenstaendigkeit
ist nicht geprueft.
