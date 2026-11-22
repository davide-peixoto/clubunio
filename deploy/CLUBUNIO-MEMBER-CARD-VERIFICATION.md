# Vereinsausweis und öffentliche Liveprüfung

Stand22.11.2026. Ausweisgestaltung wird stets aus dem Vereinsdesign gelesen,
auch wenn die Core-App das ClubUnio-Theme verwendet. `SignageBrandService`
bleibt die maßgebliche Quelle für Farbe und Logo. Lokale Vereinslogos werden
als begrenzte PNG-Projektion (max512px/1MiB) geliefert; keine Abrufe beliebiger
externer Bildadressen. Fehlt ein verwendbares Logo, erscheinen Vereinsinitialen,
niemals das Logo des App-Builds. Die SVG-/Remote-Logoquelle bleibt im Backend
konfiguriert, wird aber für Ausweis/Wallet nicht serverseitig nachgeladen.

## Prüfung und Datenminimierung

Die App erhält eine persönliche, autorisierte Kartenprojektion und einen
stabilen QR-Link `/member-card/{64hex}`. Zufälliger Token mit256Bit; in der DB
nur SHA256 sowie eine separat verschlüsselte Wiederherstellung für den eigenen
Ausweis. Die URL enthält keine Mitglieds-/Mandanten-IDs oder Personendaten.
Die öffentliche Seite funktioniert ohne Anmeldung und ohne App auf jedem
Browser. Jeder Scan prüft die aktuellen Daten. Keine gespeicherte Aussage
„gültig“ als Beleg für einen späteren Zeitpunkt verwenden.

Gültig ist die Mitgliedschaft nur, wenn Status und Mitgliederart aktiv,
Status ausdrücklich `member_card_eligible=true`, nicht `ends_membership`,
Mitglied nicht archiviert, kein früheres Austrittsdatum und der Verein nicht
betrieblich gesperrt ist. Ein Austrittsdatum gilt bis Ende dieses Tages in
Europe/Zurich. Fehlende Beziehungen/Kontexte schließen die Berechtigung.
Die additive Migration initialisiert allein aktive, wiederherstellende
Mitgliederstatus (`restores_membership=true`, `ends_membership=false`) als
berechtigt; keine Ableitung aus Statusnamen oder altenIDs. Andere Status
bleiben gesperrt, bis sie ausdrücklich in der Statusverwaltung freigegeben sind.

Öffentlich sichtbar: Vereinsname/-logo, bei gültigem Ausweis Vor-/Nachname,
Gültigkeit und Prüfzeit. Keine Geburtsdaten, Kontakt-/Eltern-/Finanz-/AHVdaten
oder numerischen Datensatzschlüssel. Bei ungültigem Ausweis kein Personenname.
Unbekannter/widerrufener Token wird als ungültig angezeigt; ein abgeschalteter
oder gestörter Prüfdienst ausdrücklich als nicht verfügbar (503).
QR-Link als öffentliche Vorzeigeberechtigung behandeln und nicht in App-Logs
schreiben. Webserver-Zugriffslogs für `/member-card/*` ebenfalls minimieren.
Die Bestätigung sagt nichts über den konkreten Rabatt aus; Partnerbedingungen
und Abgleich des Namens mit der vorzeigenden Person bleiben maßgeblich.

`Cache-Control: private,no-store`, noindex/nofollow/noarchive, no-referrer,
kein JavaScript/externe Ressourcen, CSP und90Anfragen/Minute. Sichtbare
Prüfzeit plus Aktualisieren-Link; keine ständigen Pollingabfragen. Nur zentral
aktivierte Sprachen auswählbar, aktuell DE/EN.

## Ausgabe, Sperre und Wechsel

`member_cards` wird erstmals nach persönlicher Ausweisfreigabe idempotent
angelegt (einDatensatz jeVerein/Mitglied). Wiederholtes Lesen verändert weder
Token noch Version. `SourceVersion` umfasst Daten, Status, Design und
Kartenrevision, aber keine laufende Uhr/Prüfzeit. Damit bleiben die bestehenden
frischen Autorisierungs-/Exportkontrollen belastbar.

Private POST-Endpunkte `members/{member}/id-card/revoke` und `/rotate` unter
der Mobile-Vereinsroute verlangen frische persönliche Autorisierung und
`SourceVersion`. Widerruf macht den bestehenden QR-Link sofort ungültig;
ein GET gibt niemals stillschweigend einen neuen gültigen Token aus. Erst
explizites Drehen ersetzt den Token. Alte QR-Links bleiben dann ungültig.
Wallet verwendet dieselbe KartenID und dieselbe Liveprüfung, separate
Signier-/Aktualisierungsdetails im Wallet-Betriebsdokument.

## Einführung

Additive Migration `2026_11_22_000100_member_cards.php` zuerst gezielt auf die
freigegebene ClubUnio-Instanz anwenden. Zwei Teile: neues Status-Policyfeld
und `member_cards` mit zusammengesetzter Mitglieds-FK, Unique-Token und
Unique(Verein,Mitglied). Keine Bestandsdatenimporte oder Rollenveränderungen.
Danach `CLUBUNIO_MEMBER_CARD_VERIFICATION_ENABLED=true` und kanonische
HTTPS-Origin `CLUBUNIO_MEMBER_CARD_PUBLIC_ORIGIN` setzen. Private Tokens,
Schlüssel und Laufzeitdaten bleiben außerhalbGit. Mit
`CLUBUNIO_MEMBER_CARD_DISABLED_TENANTS=slug1,slug2` lassen sich einzelne Vereine
für die öffentliche Bestätigung sperren. Reine Karten-Gestaltung funktioniert
auch bei noch deaktivierter Liveprüfung; Wallet darf dann nicht ausgestellt werden.

