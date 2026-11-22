# Event-QR und Anwesenheitsbestätigung – Release 22.11.2026

## Bedienung

Im Eventdetail als Vorstand mit `events.update` zuerst Check-in konfigurieren:
Zeitfenster in Europe/Zurich angeben und aktivieren. Danach QR-Aushang drucken.
Ein Mitglied scannt mit der Handykamera, öffnet die App, meldet sich falls nötig
an und bestätigt seine eigene Teilnahme ausdrücklich. Erst diese Bestätigung
schreibt die Anwesenheit. Die Registerkarte «Bestätigte Anwesenheit» zeigt den
Eingangszeitpunkt, auch für Mitglieder ohne vorherige RSVP-Anmeldung.
Bestehende Gäste-/RSVP-Antworten werden nicht geändert. Eltern können über diesen
Selbst-Check-in nicht stellvertretend ihre Kinder als anwesend registrieren.

Wiederholungen erzeugen keine zweite Anwesenheit und behalten den ersten
Zeitpunkt. Nach einem Timeout gleicht die App den gespeicherten Stand ab.
Vereinszugehörigkeit, Selbstverknüpfung, Teamzuordnung, Zeitfenster und Versionen
werden frisch geprüft. QR ersetzen macht alte Aushänge ungültig. Check-in
schliessen sperrt neue Bestätigungen; bestehende Anwesenheit bleibt erhalten.

Details: [API](CLUBUNIO-EVENT-CHECKIN-API.md),
[Web und Druck](CLUBUNIO-EVENT-CHECKIN-WEB.md),
[Mobile](../mobile/docs/EVENT-CHECK-IN.md).

## DEV und Stage

Auf beiden ClubUnio-Instanzen gezielt bereitgestellt, ohne PROD-Änderung:
Migration `2026_11_22_000120_event_checkin`, neue Anwesenheits-/QR-Tabellen,
Event-Zeitfenster sowie Web-/Mobile-Routen. Bestehende Events bleiben deaktiviert,
bis der Vorstand das individuelle Zeitfenster festlegt. Keine Echtdaten aktiviert.
Live-Schema `clubunio`, lokale Quellen `clubora`; bestehende Releaseanpassung
beim Deployment angewandt. Gemeinsame Routen wurden nur additiv ergänzt.

- DEV: öffentliche Basis `https://dev.clubunio.ch`, Verein 4 mit
  `https://dev-clubunio.svsissach.ch`.
- Stage: öffentliche Basis `https://stage.clubunio.com`, Verein 3 mit
  `https://stage-clubunio.svsissach.ch`.
- `CLUBUNIO_EVENT_CHECKIN_ENABLED=true`; Herkunfts-/Hostlisten je Instanz begrenzt.
- API-Bestätigung: 30 Versuche je Konto/Minute, keine gemeinsame Schranke für
  alle Besucher hinter demselben Eingangs-WLAN. Öffentlicher Einstieg 600/min/IP.

Private Sicherungen: PostgreSQL auf VPS unter
`/root/event-checkin-preflight-20261122-165059`; Code DEV unter
`/var/www/svs-laravel/shared/event-checkin-release-20261122-175852`, Stage unter
`/root/event-checkin-release-20261122-165857`. Konfiguration DEV unter
`/var/www/svs-laravel/shared/event-checkin-config-20261122-175954`, Stage unter
`/root/event-checkin-config-20261122-170336`. Kein Backup im Repository.
Bei Rücknahme Feature deaktivieren und gesicherte Code-/Konfiguration restaurieren;
Anwesenheitsdaten nicht löschen. Die additive Migration benötigt keinen DROP.

Nginx: ausschliesslich die zwei exakten Association-Dateipfade vor dem allgemeinen
Schutz versteckter Dateien an Laravel durchreichen, siehe
[event-app-links-nginx.conf](event-app-links-nginx.conf). DEV-Sicherung unter
`/var/www/svs-laravel/shared/event-app-links-nginx-20261122-180206`;
Stage-Snippet `/etc/nginx-rc/extra.d/ClubUnioStage.location.main-before.event-app-links.conf`.
Syntaxprüfung und Reload auf beiden Instanzen erfolgreich.

Stage: bestehendes Wallet-Codeverzeichnis war root-owned und wegen RunCloud-ACL
für den Webserverbenutzer unlesbar. Nur dessen Eigentümer auf `runcloud:runcloud`
korrigiert. App-Boot und Wallet-Kommando als runcloud geprüft; Wallet weiterhin
mangels Zertifikat deaktiviert, kein Passversand.

