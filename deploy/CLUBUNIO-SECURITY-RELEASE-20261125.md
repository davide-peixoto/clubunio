# ClubUnio: Sicherheitsrelease Stage, PROD und VPS â€“ 25.11.2026

## Google Drive Dauerbetrieb bestätigt am 26.11.2026

Der Kontoinhaber hat den Google-OAuth-Status auf **In production** umgestellt. Danach wurde der bestehende Zugriff mit unverändertem Scope `drive.file` neu autorisiert. Die neue Tokenantwort enthält keine siebentägige Refresh-Laufzeit. Zusätzlich wurde auf dem VPS ein echter Refresh gegen Googles Token-Endpunkt erfolgreich ausgeführt und der damit erneuerte Zugang installiert. Die frühere Testmodus-Warnung ist damit erledigt; Widerruf oder andere Google-Kontoregeln können den Zugang weiterhin beenden.

Google-Timer täglich 01:40 UTC plus bis zwei Minuten Verzögerung (Schweiz im Winter 02:40, im Sommer 03:40). Der Upload erstellt getrennte verschlüsselte Sicherungssätze, liest alle Dateien zur SHA-256-Prüfung zurück und löscht keine bestehenden Drive-Dateien. DEV-, Stage-, PROD- und Keycloak-Datenbanken sind enthalten; die vollständige Testwiederherstellung direkt aus Google Drive ist dokumentiert. Der Schlüssel bleibt getrennt vom Drive-Backup. Der lokale stündliche Prüfdienst kontrolliert frische geprüfte Google-Sätze und den Timer. Hetzner-Borg bleibt beim Betreiber; externe Alarmierung und automatische Aufbewahrungsbereinigung bleiben separate Betriebsaufgaben.

Die folgenden Abschnitte dokumentieren die Einrichtung einschliesslich inzwischen erledigter Zwischenschritte. Massgeblich für den Google-Zugang ist dieser Abschlussstand.


Stage und PROD sind aktualisiert und wieder erreichbar. DEV auf dem Pi wurde durch dieses Arbeitspaket nicht verÃ¤ndert. Dies ist eine technische SicherheitsprÃ¼fung mit gezielten aktiven Tests und Korrekturen, kein unabhÃ¤ngiges Penetrationstest-Zertifikat.

## Anwendung und IdentitÃ¤t

Sieben gezielte Composer-Sicherheitsupdates, verpflichtende MalwareprÃ¼fung und frische Web-MFA sind aktiv. Die servereigenen Passkey-/Portal-Erweiterungen wurden beim Abgleich erhalten; das parallele Clubhaus-Modul wurde nicht ausgeliefert. Die produktiven Composer-Audits melden keine Advisories.

Die additive Migration `2026_11_25_010000_keycloak_logout_watermarks` ist auf beiden Instanzen journalisiert. Im Laufzeitschema `clubunio` existieren persistente Widerrufsmarkierungen und eigene Replay-/Lock-Tabellen. Vier bestehende Vereins-/Plattform-Clients liefern Backchannel-Logout an die jeweilige kanonische Instanz. Bei den zwei PROD-Clients musste zusÃ¤tzlich Frontchannel-Logout deaktiviert werden, damit Keycloak tatsÃ¤chlich serverseitig zustellt.

Echte HTTPS-Anmeldungen mit synthetischem Konto, Code/PKCE und TOTP bestÃ¤tigten frische MFA auf allen vier Clients. Je Umgebung wurde nachgewiesen: Sitzung HTTP 200, tatsÃ¤chlicher zentraler Logout, alte Sitzung HTTP 401 und alter Webnachweis ungÃ¼ltig, erneute MFA-Anmeldung HTTP 200. TemporÃ¤rer Serviceadministrator, Testkonto, lokale TestdatensÃ¤tze und geheime PrÃ¼fdateien wurden entfernt. Keine echten Zahlungen oder Testnachrichten.

## VPS und Trennung

Stage und PROD laufen unter getrennten Unix-Benutzern und PostgreSQL-Anwendungsrollen ohne Superuser-, Rollenanlage-, Datenbankanlage- oder dauerhafte DDL-Rechte. HBA-Regeln verweigern Zugriffe auf die andere Anwendungsdatenbank und Keycloak. Code ist root-eigen; nur private Laufzeitverzeichnisse sind beschreibbar. Wallet-/Push-ZugÃ¤nge bleiben pro Instanz lesbar. Historische Sicherungen mit alten privilegierten Zugangsdaten wurden aus den Laufzeitverzeichnissen in root-private QuarantÃ¤ne verschoben. KÃ¼nftige KI-Release-Sicherungen werden dort ebenfalls root-privat abgelegt.

