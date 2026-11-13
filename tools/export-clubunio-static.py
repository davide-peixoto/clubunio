import json
import os
import re
import shutil
import sys
from html import escape
from pathlib import Path
from urllib.request import urlopen

root = Path(__file__).resolve().parents[1]
output = Path(sys.argv[1])
analytics_id = os.getenv('CLUBUNIO_GTM_ID') or os.getenv('CLUBUNIO_GA4_ID', '')
captcha_key = os.getenv('CLUBUNIO_RECAPTCHA_SITE_KEY', '')
if analytics_id and not re.fullmatch(r'(?:G|GTM)-[A-Z0-9]+', analytics_id):
    raise SystemExit('Invalid Google measurement or container ID')
if captcha_key and not re.fullmatch(r'[A-Za-z0-9_-]+', captcha_key):
    raise SystemExit('Invalid reCAPTCHA site key')
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
        html = html.replace('/js/clubunio-marketing.js"', '/js/clubunio-marketing.js?v=2"')
        html = html.replace('href="/login"', 'href="https://dev.clubunio.ch/login"')
        html = html.replace(f'action="https://clubunio.com/{locale}/inquiry"', 'action="/inquiry.php"')
        html = re.sub(r'<input[^>]*name="_token"[^>]*>', f'<input type="hidden" name="locale" value="{locale}">', html)
        if not subpage:
            assert 'action="/inquiry.php"' in html and 'name="_token"' not in html
            attrs = ' '.join(f'data-{key}="{escape(copy["inquiry_"+key], quote=True)}"' for key in ('success', 'failure', 'invalid', 'captcha'))
            html = html.replace('<form method="post"', f'<p id="inquiry-result" role="status" hidden {attrs}></p><form method="post"', 1)
            if captcha_key:
                notice = escape(copy['captcha_notice'])
                field = ('<input type="hidden" name="captcha_token">'
                         f'<p class="small-note">{notice} '
                         f'<a href="https://policies.google.com/privacy?hl={locale}">{escape(copy['privacy'])}</a> · '
                         f'<a href="https://policies.google.com/terms?hl={locale}">{escape(copy['captcha_terms'])}</a></p>')
                html = html.replace('</form>', field + '</form>', 1)
        if analytics_id:
            settings = f"""<link rel="stylesheet" href="/css/clubunio-cookie-banner.css?v=1">
<details id="analytics-settings" class="cookie-settings" hidden>
<summary>{escape(copy['analytics_settings'])}</summary>
<div class="cookie-card">
<h2>{escape(copy['cookie_title'])}</h2>
<div class="cookie-actions">
<button type="button" data-choice="allow">{escape(copy['analytics_allow'])}</button>
<button type="button" data-choice="deny">{escape(copy['analytics_deny'])}</button>
<details class="cookie-options"><summary>{escape(copy['cookie_manage'])}</summary>
<p>{escape(copy['analytics_text'])}</p></details>
</div>
<a class="cookie-privacy" href="/{locale}/privacy/">{escape(copy['privacy'])}</a>
</div>
</details>"""
            html = html.replace('</body>', settings + '</body>')
        if analytics_id or (captcha_key and not subpage):
            integration = (f'<script defer id="site-integrations" data-analytics="{analytics_id}" '
                           f'data-captcha="{captcha_key if not subpage else ""}" '
                           'src="/js/clubunio-site-integrations.js?v=3"></script>')
            html = html.replace('</body>', integration + '</body>')
        (target / 'index.html').write_text(html, encoding='utf-8')
assets = ['favicon.ico', 'css/clubunio-marketing.css', 'css/clubunio-cookie-banner.css', 'js/clubunio-marketing.js', 'brand/blue-v1/clubunio-logo-horizontal.svg', 'brand/blue-v1/clubunio-symbol.svg']
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
shutil.copyfile(root / 'public/js/clubunio-site-integrations.js', output / 'js/clubunio-site-integrations.js')
if captcha_key:
    endpoint = output / 'inquiry.php'
    source = endpoint.read_text(encoding='utf-8')
    endpoint.write_text(source.replace('const CAPTCHA_REQUIRED = false;', 'const CAPTCHA_REQUIRED = true;'), encoding='utf-8')
encoded = json.dumps(options, ensure_ascii=False)
(output / 'inquiry-options.php').write_text("<?php\nreturn json_decode(<<<'JSON'\n" + encoded + "\nJSON, true, 512, JSON_THROW_ON_ERROR);\n", encoding='utf-8')
(output / 'index.html').write_text((output / 'de/index.html').read_text(encoding='utf-8'), encoding='utf-8')
with (output / 'js/clubunio-marketing.js').open('a', encoding='utf-8') as script:
    script.write("""
const result = document.querySelector('#inquiry-result');
const status = new URLSearchParams(window.location.search).get('inquiry');
if (result && ['success', 'failure', 'invalid', 'captcha'].includes(status)) {
    result.textContent = result.dataset[status];
    result.hidden = false;
    history.replaceState(null, '', window.location.pathname + window.location.hash);
}
""")
