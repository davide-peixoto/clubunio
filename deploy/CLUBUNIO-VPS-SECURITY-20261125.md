# VPS-Sicherheitsprüfung vom 25.11.2026

## Aktualisierung nach Bereitstellung

Stage und PROD sind gehärtet und aktiv geprüft; die nachfolgenden Angaben zur lokalen Vorbereitung beziehungsweise unveränderten Bestandsaufnahme beschreiben den früheren Prüfstand. Massgeblich für den Abschluss sind [Release, Nachweise und offene Go-live-Punkte](CLUBUNIO-SECURITY-RELEASE-20261125.md). Lokal zuletzt 161 Tests / 25'917 Assertions; echtes MFA-/Logout-Ende-zu-Ende auf beiden Instanzen bestanden. Automatisches externes Backup bleibt offen.


Read-only-Bestandsprüfung von `vps.clubunio.com`, parallel zur separat
beauftragten Stage-/PROD-Bereitstellung. Es wurden keine Serverkonfiguration,
Konten, Rollen, Dienste, Firewallregeln oder Sicherungen geändert und keine
Backups, Updates, Neustarts oder Restore-Vorgänge ausgelöst. Keine Kennwörter,
Tokens, privaten Schlüssel oder vollständigen Konfigurations-/Logdateien sind
in diesem Bericht enthalten. Beobachtungen sind eine zeitpunktbezogene Prüfung,
kein vollständiger Infrastruktur-Penetrationstest und keine Go-live-Freigabe.

## Ergebnis

**Für einen breiten Go-live bestehen wesentliche Betriebsrisiken.** Vorrangig
sind die PostgreSQL-Superuserrechte der Anwendung, die fehlende Dateiisolation
zwischen Stage und PROD sowie der fehlerhafte beobachtete Offsite-Backupjob.
MariaDB und SMTP sind trotz globalem Listener durch die geprüfte Hostfirewall
gegen neue externe Verbindungen gesperrt; ein offener Datenbankport wird nicht
behauptet.

| ID | Priorität | Nachgewiesener Zustand | Nächster Schritt |
| --- | --- | --- | --- |
| VPS01 | P0, sehr hohes Auswirkungspotenzial | Stage und PROD verwenden `userunio`; diese PostgreSQL-Rolle ist Superuser und darf Rollen/Datenbanken anlegen | Getrennte Runtime-Rollen ohne Superuser/Owner/DDL vorbereiten; benötigte Rechte gezielt ermitteln und vor Umstellung auf Stage testen |
| VPS02 | P1 | Beide FPM-Pools laufen als `runcloud`; beide `.env`-Dateien sind für diesen Benutzer lesbar; `open_basedir` ist leer | Getrennte Unix-Benutzer und Dateirechte je Umgebung; Worker, Cron, Sockets und private Speicher gemeinsam umstellen |
| VPS03 | P1 | Geplanter Borgjob meldet bis zum heutigen Lauf SSH-Authentifizierungs-/Verbindungsfehler; kein erfolgreicher aktueller Offsite-Nachweis | Zugang reparieren, tatsächliches Archiv und Rücklesbarkeit prüfen; isolierten vollständigen Restore durchführen |
| VPS04 | P1 | Öffentlich erreichbares SSH erlaubt effektiv Root- und Passwortanmeldung; Root besitzt ein gesetztes Passwort | Zuerst zweiten getesteten Schlüssel-/Sudo- und Konsolenzugang sichern, danach Passwort-/direkten Rootzugang abgestimmt reduzieren |
| VPS05 | P1 vor Freigabe | Neustart erforderlich; laufender Kernel älter als installierter Neustartkandidat; weitere Paketupdates vorhanden | Wartungsfenster, Sicherung und Rückweg; Updates nach Herkunft/Sicherheitsrelevanz bewerten, Neustart und Dienstabnahme |
| VPS06 | P2 | RunCloud-Port34210 ist öffentlich erreichbar und ohne Quell-IP-Beschränkung in der Hostfirewall freigegeben | Erforderliche RunCloud-Zugänge anhand Herstellerbetrieb prüfen und möglichst begrenzen; keine blinde Sperre |

