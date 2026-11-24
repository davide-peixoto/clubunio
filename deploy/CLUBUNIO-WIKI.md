# ClubUnio-Wiki für App und Webverwaltung

## Aktuelle Freigabe einschließlich PROD – 24.11.2026

Nach ausdrücklichem Benutzerauftrag wurde der Bild-only-Stand jetzt auch auf
PROD übernommen. Alle drei Umgebungen liefern25 App-/44 Web-Anleitungen je DE/EN
mit Schritten, Bildern und Screenshots. Videos bleiben auf allen drei Umgebungen
pausiert. Auf PROD423 verbliebene Medien-/Playerdateien privat archiviert, die
146 PNG-Dateien bleiben erreichbar.91 PROD-Wiki-Prüfungen erfolgreich; alte Video-
und Playerlinks liefern404. Sicherung und Laufnachweis:
[App-Korrekturen und Winterbetrieb](CLUBUNIO-MOBILE-TODAY-TEAM-TRAININGS.md).
Der nachfolgende auf DEV/Stage begrenzte Stand vom23.11.2026 ist historische Doku.


## Aktueller Freigabestand: Videos pausiert, 23.11.2026

Benutzerentscheid nach dem Erstrollout: Videos vorerst weglassen; neue Releases
nur auf DEV und Stage. PROD ausdrücklich unverändert lassen. Der nachfolgend
dokumentierte Erstrollout auf alle drei Umgebungen bleibt ein historischer Stand.

DEV/Stage behalten 25 App- und 44 Web-Anleitungen je DE/EN, strukturierte Schritte,
Suche, 138 Schrittbilder und acht echte Android-Screenshots. Video-/Player-/
Untertitel-/Aufnahmelinks werden nicht mehr vom SupportKnowledge ausgeliefert;
die strukturierte Web-Anzeige rendert keine Videos. Die native App verwendet
diese Serverdaten; dafür ist keine weitere native Änderung nötig.

Je Umgebung wurden 423 Video-/Player-/Untertitel-/Manifestdateien anhand von
SHA256 geprüft und außerhalb des Webroots privat archiviert. Die 146 PNG-Dateien
bleiben erreichbar. Kein Löschen der redaktionellen Texte oder historischer
Quellen; keine DB-, Konto-, Rechte-, SMTP-, Push- oder Provideränderungen.
Backups: /var/backups/clubunio-wiki-images-dev-20261123T200816Z und
/var/backups/clubunio-wiki-images-stage-20261123T200817Z auf dem jeweiligen Host.
Drei gezielte Quelldateien gesichert und aktualisiert, Blade-Cache erneuert.
Kein PROD-Zugriff oder -Release bei dieser Anpassung; dort bleibt auch das
vorherige Videopaket bestehen. Die gemeinsame WebVTT-MIME-Zuordnung unverändert.

Die Medienerzeugung erstellt jetzt standardmäßig nur Bilder. --with-video ist
ein ausdrücklicher Offline-Opt-in für eine spätere Wiederaufnahme, keine Freigabe
zur Veröffentlichung. Beim Deployment ausschließlich die PNG-Dateien aus
CLUBUNIO-WIKI-IMAGES-MANIFEST.json verwenden. Das ältere Video-Inventar beschreibt
den Erstrollout, nicht die aktuelle DEV-/Stage-Freigabe.

Prüfung: 10 Backendtests mit 3604 Assertions, Übersetzungsprüfung DE/EN erfolgreich;
91 PostgreSQL-Rollbackprüfungen je DEV/Stage. Hilfe und Bilder jeweils HTTP200;
Video, stumme Aufnahmen, Player und Untertitel jeweils HTTP404. Beide Hilfeseiten
enthalten keine Videotags/-links. Unveränderte App-Tests aus dem Erstrollout bleiben
als damaliger Nachweis bestehen, es gab hier keine native Codeänderung.



