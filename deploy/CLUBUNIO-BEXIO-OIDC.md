# ClubUnio: Bexio OpenID-Verbindung

Stand12.10.2026, Release25. Nur ClubUnio, kein PHPMaker-Deployment.

## Einrichtung

Unter Einstellungen > Bexio-Verbindung als Administrator verbinden.
Die bisherige PHPMaker-Implementierung nutzt Jumbojett/OpenID-Connect-PHP;
ClubUnio verwendet denselben Connector (v1.0.2) mit Laravel-Session-Anbindung.
Der Connector prueft signierte ID-Tokens; State wird bereits vor dem
Tokenaustausch geprueft. Nonce, Issuer, Audience, Laufzeit und RS256 werden
validiert; PKCE S256, zehn Minuten Callback-Grenze und Einmalverwendung.
Office/Trainer/Spieler duerfen die Verbindung nicht einrichten.

Zusaetzliche erlaubte Callback-Adresse im Bexio-App-Portal:
https://dev-my2.svsissach.ch/clubora-bexio/sv-sissach/callback

APP_URL der jeweiligen Installation bestimmt die Callback-Adresse, niemals
ein importierter PROD-Redirect oder ein beliebiger Host-Header. Fuer direkte
lokale Einrichtung waere auch die lokale Callback-Adresse freizugeben.
Lokal/Pi verwenden momentan dieselbe separate ClubUnio-DB; einmaliges Verbinden
auf Pi reicht fuer beide, sofern dieselbe Client-Konfiguration vorliegt.
Der Benutzer hat die Pi-Callback-Adresse am12.10. bestaetigt freigegeben.
Bestehende PHPMaker-Callbacks nicht entfernen. Eine eigenstaendige Bexio-App
fuer ClubUnio ist empfehlenswert; bestehende Client-Konfiguration ist nutzbar,
aber Tokens muessen aus einer neuen Anmeldung stammen, nicht kopiert werden.

Migration26 lokal am12.10. ausgefuehrt:5 veraltete Parameter entfernt,
179 aktive Settings verbleiben (neue Verbindung entsteht erst beim Consent).
Auf ausdruecklichen Benutzerwunsch wurde ausschliesslich Account19
(davide.peixoto@svsissach.ch) fuer SV Sissach von office auf admin gesetzt;
Audit in object_changes, reproduzierbarer Einmalschritt im Tool
tools/clubunio-owner-role-20261012.php. Privates Zweitkonto unveraendert.
Lokale APP_URL auf http://localhost:8093 korrigiert, nicht Teil des Pi-Archivs.

## Parameter und Migration26

Weiter erforderlich: BEXIO_CLIENT_ID, BEXIO_CLIENT_SECRET,
BEXIO_AUTHORIZE_SCOPES. openid/offline_access werden immer ergaenzt.
PDF benoetigt die entsprechenden Rechnungs-Leserechte. Bestehende Scopes
bleiben konfigurierbar; Scopes werden nicht automatisch reduziert.
Client-Konfiguration kann nur von Admins ersetzt werden. Aenderung von
Client-ID/Secret/Scopes verlangt eine erneute Anmeldung.

Neu: BEXIO_OIDC_CONNECTION ist ein automatisch verwalteter, verschluesselter
Datensatz mit Tokenpaar, Ablauf, Konfigurationsfingerabdruck und Verbindungs-
metadaten. Nicht manuell bearbeitbar; Tokens nie im Browser oder Log ausgeben.

Migration2026_10_12_000026_bexio_oidc_cleanup entfernt ausschliesslich die
veralteten aktiven ClubUnio-INI-Eintraege:
- BEXIO_TOKEN
- BEXIO_REFRESH_TOKEN
- BEXIO_ACCESS_TOKEN_EXPIRES_AT
- BEXIO_TOKEN_ENDPOINT
- BEXIO_PROVIDER_URL (falls vorhanden)
- BEXIO_REDIRECT_URI (falls vorhanden)
- BEXIO_CRON_TOKEN (alter PHPMaker-Cron-Aufruf)

Provider/Endpoints sind fuer diese Bexio-Integration festgelegt. Kopierte
Refresh-Tokens werden weder benutzt noch beim Provider widerrufen.
Aktiv-Schalter und fachliche Konten/Steuer/Waehrungsparameter bleiben erhalten.
Vor Entfernung schreibt die Migration die bereits verschluesselten Werte
und zugehoerige Auditmetadaten nach storage/app/private/migration-backups.
Keine Klartext-Secrets im Backup. down() verlangt einen geprueften Restore.
PHPMaker-Datenbanken und der historische legacy_prod-Snapshot bleiben unveraendert.
Bei spaeterem Neuimport diese Bereinigung nach dem INI-Import wieder ausfuehren;
niemals eine bestehende ClubUnio-OIDC-Verbindung mit Snapshot-Tokens ersetzen.

## PDF und Betrieb

Ein PostgreSQL-Tenant-Row-Lock serialisiert Tokenrotation ueber lokale/Pi-
Prozesse. Tokens werden kurz vor Ablauf erneuert und atomar verschluesselt
gespeichert. Nach API401 erfolgt hoechstens eine Erneuerung/ein PDF-Retry.
Bereits parallel erneuerte Tokens werden wiederverwendet. Fehlerhafte Refresh-
Antworten ueberschreiben die alte Verbindung nicht. Keine Netzwerk-Retryschleifen.
PDF bleibt vom Bexio-Aktiv-Schalter unabhaengig. Keine Bexio-Schreibprozesse,
Rechnungsuebertragungen oder neuen mobilen Endpunkte durch dieses Paket.

Live-Status erst nach Benutzer-Consent und echtem PDF-Abruf bestaetigen.
Automatische Tests verwenden ausschliesslich signierte Test-ID-Tokens und
gemockte HTTP-Antworten; kein Test rotiert einen echten Refresh-Token.
Referenzen: https://docs.bexio.com/ und https://github.com/jumbojett/OpenID-Connect-PHP

## Bestaetigter Abschluss

12.10.2026: Verbindung eingerichtet,180 aktive Settings einschliesslich neuer
OIDC-Verbindung. Echtes PDF Rechnung6790 lokal/Pi HTTP200,1893246 Bytes und
gueltiger PDF-Header. Separater echter Refresh der neuen ClubUnio-Verbindung
auf Pi erfolgreich; direkt danach PDF erneut200. Keine PHPMaker-Tokens rotiert.
Regression115 Tests/1165 Assertions; fokussierter Lauf9/96 erfolgreich.
Release20261012-clubora-25 aktiv. Funktioniert auch bei deaktiviertem Sync.
