# Umgebungswahl und Status der Mitgliedsanmeldung

ClubUnio bietet vor dem Login eine Umgebungswahl über das Zahnrad. Dev zeigt
auf `https://dev.clubunio.ch`, Stage auf `https://stage.clubunio.com`. Prod
erhält seine bestätigte HTTPS-Adresse über den Buildwert
`CLUBUNIO_PROD_ORIGIN`. Die Auswahl wird auf dem Gerät gespeichert. Jede
Umgebung verwendet einen eigenen API-Client und einen eigenen Speicherbereich
für Sitzungen, Konten und Push-Einstellungen. Markenbuilds behalten ihre feste
Origin und den fest zugeordneten Verein.

Die bestehenden Aktionen «Registrieren» und «Neue Mitgliedschaft» stehen
auf dem Startscreen entsprechend den Vereinsfreigaben bereit. Bei aktiver
Kontoerstellung enthält der Mitgliedsantrag ein Passwortfeld. Der Server
speichert Antrag und Bestätigungsauftrag gemeinsam. Nach erfolgreicher
E-Mail-Bestätigung entsteht ein Konto mit dem bestehenden Mitgliedsrollenmodell.
Der Statuszugriff setzt eine abgeschlossene, mit dem Antrag verknüpfte
Bestätigung und ein authentifiziertes Konto voraus. Die App zeigt während der
Freigabephase den Antragsstatus und bietet ihn später auch im Menü an.

Die Bestätigung erteilt ausschliesslich den Zugriff auf die eigene
Statusübersicht. Mitgliederdaten und Vereinsfunktionen verwenden weiterhin
die regulären Rollen- und Mitgliedsverknüpfungen. Bestehende Benutzernamen
folgen dem bisherigen öffentlichen Registrierungsverfahren. Eine bestehende
E-Mail-Adresse allein verknüpft keinen Antrag mit einem vorhandenen Konto.

## Stage-Aktivierung

1. Backend-Code auf dem Stage-Webrelease bereitstellen und ausschließlich die
   additive Migration `2026_11_10_000100_mobile_membership_tracking.php` auf
   der verifizierten Stage-Datenbank ausführen. Der CLI-Aufruf muss vorher
   `StageMigrationTarget::authorize($db, 'clubunio_stage')` verwenden. Die
   Migration ergänzt den mandantengebundenen Fremdschlüssel zur Anmeldung.
2. Mobile-API und Bootstrap auf Stage aktivieren. `https://stage.clubunio.com`
   als exakte Origin in `CLUBUNIO_MOBILE_BOOTSTRAP_ORIGINS` und
   `CLUBUNIO_MOBILE_IDENTITY_ORIGINS` hinterlegen. Die zentrale Domain und die
   Vereinsliste auf die Stage-Installation abgleichen.
3. Öffentliche Konto- und Mitgliedsanmeldung für die Testvereine einschalten
   und den vorhandenen Identity-Mailer für Stage prüfen. Danach
   `CLUBUNIO_MOBILE_IDENTITY_MEMBERSHIP_ACCOUNT_ENABLED=true` setzen. Das Flag
   bleibt im Standard aus und setzt sowohl Konto- als auch Anmeldefreigabe voraus.
4. Ohne Sitzung `public/bootstrap`, `public/identity/clubs` und `context`
   prüfen. Erwartet sind ein freigegebener Startstatus, die vorgesehenen Vereine
   und HTTP 401 für den persönlichen Context. Mit einem freigegebenen
   Testpostfach Anmeldung, Mailbestätigung und Statuswechsel bis zur App prüfen.

Stand 10.11.2026. Die isolierte Stage-Pilot-Konfiguration liegt im Branch
`stage`. Die konkrete Stage-Freigabe ist dort dokumentiert. Für Prod fehlt die
bestätigte Mobile-API-Origin. Ein neuer App-Build ist für die Umgebungswahl und
die Statusansicht erforderlich. 37 Flutter-Tests und 8 PostgreSQL-Tests mit
202 Assertions bestanden. Der PostgreSQL-Test verwendet zurückgerollte
synthetische Daten und einen gefälschten Mailer.

## Anträge aus einem bestehenden App-Konto

In «Mitglieder» kann ein angemeldetes Konto im aktiven Verein «Neue
Mitgliedschaft» öffnen, sofern der Verein Mitgliedsanträge freigeschaltet
hat. Das Formular verwendet die reguläre Mitgliedsanmeldung und speichert
den Antrag für die Bearbeitung durch den Verein. Der Formulartoken ist
an Konto, Verein und Origin gebunden. Die Zuordnung wird mit dem Antrag
gespeichert. Es entsteht weder ein neues Konto noch eine Mitgliedsverknüpfung.
Die vorhandenen Antrags- und Idempotenz-Tabellen genügen.

DEV-Stand 13.11.2026. Der authentifizierte Endpunkt läuft im Release
20261113-clubunio-family-intake. Die Registrierungseinstellung für SV Sissach
ist aktiv, der neue Endpunkt verlangt ohne Sitzung HTTP 401. Der App-Einstieg
benötigt einen Build ab 78b4d83. Die separaten öffentlichen Startscreen-Abläufe
sind auf DEV und Stage derzeit deaktiviert. Es wurde kein echter Antrag erstellt.

## Aktivierungsstand am 13.11.2026

