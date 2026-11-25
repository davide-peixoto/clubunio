# ClubUnio Sicherheitsprüfung vom 25. November 2026

## Aktualisierung nach Bereitstellung

Stage und PROD sind gehärtet und aktiv geprüft; die nachfolgenden Angaben zur lokalen Vorbereitung beziehungsweise unveränderten Bestandsaufnahme beschreiben den früheren Prüfstand. Massgeblich für den Abschluss sind [Release, Nachweise und offene Go-live-Punkte](CLUBUNIO-SECURITY-RELEASE-20261125.md). Lokal zuletzt 161 Tests / 25'917 Assertions; echtes MFA-/Logout-Ende-zu-Ende auf beiden Instanzen bestanden. Automatisches externes Backup bleibt offen.


Die Erstprüfung fand konkrete Kontrolllücken und veraltete PHP-Abhängigkeiten.
Die anschliessenden lokalen Korrekturen sind in
[Sicherheitskorrekturen](CLUBUNIO-SECURITY-HARDENING.md) dokumentiert.
Insbesondere meldet der aktualisierte lokale Composer-Stand keine Advisories mehr.
Der abschliessende isolierte Korrekturlauf besteht mit 156 Tests / 25'826 Assertions;
DE/EN-Prüfung ebenfalls erfolgreich. Neue Widerrufsmigration und IdP-/Scanner-
Einrichtung sind noch auf keiner Instanz aktiviert oder abgenommen.
Die Befunde und Versionsnummern unten beschreiben den Stand vor diesen Korrekturen.
Eine allgemeine Go-live-Sicherheitsfreigabe lässt sich daraus noch nicht erteilen.
Die anonymen Stage-Prüfungen zeigen wirksame grundlegende Zugriffssperren.
Angemeldete vereinsübergreifende Stage-Tests und eine unabhängige externe Prüfung
bleiben vor einer umfassenden Freigabe erforderlich.

## Umfang und geprüfter Stand

- Vier Agenten einschliesslich Hauptagent: Anmeldung und Sitzungen; API,
  Mandanten und Rollen; Dateien und externe Abrufe; Abhängigkeiten und Stage.
- Lokaler HEAD: `ac07415ac7121bcc232736172aa842ae2ac786e0`, zusätzlich der
  vorhandene uncommittierte Arbeitsstand, insbesondere Clubhaus und Assistant.
  Diese Änderungen wurden erhalten. Ergebnisse gelten nicht automatisch für PROD.
- Vom Benutzer ausdrücklich freigegebene aktive Tests ausschliesslich gegen
  `https://stage.clubunio.com`: 25 begrenzte anonyme HTTP-Prüfungen und zwei
  zusätzliche OIDC-Startprüfungen. Keine Weiterleitungen zum Identitätsdienst
  verfolgt, kein Lasttest, keine realen Konten, Nachrichten, Provideraktionen
  oder Zahlungen. Keine Änderungen an Serverkonfiguration oder Datenbankschema.
- Codeprüfung und lokale isolierte Tests; keine Verbindung zu einer laufenden
  Anwendungsdatenbank. Keine Installation oder Aktualisierung von Paketen.
- Noch keine angemeldeten Stage-Tests: Ein eingerichteter synthetischer Zugang
  oder dokumentierter Stage-Test-Runner wurde beim Benutzer angefragt.

## Priorisierte Befunde

### SEC 01 Bekannte Sicherheitswarnungen in PHP Abhängigkeiten

**Status:** Im lokalen Lockfile bestätigt. **Priorität:** Vor Go-live bereinigen;
die Schwere einzelner Advisories ist von ihrer Erreichbarkeit abhängig.

`composer audit --locked --no-interaction --format=json` meldet am Prüfungstag
12 Advisories in sieben Paketen. Elf betreffen sechs Pakete im Produktionsbaum;
ein weiteres betrifft die Entwicklungsabhängigkeit `symfony/yaml`.

