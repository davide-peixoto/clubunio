# PROD: Keycloak-Anbindung

Vorbereitete Clientkonfigurationen fuer den bestehenden Realm clubunio:
- clubunio-verein-prod: https://app.clubunio.com/vereinslogin/oidc/callback
- clubunio-platform-prod: https://app.clubunio.com/platform/oidc/callback

Issuer: https://auth.clubunio.com/realms/clubunio.
Exakte Web-Origin https://app.clubunio.com und Logout-Ruecksprung
https://app.clubunio.com/. Keine Wildcards.

Vertrauliche Clients mit eigenen Secrets, Standard Authorization Code Flow,
PKCE S256. Kein Direct Grant, Implicit Flow oder Service Account.
MFA-Browserflow clubunio-verein-dev-mfa, ACR clubunio-mobile-mfa = Level 2.
Der Flowname ist historisch; es ist der bestehende Stage-MFA-Flow.
PROD verwendet das ClubUnio-Theme, keine Stage-Vereinszuordnung.

Die JSON-Dateien neben dieser Anleitung enthalten keine Secrets und sind
auf den bestehenden Realm mit dessen gepruefter Browserflow-ID zugeschnitten.
Bei anderer Keycloak-Installation die Flow-ID vorher ueber die Admin-Konsole
neu aufloesen. Nicht ungeprueft in andere Realms importieren.

Anwendungsvariablen:
CLUBUNIO_KEYCLOAK_ONLY=true
CLUBUNIO_VEREIN_OIDC_ENABLED=true
CLUBUNIO_VEREIN_OIDC_ORIGIN=https://app.clubunio.com
CLUBUNIO_VEREIN_OIDC_CLIENT=clubunio-verein-prod
CLUBUNIO_VEREIN_OIDC_SECRET=<privat>
CLUBUNIO_VEREIN_OIDC_SUBJECTS={}
CLUBUNIO_VEREIN_OIDC_WEB_MFA_ACR=clubunio-mobile-mfa
CLUBUNIO_VEREIN_OIDC_LEGACY_ALLOWED=false
CLUBUNIO_PLATFORM_OIDC_ENABLED=true
CLUBUNIO_PLATFORM_OIDC_ORIGIN=https://app.clubunio.com
CLUBUNIO_PLATFORM_OIDC_CLIENT=clubunio-platform-prod
CLUBUNIO_PLATFORM_OIDC_SECRET=<privat>
CLUBUNIO_PLATFORM_OIDC_SUBJECTS={}
CLUBUNIO_PLATFORM_OIDC_WEB_MFA_ACR=clubunio-mobile-mfa
CLUBUNIO_PLATFORM_OIDC_LEGACY_ALLOWED=false

Einladungen, mobile Kopplung und Vereinsdomain-Ausnahmen bleiben vorerst aus.
Secrets nur in der geschuetzten .env auf dem VPS, niemals im Repository
oder Chat. Configcache nach Aenderung neu erstellen.

Leere Subject-Zuordnungen sind beabsichtigt: Der vorhandene OIDC-Controller
verweigert ohne Zuordnung bzw. freigegebenen Einladungsweg den Einstieg.
Die technische Anbindung verleiht keinem Keycloak-Konto automatisch
ClubUnio-Vereins- oder Plattformrechte. Die erste Administration muss
anschliessend explizit angelegt und mit dem unveraenderlichen Keycloak-
Subject verknuepft werden. Keine automatische Zuordnung per E-Mail.

Wartungsmodus bleibt bis zur Einrichtung und Login-Abnahme erhalten.
DEV-/Stage-Clients und globale Realm-/SMTP-Einstellungen unveraendert lassen.
Rueckweg: private PROD-.env-Sicherung wiederherstellen, Configcache neu
erstellen, nur neue PROD-Clients bei Bedarf deaktivieren.
## Ausgefuehrt und geprueft am 19.11.2026

