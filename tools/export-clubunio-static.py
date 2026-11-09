import json
import os
import re
import shutil
import sys
from pathlib import Path
from urllib.request import urlopen

root = Path(__file__).resolve().parents[1]
output = Path(sys.argv[1])
output.mkdir(parents=True, exist_ok=False)
origin = 'http://127.0.0.1:8093'
options = {}
for locale in ('de', 'en'):
    catalog = (root / f'lang/{locale}/marketing.php').read_text(encoding='utf-8').split("<<<'JSON'\n")[1].rsplit('\nJSON,', 1)[0]
    copy = json.loads(catalog)
    options[locale] = copy['inquiry_options'].split('|')
    for subpage in ('', '/privacy'):
        target = output / (locale + subpage)
        target.mkdir(parents=True, exist_ok=True)
        with urlopen(origin + '/' + locale + subpage, timeout=10) as response:
            html = response.read().decode('utf-8')
        html = html.replace(origin, 'https://clubunio.com')
        html = html.replace('href="/login"', 'href="https://dev.clubunio.ch/login"')
        html = html.replace(f'action="https://clubunio.com/{locale}/inquiry"', 'action="/inquiry.php"')
        html = re.sub(r'<input[^>]*name="_token"[^>]*>', f'<input type="hidden" name="locale" value="{locale}">', html)
        if not subpage:
            assert 'action="/inquiry.php"' in html and 'name="_token"' not in html
            from html import escape
            attrs = ' '.join(f'data-{key}="{escape(copy["inquiry_"+key], quote=True)}"' for key in ('success', 'failure', 'invalid'))
            html = html.replace('<form method="post"', f'<p id="inquiry-result" role="status" hidden {attrs}></p><form method="post"', 1)
        (target / 'index.html').write_text(html, encoding='utf-8')
assets = ['favicon.ico', 'css/clubunio-marketing.css', 'js/clubunio-marketing.js', 'brand/blue-v1/clubunio-logo-horizontal.svg', 'brand/blue-v1/clubunio-app-icon.svg']
for asset in assets:
    target = output / asset
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(root / 'public' / asset, target)
shutil.copytree(root / 'public/images/marketing', output / 'images/marketing')
for font in (root / 'public/fonts').rglob('inter-latin-wght-normal-DIHYUR35.woff2'):
    relative = font.relative_to(root / 'public')
    target = output / relative
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(font, target)
    break
else:
    css = output / 'css/clubunio-marketing.css'
    css.write_text(re.sub(r'@font-face\s*\{[^}]*\}', '', css.read_text(encoding='utf-8'), count=1), encoding='utf-8')
shutil.copyfile(root / 'tools/clubunio-static-inquiry.php', output / 'inquiry.php')
encoded = json.dumps(options, ensure_ascii=False)
(output / 'inquiry-options.php').write_text("<?php\nreturn json_decode(<<<'JSON'\n" + encoded + "\nJSON, true, 512, JSON_THROW_ON_ERROR);\n", encoding='utf-8')
(output / 'index.html').write_text((output / 'de/index.html').read_text(encoding='utf-8'), encoding='utf-8')
with (output / 'js/clubunio-marketing.js').open('a', encoding='utf-8') as script:
    script.write("""
const result = document.querySelector('#inquiry-result');
const status = new URLSearchParams(window.location.search).get('inquiry');
if (result && ['success', 'failure', 'invalid'].includes(status)) {
    result.textContent = result.dataset[status];
    result.hidden = false;
    history.replaceState(null, '', window.location.pathname + window.location.hash);
}
""")
