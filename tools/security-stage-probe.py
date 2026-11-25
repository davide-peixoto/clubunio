"""Bounded anonymous security probes for the explicitly authorized ClubUnio Stage.

No redirects, real accounts, provider operations, or response bodies are retained.
Uses Python standard library, certificate verification, and sequential requests.
"""
import argparse
import datetime
import hashlib
import http.cookiejar
import json
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--environment', choices=['stage', 'prod'], default='stage')
    args = parser.parse_args()
    origin = 'https://stage.clubunio.com' if args.environment == 'stage' else 'https://app.clubunio.com'
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPCookieProcessor(jar))
    cases = [("GET", path, {}, None) for path in [
        "/login", "/platform/login", "/api-docs", "/api-integrations",
        "/api/v1/context", "/api/v1/members/9223372036854775000",
        "/api/mobile/v1/context", "/api/mobile/v1/profile",
        "/api/mobile/v1/clubs/9223372036854775000/members/9223372036854775000",
        "/api/mobile/v1/public/bootstrap", "/.env", "/.git/config",
        "/composer.lock", "/storage/logs/laravel.log", "/_ignition/health-check",
        "/__clubunio_security_nonexistent_20261125",
        "/platform/oidc/callback?state=synthetic-invalid&code=synthetic-invalid",
        "/vereinslogin/oidc/callback?state=synthetic-invalid&code=synthetic-invalid",
    ]]
    cases.extend([
        ("GET", "/api/v1/context", {"Authorization": "Bearer synthetic-invalid-security-probe"}, None),
        ("GET", "/api/mobile/v1/context", {"Authorization": "Bearer synthetic-invalid-security-probe"}, None),
        ("GET", "/login", {"X-Forwarded-Host": "security-probe.invalid", "X-Forwarded-Proto": "http"}, None),
        ("GET", "/login", {"Host": "security-probe.invalid"}, None),
        ("OPTIONS", "/api/mobile/v1/context", {"Origin": "https://security-probe.invalid", "Access-Control-Request-Method": "GET", "Access-Control-Request-Headers": "authorization"}, None),
        ("POST", "/platform/login", {"Content-Type": "application/json"}, b"{}"),
        ("POST", "/api/mobile/v1/session/security/redeem", {"Content-Type": "application/json"}, b'{"pair":"invalid","confirmation":"00000000"}'),
    ])
    results = []
    for method, path, extra, data in cases:
        req = urllib.request.Request(origin + path, data=data, method=method, headers={
            "User-Agent": "ClubUnio-authorized-security-review/2026-11-25",
            "Accept": "application/json" if path.startswith("/api/") else "text/html", **extra})
        item = {"method": method, "path": path, "variant_headers": list(extra)}
        try:
            try:
                response = opener.open(req, timeout=15)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                body = response.read(262144)
                headers = response.headers
                location = headers.get("Location", "")
                parsed = urllib.parse.urlsplit(location)
                item.update(status=response.code, body_bytes_sampled=len(body), body_sha256=hashlib.sha256(body).hexdigest(),
                            headers={name: headers.get(name) for name in ["Content-Type", "Cache-Control", "Strict-Transport-Security", "Content-Security-Policy", "X-Frame-Options", "X-Content-Type-Options", "Referrer-Policy", "Access-Control-Allow-Origin", "Access-Control-Allow-Credentials", "Server", "X-Powered-By"]},
                            redirect_origin=(parsed.scheme + "://" + parsed.netloc) if parsed.netloc else None,
                            redirect_path=parsed.path if location else None,
                            reflected_probe_host=b"security-probe.invalid" in body or "security-probe.invalid" in location,
                            debug_trace_markers=any(marker in body for marker in [b'"exception":', b'"trace":', b'Whoops!']),
                            possible_env_content=b"APP_KEY=" in body,
                            possible_git_config=b"[core]" in body)
                item["cookies"] = [{"name": cookie.split("=", 1)[0], "secure": "; secure" in cookie.lower(),
                    "http_only": "; httponly" in cookie.lower(), "same_site": next((v.strip() for v in cookie.split(";") if v.strip().lower().startswith("samesite=")), None)}
                    for cookie in headers.get_all("Set-Cookie", [])]
        except Exception as error:
            item["error_type"] = type(error).__name__
        results.append(item)
        print(json.dumps(item, ensure_ascii=False), flush=True)
        time.sleep(0.25)
    target = Path('storage/app/private/security-review-20261125-' + args.environment + '.json')
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(json.dumps({"origin": origin, "completed_utc": datetime.datetime.now(datetime.timezone.utc).isoformat(),
                                  "scope": "anonymous bounded probes; no authenticated coverage", "results": results}, indent=2), encoding="utf-8")
    print("Evidence: " + str(target))


if __name__ == "__main__":
    main()
