"""Build the editable ClubUnio blue identity masters; wordmark is outlined."""
from pathlib import Path
from fontTools.ttLib import TTFont
from fontTools.varLib.instancer import instantiateVariableFont
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[2]
font = TTFont(ROOT / 'public/fonts/filament/filament/inter/inter-latin-wght-normal-DIHYUR35.woff2')
font = instantiateVariableFont(font, {'wght': 650}, inplace=True)
glyphs = font.getGlyphSet()
cmap = font.getBestCmap()
pen = SVGPathPen(glyphs)
scale = 96 / font['head'].unitsPerEm
x = 0
for char in 'ClubUnio':
    glyph = glyphs[cmap[ord(char)]]
    glyph.draw(TransformPen(pen, (scale, 0, 0, -scale, x, 0)))
    x += glyph.width * scale
word = pen.getCommands()

# Three individually editable shapes, with open negative-space joins.
paths = [
    ('#164B8A', 'M40 112 C40 26 168 26 168 112 L168 186 C168 220 86 252 76 337 C72 366 74 391 84 416 C54 382 40 337 40 288 Z'),
    ('#164B8A', 'M344 112 C344 26 472 26 472 112 L472 288 C472 379 420 430 335 430 C285 430 255 413 234 391 C300 398 344 361 344 301 Z'),
    ('#48A9E8', 'M168 210 C120 254 96 300 96 352 C96 448 169 500 266 500 C360 500 430 459 456 406 C420 449 333 469 267 428 C200 397 168 366 168 311 Z'),
]

def mark(transform='', mono=None):
    return f'<g transform="{transform}">' + ''.join(f'<path fill="{mono or color}" d="{d}"/>' for color, d in paths) + '</g>'

def lettering(tx, baseline, factor=1, color='#202C3B'):
    return f'<path fill="{color}" transform="translate({tx} {baseline}) scale({factor})" d="{word}"/>'

def svg(name, w, h, body):
    data = f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w} {h}" width="{w}" height="{h}" role="img" aria-labelledby="title"><title id="title">ClubUnio</title>{body}</svg>\n'
    (HERE / name).write_text(data, encoding='utf-8')

svg('clubunio-symbol.svg', 512, 544, mark())
svg('clubunio-logo.svg', 660, 690, mark('translate(74 0)') + lettering((660-x*1.35)/2, 642, 1.35))
svg('clubunio-logo-horizontal.svg', 900, 252, mark('translate(16 2) scale(.45)') + lettering(286, 159, 1.35))
svg('clubunio-logo-horizontal-white.svg', 900, 252, mark('translate(16 2) scale(.45)', '#FFFFFF') + lettering(286, 159, 1.35, '#FFFFFF'))
svg('clubunio-logo-monochrome.svg', 660, 690, mark('translate(74 0)', '#202C3B') + lettering((660-x*1.35)/2, 642, 1.35))
svg('clubunio-logo-white.svg', 660, 690, mark('translate(74 0)', '#FFFFFF') + lettering((660-x*1.35)/2, 642, 1.35, '#FFFFFF'))
# Full square opaque background; mobile platforms apply their own corner mask.
svg('clubunio-app-icon.svg', 1024, 1024, '<path fill="#F4F8FD" d="M0 0H1024V1024H0Z"/>' + mark('translate(153.6 131.2) scale(1.4)'))
svg('clubunio-preview.svg', 1400, 850,
    '<path fill="#FFFFFF" d="M0 0H1400V850H0Z"/>'
    + mark('translate(140 60) scale(.85)')
    + lettering(120, 595, 1.22)
    + '<rect x="820" y="145" width="400" height="400" rx="84" fill="#F4F8FD"/>'
    + mark('translate(880 196) scale(.55)')
    + '<rect x="100" y="708" width="72" height="32" rx="4" fill="#164B8A"/>'
    + '<rect x="188" y="708" width="72" height="32" rx="4" fill="#48A9E8"/>'
    + '<rect x="276" y="708" width="72" height="32" rx="4" fill="#202C3B"/>')
print('Generated SVG masters with outlined wordmarks.')
