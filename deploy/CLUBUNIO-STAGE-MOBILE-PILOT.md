# ClubUnio Stage: Mobile-Pilot

Stand 10.11.2026. Nur `https://stage.clubunio.com` und `clubunio_stage`.
DEV, PROD und PHPMaker unveraendert. Keine Benachrichtigungen versendet.

## Mobile

Auf Stage sind Anlagenstatus inklusive Platzplanung, Anmeldungen,
Trainingsplaner, Bus, Dokumente und Sponsorenlauf fuer die native App
aktiviert. Der serverseitige Modulkatalog meldet alle sechs fuer das
freigegebene Konto in `sv-sissach-refresh` als nativ verfuegbar; der
HTTPS-Pilottest fuer Anmeldung und Mandantentrennung ist gruen.
Berechtigungen und Client-Feature-Pruefungen bleiben aktiv. Keine
Endgeraeteabnahme; KI-Generierung, Uploads und Push-Versand bleiben aus.
Die vorherige Stage-ENV wurde privat gesichert. DEV und PROD unveraendert.

Das explizit freigegebene bestehende Stage-Konto darf jetzt auch Mobile
fuer `sv-sissach-refresh` verwenden. Die Web-Mitgliedschaft allein
hatte nicht gereicht, weil die separate Mobile-Pilotgrenze das Konto
abgewiesen hatte (App-Login HTTP 401). Kontoaktivierung und bestehender
Hash bleiben unveraendert. Loginliste, Kontext und Mandanten-Endpunkte
sind fuer dieses Konto auf den neuen Mandanten begrenzt; die bisherigen
Testkonten bleiben im Pilotverein. Andere importierte Konten sind
weiterhin ausgeschlossen. Bestehende Stage-HTTPS-Pilottests bestanden;
die Reviewer-Freigabe wurde serverseitig geprueft, ohne dessen Passwort
zu verwenden. Kein Push freigeschaltet und kein DEV-/PROD-Release.

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
  Erneute Abnahme per direktem Windows-SSH am 10.11.2026: Beide Testkonten
  bestanden Login, Kontext, Modulliste, Teamplanung, Meine Teams und Spesen.
  Nicht freigegebener Login (401) und fremder Mandant (404) wurden abgewiesen;
  beide Sitzungen wurden durch Logout widerrufen. Wiederholung auf dem VPS
  als RunCloud-Anwendungsbenutzer im Stage-Verzeichnis:
  `/RunCloud/Packages/php83rc/bin/php deploy/check-stage-mobile-pilot.php`.
  Der Test gibt keine Passwoerter oder Tokens aus und widerruft die
  Testsitzungen. Mitglieder fachlich mit synthetischem Datensatz abnehmen.

## Push: bewusst noch geschlossen

Stage nutzt nach Freigabe dasselbe Firebase-Testprojekt wie DEV, aber eine
eigene Dienstidentitaet mit eigenem Schluessel und nur den fuer FCM noetigen
Rechten. Kein DEV-Schluessel auf Stage und keine PROD-Ressourcen verwenden.
Das gemeinsame Projekt ist keine technische Trennung der Empfaenger:
Nur explizit verifizierte iOS-/Android-Stage-Testgeraete verwenden. Fuer iOS
auch die APNs-Konfiguration des Testbuilds pruefen.

Die neue Stage-JSON hat eine von DEV getrennte Dienstidentitaet und
Schluessel-ID im gemeinsamen Firebase-Testprojekt. Sie wurde mit Modus
0600 auf den VPS uebertragen; der RunCloud-Benutzer erhielt erfolgreich
ein OAuth-Token mit FCM-Scope. Das bestaetigt noch keine Zustellung.
Bei der letzten DB-Pruefung gab es keine Geraete und keine Versandauftraege.

Der Stage-Branch trennt die Registry-Eintraege nach Plattform, auch wenn
beide Testbuilds dieselbe App-ID verwenden (`ios:com.my.clubunio.core`
und `android:com.my.clubunio.core`). Registrierung und Status sind je
Testkonto auf genau die zugeordnete Plattform begrenzt. Die Client-
Capability fuer Registrierung ist separat von der Versandfreigabe.
Stage laeuft mit `5fb2b22`. Die Registrierung wurde in der privaten ENV
aktiviert; das Versandflag bleibt explizit false. Der neu erzeugte
Konfigurationscache lieferte per Stage-PHP-CLI zwei App-Eintraege; der
iOS-Capability- und Registry-Test mit der Stage-Origin war erfolgreich.
Der erste authentifizierte HTTPS-Test meldete die Capability jedoch noch
nicht. Der Wiederholungstest brach mit einem SSH-Timeout ab. Die
Web-Freigabe ist deshalb noch nicht abgenommen; bei Wiederanlauf
`deploy/check-stage-mobile-pilot.php --push-registration` ausfuehren
und bei einer Abweichung zuerst die effektive Stage-Webkonfiguration
pruefen. Beim Start der Aktivierung waren keine aktiven Geraete vorhanden.
Beim erneuten HTTPS-Test reproduziert: Der private FastCGI-Test auf dem
Stage-Pool zeigte beide Registry-Eintraege, aber `open_basedir` sperrt den
Zugriff auf die bisherige Schluesseldatei. CLI und FPM unterscheiden sich
hier absichtlich. Die Stage-Registry verweist jetzt auf die private Datei unter
`storage/framework/stage-secrets/` (0700/0600, runcloud, Git-ignoriert)
innerhalb der Stage-App, aber ausserhalb des Nginx-Webroots `public/`.
Nur die zwei Stage-Registry-Pfade wurden umgestellt und der
Stage-Konfigurationscache erneuert. Die PHP-Zugriffsgrenzen blieben
unveraendert. Die bisherige private Datei bleibt bis zur Abnahme als
Rueckweg erhalten. Beide anschliessenden Windows-SSH-Versuche fuer den
authentifizierten HTTPS-Test endeten mit Timeout; der Test nach der
Pfadkorrektur steht deshalb noch aus.
Kein bestaetigtes Testgeraet, kein Worker und kein Push-Versand.

Private Uebergabe auf Windows:
`C:\ClubUnio-Secrets\firebase\clubunio-stage-service-account.json`.
Nicht die DEV-Datei ersetzen. Die Datei nicht in OneDrive, im Repository,
im Chat oder in der mobilen App ablegen. Nur dem eigenen Windows-Konto
und Administratoren Zugriff geben (SYSTEM fuer Systemverwaltung).
Der aktive Registry-Pfad auf dem VPS ist
`/home/runcloud/webapps/ClubUnioStage/storage/framework/stage-secrets/firebase-service-account.json`
(Verzeichnis 0700, Datei 0600, Besitzer runcloud). Vor Aktivierung das
Firebase-Projekt des Servicekontos mit beiden Stage-Testbuilds abgleichen.

Vor der Zustellung Stage-Origin, Testmandant, Konto, Plattform und
Gerätebindung pruefen. Nur vorab gepruefte synthetische Outbox-IDs mit
einem Stage-eigenen, begrenzten Worker verarbeiten. Keinen allgemeinen
Queue-Worker starten, solange importierte Altbestände nicht isoliert sind.
FCM-Annahme und Anzeige am jeweiligen physischen Testgeraet getrennt
bestaetigen. DEV-/PROD-Schluessel, Geraetebindungen oder Outbox-Eintraege
nicht uebernehmen.

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