| Paket | Lockversion | Advisories | Abhängigkeitsbaum |
| --- | --- | --- | --- |
| laravel/framework | 12.69.3 | 1 | Produktion |
| phpseclib/phpseclib | 3.0.57 | 1 | Produktion |
| symfony/html-sanitizer | 7.4.20 | 3 | Produktion |
| symfony/http-foundation | 7.4.20 | 4 | Produktion |
| symfony/http-kernel | 7.4.20 | 1 | Produktion |
| symfony/routing | 7.4.20 | 1 | Produktion |
| symfony/yaml | 7.4.20 | 1 | Entwicklung |

Die phpseclib-Warnung zu unbeschränkten PBKDF-Iterationen ist beim Hersteller
als hoch eingestuft; Voraussetzung ist die Verarbeitung entsprechend manipulierter
verschlüsselter Schlüssel/PFX/CMS-Daten. Ein solcher ClubUnio-Angriffsweg wurde
nicht nachgewiesen. Ebenso wurden keine direkten Aufrufe der Laravel-Concurrency-
Funktion oder expliziten Symfony-Hostallowlist-Konfigurationen im Anwendungscode
gefunden; indirekte Nutzung durch Bibliotheken ist damit nicht ausgeschlossen.
Bei `http-foundation` betrifft ein Advisory speziell `private_ranges`; der lokale
Bootstrap vertraut stattdessen einer expliziten Proxyadresse. Alle Treffer müssen
daher einzeln bewertet werden, sie sind keine zwölf bestätigten Exploits.

**Massnahme:** Kompatible Sicherheitsupdates in einem getrennten Änderungspaket
auflösen, Lockfile und gebaute Serverstände abgleichen und Auth-/API-/Upload-
Regressionen wiederholen. Keine ungeprüfte pauschale Major-Aktualisierung.
Abnahme: Audit ohne offene relevante Warnungen oder dokumentierte, nachvollziehbare
Ausnahme je Advisory; tatsächlich ausgelieferte Versionen nachgewiesen.

