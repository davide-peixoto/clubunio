# Firebase-Serviceaccount: ClubUnio-Testvorbereitung

Stand05.11.2026; nur ClubUnio, PHPMaker DEV/PROD unveraendert.

## Sichere Ablage

Vom Benutzer bereitgestellt ausserhalb des Repositories:
`C:\ClubUnio-Secrets\firebase\clubunio-service-account.json`.
Urspruengliche geerbte Zugriffe fuer Benutzer/Authentifizierte Benutzer entfernt.
Ordner/Datei erlauben nur aktuelles Windows-Konto, Administratoren und SYSTEM.
Kein Upload in Git, OneDrive, App, Dokumentablage oder Parameterdatenbank.

Per SSH/SCP verschluesselt auf svs-pi-server uebertragen. Private temporaere
Ablage0700, Datei0600; nach bytegleicher Installation temporaere Kopie entfernt.
Endziel `/etc/clubunio/secrets/firebase-service-account.json`: root:www-data0640;
Elternordner root:www-data0750. Ausserhalb des Webroots und aller Releasepakete.
Lesbarkeit fuer PHP-Worker www-data geprueft. Keine Schluessel-/Tokenausgabe.

## Konfiguration

Bestehender Release20261105-clubunio-pitch-usability-v3 bleibt aktiv.
Nur `CLUBUNIO_MOBILE_PUSH_APPS` in dessen privater .env gesetzt:
Native-ID `com.my.clubunio.core`, Plattform ios, Origin https://dev.clubunio.ch,
fixed_tenant_id=null fuer die Plattform-App; Firebase-Projekt aus dem geprueften
ClubUnio-Serviceaccount, credentials_file mit obigem absoluten privaten Pfad.
Android erst nach gepruefter eigener Native-ID/Clientkonfiguration ergaenzen.

`CLUBUNIO_MOBILE_PUSH_ENABLED` und `CLUBUNIO_MOBILE_PUSH_DELIVERY_ENABLED`
bleiben false. Keine Mail-/Provider-/Benutzerrechte oder DB-Daten geaendert.
Kein Scheduler gestartet. Keine Outbox abgearbeitet, keine Nachricht gesendet.
Altes check-mobile-push.php --closed erwartet historisch eine leere Registry
und ist fuer diesen vorbereiteten Konfigurationsstand nicht das passende Gate.

Vorherige .env und Cachekonfiguration unter dem privaten Verzeichnis
`/var/www/svs-laravel/shared/firebase-setup-20261105` gesichert (0700/0600).
Konfigurationscache mit gleichem releaseprivatem VIEW_COMPILED_PATH erneuert;
vollstaendiger Arrayvergleich belegt ausschliesslich die Registryaenderung.
PHP8.3-FPM neu geladen, Health200. Bei Folge-Releases die private Registry
bewusst erhalten; keine Credentialdatei ins Deployarchiv aufnehmen.

## Verifikation Und Grenzen

- Typ, Projekt, Serviceaccount-Domain, festes Google-Tokenziel und privater
  RSA-Schluessel strukturell geprueft; keine dynamischen fremden Ziele.
- Echte Google-OAuth-Authentifizierung mit firebase.messaging-Scope erfolgreich.
  Zugriffstoken nur kurz im Prozessspeicher, weder gespeichert noch ausgegeben.
- OAuth-Erfolg ist kein Nachweis einer FCM-Sendeberechtigung oder APNs-Zustellung.
  Kein FCM-Nachrichtenaufruf und keine Nachricht an reale Empfaenger.
- READ ONLY / REPEATABLE READ: fuer davide.peixoto@svsissach.ch existieren0
  nicht widerrufene iOS-Bindungen dieser App/Origin. Keine Sitzung erzeugt.
- Acht synthetische Provider-/Auswahltests erfolgreich,17 Assertions;
  ein opt-in PostgreSQL-Isolationstest nicht erneut ausgefuehrt.
- PHP-Syntax geprueft. Keine Aenderung von UI oder Sprachkatalogen.

Noch offen: aktueller Testbuild auf dem Geraet, Abgleich seines Firebase-
Projekts, APNs-Konfiguration sowie persoenlich eingegrenzte Aktivierung und
ausdrueckliche Mitteilungsfreigabe. Erst danach ausgewaehlte Test-Outbox-ID
ausschliesslich fuer das freigegebene eigene Konto senden und Geraeteanzeige
pruefen. Nicht unkontrolliert beide Flags aktivieren oder den allgemeinen
Worker ohne --outbox starten. Keine flaechenweite Pushfreigabe erteilt.

## Rueckweg

Solange keine weiteren Konfigurationsaenderungen erfolgten, gepruefte private
before.env wiederherstellen und Konfigurationscache am selben Releasepfad
erneuern. Sonst nur die Registryaenderung gezielt zuruecknehmen; nicht fremde
Aenderungen ueberschreiben. Schluessel bei Verdacht separat in Google widerrufen.
Die Credentialinstallation selbst erfordert keine Datenbankmigration.

Operatorwerkzeug `configure-firebase-credentials.php` ist fest auf diesen
Release und diese private Ablage begrenzt. --configure nur einmal; --verify
liest nur, --oauth-check authentifiziert ohne Versand. Alte Sicherungen und
Serviceaccount niemals in Supportausgaben oder Versionskontrolle aufnehmen.


## 05.11.2026: Persönliches iOS-Push auf DEV freigeschaltet

Auf ausdrücklichen Auftrag beide Pushflags true; vorhandene iOS-Core-Registry
und übrige Konfiguration byte-/arraygeprüft erhalten. www-data-Capability true,
unsupported Clients weiter false. Neuer Minutentimer/oneshotwww-data-Worker,
max10 Einträge und kein Parallellauf. Vorher0 Geräte/Bindungen/Outbox, erster
Lauf erfolgreich0 verarbeitet, kein künstlicher Testversand. FPM aktiv,
HTTPSup200/context401. Kein Runtime-/DB-/PHPMakerwechsel, noch keine physische
FCM-/APNs-Zustellungsabnahme. CLUBUNIO-DEV-PUSH-ACTIVATION.md.