Root-SSH akzeptiert ausschliesslich SchlÃ¼ssel; ein neuer unabhÃ¤ngiger SchlÃ¼sselzugang wurde nach der Ã„nderung geprÃ¼ft. Nicht-Root-SSH und Firewall wurden nicht pauschal umgestellt. Der alte DB-Superuser wurde wegen weiterer mÃ¶glicher Verbraucher nicht global verÃ¤ndert; die beiden geprÃ¼ften Anwendungen verwenden ihn nicht mehr.

## Sicherung und Wiederherstellung

Vorher- und Nachher-Sicherungen sind verschlÃ¼sselt. VollstÃ¤ndige PostgreSQL-Wiederherstellungen in isolierte temporÃ¤re Datenbanken waren erfolgreich: Stage 368, PROD 268, Keycloak 101 Tabellen; PrÃ¼f-Datenbanken anschliessend entfernt.

Ein tÃ¤glicher systemd-Job sichert verschlÃ¼sselt drei Datenbanken, globale Rollen, zwei AnwendungsstÃ¤nde, zwei private UploadbestÃ¤nde sowie IdentitÃ¤ts-/Wallet-/Push- und Dienstkonfigurationen. Der aktuelle Satz umfasst neun verschlÃ¼sselte Dateien. Eine manuelle Kopie ausserhalb des VPS unter `C:\SVS-Private\clubunio-security-20261125` wurde mit allen SHA-256-Werten und entschlÃ¼sselten PostgreSQL-DateikÃ¶pfen geprÃ¼ft. SchlÃ¼sselmaterial liegt ausschliesslich privat, nicht im Repository.

Der stÃ¼ndliche lokale PrÃ¼fdienst kontrolliert 14 Betriebsgrenzen einschliesslich Backupalter, Rollen, Dateirechten und SSH. Beide Timer sind aktiv; letzte AusfÃ¼hrungen erfolgreich. Es gibt noch keine externe Alarmzustellung und keine automatische Aufbewahrungsbereinigung.

## Nachweise und Grenzen

- Lokal: 161 Tests / 25'917 Assertions, DE/EN-Kataloge und Composer-Audit erfolgreich. Reproduzierbar mit `tools/check-security-local.ps1`; optionale parallele Module werden nur geprÃ¼ft, wenn vorhanden.
- Pro Umgebung: 43 native API-/PostgreSQL-PrÃ¼fungen mit Rollback, 15 echte FPM-PrÃ¼fungen einschliesslich sauberer Datei und EICAR-Antivirusnachweis sowie Datenbank-/DDL-Grenzen.
- Begrenzte anonyme HTTPS-PrÃ¼fungen auf Stage und PROD; keine Lasttests. Die angemeldeten synthetischen PrÃ¼fungen ersetzen keine vollstÃ¤ndige fachliche Abnahme aller Rollen und GerÃ¤te.
- Bereinigte Ergebnisse und Quellhashes: [Evidence](evidence/security-release-20261125/). Der [Laufzeitpatch](security-runtime-20261125.patch) dokumentiert die bewahrten servereigenen Abweichungen; kein allgemeiner Neuinstallationspatch.
- Root-private VorherstÃ¤nde und RÃ¼ckweg unter `/root/clubunio-releases/security-20261125`. Widerrufsmarkierungen bei einem RÃ¼ckbau erhalten; ein blindes ZurÃ¼cksetzen kÃ¶nnte alte Sitzungen wieder gÃ¼ltig machen.

## Offene Go-live-Punkte

1. **Automatisches externes Backup weiterhin gestÃ¶rt.** Die Hetzner Storage Box lehnt den vorhandenen Ã¶ffentlichen VPS-SchlÃ¼ssel ab. Der Betreiber hat keinen alternativen autorisierten Zugang. Der Storage-Box-EigentÃ¼mer muss den bestehenden SchlÃ¼ssel freigeben; danach automatischen Transport und Wiederherstellung erneut nachweisen. Die manuelle externe Kopie ersetzt diesen Dauerbetrieb nicht.
2. Geplanten VPS-Neustart fÃ¼r den bereits installierten Kernel und ausstehende Paketwartung koordinieren. Kein ungeplanter Neustart oder pauschales Paketupgrade auf dem gemeinsamen Host.
3. Externe Alarmierung, Backupaufbewahrung und Wiederherstellungsablauf als Betriebsprozess abschliessen. RunCloud-Portzugriff gezielt mit dessen Anforderungen abgleichen.
4. UnabhÃ¤ngigen Pentest vor breiter Freigabe sowie vollstÃ¤ndige Rollen-/Mandanten- und GerÃ¤teabnahme einplanen. Ein echter paralleler Mehrprozess-PostgreSQL-Racetest ist noch nicht nachgewiesen.

