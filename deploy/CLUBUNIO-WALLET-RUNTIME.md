# Wallet: private Dateien im Webprozess

## Fehler und Ursache am22.11.2026

SV-Sissach-Ausweis auf Stage zeigte Wallet als nicht verfuegbar. Die reine
CLI-Pruefung ergab enabledtrue, AppleAvailabletrue und ein korrektes Vereinslogo.
Der tatsaechliche PHP-FPM-Prozess meldete dagegen AppleAvailablefalse und
GoogleAvailablefalse. CLI und FPM verwendeten PHP8.3.37; HTTP/2 war vorhanden.

Ursache war RunClouds `realpath_turbo.open_basedir`: Der Pool durfte nur das
Webappverzeichnis, PHP-Sessions und /tmp lesen. Die absichtlich ausserhalb des
Webroots aufbewahrten Apple-Zertifikate und Google-Dienstkontodateien waren dort
nicht enthalten. ApplePassBundle bzw. GoogleWalletClient schlossen deshalb die
Freigabe im Ausweis-DTO. Die fruehere erfolgreiche CLI-Abnahme hatte diese
Webbeschraenkung nicht abgedeckt.

## Gezielte Korrektur

In `/etc/php83rc/fpm.d/ClubUnioStage.conf` und `ClubUnioProd.conf` jeweils nur
die zwei zur jeweiligen Umgebung gehoerenden privaten Ordner an die vorhandene
`php_admin_value[realpath_turbo.open_basedir]`-Liste angehaengt:

| Pool | Apple Wallet | Google Wallet |
|---|---|---|
| ClubUnioStage | /home/runcloud/wallet/stage | /home/runcloud/google-wallet/stage |
| ClubUnioProd | /home/runcloud/wallet/prod | /home/runcloud/google-wallet/prod |

Keine uebergeordnete Freigabe von /home/runcloud, kein Abschalten von open_basedir,
keine fremde Umgebung, keine Schluessel im Webroot und keine Rechteerweiterung der
privaten Dateien. `php_admin_value[open_basedir]` und alle uebrigen Pooloptionen
erhalten. Keine App-/Rollen-/Mitgliedsdaten oder Featureflags geaendert.

Vorherige Pooldateien privat gesichert unter
`/root/wallet-fpm-readiness-fix-20261122-225353`.
`/RunCloud/Packages/php83rc/sbin/php-fpm -t` erfolgreich, anschliessend
`systemctl reload php83rc-fpm`, Dienst active. Keine Anwendungsmigration.

## Nachweis im wirklichen FPM-Prozess

Direkter FastCGI-Aufruf ueber die jeweiligen lokalen Unix-Poolsockets, mit
kurzlebiger privater Diagnosedatei unter storage/app und gebootetem HTTP-Kernel.
Keine oeffentliche Diagnoseroute, kein Browser-Login-Bypass. Diagnosedateien nach
jedem Lauf entfernt; abschliessend null verbliebene wallet-readiness-Dateien.

Auf Stage einschliesslich Vereinslogo/Capabilities fuer Mandant3 und auf PROD
ohne echten Mitgliederdatensatz geprueft:

- SAPI fpm-fcgi, AppleAvailabletrue, GoogleAvailabletrue.
- Echte Apple-Zertifikate im FPM-Prozess gelesen und synthetischen PKPass gebaut.
- Kein echter Mitgliederausweis an einen Provider gesendet, keine APNs-Zustellung,
  keine Datenbank-Testzeilen angelegt.
- HTTPS /up200 und unauthentifizierter Mobile-context401 auf Stage, der
  SVS-Stage-Customdomain und PROD nach Reload nachgewiesen.

Dies ersetzt keine persoenlich angemeldete Wallet-Geraeteabnahme. Die App laedt
den Ausweis beim erneuten Oeffnen frisch und uebernimmt dadurch die Freigabe.
Fuer diese Serverkorrektur ist kein neuer App-Build erforderlich. Der iOS-Button
steht im Abschnitt Export unter dem Teilen-Button.

## Betrieb und Rueckweg

RunCloud verwaltet die Pooldateien. Bei einer Neuerzeugung oder PHP-Umstellung
die zwei privaten umgebungsspezifischen Pfade in der resultierenden Konfiguration
erhalten und erneut im FPM-Prozess pruefen. Ein CLI-Pass allein ist kein
Freigabenachweis mehr. Keine gesamten Pooldateien aus alten Sicherungen ueber
inzwischen geaenderte RunCloud-Konfigurationen kopieren.

Rueckweg: ausschliesslich die zwei hinzugefuegten privaten Pfade entfernen,
FPM-Konfiguration pruefen und graceful neu laden. Dadurch ist Wallet im Webprozess
wieder gesperrt; Daten, Schluessel und bereits ausgegebene Paesse bleiben erhalten.
Googles allgemeine Publishingfreigabe bleibt unabhaengig davon ausstehend.
