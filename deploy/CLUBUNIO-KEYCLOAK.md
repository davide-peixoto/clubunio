# ClubUnio Keycloak

Stand 16.11.2026. Keycloak läuft als eigenständiger Identitätsdienst. Die OIDC-Anbindung von ClubUnio steht noch aus.

## VPS und Dienst

| Komponente | Bereitstellung |
| --- | --- |
| VPS | `vps.clubunio.com`, Ubuntu 24.04, OpenJDK 21 |
| Keycloak | 26.8.2 aus dem offiziellen Release-Archiv, nativ installiert |
| Installation | `/opt/keycloak-26.8.2`, Symlink `/opt/keycloak` |
| Dienst | `keycloak.service`, Systembenutzer `keycloak`, Autostart aktiviert |
| Konfiguration | `/opt/keycloak/conf/keycloak.conf` und geschützte `/etc/keycloak/keycloak.env` |
| PostgreSQL | Lokale Instanz auf `127.0.0.1:5432`, separate Datenbank und Rolle `keycloak` |
| HTTP intern | `127.0.0.1:8180` |
| HTTPS extern | [auth.clubunio.com](https://auth.clubunio.com), RunCloud-NGINX, Let's Encrypt |

Die RunCloud-App `auth` verwendet `/home/runcloud/webapps/auth` als Webroot. Der Dienst wird über die RunCloud-NGINX-Snippets `auth.location.root.keycloak.conf` und `auth.location.main.keycloak.conf` unter `/etc/nginx-rc/extra.d` erreicht. Beide verwenden `/etc/nginx-rc/nginx-includes/auth-keycloak-proxy.conf`. Die eigene Location für `/realms/` lässt OIDC-Discovery unter `.well-known` passieren. NGINX setzt die Forwarded-Header für den lokalen Upstream.

## Realms, Anmeldung und SMTP

| Realm | Zweck | Aktives Konto |
| --- | --- | --- |
| `master` | Serverweite Keycloak-Verwaltung | `info@clubunio.com` mit globaler Realm-Rolle `admin` |
| `clubunio` | ClubUnio-Identitäten | `info@clubunio.com` mit Client-Rolle `realm-management/realm-admin` |

Die beiden Konten sind eigenständige Identitäten mit separaten Passwörtern. Die Anmeldung im Realm `master` wurde durch den Betreiber bestätigt. Anschliessend wurde der temporäre `bootstrap-admin` deaktiviert. Eine PostgreSQL-Abfrage bestätigte, dass nur die beiden Einträge für `info@clubunio.com` aktiv sind.

Im Realm `clubunio` dient die E-Mail-Adresse als Anmeldename. E-Mail-Adressen sind eindeutig und die Selbstregistrierung ist ausgeschaltet. Beide Realms verwenden die bestehende ClubUnio-SMTP-Konfiguration für Identitätsmails mit `noreply@clubunio.com` als Absender. Die Einladungen wurden als Keycloak-Aktionsmails mit 24 Stunden Gültigkeit für E-Mail-Bestätigung und Passwortvergabe verschickt.

Die temporäre Passwortdatei `/etc/keycloak/bootstrap-admin.password` wurde beim Bootstrap angelegt. Die Deaktivierung des Kontos ist bestätigt. Die Entfernung der Datei ist noch nicht bestätigt.

## Login-Theme

Der Realm `clubunio` verwendet das [ClubUnio-Theme](keycloak/themes/clubunio/login). Es erweitert das eingebaute Keycloak-Theme mit der ClubUnio-Wortbildmarke und dem Farbschema Blau v1. Die Anmeldeformulare übernehmen die Keycloak-Sprachkataloge. Deutsch ist Standard, Englisch ist auswählbar. Beide Sprachversionen sowie Stylesheet und Logo wurden über HTTPS geprüft.

Das Theme liegt auf dem VPS unter `/opt/keycloak/themes/clubunio`. Bei einem Versionswechsel das Theme in die neue Installation übernehmen und nach dem Neustart beide Sprachen prüfen.

Die Login-Karte ist auf grossen Bildschirmen zentriert und auf schmalen Bildschirmen flexibel. Eingabefelder und Anmeldebutton haben eine Mindesthöhe von 2,75 rem. Das Stylesheet verwendet die ClubUnio-Farben auch für den PatternFly-Anmeldebutton.

## Betriebsprüfung

### E-Mail-Theme für Einladungen und Passwort-Reset

Die lokale Fassung des [ClubUnio-E-Mail-Themes](keycloak/themes/clubunio/email)
verwendet das gemeinsame HTML-Layout für die eingebauten Keycloak-Vorlagen
für Aktionsmails und Passwort-Reset. Betreff, Nachrichtentext, Aktionslink,
Gültigkeitsangabe und Klartextfassung bleiben bei den Keycloak-Vorlagen.
Die HTML-Variante verwendet Deutsch oder Englisch gemäss Keycloak-Sprachwahl.

**Realm settings → Themes → Email theme → clubunio** wählt das Theme für den
gesamten Realm. Der Login-Theme-Override am OIDC-Client gilt nur für das
Login-Theme. Keycloak stellt im E-Mail-Template das Empfängerprofil bereit.
Der Provider `clubunio` wählt das Design pro Nachricht über den Keycloak-Client. Für `clubunio-sv-sissach-dev` verwendet er die in `email/theme.properties` hinterlegten Vereinsdaten. Ohne Zuordnung gilt das ClubUnio-Design. Der Provider wurde gegen Keycloak 26.8.2 gebaut und mit `--spi-email-template--provider=clubunio` aktiviert. Keycloak bestätigte den Versand der DEV-Bestätigungsmail an `info@clubunio.com` am 17.11.2026 mit HTTP 204. Der Betreiber bestätigte den Empfang und das SV-Sissach-Design.

#### Weiteren Verein im Custom Design Mode einrichten

1. Den Custom Design Mode des Vereins in ClubUnio und den zugehörigen DEV-OIDC-Client im gemeinsamen Realm `clubunio` prüfen. Den Mandantenslug, die exakte Client-ID, den Vereinsnamen, die Markenfarbe und das freigegebene Logo übernehmen.
2. In [email/theme.properties](keycloak/themes/clubunio/email/theme.properties) die drei Werte `clubunioBrand.<slug>.name`, `clubunioBrand.<slug>.color` und optional `clubunioBrand.<slug>.logo` ergänzen. Die Farbe ist ein sechsstelliger Hexwert mit `#`. Das Logo als PNG unter `email/resources/img/<slug>.png` ablegen und im Eintrag nur den Dateinamen angeben. Für Name und Farbe dient der vorhandene Eintrag `sv-sissach` als Vorlage.
3. In der [Provider-Factory](keycloak/provider/src/org/clubunio/keycloak/ClubUnioEmailProviderFactory.java) die freigegebene Client-ID dem Slug zuordnen. Die Zuordnung ist derzeit im Java-Code hinterlegt. Das Realm-Theme bleibt `clubunio`. Beim Versand über die Admin-API die exakte Client-ID als `client_id` mitgeben, etwa bei `execute-actions-email?client_id=<dev-client>&lifespan=3600`. Im Browserflow verwendet der Provider den Client der Authentifizierungssitzung. Aufrufe ohne zugeordneten Client verwenden den ClubUnio-Kopf.
4. Das Provider-JAR gegen die installierte Keycloak-Version kompilieren und den Service-Descriptor aus `deploy/keycloak/provider/resources/META-INF/services` einpacken. Vor der Installation das bisherige JAR und das E-Mail-Theme sichern. Das neue JAR nach `/opt/keycloak/providers/clubunio-email.jar` und das Theme nach `/opt/keycloak/themes/clubunio/email` kopieren. Auf dem VPS `/opt/keycloak/bin/kc.sh build --db=postgres --spi-email-template--provider=clubunio` ausführen, den Dienst neu starten und OIDC-Discovery über HTTPS prüfen. Für einen Rollback das gesicherte JAR und Theme wiederherstellen, mit derselben Provider-Einstellung neu bauen und Keycloak neu starten.
5. Über den DEV-Client eine Bestätigungsmail an das freigegebene Testpostfach senden. Im Posteingang Vereinsname, Farbe, PNG, den Hinweis «Powered by ClubUnio» und die Klartextfassung prüfen. Die deutschen und englischen Varianten kontrollieren. Mit einem Aufruf ohne Vereinsclient zusätzlich das ClubUnio-Design prüfen. HTTP 204 bestätigt den Versandaufruf. Zustellung und Darstellung anhand der empfangenen Mail abhaken. Den Aktionslink zur Sichtprüfung geschlossen lassen.

Bei Keycloak-Updates den Provider neu kompilieren und die Mailvarianten erneut prüfen. Der Provider verwendet das interne EmailTemplate-SPI.

Das [ClubUnio-Admin-Theme](keycloak/themes/clubunio/admin) erweitert
`keycloak.v2` mit Wortbildmarke, Favicon und ClubUnio-Blau. Es liegt auf
dem VPS unter `/opt/keycloak/themes/clubunio/admin`. Im Realm `master`
wird es unter **Realm settings → Themes → Admin theme** ausgewählt.
Die Anmeldung der Admin-Konsole wird dort über **Login theme** separat
gesteuert. Für beide Felder ist das passende Theme `clubunio`.
Die Aktivierung und die Browserprüfung stehen aus.

Auf dem VPS:

```bash
systemctl is-active keycloak
systemctl is-enabled keycloak
journalctl -u keycloak -n 80 --no-pager
/usr/local/sbin/nginx-rc -t
```

Öffentlich über HTTPS:

- [OIDC-Discovery für clubunio](https://auth.clubunio.com/realms/clubunio/.well-known/openid-configuration)
- [ClubUnio-Anmeldung](https://auth.clubunio.com/realms/clubunio/account/)
- [Master-Admin-Konsole](https://auth.clubunio.com/admin/master/console/)

## Spätere ClubUnio-Anbindung

### DEV-Pilot für Vereinsdomains und Profil

DEV-Release `20261117-sv-sissach-domain-oidc` aktiv auf dem Pi.
Pilotdomain `dev-clubunio.svsissach.ch` ist dem Mandanten `sv-sissach`
zugeordnet. Der vertrauliche Client `clubunio-sv-sissach-dev` verwendet
PKCE S256, das Theme `sv-sissach` und das bestehende MFA-Niveau 2.
Der öffentliche Einstieg erreicht das Keycloak-Anmeldeformular mit
dem Vereins-Theme. Der Vereinsclient übernimmt den Browserflow
`clubunio-verein-dev-mfa`. Der Betreiber zeigte den angemeldeten
SV-Sissach-Vereinsbereich und den Wechsel zur Keycloak-Kontosicherheit.
Das Kontoprofil-Theme `clubunio` ist im Realm aktiv. Logo und Kopfzeile
wurden im angemeldeten Browser geprüft.
Das vorherige DEV-Release ist `20261117-clubunio-native-login`.

Die Korrektur der Abmeldung liegt im Projekt. Sie verwendet bei einer
freigegebenen Vereinsdomain deren Keycloak-Client und HTTPS-Origin. Für
die DEV-Abnahme zuerst beim Client `clubunio-sv-sissach-dev` die exakte
**Valid post logout redirect URI** `https://dev-clubunio.svsissach.ch/`
speichern. Der direkte Logout-Aufruf wurde ohne diesen Eintrag von
Keycloak abgelehnt. Danach den Fix auf DEV ausrollen und aus dem
Vereinsbereich bis zurück zur Vereinsanmeldung testen.

- Das Login-Theme `sv-sissach` erweitert `clubunio` um das Vereinslogo.
  Der DEV-OIDC-Client des Vereins erhält dieses Theme als Client-Override.
  Der Realm bleibt `clubunio` und der Issuer bleibt auf `auth.clubunio.com`.
- Die Vereinsdomain benötigt bestätigte Inhaberschaft, DNS, TLS und eine
  aktive Mandantenzuordnung. Die Anwendung kann ausgewählte HTTPS-Origins
  über `CLUBUNIO_VEREIN_OIDC_DOMAINS` einem Verein und dessen OIDC-Client
  zuordnen. Der private JSON-Eintrag pro Origin enthält `tenant` (Slug),
  `client` (Client-ID) und `secret`. Der Callback bindet Client und Origin
  an den Login-Versuch und prüft den Vereinszugriff vor dem Sitzungsaufbau.
  Für den Keycloak-Client die exakte Redirect-URI
  `https://<vereinsdomain>/vereinslogin/oidc/callback` registrieren.
- Das Kontoprofil-Theme `clubunio` erweitert `keycloak.v3` mit Wortbildmarke,
  Favicon und ClubUnio-Blau. Die Einstellung **Account Theme** gilt für den
  ganzen Realm. Für eine isolierte DEV-Abnahme ist eine separate
  Keycloak-Testinstanz oder ein Test-Realm erforderlich.
- Die Abnahme umfasst Deutsch und Englisch, die Rückkehr zur exakten
  Vereinsdomain, den Ausschluss von Benutzern ohne Vereinsberechtigung und
  die Profilansicht nach der Anmeldung.

### Stage vorbereiten und abnehmen

1. `stage.clubunio.com` ist die zentrale Stage-Adresse. Die separate
   Vereinsdomain für Stage festlegen und mit DNS, TLS und verifizierter
   Mandantenzuordnung bereitstellen. Die DEV-Domain bleibt der DEV-Pilot.
2. Im Realm `clubunio` einen eigenen vertraulichen OIDC-Client für den
   Stage-Verein anlegen. Standard Flow, PKCE S256 und den auf Stage
   vorgesehenen MFA-Browserflow mit passendem ACR-Mapping konfigurieren.
   Das Login-Theme `sv-sissach` am Client auswählen. Die vollständige URI
   `https://<stage-vereinsdomain>/vereinslogin/oidc/callback` als Redirect
   und `https://<stage-vereinsdomain>/` als Post-Logout-Redirect eintragen.
3. In der privaten Stage-Konfiguration unter
   `CLUBUNIO_VEREIN_OIDC_DOMAINS` die exakte HTTPS-Origin mit
   `tenant: sv-sissach`, der Stage-Client-ID und dem eigenen Client-Secret
   hinterlegen. Vorhandene Stage-Einträge erhalten. Keycloak-Konto und
   Vereinsberechtigung in Stage prüfen und den Konfigurationscache neu
   erstellen.
4. Für Vereinsmails die Stage-Client-ID in der Provider-Factory ebenfalls
   `sv-sissach` zuordnen. Die bestätigte DEV-Zuordnung beibehalten.
   Provider und Theme gemäss Mail-Anleitung ausliefern. Für die
   Mailabnahme den Stage-Client beim Versand explizit angeben.
5. Nach erfolgreicher DEV-Abmeldung auf Stage mit einem berechtigten
   Testkonto Anmeldung, MFA und Rücksprung auf die Vereinsdomain prüfen.
   Das ClubUnio-Kontoprofil aus dem Vereinsbereich öffnen. Über die
   Abmeldung muss die Vereinsdomain wieder erreicht werden und der
   anschliessende Login muss das Vereinslogo zeigen. DE und EN sowie die
   Ablehnung eines Kontos ohne Vereinsberechtigung prüfen. Die
   Stage-Testmail im Posteingang auf Logo, Farbe und Links kontrollieren.
6. Bei einem Rollback den vorherigen Stage-Release und dessen private
   Konfiguration wiederherstellen und den Konfigurationscache neu
   erstellen. Falls der Mail-Provider geändert wurde, dessen bisheriges
   JAR und Theme gemäss Mail-Anleitung wiederherstellen.

## Nativer App-Anmeldedialog (DEV)

- Flutter nutzt auf iOS die System-Anmeldesitzung und auf Android den
  Browser-Auth-Tab. Der vertrauliche Keycloak-Client und seine bestehende
  HTTPS-Callback-Adresse bleiben gleich. Passkey und MFA laufen auf der
  Keycloak-Domain; Flutter verarbeitet keine Keycloak-Passwörter.
- Der Backend-Callback gibt nur einen einmaligen Rückgabenachweis an die App.
  Für eine Sitzung sind dieser Nachweis, der ursprüngliche App-Nachweis und
  die bestehende Keycloak-MFA-Prüfung erforderlich.
- «Konto hinzufügen» startet eine erneute Anmeldung mit `prompt=login`
  statt der stillen Übernahme der Browser-Sitzung. Beim Abbruch bleibt das
  bisherige Konto aktiv.
- Backend vor dem neuen App-Build ausrollen. Keine DB-Migration; vorhandene
  Builds können den bisherigen Code-Dialog weiterverwenden. Vor Stage auf
  iOS und Android Passkey, automatische Rückkehr, Abbruch, zweites Konto
  und anschliessenden Kontowechsel am Gerät abnehmen.

### Native Anmeldung der SV-Sissach-Test-App

Der gemeinsame SV-Sissach-Testbuild verwendet auf DEV die Custom Domain
dev-clubunio.svsissach.ch und Mandant 4, auf Stage
stage-clubunio.svsissach.ch und Mandant 3 (sv-sissach-refresh).
Beide Origins sind im jeweiligen mobilen Bootstrap freigeschaltet. Das
native Pairing ist an die verifizierte Origin gebunden, und der Browser
kehrt über sv-sissach-test-auth zur Test-App zurück. Die zentrale
ClubUnio-App verwendet ihren bisherigen Callback.

DEV-Release 20261117-sv-sissach-mobile-oidc, Rückweg
20261117-sv-sissach-domain-oidc. Stage-Code 242c75d.
HTTPS-Smokes bestätigen den richtigen Mandanten, den Pairing-Start und die
exakte Keycloak-Callback-URL auf beiden Domains. Die zentrale Stage-Origin
weist die Einlösung eines Custom-Domain-Pairings ab. Ein Geräte-Login mit
einem echten Konto und Push auf der Testkennung bleiben offen.
App-Quellstand c6388bd.

## Isolierte E-Mail-Abnahme vom 19.11.2026

Separate Keycloak-26.8.2-Instanz auf Loopback-Port 18080 mit In-Memory-DB,
synthetischem Realm clubunio und vier synthetischen Konten. Die Instanz
verwendete das vorhandene Provider-JAR und eine Kopie des lokalen E-Mail-Themes.
Ein lokaler SMTP-Empfänger fing alle Nachrichten ab.

Alle acht Kombinationen bestanden: ClubUnio und SV Sissach, jeweils Einladung
per execute-actions-email und Passwort-Reset über das Anmeldeformular in DE/EN.
HTML- und Klartextteile, Aktionslink, HTML-Sprachattribut, Designfarbe und
Vereinsattribution geprüft. Das SV-Sissach-PNG lieferte HTTP 200.
Die Einladungen forderten UPDATE_PASSWORD an. Aktionslinks wurden auf
Vorhandensein geprüft; die Passwortänderung wurde nicht abgeschlossen.
Die Loopback-HTTP-Prüfung erlaubte Secure-Cookies im Testclient ausdrücklich
auch über HTTP. Das HTTPS-Verhalten und die Darstellung in E-Mail-Clients
sind damit nicht abgenommen.

[Technische Ergebnisse ohne Tokens](evidence/keycloak-email-theme-20261119.json).
Testinstanz gestoppt, temporäre Daten und Bootstrap-Zugang entfernt.
Keine Änderung an DEV, Stage oder dem laufenden Identitätsdienst.

## Vollständiger Passwortablauf vom 19.11.2026

Erneut separate Keycloak-26.8.2-Loopback-Instanz mit In-Memory-Datenbank,
isoliertem Realm und synthetischen Konten. Alle acht Kombinationen aus
ClubUnio/SV Sissach, DE/EN und Einladung/Passwort-Reset bestanden.
Der SMTP-Testempfänger fing die Mails ab. Die Prüfung öffnete den echten
Aktionslink, bestätigte gegebenenfalls die Aktionsseite und speicherte das
neue Passwort im Keycloak-Formular.

Nach jeder Passwortänderung bestanden ein frischer OIDC-Formularlogin
bis zur Callback-Weiterleitung und eine zusätzliche Token-Anmeldung.
Nach jedem Reset wurde das vorherige Passwort abgelehnt.
Verbrauchte Einladungs- und Resetlinks boten keine erneute Passwortvergabe an.
Die Testclients erlaubten den Passwort-Grant ausschliesslich als zusätzliche
Kontrolle in dieser temporären Instanz. Die HTTP-Cookie-Ausnahme für Loopback
aus der vorigen Prüfung gilt weiterhin. Die Darstellung in echten
E-Mail-Clients und die HTTPS-Cookie-Eigenschaften sind separat zu prüfen.

[Ergebnisse ohne Passwörter und Tokens](evidence/keycloak-password-flow-20261119.json).
Die Instanz und temporären Zugangsdaten wurden entfernt. DEV, Stage und der
laufende Identitätsdienst wurden nicht verändert.

## HTTPS-Cookie-Prüfung vom 19.11.2026

Separate Loopback-Instanz mit eigenem kurzlebigem TLS-Testzertifikat für
127.0.0.1 und eigener In-Memory-Datenbank. Die Python-Testclients validierten
Zertifikat und Hostnamen anhand des explizit vertrauten Testzertifikats.
Die acht Passwortabläufe bestanden erneut über HTTPS. Die geprüften
AUTH_SESSION_ID-, KC_RESTART- und KEYCLOAK_IDENTITY-Cookies trugen Secure,
HttpOnly und einen SameSite-Wert. Der unveränderte Python-CookieJar fügte
diese sicheren Cookies einer HTTP-Testanfrage nicht hinzu.
Eine Browserprüfung der SameSite-Durchsetzung und JavaScript-Zugriffe ist
hiermit noch offen. Der historische Ergebnisname browser_login bezeichnet
den per HTTP-Client durchlaufenen OIDC-Formularlogin.

[HTTPS-Ergebnisse ohne Tokens](evidence/keycloak-https-cookie-20261119.json).
Instanz, privater Testschlüssel und temporäre Zugangsdaten entfernt.
Für die Outlook-/Gmail-Sichtprüfung werden freigegebene Testpostfächer und
die gewünschte Outlook-Version benötigt. Diese Sichtprüfung ist offen.
Keine Änderung an DEV, Stage oder dem laufenden Identitätsdienst.

## Sichtabnahme durch den Betreiber (19.11.2026)

Der Betreiber bestätigte die Sichtprüfung der E-Mail-Themes in den Clients
Outlook und Gmail als erfolgreich. Die Bestätigung bezieht sich auf die
angefragten ClubUnio- und SV-Sissach-Varianten für Einladungen und
Passwort-Reset in Deutsch und Englisch. Die Clientprüfung wurde durch den
Betreiber durchgeführt. Clientversionen und Screenshots wurden nicht erfasst.
Damit ist die zuvor offene Client-Sichtabnahme erledigt.
Die separate Browserprüfung der SameSite-Durchsetzung bleibt offen.

## E-Mail-Release auf DEV und Stage (19.11.2026)

Das geprüfte HTML-Theme und der aktualisierte E-Mail-Provider wurden im
gemeinsamen Keycloak-Dienst aktiviert. Der Realm verwendet bereits
emailTheme=clubunio. Die Zuordnung umfasst jetzt clubunio-sv-sissach-dev und
clubunio-verein-stage für SV Sissach. Plattformclients verwenden ClubUnio.
Vor der Aktivierung bestanden acht vollständige Einladungs-/Resetabläufe mit
dem neuen JAR in der isolierten Instanz, einschliesslich der Stage-Client-ID.
[Ergebnisse](evidence/keycloak-stage-email-release-20261119.json).

Rückweg: JAR und Theme unter
/root/clubunio-email-release-20261119-YyEanW gesichert. Für Rückkehr den Dienst
stoppen, die gesicherten Dateien wiederherstellen, mit
kc.sh build --db=postgres --spi-email-template--provider=clubunio bauen und
den Dienst starten. Der Release aktualisierte nur den gemeinsamen
Identitätsdienst. Keine Laravel-Dateien oder Anwendungsdatenbanken verändert.

Nach Neustart Keycloak aktiv, externe OIDC-Discovery erfolgreich. Die Vereins-
und Plattformlogins von DEV und Stage liefern nach Weiterleitung HTTP 200
auf auth.clubunio.com. Installiertes JAR und HTML-Theme stimmen per SHA-256
mit den geprüften Release-Dateien überein. Kein erneuter Versand an echte
Empfänger bei dieser technischen Releaseprüfung.