Stand: 23.11.2026. Auf DEV, Stage und PROD ausgerollt.

## Inhalt und Bedienung

Die Wissensbasis unterscheidet App und Webverwaltung. Mobile startet mit App,
die Weboberfläche mit Webverwaltung; die Auswahl ist bewusst umschaltbar.
25 App-Anleitungen und 44 Web-Anleitungen sind jeweils in DE und EN verfügbar:
69 Themen, 138 Sprachfassungen und 277 Handlungsschritte je Sprache.
Suche berücksichtigt Titel, Einleitung, Schritte und Hinweise.

Die bisherigen 18 Themen bleiben erhalten. App-Ergänzungen: Training planen,
Anlagenstatus, Challenge, Sponsorenlauf, Anmeldungen prüfen, Freigaben und Erste
Schritte. Web-Ergänzungen: Berechtigungen, Saisonplanung, Training, Beiträge,
Rechnungen, Ankündigungen, Dokumentenserien, Social Media, Kampagnen,
Integrationen, Anlagen, Materialrückgabe, Sponsorenlauf, Veo, Challenge-Abgrenzung,
Staff/Funktionen, Website/API, Berichte, Familien, SFV, Signage, Sponsoren,
Synchronisation, Arbeitsvorrat, Stammdaten und Nachrichtenvorlagen.

Alle Texte beschreiben tatsächliche Modulpfade und erläutern Rollen-/Statusgrenzen.
Öffentliche Anleitung ist keine Modulfreigabe. Private redaktionelle Artikel,
Tickets und Daten bleiben durch bestehende Tenant-/Audience-Prüfungen geschützt.
Die Support-KI und ihre Kosten wurden durch diesen Ausbau nicht aktiviert/erhöht.

## Bilder und Videos

138 bebilderte Schrittübersichten, 138 vertonte Folienvideos (überwiegend40–60s),
138 WebVTT-Untertitel und 138 HTML-Player mit lesbarer Textalternative erstellt.
Die Videos sind erklärende Schrittfolgen, keine vollständigen Bildschirmaufnahmen.
Audio wird einmalig lokal mit Windows-Sprachsynthese DE/EN erzeugt; kein KI-Aufruf
beim Anschauen und keine automatische Übersetzungs-API für die Oberfläche.

Zusätzlich8 echte Android-Screenshots und8 kurze stumme Bedienbeispiele:
Spesen, Chat, Teamplanung und Profil, jeweils DE/EN. Der explizite Offline-
Debug-Einstieg verwendet echte Produktionswidgets mit synthetischen Daten,
sichtbarem DEMO-Banner und ohne Backend-/Providerzugriff. Keine echten Mitglied-
oder Kontodaten, PINs oder Schlüssel im Medienpaket. Diese vier Beispiele sind
keine vollständigen End-to-End-/Hardwaretests. Weitere tatsächliche Bildschirm-
aufnahmen für andere Module sind noch nicht erstellt; deren Schrittmedien sind
vollständig vorhanden. EN-Testnachrichten dürfen deutsch bleiben, da Benutzer-
freitexte nicht automatisch ersetzt werden.

Medien liegen unter public/support-media/{de|en}/{app|web}/. Webvideo lädt erst
auf Anforderung (preload=none); Player öffnet bewusst, ohne Autoplay. Native App
zeigt Schritte, Hinweise und Bilder und öffnet Videos mit Untertiteln im externen
Player. Screenshots lassen sich für die größere Ansicht öffnen. Keine Medien als
Base64 im API-Payload. Video-Range-Requests HTTP206 geprüft.

## Quellen und Pflege

Versionierte redaktionelle Quellen:
- lang/{de,en}/support-app-guides.php und support-app-extra.php
- lang/{de,en}/support-web-guides.php und support-web-extra.php
- lang/{de,en}/support-wiki.php für Bedienelemente/Bildbeschriftungen
- SupportKnowledge liefert passende Oberfläche und vorhandene Medien.