Herstellerbelege:
[Laravel Concurrency](https://github.com/laravel/framework/security/advisories/GHSA-63hm-g96r-2m2q),
[phpseclib PBKDF](https://github.com/phpseclib/phpseclib/security/advisories/GHSA-w3p9-g4p2-h3rr),
[Symfony HTML Sanitizer](https://symfony.com/cve-2026-118517).
Die vollständigen Advisory-IDs und Quellen stehen im privaten Audit-JSON.

`npm audit --json --ignore-scripts` meldet dagegen null bekannte Warnungen.
Das ersetzt keine Prüfung eigener JavaScript-Logik oder bereits ausgelieferter Assets.

### SEC 02 Keycloak Sperre widerruft App Sitzung lokal nicht automatisch

**Schwere:** Mittel; bei gestohlenen privilegierten Tokens höhere Auswirkung.
**Status:** Lokaler Kontrollfluss bestätigt, kein Ende-zu-Ende-Keycloak-Nachweis.

`app/ClubUnio/Mobile/MobileSessions.php:65` erstellt standardmässig bis zu
30 Tage gültige eigene App-Tokens. `authenticate()` ab Zeile 82 prüft lokale
Sitzung, Kontoflags und Sicherheitsstempel. Der föderierte Stempel in
`app/ClubUnio/Services/KeycloakMobileAssurance.php:33` enthält lokale
Subject-Verknüpfung und Konfiguration, aber keinen aktuellen Keycloak-Kontostatus
oder widerrufenen Keycloak-Sitzungsstatus. Im geprüften Anwendungscode wurde keine
Backchannel-Logout-/Introspection-Verarbeitung gefunden.

Ein bereits gestohlenes App-Token kann deshalb bei unverändertem lokalen Konto
weiter akzeptiert werden, obwohl das zugehörige Konto ausschliesslich im IdP
gesperrt wurde. Ein zusätzlicher externer Widerrufsprozess könnte diese Lücke
schliessen; sein Vorhandensein wurde nicht nachgewiesen.

Die lokale Probe verwendet Originalklassen mit Eloquent und SQLite ausschliesslich
im Arbeitsspeicher: gültiges Token akzeptiert; nach rein simuliertem externem
Ereignis bei unverändertem lokalen Zustand weiterhin akzeptiert; nach lokalem
`revoked_at` korrekt HTTP 401. Das simulierte Ereignis wurde keinem echten IdP
zugestellt und ist kein Live-Beweis einer fehlgeschlagenen Keycloak-Sperre.

**Massnahme:** Sicherheitskontosperren müssen sofort auch alle lokalen App-Sitzungen
widerrufen. Verifizierte IdP-Ereignisse/Backchannel mit Subject-/Sitzungszuordnung
integrieren und eine Revalidierungsstrategie festlegen.
Abnahme: Testkonto auf Stage anmelden, ausschliesslich am IdP sperren und alte
App-Sitzung innerhalb der festgelegten Widerrufsfrist mit 401 zurückweisen;
lokaler Sofortwiderruf ebenfalls prüfen.

### SEC 03 Web MFA hängt von Mobile und Einladungsflags ab

**Schwere:** Mittel, abhängig von der Keycloak-Policy. **Status:** Lokale
Konfigurationsschwäche reproduziert; kein bestätigter Stage-MFA-Bypass.

`KeycloakMobileAssurance.php:15` gibt das MFA-Level nur bei gleichzeitig aktivem
OIDC, Mobile und Einladungen zurück. Der Webstart in
`app/ClubUnio/Http/PlatformOidcController.php:86` fordert ohne dieses Level kein
frisches ACR an; die Callbackbedingung ab Zeile 125 deaktiviert dann die
MFA-Prüfung, statt die Fehlkonfiguration abzulehnen. Der Backofficezweig ab
Zeile 192 kann anschliessend die OIDC-Sitzung erstellen.

Die Originalklasse wurde ohne DB/Netzwerk mit synthetischer Konfiguration geladen:
alle Flags aktiv ergibt `mfa`; allein Mobile oder Einladungen deaktiviert ergibt
`NULL`. Der Callback-Ausdruck wurde in der Probe aus dem Quellcode nachgebildet.
Eine zwingende Keycloak-MFA-Policy kann einen realen Angriff verhindern.

**Stage-Beobachtung:** Sowohl Vereins- als auch Plattformstart senden aktuell
`max_age=0`, PKCE S256 und ein wesentliches ACR-Claim `clubunio-mobile-mfa`.
Insbesondere der Plattformstart unterscheidet sich damit vom geprüften lokalen
Controller. Lokaler Arbeitsstand und Stage dürfen nicht als identisch angenommen
werden; ein fehlendes Plattform-ACR ist ausdrücklich kein Stage-Befund.

**Massnahme:** Eigene Web-/Plattform-MFA-Vorgabe unabhängig von Mobile und
Einladungen; bei fehlender Pflichtkonfiguration geschlossen abbrechen. ACR und
`auth_time` im Callback prüfen. Abnahme: Schwache Claims werden auch bei
deaktiviertem Mobile/Einladungen verweigert; starker Weblogin funktioniert weiter.
Vor einem Release tatsächlichen Stage-Code mit dem vorgesehenen Quellstand abgleichen.

### SEC 04 Malwareprüfung ist zwischen Uploadwegen uneinheitlich

**Schwere:** Mittel. **Status:** Fehlende Inhaltskontrolle im Code bestätigt;
keine Schadsoftwareausführung, Server-RCE oder Browser-XSS nachgewiesen.

`app/ClubUnio/Services/ClubhouseMenus.php:81` prüft Grösse, MIME und PDF-Prefix,
speichert aber Originalbytes ohne MalwareScanner oder Inhaltsnormalisierung.
Die Freigabe ab Zeile 121 prüft Lesbarkeit/Hash. Öffentliche Ausgabe:
`app/ClubUnio/Http/ClubhouseController.php:47` und ab Zeile 93, inline.
Ein Angreifer benötigt Uploadrechte und die zusätzliche Publikationsfreigabe;
diese Rechteaufteilung reduziert das Risiko.

Auch `app/ClubUnio/Services/DocumentUploadService.php:26` und ab Zeile 173
besitzt im geprüften Web-/API-Pfad keine entsprechende Scannerprüfung. Je nach
Aufrufweg sind auch öffentliche Anmeldedokumente betroffen; deren nachgelagerte
Verfügbarkeit bleibt durch die bestehenden privaten Dokumentrechte begrenzt.
Mobile Mitgliederdokumente und Chat-Anhänge besitzen bereits Scanner-/Quarantänewege.

Ein im Arbeitsspeicher erstelltes PDF mit harmloser JavaScript-/OpenAction-Markierung
passiert die verwendeten MIME-/Prefixprüfungen. Das zeigt, dass diese Prüfungen
aktive Inhalte nicht ausschliessen; es belegt keine schädliche Ausführung.

**Massnahme:** Gemeinsame Quarantäne und Malwareprüfung vor Freigabe, bei
Scannerfehler keine Veröffentlichung. Soweit fachlich möglich PDFs statisch
normalisieren; passende Sandbox-/Downloadheader ergänzen. Ein Virenscanner allein
garantiert keine Entfernung aller aktiven PDF-Funktionen.
Abnahme: Abgelehnte und nicht prüfbare Dateien bleiben unveröffentlicht; reguläre
PDFs funktionieren in allen betroffenen Uploadwegen und beiden UI-Sprachen.

## Erfolgreiche Prüfungen

### Lokale Regressionen

| Vollständiger Lauf | Tests | Assertions |
| --- | --- | --- |
| ClubhouseTest | 14 | 75 |
| ClubhouseWorkspaceTest | 5 | 22 |
| BackofficeAssistantPageTest und AssistantLocalQueriesTest | 48 | 726 |
| PlatformOidcTest | 5 | 33 |
| VereinOidcMemberCallbackTest | 2 | 3 |
| Summe unterschiedlicher Tests | 74 | 859 |

Alle Läufe bestanden. Clubhaus/Assistant verwenden SQLite im Arbeitsspeicher und
sperren unbeabsichtigte Providerrequests; OIDC arbeitet mit synthetischen Claims
und gemocktem Transport. Ein zunächst abgebrochener Clubhauslauf ist nicht gezählt.
Zusätzliche Standalone-Proben zu SEC 02/03 sind nicht in der PHPUnit-Summe enthalten.
Mockbasierte Tests ersetzen keine vollständige Ende-zu-Ende-Autorisierung.

Die Codeprüfung fand im untersuchten Umfang keinen bestätigten vereinsübergreifenden
API-/Assistant-/Clubhauszugriff, keine bestätigte SSRF und kein Path Traversal.
Geprüfte Schutzmechanismen umfassen tenantgebundene Abfragen, frische Rollen-/Scope-
Prüfungen, getrennte Schreib-/Publikationsrechte, gesperrten Livewire-Kontext,
Autorisierung vor Dateizugriff und eingeschränkte externe Downloadziele.

### Anonyme Stage Prüfungen

- Fehlendes und ungültiges Bearertoken: API-/Mobile-Kontext HTTP 401;
  Mobileprofil und fremde synthetische Vereins-/Mitglieds-ID ebenfalls gesperrt.
- API-Dokumentation und Integrationsverwaltung leiten anonym zur Anmeldung.
- `.env`, `.git/config` und Laravel-Logpfad HTTP 403; `composer.lock`, Ignition-
  Prüfpfad und zufällige unbekannte Route HTTP 404.
- Ungültige Vereins-/Plattform-OIDC-States HTTP 403.
- Plattform-Login-POST ohne CSRF HTTP 419; ungültiges App-Pairing HTTP 404.
- Manipulierter Host HTTP 404; Forwarded-Host/-Proto-Test reflektiert den
  fremden Host nicht in Antwort oder Weiterleitung.
- Stage-Sitzungscookie besitzt Secure, HttpOnly und SameSite=Lax.
  Das JavaScript-lesbare XSRF-Cookie ist erwartungsgemäss nicht HttpOnly.
- HSTS auf den geprüften normalen HTTPS-Antworten; keine Debug-Trace-Marker in
  den begrenzten Antwortsamples. Keine Netzwerkfehler in den 25 Grundprüfungen.
- CORS-Preflight liefert `Access-Control-Allow-Origin: *`, aber kein
  `Access-Control-Allow-Credentials`. Für Bearer-APIs allein kein bestätigter
  Zugangsbypass; erlaubte Browserintegrationen sollten explizit dokumentiert sein.

Härtungshinweis mit niedriger Priorität: CSP und Referrer-Policy fehlen auf mehreren
geprüften Antworten, und die 419-Fehlerseite besitzt nicht dieselben Frame-/nosniff-
Header wie normale Antworten. Diese Beobachtung belegt keinen XSS-Angriff; eine
einheitliche, mit Livewire/React kompatible Headerstrategie sollte geprüft werden.

## Nachweise und Wiederholung

Private lokale Nachweise unter `storage/app/private/`:

- `security-review-20261125-stage.json`: Status, ausgewählte Header, Cookieattribute
  und SHA256 der begrenzten Antwortsamples; keine Cookie-/Tokenwerte oder Bodies.
- `security-review-20261125-stage-oidc.json`: bereinigte OIDC-Anforderungsparameter;
  kein State, Nonce, Code oder Sitzungswert gespeichert.
- `security-review-20261125-composer.json`: vollständige Auditantwort.
- `security-review-20261125-mfa-probe.php` und gleichnamiges JSON.
- `security-review-20261125-mobile-revocation-probe.php` und gleichnamiges JSON.

`python tools/security-stage-probe.py` wiederholt die 25 anonymen Grundprüfungen
gegen den fest eingebauten freigegebenen Stage-Host. Nur innerhalb eines weiterhin
autorisierten Testfensters erneut ausführen. Keine Responsebodies werden gespeichert.
Ein HTTP-Status allein beweist keine vollständige fachliche Zugriffssicherheit.

## Verbleibende Go live Gates

1. SEC 01 bis SEC 04 beheben oder begründet bewerten, danach gezielt nachtesten.
2. Angemeldete Stage-Matrix mit zwei synthetischen Vereinen und mindestens
   Mitglied, Trainer, Vereinsadmin und Plattformadmin: fremde Objekt-IDs,
   Dokumente, Exporte, Rollenentzug, Tokenwiderruf und Hintergrundjobs.
3. Tatsächliche Keycloak-Policy samt Recovery und Kontosperre Ende zu Ende
   nachweisen; aktuellen mobilen Build auf iOS/Android prüfen.
4. Ausgelieferte Versionen und wirksame PROD-Einstellungen separat lesen;
   Stage-Ergebnisse sind keine PROD-Konfigurationsfreigabe.
5. Vollständigen Restore von Anwendung, Dokumenten und Identitätsdienst mit
   Zeitmessung sowie Alarmierung/Incident-Ablauf prüfen.
6. Unabhängige authentifizierte Sicherheitsprüfung mit Quellcodeeinblick und
   Nachtest, sinnvoll nach OWASP ASVS 5.0 mit Level 2 als Ziel.

Die vollständigen PostgreSQL-Grenztests `TenantBoundaryTest`,
`ApiTenantBoundaryTest` und `ApiSecurityRegressionTest` wurden nicht gegen die
gemeinsame Datenbank gestartet. Kein Geheimnisscan der vollständigen Git-Historie,
kein Infrastruktur-/Portscan, keine vollständige ASVS-Abdeckung, kein PROD-Test
und keine Freigabe für SaaS, Store oder Betriebsumstellungen sind enthalten.