Rückweg: Liveprüfung deaktivieren (503 statt grüner Altbestätigung), Karten/
Widerrufe erhalten. Kein automatisches DROP/Down. Keine PROD-/Storefreigabe.

## Prüfung

`MemberCardPolicyTest` prüft echte semantische Status-/Austritts-/Mandantenregeln
sowie stabile Versionen und kanonische Origins. `MemberCardPublicVerificationTest`
prüft DE/EN, unbekannt/gestört/deaktiviert, Datenminimierung und Sicherheitsheader.
`MemberCardIssuanceTest` ist eine opt-in PostgreSQL-Rückrollprüfung für Ausgabe,
Hashes/Verschlüsselung, Drehung, Widerruf, Statuswechsel und Mandantengrenzen;
Standardlauf überspringt sie ohne `CLUBUNIO_TEST_DB=1` und vorbereitete Migration.


## DEV/Stage-Bereitstellung und Abschluss am 22.11.2026

Beide additiven Migrationen 000100/000110 gezielt auf `clubunio_dev` und
`clubunio_stage` angewendet, mit vorhandener Laufzeit-Schemabezeichnung
`clubunio` und einzeln erteilten CRUD-/Sequenzrechten für die jeweilige Approlle.
Statusfreigabe ausschliesslich im neuen Feld initialisiert; keine Mitglieder,
Rollen oder Altbestände importiert. Vorprüfung zeigte keine Zielobjekte.
Private, mit pg_restore lesbar geprüfte Katalog-/Journal-Sicherung:
`/root/member-card-preflight-20261122-161424` auf dem VPS.

25 Dateideltas je Instanz bereitgestellt; vorhandene abweichende Stage-Login-
und Kampagnenscheduler-Konfiguration durch gezielte Einfügungen erhalten.
Quellrückweg DEV: `shared/member-card-wallet-release-20261122-172217`,
Stage: `/root/member-card-wallet-release-20261122-162221`.
Private Konfigurationsrückwege DEV:
`shared/member-card-wallet-config-20261122-172617`, Stage:
`/root/member-card-wallet-config-20261122-162619`.
Verifikation aktiv, kanonische Origins `https://dev.clubunio.ch` und
`https://stage.clubunio.com`. Kein PROD-/Store-Eingriff.

`deploy/check-member-card-wallet.php --synthetic-rollback <Datenbank>` besteht
auf beiden echten PostgreSQL-Instanzen: autorisierte Kartenprojektion samt
Vereinslogo/Wallet-Capability-Vertrag, stabile QR-Ausgabe, echte HTTP-Kernelprüfung
DE/EN, Statuswechsel ohne neuen Link, gesperrte Karten, explizite Neuausgabe,
private Daten nicht öffentlich, Vereinsgrenzen sowie Wallet-Revision/ApplePass-
Authentifizierung. Alle synthetischen Datensätze zurückgerollt; kein Apple-Aufruf.
Hierbei die abweichende DEV-Defaultverbindung entdeckt und alle neuen Raw-
Ausdrücke explizit an die ClubUnio-Verbindung gebunden.

Externes HTTPS auf beiden Instanzen: unbekannter QR ohne Login erreichbar,
korrekte DE/EN-Antwort, no-store/noindex und eindeutiger Ungültigstatus.
21 Backendtests/154 Assertions bestanden; drei opt-in PostgreSQL-PHPUnitfälle
standardmässig übersprungen, deren Kernabläufe durch obige Live-Rückrollprüfung
abgedeckt. DE/EN 3015 Schlüssel vollständig. Mobiler Gesamtlauf 1046 bestanden,
eine nur separat ausführbare Bildaufnahme übersprungen, separat erfolgreich;
Dartanalyse/i18n 2138 lokale Meldungen grün. Flutter-Ausweiskarte visuell geprüft.
Browser-Sichtprüfung hier nicht möglich (GetCursorPos: Zugriff verweigert).

Apple Wallet bleibt ohne Zertifikat ausdrücklich deaktiviert; Befehl auf
beiden Instanzen ergibt configured=false und sent=0. Native Swiftkompilierung,
Apple-signierter Pass, iPhone-Hinzufügen, Kamera-Scan und echte Wallet-Updates
stehen nach Zertifikatseinrichtung noch zur Abnahme an. Anleitung:
[Apple Wallet](CLUBUNIO-APPLE-WALLET.md).

Zusätzlich den konkreten Stage-Refresh-Ausweis mit vorhandener Berechtigung geprüft:
Vereinsrot und PNG-Logo, gültiger Status, stabile Version bei zwei frischen
Aufrufen. Sein QR-Ziel extern per HTTPS ohne Login in DE/EN erfolgreich geprüft;
keine Geburts-/Kontaktfelder in der öffentlichen Antwort. Keine Kontorechte
geändert.