P0/P1 beschreiben die Reihenfolge der Risikobehandlung. Es wurde keine
Ausnutzung oder bestehende Kompromittierung nachgewiesen.

## Netz und Firewall

Erhoben mit `ss -lntup`, `nft list ruleset`, Firewalld-Status und dessen aktiver
Zone. Die aktive Standardzone `runcloud` akzeptiert TCP22/80/443/34210 und
UDP443 sowie benötigten IPv6-DHCP-Verkehr. Nach den geprüften Input-Regeln werden
übrige neue externe Verbindungen zurückgewiesen. Die ACCEPT-Policy der separaten
iptables-Fail2ban-Tabelle hebt den nachfolgenden Firewalld-Filter nicht auf.

| Dienst | Listener | Wirksamer Nachweis |
| --- | --- | --- |
| SSH | IPv4/IPv6 Port22 | Firewall erlaubt; TCP-Verbindung vom lokalen Prüfcomputer erfolgreich |
| Web/HTTP3 | Port80/443 TCP,443 UDP | Firewall erlaubt; HTTPS-Antworten geprüft |
| RunCloud-Agent | Port34210 | Firewall erlaubt; externer TCP-Verbindungsaufbau erfolgreich |
| MariaDB | `0.0.0.0:3306` | Firewall erlaubt Port nicht; externer TCP-Versuch innerhalb3s ohne Verbindung |
| SMTP | IPv4/IPv6 Port25 | Firewall erlaubt Port nicht; externer TCP-Versuch innerhalb3s ohne Verbindung |
| PostgreSQL | `127.0.0.1:5432` | Nur Loopback; zusätzlich lokale Peer-/Loopback-SCRAM-Regeln in `pg_hba.conf` |
| Keycloak | Loopback Port8180 | Kein unmittelbar öffentlicher Java-Listener; HTTPS über Reverseproxy |
| pgAdmin/Gunicorn | `127.0.0.1:5050` | Nur Loopback; der veröffentlichte Reverseproxy-Zugang wurde nicht authentifiziert geprüft |

Timeouts allein beweisen keine Firewallwirkung. Hier stimmen sie mit den
gelesenen aktiven Regeln überein. Kein vollständiger Portscan, kein externer
IPv6-Test und kein Cloud-/Provider-Firewallaudit. Kein AAAA-Eintrag für den
geprüften VPS-Host beobachtet. MariaDB/SMTP auf Loopback zu begrenzen wäre
zusätzliche Absicherung, muss aber vorhandene Dienste berücksichtigen.

## Anmeldung und Betriebssystem

`sshd -T` und ein Match-Kontext für Root lieferten `PermitRootLogin yes`,
`PasswordAuthentication yes`, `PubkeyAuthentication yes`,
`AuthenticationMethods any`, `PermitEmptyPasswords no`,
`KbdInteractiveAuthentication no`, `MaxAuthTries6`, `LoginGraceTime120`.
`passwd -S root` meldete Status`P`; keine Kennwortanmeldung wurde versucht.
`AllowTcpForwarding yes` und `X11Forwarding yes` sind ebenfalls aktiv und sollten
nach tatsächlichem Verwaltungsbedarf bewertet werden.

Fail2ban läuft mit Jails für SSH, SSH-DDoS und RunCloud-Agent. Es wurden bereits
greifende Sperrzähler beobachtet. Fail2ban ersetzt keine starke
Administrationsauthentifizierung. Kein unbekannter zusätzlicher UID0-Account
beobachtet; reguläre Shellkonten sind Root und RunCloud. Die Sudo-Gruppe war leer.