Die vorhandene Datenbankredaktion für zusätzliche Artikel bleibt nutzbar. Die
neuen strukturierten Anleitungen werden derzeit im Repository gepflegt, nicht
mit einem neuen visuellen Wiki-Editor. Inhalt und Rollenpfad bei Moduländerungen
nachführen, DE/EN gemeinsam prüfen und Medien neu erzeugen. Neue Sprache erst
nach zentraler Freigabe und Abnahme. Andere App-Kataloge bieten keine automatische
Freigabe zusätzlicher Hilfesprachen.

Reproduktion auf Windows mit PHP, Python/Pillow, imageio-ffmpeg und installierten
Sprachstimmen Microsoft Hedda Desktop/Microsoft Zira Desktop:

    python tools/build-support-media.py --output public/support-media --workers 3

Export liest ausschließlich öffentliche Kataloge, keine Umgebung/DB. Bereits
unveränderte Videos werden anhand des Quellenhashs wiederverwendet. Android-
Aufnahmeanleitung im App-Repository docs/WIKI-CAPTURE.md. Generierte Bilder,
Videos und Audio-Zwischendateien werden nicht in Git committed. Reproduzierbare
Quellen und SHA256-Inventar: CLUBUNIO-WIKI-MEDIA-MANIFEST.json. Sichere öffentliche
Medien separat mit dem Release übertragen; keine Upload-/Laufzeitdaten beimischen.

## Rollout, Prüfung und Rückweg

Gezieltes Delta:16 Backend-/View-/Katalogdateien plus569 öffentliche Medien-/
Player-/Manifestdateien, insgesamt585 Dateien je Umgebung. Live-Dateihashes vor
Installation geprüft, fremde Live-Deltas erhalten; keine DB-Migration, kein
Datenimport, keine neuen Versand-/KI-Freigaben. Blade-Cache erneuert.
Backups: /var/backups/clubunio-wiki-{dev|stage|prod}-20261123T1935*Z auf jeweiligem
Host; enthalten gezielte Quellen und Inventar. Rückweg: nur gesicherte Änderungen
zurückspielen; neue eigene Dateien anhand Inventar entfernen, keine fremden
Dateien oder Triage-Historie löschen. Blade-Cache danach erneuern.

Für Untertitel fehlte Nginx text/vtt. Auf Pi und VPS MIME-Zuordnung text/vtt vtt
ergänzt, nginx -t erfolgreich und Dienst sanft neu geladen. Sicherungen:
/var/backups/clubunio-wiki-mime-20261123T19364*Z. Zuordnung nach Nginx-/RunCloud-
Updates prüfen; Musterdatei deploy/nginx/support-webvtt.types. Kein neuer Dienst.

Prüfung:9 Backendtests/3323 Assertions, DE/EN3075 zentrale Schlüssel sowie neue
Guide-Parität geprüft;87 PostgreSQL-Rollbackprüfungen je Umgebung;18 Fluttertests;
gezielte Analyse sauber;2176 Apptexte in fünf Katalogen. Alle138 vertonten Videos
vollständig mit ffmpeg decodiert, alle8 Android-Beispiele geprüft. HTTP200 für
Hilfe/Player/Bilder, korrektes text/vtt, HTTP206 für Video-Segmente. Bestehender
Gmail-Zugang auf Stage/PROD erhält25 App-Guides. Browseransicht und Player auf
Stage per isoliertem Headless-Chrome geprüft; Desktop-Browsersteuerung war in
dieser Sitzung nicht verfügbar. Neues Android-Debug-Demo-APK erstellt/getestet.

Backend-/Webinhalte sind sofort verfügbar. Für neue Auswahl, strukturierte
Schritte und Medienanzeige in der nativen App ist ein aktualisierter App-Build
nötig. Kein Store-Release oder signierter Produktivbuild durchgeführt.
