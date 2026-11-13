# Push auf DEV aktiviert

Stand05.11.2026, ausdrücklicher Auftrag «im dev push aktivieren».
Aktiver App-/Backendrelease bleibt20261105-clubunio-chat-image-compression;
keine Schema-/Runtimecode-/PHPMakeränderung. Ausführungssource2f5f41b.

## Aktive Konfiguration und Dienst

CLUBUNIO_MOBILE_PUSH_ENABLED=true und
CLUBUNIO_MOBILE_PUSH_DELIVERY_ENABLED=true. Exakte Registry unverändert:
com.my.clubunio.core, ios, https://dev.clubunio.ch, Core ohne festen Mandanten.
Serviceaccount/RSA/Projekt/Tokenziel strukturell geprüft, private Datei für
www-data lesbar; keine Credentials/Token in diesem Commit oder Toolausgaben.
Android bleibt mangels geprüfter eigener Registry/Clientkonfiguration geschlossen.

Konfigurationsvergleich nach Cacheaufbau bestätigt ausschliesslich diese beiden
Flagänderungen. Alle übrigen Werte inklusive privater Firebase-Registry und
bisherigem VIEW_COMPILED_PATH identisch. Private Sicherung der vorherigen ENV
und Cachekonfiguration unter shared/mobile-push-activation-backup-20261105,
Verzeichnis0700/Dateien0600. Sie wird nicht als Releaseartefakt/Gitdatei verteilt.

clubunio-dev-mobile-push.timer ist enabled+active; oneshot-Service läuft als
www-data, Ziel ist jeweils current/artisan. Alle60s nach Ende des letzten Laufs,
maximal10 Outboxeinträge pro Aufruf, kein paralleler Lauf (Unit+flock), Timeout420s.
Vorhandene Domain-/Kontorechte, Bindungsrevision, Sitzung und Opt-in werden vor
Versand frisch geprüft. Vorher keine Cron-/Timer-Doppelregistrierung vorhanden.

## Tatsächlicher Nachweis

Vor Freischaltung0 Geräte,0 Bindungen, leere Outbox. Nachher beide Flags true,
MobilePushReady für registrierten iOS-Client unter www-data=true; ohne benötigten
Featureheader bleibt Capability false. Timer enabled/active, Service Result=success,
ExecMainStatus=0, erster Lauf0 verarbeitet, wartender Bestand0. FPM aktiv;
HTTPSup200/context401. Ein synthetischer ENV-Versuch prüfte unveränderte fremde
Werte, eingebettete PHP-/Python-/Bashsyntax und systemd-Units wurden geprüft.

Keine Testnachricht oder künstliche Gerätebindung erzeugt. Echter FCM-/APNs-
Empfang ist noch nicht nachgewiesen: erst reale Anmeldung, OS-Zustimmung und
Tokenbindung aus dem nativen Appbuild ermöglichen eine Zustellungsabnahme.
OAuthauthentifizierung aus der Vorbereitung allein war kein Versandnachweis.

## App und Rückweg

Mit dem aktuellen Push-/Firebase-fähigen iOS-Build erneut mit Passwort anmelden.
Die neue Einrichtungsabfrage erscheint einmal pro Konto, sofern noch nicht
beantwortet; alternativ Profil > Mitteilungen einstellen aktivieren. App muss
CLUBUNIO_PERSONAL_PUSH=true und passende Firebase-Clientkonfiguration enthalten.
Alte/manuell abgelehnte Einrichtung wird nicht durch die Serverfreigabe erzwungen.
Das Einschalten sendet keine historische Pushflut: Fachquellen müssen aktive
Bindungen bzw. deren Erstellzeit und bestehende Rechte berücksichtigen.

Bei Rücknahme zunächst Timer deaktivieren, laufenden Worker kontrolliert beenden
bzw. auslaufen lassen; nur die beiden Pushflags wieder false setzen. Cache mit
bisherigem Viewpfad neu bauen, zwei-Flag-Diff prüfen und FPM neu laden. Private
Registry, übrige ENV-Werte, Geräte-/Outboxhistorie erhalten; kein Tabellen-Down
oder blindes Zurückspielen einer inzwischen veralteten gesamten ENV-Datei.

## Wiederaktivierung am 13.11.2026

Beide Push-Schalter und die bisherige private Firebase-Registry wurden in
der gemeinsamen DEV-Konfiguration wiederhergestellt. iOS-Core-Capability und
Service-Account-Datei sind unter www-data geprüft. Der dauerhafte Worker läuft
wieder, die alte Timer-Unit bleibt inaktiv. Die Release-Prüfung erkennt künftig
verlorene aktive Push-Schalter und fehlende bisher registrierte Apps. Empfang
auf einem iPhone wurde bei dieser Wiederaktivierung nicht geprüft.