Damit sind wesentliche konkrete LÃ¼cken behoben. Eine uneingeschrÃ¤nkte Go-live-Freigabe bleibt insbesondere wegen des fehlenden automatischen externen Backups offen.

## Ergänzung: DEV-Datenbank ebenfalls täglich gesichert

Auf Wunsch ist `clubunio_dev` zusätzlich im täglichen verschlüsselten PostgreSQL-Backup enthalten. Die DEV-Anwendung läuft auf dem Pi, ihre PostgreSQL-Datenbank auf dem VPS (privater Tunnel). Der Job sichert jetzt DEV, Stage, PROD und Keycloak sowie globale Rollen; zusammen zehn verschlüsselte Dateien einschliesslich der bisherigen Wiederherstellungsmaterialien. Zeitplan unverändert täglich 01:20 UTC, bis zwei Minuten Verzögerung. Der Pi-Anwendungscode und dessen lokale Dateien sind dadurch nicht zusätzlich gesichert. Die automatische externe Borg-Übertragung bleibt Aufgabe des Betreibers und weiterhin unbestätigt.

Abnahme der Ergänzung: vollständige Wiederherstellung aller vier Datenbanken erfolgreich (DEV 358, Stage 368, PROD 268, Keycloak 101 Tabellen), temporäre Prüf-Datenbanken entfernt. Zehn verschlüsselte Dateien erneut ausserhalb des VPS kopiert und sämtliche Prüfsummen bestätigt; lokaler Überwachungsdienst erfolgreich.

## Google Drive Zusatzbackup vorbereitet

Persönliches Google Drive / Google One als unabhängiges zweites Offsite-Ziel angefordert. Ubuntu-rclone installiert (ein zusätzliches Paket, keine vorhandenen Pakete aktualisiert); root-eigener Uploaddienst und Timer auf dem VPS installiert, aber bis zur OAuth-Anmeldung und Erstabnahme deaktiviert.

`security-gdrive-backup.py` validiert den aktuellen vollständigen Sicherungssatz (höchstens 36 Stunden alt, vier Datenbankdumps, verschlüsselte Dateien, lokale SHA-256-Werte). Es kopiert ausschliesslich die im Manifest aufgeführten `.enc`-Dateien und ein Manifest ohne Schlüssel nach `clubunio-gdrive:ClubUnio-Backups/vps.clubunio.com/<UTC-Zeitstempel>`. Nach Upload werden sämtliche Dateien vollständig zurückgelesen und per SHA-256 verglichen. Erst danach entsteht die root-private Erfolgsmarkierung `/root/clubunio-backups/gdrive-latest.json`. Keine Löschung oder Synchronisation bestehender Drive-Inhalte; keine automatische Aufbewahrungsbereinigung. Der Entschlüsselungsschlüssel wird nicht hochgeladen und muss separat privat erhalten bleiben.

Zeitplan nach Aktivierung: täglich 01:40 UTC plus bis zwei Minuten Verzögerung, nach dem lokalen Backup um 01:20 UTC. Der Upload erfordert einen erfolgreich abgeschlossenen aktuellen lokalen Sicherungssatz. Lokale Prüfung des echten Zehndateien-Satzes und sechs synthetische Grenzprüfungen erfolgreich; systemd-Definitionen validiert. Noch kein Google-Upload oder Remote-Restore nachgewiesen.

### Einmalige Google Anmeldung

Ein eigener OAuth-Client ist vorgesehen, da rclone den gemeinsamen Client 2026 ausser Betrieb nimmt. Google Cloud Console: eigenes Projekt, Drive API aktivieren, Google Auth Platform für das persönliche Konto konfigurieren, Desktop-App-Client anlegen. Nur `https://www.googleapis.com/auth/drive.file` anfordern; damit wird Zugriff auf die von dieser Integration erstellten Dateien begrenzt. Für den Dauerbetrieb OAuth-Veröffentlichungsstatus und Tokenlebensdauer prüfen; nicht dauerhaft mit kurzlebiger Testfreigabe betreiben. Keine ClubUnio-SaaS- oder Store-Veröffentlichung ist damit verbunden.

