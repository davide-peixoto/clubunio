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
dem Vereins-Theme. Anmeldung, Rücksprung und Profilabnahme sind offen.
Das vorherige DEV-Release ist `20261117-clubunio-native-login`.

Die Theme-Dateien für das ClubUnio-Kontoprofil und für die Anmeldung
des SV Sissach wurden am 17.11.2026 auf den VPS kopiert. Die Aktivierung
und die Browserabnahme stehen aus.

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

Für eine spätere OIDC-Anbindung die Zielinstanz und ihre exakten Redirect-URIs festlegen, den Client anlegen und die Zuordnung bestehender Konten prüfen. Vor einer Umstellung Anmeldung, Rollen, Mandantenzuordnung und Rückfall auf den bestehenden Login in der Zielinstanz testen.

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
