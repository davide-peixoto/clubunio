# Nginx: Wiederanlauf bei voruebergehend fehlender Adresse

## Anlass und gezielte Betriebsfreigabe

16.10.2026: Nginx scheiterte beim Neustart an der zeitweise nicht vorhandenen
Adresse192.168.3.157:8092. Ohne Restart-Regel blieb der Dienst failed, obwohl
das Netzwerk spaeter wieder verfuegbar war. Der Benutzer hat diesen konkreten
Betriebsschutz ausdruecklich beauftragt. Keine allgemeine Betriebsautomation,
API-/SaaS-Entwicklung oder Aenderung von PHPMaker DEV/PROD.

## Installierte Regel

Quelle: deploy/systemd/nginx.service.d/10-clubunio-recovery.conf.
Ziel: /etc/systemd/system/nginx.service.d/10-clubunio-recovery.conf auf dem Pi.
Paketdatei /usr/lib/systemd/system/nginx.service bleibt unveraendert.

- Restart=on-failure: Wiederholung bei fehlgeschlagenem Start inklusive
  nginx-Konfigurationspruefung in ExecStartPre und unerwartetem Fehlerende.
- RestartSec=10s: keine hektische Neustartschleife; zehn Sekunden Pause.
- StartLimitIntervalSec=0: automatische Wiederholung wird bei laengerem
  Adressausfall nicht dauerhaft durch die Startversuchsbegrenzung gesperrt.
- Ein ausdrueckliches systemctl stop bleibt gestoppt.

Die Adresse, listen-Direktiven, Proxy-Allowlist, Firewall und PHP bleiben
unveraendert. Kein Lauschen auf weiteren Interfaces, kein ip_nonlocal_bind,
kein Netzwerkneustart und kein neuer Cronjob. Die Regel betrifft den bestehenden
Nginx-Dienst mit seinen virtuellen Hosts, nicht einen neuen ClubUnio-Prozess.
Sie gilt nach daemon-reload fuer spaetere Fehler; laufender Nginx wurde fuer
die Installation nicht neu gestartet. Netzwerk-Online-Abhaengigkeit bleibt.

Grenzen: Bei weiter fehlendem Netzwerk bleibt der Zugriff bis zur Rueckkehr
unterbrochen. Nach einem Startfehler folgt der naechste Versuch normalerweise
nach zehn Sekunden plus Startdauer. Auch echte Konfigurationsfehler werden
wiederholt, aber nicht automatisch repariert. Sie bleiben im Journal sichtbar;
zur bewussten Wartung systemctl stop nginx verwenden. Dies ist kein HTTP-
Healthmonitor und behebt keine Datenbank-, PHP- oder externen Proxyfehler.

## Installation / Ruecknahme

deploy/install-nginx-recovery.sh als root mit zugehoeriger systemd-Unterstruktur
ausfuehren. Vorher nginx -t und aktiven Dienst pruefen. Vorhandene gleichnamige
Ergaenzung und zusammengefuehrte Dienstdefinition werden unter
/var/backups/clubunio-nginx-recovery/<UTC-Zeit>-<PID>/ gesichert. Anschliessend
daemon-reload, systemd-analyze verify und effektive Eigenschaften pruefen.
Keine Paket-Unit editieren und vorhandene fremde Drop-ins nicht ersetzen.

Ruecknahme: ausschliesslich die genannte Ergaenzung aus previous.conf der
passenden Sicherung wiederherstellen. Wenn previously-absent vorhanden ist,
nur diese einzelne neu erstellte Ergaenzung entfernen. Danach daemon-reload
und systemctl show nginx pruefen. Der laufende Dienst braucht dazu keinen
Neustart. Beim akuten Ausfall nach Ruecknahme nginx -t und systemctl start nginx.

## Sicherer Funktionstest

deploy/test-nginx-recovery.sh startet eine getrennte temporaere Systemd-Unit
clubunio-nginx-recovery-check.service mit derselben installierten Drop-in-Datei.
Sie verwendet eine minimale eigene Nginx-Konfiguration und Port18092:

1. Nicht vorhandene Testadresse198.51.100.254: echter ExecStartPre/nginx -t
   Fehler, danach automatisch mindestens ein weiterer Startversuch.
2. Nur die Testkonfiguration auf127.0.0.1 umstellen: Dienst wird ohne erneutes
   systemctl start automatisch aktiv; HTTP200 mit synthetischem Testtext.
3. Testdienst ausdruecklich stoppen und laenger als RestartSec warten:
   Zustand bleibt inactive. Temporaere Unit/Dateien werden gezielt entfernt.

Keine echten Interfaces/IPs entfernen oder verstellen, kein Ausfalltest am
produktiven Nginx. Belegte Testpfade/Ports fuehren zum Abbruch. Das bestehende
ClubUnio und seine Anhaenge/Daten werden nicht veraendert. Fehlversuche und
Wiederherstellung stehen weiterhin im Systemd-Journal. Installations- und
Testskript sind mit bash -n zu pruefen.

## Nachweis vom 16.10.2026

Beide Shellskripte mit bash -n und zusammengefuegte nginx.service mit
systemd-analyze verify erfolgreich geprueft. Effektive Pi-Einstellungen:
Restart=on-failure, RestartUSec=10s, StartLimitIntervalUSec=0.
Isolierter Test: Startfehler08:24:48 CEST, automatischer Fehlversuch08:24:58,
automatisch aktiv08:25:08 nach Umstellung nur der Testkonfiguration. Test-HTTP200.
Expliziter Stop08:25:09 blieb nach weiteren zwoelf Sekunden inactive.
Temporaere Unit und Dateien entfernt; Journalnachweis bleibt erhalten.
Bestehender Nginx unveraendert seit08:18:56 aktiv. Oeffentlicher Login und /up
abschliessend HTTP200. Installierte Drop-in-Datei SHA256 identisch mit Git-Quelle:
9b098b66516ea759ecfb794f0dc2190ae5103e35aabc16b8680e0bbd30df82a3.
Keine Datenbank-/PHP-Tests erforderlich: keine Anwendungslogik geaendert.
