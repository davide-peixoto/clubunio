# Gemeinsamer Webchat: Abnahme und Release

Stand04.11.2026. Umsetzung/Pruefung laeuft; Serveraktivierung noch nicht erfolgt.
Dieses Dokument trennt Implementierung, synthetische Nachweise und echte Abnahme.

## Daten und Zugriff

- `/chats` in der bestehenden ClubUnio-Shell, Navigation Anlagen & Kommunikation.
- `/chat-data/{operation}` ist ein sessiongeschuetzter Webadapter mit CSRF,
  Throttle und exakt geprueftem ActiveTenant-Snapshot. Kein neuer JWT, kein
  frei waehlbarer Mandant, keine zweite App-API und kein technisches Sammelkonto.
- GET nur fuer feste lesende Operationen; POST fuer feste Schreibaktionen.
  Gesicherte Schreibtransaktionen nutzen denselben Mandantenlock wie die App.
  Chatlisten koennen vorhandene technische Mitgliedschaften bereitstellen;
  diese idempotente Initialisierung ist keine fachliche Sendeberechtigung.
- Verschluesselte Nachrichten, Anhaenge, Umfragen, Reaktionen, Meldungen,
  Lesestaende und Stummschaltung verwenden die vorhandenen gemeinsamen Tabellen.
- Chatbezogene Rechte sind von generischen Mobile-/Finanzrechten getrennt.
  Office bekommt keine Adminrolle. Profilzuordnungen werden nicht migriert.
- Leserecht allein oeffnet keine privaten Fremdunterhaltungen. Alte Tabs,
  Profilwiderruf und Vereinswechsel werden erneut geprueft. Simulation bleibt
  in der bestehenden expliziten Allowlist, keine neue Support-Ausnahme.
- Keine neuen Chat-E-Mails. Push entsteht ausschliesslich ueber ChatService und
  MobilePushOutbox innerhalb derselben Transaktion; geschlossene Flags bleiben zu.

## Bedienung

Gemeinsame Unterhaltungen, Kontakte, Text/Anhang/Umfrage/Termin, Bearbeiten und
Entfernen im bestehenden Fachvertrag, Reaktionen mit Namen/Pagination,
Meldungen/Moderation, Blockieren, Stummschalten und Uebersetzungsopt-in.
Vereinsankuendigungen erfordern eine aktuelle Empfaengervorschau/Fingerprint.
Geschlossene KI-/Uploaddienste werden nicht als verwendbar angeboten.

Nachrichten werden als Textknoten ausgegeben, nicht als Benutzer-HTML.
Private Downloads gehen durch denselben Anhangdienst; keine oeffentlichen
Blob-URLs. Entwuerfe bleiben nur im Arbeitsspeicher des aktuellen Browser-Tabs.
Unklarer Sendestatus behaelt eine eingefrorene client_id samt Nutzlast fuer
manuellen Retry, keine automatische Wiederholung einer Nachricht.
Delta-Abfragen pausieren bei unsichtbarem Tab, ohne neue WebSocket-Infrastruktur.

## Bisherige technische Tests

- MobileCommunication, ChatAccessPolicy und MobileChatDelta:60 Tests/968 Assertions.
- Bestehender MobileChatDeltaTest:62 Kernelchecks,16 SQL beim leeren Delta;
  synthetische PostgreSQL-Fixtures vollstaendig zurueckgerollt.
- WebChatTest gegen echte Webroutes mit PostgreSQL-Rollback: Versand/Replay,
  Edit/Reaktion/Umfrage, Nur-Lesen, fremder Direktchat, Kontextwechsel,
  Domainbindung, Ankuendigungsvorschau und separate Moderationsrechte.
  Abschliessende Testzahlen werden vor Aktivierung ergaenzt.
- Unit-Suite126 Tests/56028 Assertions.
- Rollen/Finanzen/Lesekontext/Direktkontakte:35 Regressionstests/372 Assertions;
  der echte Direktkontakt-Kernelprobe enthaelt272 Teilpruefungen mit Rollback.
- Releasearchiv:7 lokale Tests fuer exakte Dateiliste, Hash, Duplikate, Extras,
  Pfadtraversal sowie symbolische und harte Links; kein Datenbank-/Serverzugriff.
- DE/EN-Katalog:2668 Schluessel, kein historischer Literal-Rueckstand.
-14 Chrome-Browsertests mit synthetischem HTTP-Vertrag: Chatwechsel waehrend
  laufender Anfragen, Doppelbedienung, unveraenderter Retry, Upload-/Scanfehler,
  Vorschau, Einstellungen, Moderation, KI und DE/EN. Keine echten Nachrichten.
  Screenshots320/390/1280px unter lokalem TEMP/clubunio-web-chat-frontend geprueft;
  dies ist die isolierte Komponente, nicht die angemeldete Filament-Sitzung.

Diese Zahlen sind der Zwischenstand, kein Nachweis einer echten Geraetezustellung
oder einer angemeldeten Desktop-/Mobil-Sichtpruefung.

## Gezieltes Release

`tools/package-web-chat.py --revision <commit> --inventory` zeigt ausschliesslich
die feste gepruefte Git-Dateiliste. Nach Review mit `--reviewed-sha256` paketieren.
Serverbaseline wird vor jedem Overlay byteweise verglichen; fremde Serveraenderung
bricht das Paket ab. Keine uncommitteten Fremddateien, Secrets oder Laufzeitdaten.

`deploy/release-clubunio-web-chat.sh` trennt `--stage`, `--verify` und
`--activate-after-review`, jeweils mit exaktem SHA256SUMS-Hash. Kandidat ist eine
Kopie von pitch-v1 plus gepruefte regulaere Dateien; Symlinks/Traversal/Extras im
Archiv sind verboten. ENV, Composer und shared storage werden verglichen und
erhalten. Views werden release-lokal gecacht; keine Cachemutation der aktiven Seite.
Keine SQL-Migration, kein Seed, keine Datenauffrischung.

Zielrelease: `/var/www/svs-laravel/releases/20261104-clubunio-web-chat-v1`.
Rueckweg: `/var/www/svs-laravel/releases/20261104-clubunio-pitch-v1` durch geprueften
atomaren Symlinkwechsel und Reload des ClubUnio-PHP-FPM. Daten bleiben erhalten.
Nach Aktivierung HTTPS Health/Login/Assets und anonyme401/302 pruefen.

## Offen / nicht behauptet

- Angemeldete visuelle Abnahme in der echten ClubUnio-Sitzung; Benutzer aktuell
  ohne Zugriff. Synthetische Browser-Fixtures ersetzen diese Abnahme nicht.
- Firebase/Appregistry, eigene aktive Testgeraetebindung und FCM/APNs.
  Reale Pushnachrichten ausschliesslich an das freigegebene eigene Testkonto,
  nicht an Vereinsmitglieder. Betriebs-/Workeraktivierung getrennt abnehmen.
- Scanner-/KI-Provider nur nach vorhandener Konfiguration und Opt-in testen.
- Die Empfaengerliste mit Fingerprint gilt wie im bisherigen Vertrag fuer
  Vereinsankuendigungen. Eine zusaetzliche Team-/Staff-Empfaengervorschau ist
  eine offene Komforterweiterung; deren normale Gruppenunterhaltungen sind nutzbar.
- Keine Storeveroeffentlichung, SaaS-Einfuehrung oder PHPMaker-DEV-/PROD-Aenderung.
