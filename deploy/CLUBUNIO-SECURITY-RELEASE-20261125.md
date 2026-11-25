# ClubUnio: Sicherheitsrelease Stage, PROD und VPS – 25.11.2026

Stage und PROD sind aktualisiert und wieder erreichbar. DEV auf dem Pi wurde durch dieses Arbeitspaket nicht verändert. Dies ist eine technische Sicherheitsprüfung mit gezielten aktiven Tests und Korrekturen, kein unabhängiges Penetrationstest-Zertifikat.

## Anwendung und Identität

Sieben gezielte Composer-Sicherheitsupdates, verpflichtende Malwareprüfung und frische Web-MFA sind aktiv. Die servereigenen Passkey-/Portal-Erweiterungen wurden beim Abgleich erhalten; das parallele Clubhaus-Modul wurde nicht ausgeliefert. Die produktiven Composer-Audits melden keine Advisories.

Die additive Migration `2026_11_25_010000_keycloak_logout_watermarks` ist auf beiden Instanzen journalisiert. Im Laufzeitschema `clubunio` existieren persistente Widerrufsmarkierungen und eigene Replay-/Lock-Tabellen. Vier bestehende Vereins-/Plattform-Clients liefern Backchannel-Logout an die jeweilige kanonische Instanz. Bei den zwei PROD-Clients musste zusätzlich Frontchannel-Logout deaktiviert werden, damit Keycloak tatsächlich serverseitig zustellt.

Echte HTTPS-Anmeldungen mit synthetischem Konto, Code/PKCE und TOTP bestätigten frische MFA auf allen vier Clients. Je Umgebung wurde nachgewiesen: Sitzung HTTP 200, tatsächlicher zentraler Logout, alte Sitzung HTTP 401 und alter Webnachweis ungültig, erneute MFA-Anmeldung HTTP 200. Temporärer Serviceadministrator, Testkonto, lokale Testdatensätze und geheime Prüfdateien wurden entfernt. Keine echten Zahlungen oder Testnachrichten.

## VPS und Trennung

Stage und PROD laufen unter getrennten Unix-Benutzern und PostgreSQL-Anwendungsrollen ohne Superuser-, Rollenanlage-, Datenbankanlage- oder dauerhafte DDL-Rechte. HBA-Regeln verweigern Zugriffe auf die andere Anwendungsdatenbank und Keycloak. Code ist root-eigen; nur private Laufzeitverzeichnisse sind beschreibbar. Wallet-/Push-Zugänge bleiben pro Instanz lesbar. Historische Sicherungen mit alten privilegierten Zugangsdaten wurden aus den Laufzeitverzeichnissen in root-private Quarantäne verschoben. Künftige KI-Release-Sicherungen werden dort ebenfalls root-privat abgelegt.

Root-SSH akzeptiert ausschliesslich Schlüssel; ein neuer unabhängiger Schlüsselzugang wurde nach der Änderung geprüft. Nicht-Root-SSH und Firewall wurden nicht pauschal umgestellt. Der alte DB-Superuser wurde wegen weiterer möglicher Verbraucher nicht global verändert; die beiden geprüften Anwendungen verwenden ihn nicht mehr.

## Sicherung und Wiederherstellung

Vorher- und Nachher-Sicherungen sind verschlüsselt. Vollständige PostgreSQL-Wiederherstellungen in isolierte temporäre Datenbanken waren erfolgreich: Stage 368, PROD 268, Keycloak 101 Tabellen; Prüf-Datenbanken anschliessend entfernt.

Ein täglicher systemd-Job sichert verschlüsselt drei Datenbanken, globale Rollen, zwei Anwendungsstände, zwei private Uploadbestände sowie Identitäts-/Wallet-/Push- und Dienstkonfigurationen. Der aktuelle Satz umfasst neun verschlüsselte Dateien. Eine manuelle Kopie ausserhalb des VPS unter `C:\SVS-Private\clubunio-security-20261125` wurde mit allen SHA-256-Werten und entschlüsselten PostgreSQL-Dateiköpfen geprüft. Schlüsselmaterial liegt ausschliesslich privat, nicht im Repository.

Der stündliche lokale Prüfdienst kontrolliert 14 Betriebsgrenzen einschliesslich Backupalter, Rollen, Dateirechten und SSH. Beide Timer sind aktiv; letzte Ausführungen erfolgreich. Es gibt noch keine externe Alarmzustellung und keine automatische Aufbewahrungsbereinigung.

## Nachweise und Grenzen

- Lokal: 161 Tests / 25'917 Assertions, DE/EN-Kataloge und Composer-Audit erfolgreich. Reproduzierbar mit `tools/check-security-local.ps1`; optionale parallele Module werden nur geprüft, wenn vorhanden.
- Pro Umgebung: 43 native API-/PostgreSQL-Prüfungen mit Rollback, 15 echte FPM-Prüfungen einschliesslich sauberer Datei und EICAR-Antivirusnachweis sowie Datenbank-/DDL-Grenzen.
- Begrenzte anonyme HTTPS-Prüfungen auf Stage und PROD; keine Lasttests. Die angemeldeten synthetischen Prüfungen ersetzen keine vollständige fachliche Abnahme aller Rollen und Geräte.
- Bereinigte Ergebnisse und Quellhashes: [Evidence](evidence/security-release-20261125/). Der [Laufzeitpatch](security-runtime-20261125.patch) dokumentiert die bewahrten servereigenen Abweichungen; kein allgemeiner Neuinstallationspatch.
- Root-private Vorherstände und Rückweg unter `/root/clubunio-releases/security-20261125`. Widerrufsmarkierungen bei einem Rückbau erhalten; ein blindes Zurücksetzen könnte alte Sitzungen wieder gültig machen.

## Offene Go-live-Punkte

1. **Automatisches externes Backup weiterhin gestört.** Die Hetzner Storage Box lehnt den vorhandenen öffentlichen VPS-Schlüssel ab. Der Betreiber hat keinen alternativen autorisierten Zugang. Der Storage-Box-Eigentümer muss den bestehenden Schlüssel freigeben; danach automatischen Transport und Wiederherstellung erneut nachweisen. Die manuelle externe Kopie ersetzt diesen Dauerbetrieb nicht.
2. Geplanten VPS-Neustart für den bereits installierten Kernel und ausstehende Paketwartung koordinieren. Kein ungeplanter Neustart oder pauschales Paketupgrade auf dem gemeinsamen Host.
3. Externe Alarmierung, Backupaufbewahrung und Wiederherstellungsablauf als Betriebsprozess abschliessen. RunCloud-Portzugriff gezielt mit dessen Anforderungen abgleichen.
4. Unabhängigen Pentest vor breiter Freigabe sowie vollständige Rollen-/Mandanten- und Geräteabnahme einplanen. Ein echter paralleler Mehrprozess-PostgreSQL-Racetest ist noch nicht nachgewiesen.

Damit sind wesentliche konkrete Lücken behoben. Eine uneingeschränkte Go-live-Freigabe bleibt insbesondere wegen des fehlenden automatischen externen Backups offen.