Die heruntergeladene Client-JSON ausschliesslich privat unter `C:\SVS-Private\clubunio-security-20261125\google-drive-oauth.json` bereitstellen. OAuth im Browser durch den Kontoinhaber; Tokens nicht im Chat ausgeben. Zielkonfiguration auf dem VPS: `/root/.config/rclone/clubunio-backup.conf`, root 0600, Remote `clubunio-gdrive`, Typ `drive`, Scope `drive.file`. Nach Verbindung zuerst manuellen Upload und vollständige Rückleseprüfung ausführen, unabhängige Wiederherstellung aus dem Drive-Satz nachweisen und erst dann `clubunio-gdrive-backup.timer` aktivieren. Externe Überwachung/Alarmierung danach ergänzen. Hetzner-Borg bleibt unverändert beim Betreiber.

Quellen: [rclone Google Drive](https://rclone.org/drive/), [Remote-Anmeldung](https://rclone.org/commands/rclone_authorize/), [Google Drive Berechtigungen](https://developers.google.com/workspace/drive/api/guides/api-specific-auth).

## Google Drive Erstabnahme am 26.11.2026

Der separate Desktop-OAuth-Client im bestehenden Google-Projekt ClubUnio ist verbunden, mit ausschliesslich `drive.file` und root-privater Konfiguration. Zehn verschlüsselte Dateien des Satzes `20261126T012007Z` wurden nach Google Drive kopiert und vollständig zurückgelesen; sämtliche SHA-256-Werte stimmen. Kein Entschlüsselungsschlüssel wurde hochgeladen.

Alle vier PostgreSQL-Dumps wurden direkt aus Google Drive heruntergeladen, gegen das ursprüngliche lokale Manifest geprüft, im Arbeitsspeicher entschlüsselt und vollständig in isolierte temporäre Datenbanken wiederhergestellt: DEV 358, Stage 375, PROD 275, Keycloak 101 Tabellen. Prüf-Datenbanken anschliessend entfernt. Bereinigte Nachweise: `evidence/security-release-20261125/gdrive-upload-20261126.json` und `gdrive-restore-20261126.json`.

Der tägliche Google-Timer ist jetzt aktiv: 01:40 UTC plus bis zwei Minuten Verzögerung, im Schweizer Winter 02:40 Uhr. Der stündliche Prüfdienst kontrolliert zusätzlich frischen verifizierten Google-Satz, aktiven Timer und rechtzeitige OAuth-Erneuerung. Letzter Lauf erfolgreich. Hetzner-Borg bleibt unverändert beim Betreiber; dessen Fehler ist jetzt getrennt vom funktionierenden Google-Transport ausgewiesen.

**Noch offen für Dauerbetrieb:** Google-OAuth steht weiter auf Testing. Der tatsächlich ausgestellte Refresh-Token hat eine Laufzeit von knapp sieben Tagen. In Google Branding wurden die vorhandene öffentliche Homepage, Datenschutzseite und eigene Domain ergänzt; Publish app ist nun verfügbar. Der Kontoinhaber muss den OAuth-Produktionsstatus bestätigen. Das erlaubt OAuth-Anmeldungen ausserhalb der Testliste, gibt aber weder Backups frei noch veröffentlicht es ClubUnio im Store. Danach eine neue Autorisierung durchführen und Tokenlebensdauer/Refresh prüfen; ein bereits befristet ausgestellter Testtoken gilt nicht als Dauerbetriebsnachweis. Der Prüfdienst warnt vor Testmodus und schlägt spätestens 48 Stunden vor Ablauf fehl. Noch keine externe Alarmzustellung oder automatische Lösch-/Aufbewahrungsregel eingerichtet.

Google beschreibt die siebentägige Testtoken-Laufzeit in der [OAuth-Dokumentation](https://developers.google.com/identity/protocols/oauth2).

Abschlusskontrolle nach Token-Erneuerung: kompletter Google-Upload mit Rückleseprüfung erneut erfolgreich; täglicher Timer aktiv und stündlicher Betriebscheck erfolgreich, ohne Testmodus-/Tokenablaufwarnung.