- DEV: Öffentliche «Neue Mitgliedschaft» für SV Sissach aktiv. Live geprüft
  über Bootstrap und Vereinsliste. Release 20261113-clubunio-public-intake.
  Additive Tracking-Spalte mit Mandanten-Fremdschlüssel, Zweckprüfung und
  Index auf PostgreSQL-Datenbank clubunio_dev im Schema clubunio angewendet.
- DEV: Antrag für ein weiteres Kind mit einem bestehenden Elternkonto über
  die authentifizierten Routen erfolgreich. Zuordnung und Wiederholung ohne
  Duplikat geprüft. Separat Antrag, E-Mail-Bestätigung, Login und Statusabfrage
  mit simuliertem Mailversand erfolgreich. Beide Tests zurückgerollt.
- Stage: Authentifizierte Routen bereitgestellt (21ef35c), HTTP 401 ohne
  Sitzung geprüft. Öffentliche «Neue Mitgliedschaft» im isolierten Verein
  Mobile Stage Test aktiv. Länder, Geschlechter, Rechnungsempfänger und
  Statuswerte wurden als Referenzdaten aus dem Stage-Verein übernommen.
  Bootstrap und Formularoptionen über HTTPS geprüft.
- DEV und Stage: Für öffentliche Kontoregistrierung und Kontoerstellung
  mit Statusverfolgung fehlen die Zugangsdaten und die Absenderadresse des
  dedizierten TLS-Identity-Mailers. Nach privater Einrichtung die Schalter
  für Mail, Registrierung und Membership-Account aktivieren, Cache neu
  bauen und eine Bestätigung mit einem Testpostfach prüfen.

### Stage-Elternabnahme und SMTP-Bereitschaft

Der Stage-Elternablauf wurde im isolierten Verein mit einem vorhandenen
Pilotkonto und einer temporär genehmigten Elternverknüpfung geprüft. Der
Antrag für das zweite Kind war eingereicht und dem bestehenden Konto
zugeordnet. Wiederholtes Absenden lieferte denselben Antrag. Die Zahl
der Elternverknüpfungen blieb gleich. Die gesamte Transaktion wurde
zurückgerollt, der Test lief mit deaktiviertem externem Versand.

Auf DEV und Stage fehlen weiterhin Host, Benutzername, Passwort und
Absender des dedizierten Identity-SMTP-Kanals. Die vorhandene öffentliche
Mitgliederanmeldung bleibt verfügbar. Kontoaktivierung und Statusverfolgung
werden erst nach privater Bereitstellung des SMTP-Zugangs freigegeben.

### Bestätigungsmails auf DEV und Stage aktiviert

Die vorhandene SMTP-Anmeldung des ClubUnio-Absenders noreply@clubunio.com
wurde dem separaten Identity-Mailer auf DEV und Stage zugeordnet. Auf beiden
Hosts sind TLS und SMTP-Anmeldung erfolgreich geprüft. Der öffentliche
Bootstrap meldet Registrierung und Mitgliedsanmeldung aktiv. Die
Formularoptionen bestätigen Kontoerstellung für SV Sissach auf DEV und
Mobile Stage Test auf Stage. Kein Mailversand an ein Testpostfach während
dieser Prüfung. Zugangsdaten verbleiben in den privaten Serverdateien.

### Vollständiges mobiles Formular nach PHPMaker

complete-intake-v1 gilt für die öffentliche Mitgliedschaft und den Einstieg
aus einem bestehenden Konto. Der Formulartoken bindet die Version.
Zusätzlich erforderlich sind Hausnummer, Geburtsort, Mobilnummer, AHV-Nummer,
Vorverein-Auswahl, Passfoto und mindestens eine Ausweisdatei. Bei Minderjährigen
sind beide Elternkontakte nötig. Bei vorhandenem Vorverein sind Name und
Inland/Ausland erforderlich. Die E-Mail des Rechnungsempfängers wird geprüft.
Die AHV-Nummer landet im verschlüsselten Antrag und wird bei Annahme in das
bestehende verschlüsselte Mitgliedsfeld übernommen. Technische Statusfelder
bleiben im Vereinsworkflow. Kein Schemawechsel. Ältere App-Builds verwenden
das bisherige Formular. Stage braucht den Scanner und ein privates Upload-Ziel.

DEV: Release 20261113-clubunio-complete-intake, Rückweg
20261113-clubunio-social-performance. HTTPS bestätigt den vollständigen
Formularvertrag und aktivierte Uploads. App-Quellstand 4e3282b.

Beim vollständigen App-Formular bestimmt der Rechnungsempfänger das
Pflichtfeld für die Rechnungs-E-Mail. Die Spieleradresse kann leer bleiben,
wenn Mutter oder Vater gewählt ist. Bei Minderjährigen bleiben beide
Elternkontakte erforderlich. Für die Kontoaktivierung dient bei fehlender
Spieleradresse die Rechnungs-E-Mail. Die Spieleradresse wird dabei nicht
mit der Elternadresse überschrieben.

Das öffentliche Webformular /join/{verein} verwendet dieselben Pflichtregeln.
Das gewählte Rechnungsziel steuert die erforderliche E-Mail. Minderjährige
brauchen beide Elternkontakte. AHV, vollständige Adresse, Passfoto und Ausweis
werden vor der Speicherung geprüft. Die AHV wird nach einem Formularfehler
nicht in die Web-Sitzung übernommen.

Abnahme: Stage 2c3781a und DEV 20261113-clubunio-join-rules liefern das
aktualisierte SV-Sissach-Webformular. App-Quellstand 73fe3cf. Die
PostgreSQL-Webtests sind ohne expliziten Testdatenbankmodus übersprungen.
