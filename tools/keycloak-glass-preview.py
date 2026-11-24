"""Loopback-only synthetic UI fixture; never connects to Keycloak or a database."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse
import json
from threading import Event

ROOT = Path(__file__).resolve().parents[1]
RESOURCES = ROOT / 'deploy/keycloak/themes/clubunio-glass-dev/login/resources'
ASSETS = {
    '/css/clubunio-glass-v6.css': (RESOURCES / 'css/clubunio-glass-v6.css', 'text/css'),
    '/js/clubunio-glass-v6.js': (RESOURCES / 'js/clubunio-glass-v6.js', 'text/javascript'),
    '/img/clubunio-logo-light.svg': (RESOURCES / 'img/clubunio-logo-light.svg', 'image/svg+xml'),
    '/img/svs-logo.png': (ROOT / 'deploy/keycloak/themes/sv-sissach/login/resources/img/svs-logo.png', 'image/png'),
    '/css/sv-sissach-glass.css': (ROOT / 'deploy/keycloak/themes/sv-sissach-glass-dev/login/resources/css/sv-sissach-glass.css', 'text/css'),
}
RESULTS = []
RELEASE = Event()
WAITING = Event()


class Fixture(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def send(self, body, kind='text/html; charset=utf-8', status=200):
        body = body.encode() if isinstance(body, str) else body
        self.send_response(status)
        self.send_header('Content-Type', kind)
        self.send_header('Cache-Control', 'no-store')
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        parsed = urlparse(self.path)
        if parsed.path in ASSETS:
            path, kind = ASSETS[parsed.path]
            return self.send(path.read_bytes(), kind)
        if parsed.path == '/results':
            return self.send(json.dumps(RESULTS), 'application/json')
        if parsed.path == '/pending':
            return self.send(json.dumps({'waiting': WAITING.is_set()}), 'application/json')
        if parsed.path == '/release':
            RELEASE.set()
            return self.send('{"released":true}', 'application/json')
        if parsed.path == '/completed':
            return self.send('<h1>Native POST verified</h1><pre>' + json.dumps(RESULTS[-1] if RESULTS else {}) + '</pre><a href="/">Back to OTP fixture</a>')
        if parsed.path != '/':
            return self.send('Not found', status=404)
        query = parse_qs(parsed.query)
        otp = query.get('view', ['otp'])[0] == 'otp'
        club = query.get('brand', ['clubunio'])[0] == 'sv-sissach'
        en = query.get('lang', ['de'])[0] == 'en'
        digits = 8 if query.get('digits', ['6'])[0] == '8' else 6
        invalid = query.get('invalid', ['0'])[0] == '1'
        title = ('One-time code' if en else 'Einmalcode') if otp else ('Sign in' if en else 'Anmelden')
        brand = '<link rel="stylesheet" href="/css/sv-sissach-glass.css">' if club else ''
        fields = '''<input type="hidden" name="selectedCredentialId" value="synthetic-credential">
            <label for="otp">%s</label><div class="form-group"><input id="otp" name="otp" type="text" class="pf-c-form-control" autocomplete="one-time-code" data-otp-length="%d" aria-invalid="%s" autofocus></div>''' % (title, digits, 'true' if invalid else 'false') if otp else '''
            <label for="username">Email</label><div class="form-group"><input id="username" name="username" class="pf-c-form-control" autocomplete="username"></div>
            <label for="password">Password</label><div class="form-group"><input type="password" id="password" name="password" class="pf-c-form-control" autocomplete="current-password"></div>'''
        controls = '''<aside class="fixture-controls" aria-label="Synthetic test controls">
            <button onclick="document.getElementById('otp').value='654321'">Silent extension fill</button>
            <button onclick="const e=document.getElementById('otp');e.value='12345678';e.dispatchEvent(new Event('input',{bubbles:true}))">Extension input event</button>
            <button onclick="const e=document.getElementById('otp');e.value='123456';e.dispatchEvent(new Event('input',{bubbles:true}));e.form.requestSubmit();e.form.requestSubmit()">Race auto and manual</button>
            <a href="/?view=otp&brand=sv-sissach&lang=de">SV Sissach OTP</a>
            <a href="/?view=login&brand=clubunio&lang=en">ClubUnio login</a>
            </aside>''' if otp else ''
        html = '''<!doctype html><html class="login-pf" lang="%s"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
            <title>Synthetic Keycloak UI fixture</title><link rel="stylesheet" href="/css/clubunio-glass-v6.css">%s
            <style>*{box-sizing:border-box}body{margin:0}#kc-header-wrapper{width:240px;background-size:contain;background-position:center;background-repeat:no-repeat;color:transparent}.form-group{margin:8px 0 24px}label{display:block;font-size:14px}input{font:inherit;width:100%%}#kc-page-title{margin:0}h1{text-align:center}.fixture-controls{position:relative;z-index:5;display:flex;gap:12px;flex-wrap:wrap;padding:16px;color:#fff}.fixture-controls button,.fixture-controls a{font:14px system-ui;padding:12px;background:#142b44;color:#fff;border:1px solid #8ac1e0;border-radius:8px}</style>
            <script src="/js/clubunio-glass-v6.js"></script></head><body><main class="login-pf-page"><header id="kc-header"><div id="kc-header-wrapper">%s</div></header><section class="card-pf"><header class="login-pf-header"><h1 id="kc-page-title">%s</h1></header><div id="kc-content"><form id="%s" action="/" method="post" onsubmit="login.disabled=true;return true;">%s<input type="submit" name="login" value="%s" class="pf-c-button pf-m-primary"></form></div></section></main>%s</body></html>''' % ('en' if en else 'de', brand, 'SV Sissach' if club else 'ClubUnio', title, 'kc-otp-login-form' if otp else 'kc-form-login', fields, 'Sign In' if en else 'Anmelden', controls)
        if query.get('slow') == ['1']:
            # Keep the top document inspectable while the native POST waits.
            # The hidden response frame redirects the fixture once it loads.
            html = html.replace('action="/"', 'action="/?delay=1" target="fixture-result"')
            html = html.replace('onsubmit="login.disabled=true;', 'onsubmit="document.getElementById(\'fixture-result\').dataset.armed=\'1\';login.disabled=true;')
            html = html.replace('</body>', '<iframe hidden name="fixture-result" id="fixture-result" onload="if(this.dataset.armed)location.href=\'/completed\'"></iframe></body>')
        self.send(html)

    def do_POST(self):
        if int(self.headers.get('Content-Length', '0')) > 4096:
            return self.send('Too large', status=413)
        body = parse_qs(self.rfile.read(int(self.headers['Content-Length'])).decode())
        code = body.get('otp', [''])[0]
        if 'password' in body:
            result = {'native_post': True, 'kind': 'login', 'synthetic_credentials_unchanged':
                      body.get('username') == ['design@example.invalid'] and body.get('password') == ['Synthetic-Only-123']}
        else:
            result = {'native_post': True, 'kind': 'otp', 'synthetic_code_unchanged': code in ('123456', '12345678', '654321'),
                      'length': len(code), 'credential_preserved': body.get('selectedCredentialId') == ['synthetic-credential']}
        RESULTS.append(result)
        if parse_qs(urlparse(self.path).query).get('delay') == ['1']:
            RELEASE.clear(); WAITING.set()
            RELEASE.wait(60)
            WAITING.clear()
        self.send('<h1>Native POST verified</h1><pre>' + json.dumps(result) + '</pre><a href="/">Back to OTP fixture</a>')


if __name__ == '__main__':
    print('Synthetic preview: http://127.0.0.1:8766 (loopback only)', flush=True)
    ThreadingHTTPServer(('127.0.0.1', 8766), Fixture).serve_forever()