Beide Clients wurden nach ausdruecklicher Freigabe in der Keycloak-
Admin-Konsole gespeichert, Theme clubunio, PKCE S256 und MFA-Flow samt
ACR-Mapping anschliessend gespeichert und lesend bestaetigt.
Secrets direkt auf dem VPS aus dem lokalen Keycloak-Bestand in die
geschuetzte PROD-.env uebernommen, ohne Ausgabe oder Git-Ablage.
Private Sicherung vor Aenderung:
 /root/clubunio-prod-backups/keycloak-20261119T175408Z/.env.before

Configcache erneuert; .env und bootstrap/cache/config.php Modus 0600.
Anwendungsbootstrap und gepruefte Konfigurationswerte:
Keycloak-only aktiv, beide OIDC-Wege aktiv, Legacy-Login aus,
Subject-Maps leer, Einladungen/mobile Kopplung aus.

Geprueft fuer jeden der beiden Clients vom PROD-PHP aus:
- Loginformular ueber HTTPS auf Deutsch und Englisch: HTTP 200.
- Fremde Redirect-URI: HTTP 400, abgelehnt.
- Ohne PKCE: HTTP 302 auf den eigenen Callback mit invalid_request;
  kein Autorisierungscode. Dies ist das erwartete OAuth-Fehlerverhalten.
- Client-Secret am Tokenendpoint akzeptiert: ungueltiger Testcode liefert
  invalid_grant, nicht invalid_client. Kein Benutzertoken erzeugt.
- Discovery/JWKS erreichbar. MFA-Bindung und ACR-Mapping unabhaengig
  aus der laufenden Keycloak-Konfiguration bestaetigt.

141 Migrationen, null Vereine, null Konten. Kein Schema-/Fachdatenwechsel.
Wartungsmodus bleibt aktiv. Vollstaendige Benutzeranmeldung, Tokenpruefung
mit echtem ID-Token und Logout-Abnahme folgen nach expliziter Einrichtung
der ersten Administration. Keine automatische Rechtevergabe.
## Erster PROD-Plattformadmin (19.11.2026)

Auf ausdruecklichen Auftrag info@clubunio.com als aktives persoenliches
PROD-Konto angelegt, account_id=2. Der Rollback-Probelauf verbrauchte die
erste Sequenznummer; keine Lueckenauffuellung. Einziger Plattformadmin,
keine Vereinszuordnung. Bootstrap ueber PlatformAdministrationService
mit genau einem platform.bootstrap-Auditeintrag. Zufaelliges, nicht
ausgegebenes lokales Passwort; lokaler Login bleibt durch Keycloak-only
gesperrt.

Bestehende Keycloak-Identitaet im Realm clubunio vorab eindeutig anhand
Username/E-Mail geprueft: aktiv und E-Mail verifiziert. Ihr Subject ist
ausschliesslich in CLUBUNIO_PLATFORM_OIDC_SUBJECTS auf Konto 2 abgebildet.
Keine neue Keycloak-Identitaet, keine Keycloak-Rollen und keine
Vereinslogin-Zuordnung angelegt. Private .env-/DB-Sicherung:
 /root/clubunio-prod-backups/platform-admin-20261119T214308Z

Probelauf samt Audit und Berechtigungspruefung zurueckgerollt, danach
verbindlicher Bootstrap in einer Transaktion. Configcache erneuert.
Lesende Nachkontrolle: Konto aktiv, api_only=false, Plattformfreigabe
aktiv, null Mitgliedschaften, ein Bootstrap-Auditeintrag; exakte
Subject-Map und Keycloak-only bestaetigt. Keine Migration.

Ein zusaetzlicher synthetischer Controller-Loginstart wurde von der
automatischen Ausfuehrungspruefung mit 'blocked by policy' abgelehnt;
keine Login- oder Sitzungspruefung daraus behaupten. Vollstaendige
Browseranmeldung/MFA und Rueckkehr zu /platform bleiben offen.
Wartungsmodus bleibt aktiv, Einstieg nach Freigabe unter /platform/login.