Ubuntu24.04.5 LTS, laufender Kernel `6.8.0-149-generic`; `reboot-required` nennt
`linux-image-6.8.0-151-generic` und `linux-base`. Die installierte Plattform ist
damit nicht allein wegen der LTS-Version ausserhalb der regulären Wartung:
[Ubuntu-LTS-Lebenszyklus](https://ubuntu.com/about/release-cycle).

Vorhandene APT-Metadaten wurden heute aktualisiert. Unattended-Upgrades ist aktiv;
Security-Origin und tägliche Aktualisierung/Installation sind konfiguriert.
Der vorhandene APT-Check meldete13 sofort anwendbare Updates sowie4 zusätzliche
ESM-Apps-Sicherheitsupdates bei nicht aktiviertem ESM Apps. Die sichtbaren
normalen Kandidaten betreffen MariaDB/Galera, Redis und Node.js. Das ist kein
Nachweis, dass alle13 Updates Sicherheitslücken beheben; für Drittanbieterquellen
ist deren konkreter Sicherheitshinweis separat zu prüfen. Kein `apt update`
oder Paketupgrade wurde ausgelöst.

Beobachtete Laufzeitstände: PostgreSQL16.15, PHP8.3.37,
OpenSSH-Paket9.6p1/Ubuntu13.20, ClamAV-Daemon1.5.4. AppArmor, ClamAV-Daemon und
Freshclam waren aktiv; `systemctl --failed` meldete keine fehlgeschlagene Unit.
Keine vollständige Paket-CVE-/Kernel-Livepatchprüfung.

## Benutzer-, Datei- und Datenbankisolation

Stage und PROD besitzen unterschiedliche FPM-Sockets mit Modus660 für
`runcloud-www`, führen PHP aber beide als `runcloud:runcloud` aus. Beide
Projektverzeichnisse sind755 und gehören RunCloud, beide Storage-Verzeichnisse
775. Die `.env`-Dateien sind600, gehören aber demselben Runtime-Benutzer.
`test -r` als RunCloud bestätigt die Lesbarkeit beider Dateien, ohne Inhalte
auszugeben. Die FPM-Pools setzen `open_basedir` leer; in den geprüften
Extra-Dateien wurde keine abweichende Einschränkung gefunden.

Ein kompromittierter Stage-Prozess könnte daher auch PROD-Konfiguration und
Dateien unter demselben Unix-Benutzer lesen bzw. verändern. Getrennte
Datenbanknamen allein beheben diese Grenze nicht. `open_basedir` wäre ergänzend,
kein Ersatz für getrennte Benutzer und Rechte.

Aus den gecachten Runtime-Konfigurationen wurde ausschliesslich der nicht geheime
DB-Benutzername gelesen: Stage und PROD verwenden `userunio`. Read-only-Abfragen
von `pg_roles` bestätigen für diese Rolle `rolsuper`, `rolcreatedb` und
`rolcreaterole`. Sie ist zudem Owner der ClubUnio-Datenbanken. Die separate
Keycloak-Rolle hat keine dieser privilegierten Rolleneigenschaften.

Damit kann ein SQL-/Appkompromiss der ClubUnio-Runtime über den erwarteten
Vereins-/Umgebungsscope hinaus auf andere Datenbanken und privilegierte
PostgreSQL-Funktionen übergreifen. Es wurde kein entsprechender Zugriff oder
Exploit ausgeführt. Vor einer Rechteänderung müssen auch Queues, Scheduler,
Migrationen und Integrationsjobs mit einer begrenzten Stage-Runtime geprüft
werden; Owner-Migration und Runtime-Zugang sind zu trennen.

Keycloak läuft als eigener Benutzer`keycloak`, mit `PrivateTmp=yes` und
`NoNewPrivileges=yes`. Die Environment-Datei ist Root600 und für RunCloud nicht
lesbar. `ProtectSystem=no` und `ProtectHome=no` lassen zusätzliche
Systemd-Härtung offen. Die öffentlich lesbare allgemeine Keycloak-Konfigurationsdatei
enthielt bei selektiver Schlüsselprüfung keine konfigurierten
Passwort-/Secret-/Tokenwerte. Es wurden keine Secretwerte ausgegeben.

## Sicherungen und Wiederherstellung

Root-Cron startet täglich um01:35 Serverzeit `dbbackup.sh` und um01:45
`backup-borg.sh`. Journal-Metadaten bestätigen die Starts am23.,24. und25.11.
Ein Cronstart ist kein Erfolgsnachweis.

- Der DB-Backupjob verwendet MySQL-/MariaDB-Dumpwerkzeuge.35 lokale Dumpdateien
  wurden beobachtet, jüngster Änderungszeitpunkt25.11.2026 01:35 UTC.
- Der Borgjob verwendet `borg create`/`prune`, SSH und eine
  Verschlüsselungseinstellung; beobachtete Quellpfade schliessen
  `/home/runcloud` und `/home/dbbackups` ein. Es wurde kein Archivlisting,
  `borg check`, Remotejob oder Rücklesetest ausgelöst.
- Das Borglog ist bis25.11.2026 01:45 UTC aktuell. Seine letzten45 Zeilen
  enthalten zwölf Permission-denied- und fünf Connection-closed-Treffer;
  konkret ist `Permission denied (publickey,password)` vorhanden.
  Keine jüngeren erfolgreichen Archivmarker in diesem Ausschnitt beobachtet.
- In den geprüften Cron-/Timerdefinitionen und diesen beiden Backupskripten
  wurde kein geplanter PostgreSQL-Dump oder Keycloak-spezifischer Backupablauf
  nachgewiesen. Das beweist nicht die Abwesenheit sämtlicher Hoster-/externer
  Sicherungen; dafür fehlen bestätigte Nachweise.
- Private Release-Sicherungen mit verschlüsselten PostgreSQL-Dumps und
  Root600-Dateien sind vorhanden. Sie sind ereignisbezogene Sicherungen und
  belegen keinen funktionierenden laufenden Offsiteplan oder vollständigen Restore.

Vor Freigabe erforderlich: tatsächliche aktuelle Offsitearchive nachweisen,
PostgreSQL inklusive Keycloak sowie Dokumente und notwendige Schlüssel sicher
abdecken, Aufbewahrung/Überwachung festlegen und isolierte Wiederherstellung
mit gemessener Wiederanlaufzeit durchführen. Den Borgfehler nicht durch
Abschalten von Host-/Schlüsselprüfung umgehen.

## HTTPS und öffentliche Dateigrenzen

Die geprüften Nginx-Domaindateien für Stage, PROD und Auth erlauben TLS1.2/1.3.
HTTPS-Antworten von Stage/Auth enthalten HSTS und `nosniff`; Stage zusätzlich
`X-Frame-Options: SAMEORIGIN`. Auth liefert teilweise doppelte Header durch
Anwendung und Proxy; eine spätere Bereinigung ist sinnvoll. Kein vollständiges
CSP-, Cipher-, Zertifikatsketten- oder Browser-Sitzungsaudit dieses Infrastrukturteils.

HEAD-Anfragen auf `/.env` und `/.git/config` liefern auf
`stage.clubunio.com` und `app.clubunio.com` jeweils403. Es wurden keine Inhalte
heruntergeladen. Das ist ein gezielter positiver Nachweis, keine pauschale
Abnahme aller sensitiven Dateipfade oder virtuellen Hosts.

## Abschluss und Freigabegrenzen

Kein sofortiger Eingriff am geteilten VPS aus diesem Audit. Datenbankrollen,
Unix-Isolation, Backupzugang und SSH dürfen erst mit konkretem Rückweg und
getestetem Administrationszugang umgestellt werden. Die parallel erfolgende
Anwendungsbereitstellung beseitigt diese Infrastrukturpunkte nicht automatisch.

Dieser Bericht prüft keine anderen Anwendungen auf dem VPS, keinen Mailrelay,
keine Benutzerkennwörter, keine Providerkonten und keine vorhandene
Kompromittierung forensisch. Rohlogs, IP-Sperrlisten und Zugangsdaten sind nicht
Bestandteil des versionierbaren Berichts. Ein externer unabhängiger Test und die
ausstehenden Betriebsnachweise bleiben erforderlich.
