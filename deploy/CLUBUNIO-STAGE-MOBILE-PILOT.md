# ClubUnio Stage: Mobile-Pilot

Stand 10.11.2026. Nur `https://stage.clubunio.com` und `clubunio_stage`.
DEV, PROD und PHPMaker unveraendert. Keine Benachrichtigungen versendet.

## Mobile

- Der Stage-Branch begrenzt Mobile-Login und bestehende Mobile-Sitzungen
  auf zwei dedizierte synthetische Testkonten. Mandanten-Endpunkte sind auf
  `stage-mobile-pilot` begrenzt. Die Konten gehoeren nur diesem Testverein.
  Die Stage-spezifische Zugriffsregel nicht nach DEV/PROD mergen.
- Beide Konten und der Testverein sind in Stage angelegt. Testpasswoerter
  liegen ausschliesslich privat auf dem VPS unter
  `/home/runcloud/.clubunio-stage-mobile/accounts.json`, nicht in Git, App
  oder Webroot. Keine importierten Konten oder Rechte wurden geaendert.
- Mobile, Teamplanung, Meine Teams, Spesen und Mitglieder sind im
  Stage-Konfigurationscache aktiviert. Push und Uploads wurden nicht
  aktiviert. Vorherige Stage-ENV wurde privat gesichert.
- Extern bestaetigt: Health HTTP 200, Mobile-Kontext ohne Sitzung HTTP 401.
  Der angemeldete Testlauf konnte nach einem SSH-Timeout noch nicht
  ausgefuehrt werden. Auf dem VPS als RunCloud-Anwendungsbenutzer im
  Stage-Verzeichnis ausfuehren:
  `/RunCloud/Packages/php83rc/bin/php deploy/check-stage-mobile-pilot.php`.
  Der Test gibt keine Passwoerter oder Tokens aus und widerruft die
  Testsitzungen. Mitglieder fachlich mit synthetischem Datensatz abnehmen.

## Push: bewusst noch geschlossen

Kein Stage-Firebase-Servicekonto, keine Stage-App-Registry und kein Worker.
Separates Firebase-Projekt und darauf konfigurierte iOS-/Android-Testbuilds
bereitstellen. Fuer iOS auch die APNs-Konfiguration des Testbuilds pruefen.
Credential ausserhalb des Webroots hinterlegen, nicht nach Git uebertragen.

Private Uebergabe auf Windows:
`C:\ClubUnio-Secrets\firebase\clubunio-stage-service-account.json`.
Nicht die DEV-Datei ersetzen. Die Datei nicht in OneDrive, im Repository,
im Chat oder in der mobilen App ablegen. Nur dem eigenen Windows-Konto
und Administratoren Zugriff geben (SYSTEM fuer Systemverwaltung).
Das geplante Ziel auf dem VPS ist
`/home/runcloud/.clubunio-stage-mobile/firebase-service-account.json`
(Verzeichnis 0700, Datei 0600, Besitzer runcloud). Vor Aktivierung das
Firebase-Projekt des Servicekontos mit beiden Stage-Testbuilds abgleichen.

Vor der Zustellung Stage-Origin, Testmandant, Konto, Plattform und
Gerätebindung pruefen. Nur vorab gepruefte synthetische Outbox-IDs mit
einem Stage-eigenen, begrenzten Worker verarbeiten. Keinen allgemeinen
Queue-Worker starten, solange importierte Altbestände nicht isoliert sind.
FCM-Annahme und Anzeige am jeweiligen physischen Testgeraet getrennt
bestaetigen. DEV-/PROD-Firebase-Konfiguration nicht uebernehmen.

Rueckweg: Mobile-Hauptflag ausschalten und nur den Stage-Konfigurationscache
neu aufbauen. Synthetische Konten bei Bedarf separat sperren.


## Umgebungswahl und Mitgliedsanmeldung

Die ClubUnio-App bietet `https://stage.clubunio.com` als Stage-Auswahl an.
Der Stage-Branch enthält die additive Antragsverknüpfung für das Schema
`clubunio`. Die Pilotgrenze gilt für öffentliche Anmeldungen, Login und
Statusabfrage. Zusätzlich zu den vorhandenen Testkonten sind nach der
Identity-Freigabe per E-Mail bestätigte neue Konten des Pilotvereins erlaubt.
Importierte Konten erhalten dadurch keinen Mobile-Zugang. Details und
Aktivierungsvoraussetzungen: [Mitgliedsanmeldung](CLUBUNIO-MOBILE-MEMBERSHIP-TRACKING.md).

10.11.2026: Migration `2026_11_10_000100_mobile_membership_tracking` atomar
auf `clubunio_stage` angewendet und im Ledger erfasst. Der Stage-Bootstrap ist
für die exakte Stage-Origin aktiv. Extern HTTP 200 beim Bootstrap und HTTP 401
beim anonymen Context. Der HTTP-Pilottest bestätigte Login, Module,
Mandantentrennung und Logout mit beiden bestehenden Testkonten.

Der Stage-Identity-Mailer hat noch keinen SMTP-Host, keine Zugangsdaten und
keinen Absender. Öffentliche Registrierung und Mitgliedsanmeldung bleiben
bis zur Stage-Mailkonfiguration und Testpostfachprüfung geschlossen. Die
Pilotvereins-Lookups und die öffentliche Anmeldefreigabe sind bei der
Aktivierung zu prüfen. Push und Uploads sind vom bestehenden Pilotbetrieb
weiterhin getrennt.