## Native App-Verknüpfung fertigstellen

Die Serverdateien sind über HTTPS auf allen vier Hosts erreichbar, ohne Login.
Sie beanspruchen ausschliesslich `/event-check-in/*`; Ausweis-Prüfseiten bleiben
normale Webseiten. Core und SV-Sissach-Test verwenden getrennte Profile.

### iPhone

Apple-Team `XP7296D478`; Bundle-IDs `com.my.clubunio.core` und
`com.my.sportvereinSissach.test` (Grossschreibung beachten). Associated Domains
für diese App-IDs in Apple Developer aktivieren und passende Provisioning-Profile
verwenden. Mit den aktualisierten Brand-Werkzeugen neu nativ bauen; die Quellen
enthalten Entitlements und profilgebundene Domains. Team-/Application-Identifier
im signierten Build mit der ausgelieferten AASA abgleichen. Flutter-Hot-Restart
allein übernimmt keine neuen Entitlements oder URL-Schemes.

### Android – echter Fingerabdruck noch offen

Laut Benutzer noch nicht hinterlegt. Keinen Platzhalter konfigurieren.
Den öffentlichen SHA-256-Fingerabdruck des tatsächlich ausgelieferten
Signierzertifikats ermitteln, beispielsweise mit
`keytool -list -v -keystore <sicherer-keystore-pfad>` (Passwort interaktiv).
Bei Play-Verteilung das App-Signing-Zertifikat aus Play Console verwenden,
nicht nur das Upload-Zertifikat. Debug-/Testzertifikate gelten nur für die damit
signierten Builds. Schlüsseldateien und Passwörter nie ins Repository kopieren.

Auf DEV/Stage sicher setzen:

- `CLUBUNIO_APP_LINKS_ANDROID_CORE_SHA256` für `com.my.clubunio.core`.
- `CLUBUNIO_APP_LINKS_ANDROID_SVS_SHA256` für `com.my.sportvereinsissach.test`.

Mehrere freigegebene Fingerabdrücke kommasepariert, danach Laravel config:cache
als Webserverbenutzer. `/.well-known/assetlinks.json` auf den jeweiligen Hosts
prüfen: Package und Fingerabdruck müssen genau passen. Aktuell liefert es bewusst
`[]`. Neuen signierten Build installieren und mit Android App-Link-Verifikation
(z.B. `adb shell pm verify-app-links --re-verify <package>`, danach
`adb shell pm get-app-links <package>`) prüfen. Abschliessend QR mit echter Kamera
bei kalter und bereits laufender App öffnen.

Die öffentliche Einstiegsseite hat zusätzlich einen ausdrücklichen
«App öffnen»-Link über das profilgebundene URL-Scheme. Dieser benötigt ebenfalls
den neuen nativen Build. Ohne installierte App ist die Seite keine Web-Check-in-
Alternative und schreibt keine Anwesenheit.

## Abnahme

- Backend: 28 Tests / 162 Assertions erfolgreich (API, Web/Druck, Association,
  kontobasierte Drosselung); zentrale DE/EN-Prüfung: 3044 Schlüssel, kein Literalrückstand.
- Echte PostgreSQL-Prüfung auf DEV und Stage, auch mit Webserver-Benutzern:
  eigene Bestätigung, Idempotenz, Rollen-/Vereinsgrenzen, RSVP-Trennung,
  Versionskonflikte, Schliessen/Rotation erfolgreich, synthetische Daten zurückgerollt.
- Mobile: vollständiger Testlauf 1061 erfolgreich, ein bestehender Capture-Test
  übersprungen; flutter analyze ohne Befund. 2147 Texte vollständig in fünf lokalen
  Katalogen, zentral nur freigegebene Sprachen auswählbar. Brand-Werkzeuge:
  15 Tests erfolgreich, ein bestehender Test übersprungen.
- Auf allen vier HTTPS-Hosts AASA an beiden Pfaden und assetlinks geprüft;
  öffentlicher Einstieg 200/no-store und korrekte App-Links.
- Synthetischer A4-Aushang gerendert, visuell geprüft und QR aus dem Rasterbild
  erfolgreich dekodiert. Kein Nachweis einer physischen iPhone-/Android-Abnahme.

Offen: neuer signierter nativer Build, iOS-Provisioning-/Gerätetest und echte
Android-Signierfingerabdrücke mit Geräteverifikation. Gerätecheck: kalt/warm,
vor Login, Core-Vereinswechsel, Custom-Build-Fremdverein ablehnen,
explizit bestätigen, erneut scannen und ausserhalb des Fensters ablehnen.
