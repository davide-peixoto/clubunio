# ClubUnio-Wiki fÃ¼r App und Webverwaltung


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
die WeboberflÃ¤che mit Webverwaltung; die Auswahl ist bewusst umschaltbar.
25 App-Anleitungen und 44 Web-Anleitungen sind jeweils in DE und EN verfÃ¼gbar:
69 Themen, 138 Sprachfassungen und 277 Handlungsschritte je Sprache.
Suche berÃ¼cksichtigt Titel, Einleitung, Schritte und Hinweise.

Die bisherigen 18 Themen bleiben erhalten. App-ErgÃ¤nzungen: Training planen,
Anlagenstatus, Challenge, Sponsorenlauf, Anmeldungen prÃ¼fen, Freigaben und Erste
Schritte. Web-ErgÃ¤nzungen: Berechtigungen, Saisonplanung, Training, BeitrÃ¤ge,
Rechnungen, AnkÃ¼ndigungen, Dokumentenserien, Social Media, Kampagnen,
Integrationen, Anlagen, MaterialrÃ¼ckgabe, Sponsorenlauf, Veo, Challenge-Abgrenzung,
Staff/Funktionen, Website/API, Berichte, Familien, SFV, Signage, Sponsoren,
Synchronisation, Arbeitsvorrat, Stammdaten und Nachrichtenvorlagen.

Alle Texte beschreiben tatsÃ¤chliche Modulpfade und erlÃ¤utern Rollen-/Statusgrenzen.
Ã–ffentliche Anleitung ist keine Modulfreigabe. Private redaktionelle Artikel,
Tickets und Daten bleiben durch bestehende Tenant-/Audience-PrÃ¼fungen geschÃ¼tzt.
Die Support-KI und ihre Kosten wurden durch diesen Ausbau nicht aktiviert/erhÃ¶ht.

## Bilder und Videos

138 bebilderte SchrittÃ¼bersichten, 138 vertonte Folienvideos (Ã¼berwiegend40â€“60s),
138 WebVTT-Untertitel und 138 HTML-Player mit lesbarer Textalternative erstellt.
Die Videos sind erklÃ¤rende Schrittfolgen, keine vollstÃ¤ndigen Bildschirmaufnahmen.
Audio wird einmalig lokal mit Windows-Sprachsynthese DE/EN erzeugt; kein KI-Aufruf
beim Anschauen und keine automatische Ãœbersetzungs-API fÃ¼r die OberflÃ¤che.

ZusÃ¤tzlich8 echte Android-Screenshots und8 kurze stumme Bedienbeispiele:
Spesen, Chat, Teamplanung und Profil, jeweils DE/EN. Der explizite Offline-
Debug-Einstieg verwendet echte Produktionswidgets mit synthetischen Daten,
sichtbarem DEMO-Banner und ohne Backend-/Providerzugriff. Keine echten Mitglied-
oder Kontodaten, PINs oder SchlÃ¼ssel im Medienpaket. Diese vier Beispiele sind
keine vollstÃ¤ndigen End-to-End-/Hardwaretests. Weitere tatsÃ¤chliche Bildschirm-
aufnahmen fÃ¼r andere Module sind noch nicht erstellt; deren Schrittmedien sind
vollstÃ¤ndig vorhanden. EN-Testnachrichten dÃ¼rfen deutsch bleiben, da Benutzer-
freitexte nicht automatisch ersetzt werden.

Medien liegen unter public/support-media/{de|en}/{app|web}/. Webvideo lÃ¤dt erst
auf Anforderung (preload=none); Player Ã¶ffnet bewusst, ohne Autoplay. Native App
zeigt Schritte, Hinweise und Bilder und Ã¶ffnet Videos mit Untertiteln im externen
Player. Screenshots lassen sich fÃ¼r die grÃ¶ÃŸere Ansicht Ã¶ffnen. Keine Medien als
Base64 im API-Payload. Video-Range-Requests HTTP206 geprÃ¼ft.

## Quellen und Pflege

Versionierte redaktionelle Quellen:
- lang/{de,en}/support-app-guides.php und support-app-extra.php
- lang/{de,en}/support-web-guides.php und support-web-extra.php
- lang/{de,en}/support-wiki.php fÃ¼r Bedienelemente/Bildbeschriftungen
- SupportKnowledge liefert passende OberflÃ¤che und vorhandene Medien.

Die vorhandene Datenbankredaktion fÃ¼r zusÃ¤tzliche Artikel bleibt nutzbar. Die
neuen strukturierten Anleitungen werden derzeit im Repository gepflegt, nicht
mit einem neuen visuellen Wiki-Editor. Inhalt und Rollenpfad bei ModulÃ¤nderungen
nachfÃ¼hren, DE/EN gemeinsam prÃ¼fen und Medien neu erzeugen. Neue Sprache erst
nach zentraler Freigabe und Abnahme. Andere App-Kataloge bieten keine automatische
Freigabe zusÃ¤tzlicher Hilfesprachen.

Reproduktion auf Windows mit PHP, Python/Pillow, imageio-ffmpeg und installierten
Sprachstimmen Microsoft Hedda Desktop/Microsoft Zira Desktop:

    python tools/build-support-media.py --output public/support-media --workers 3

Export liest ausschlieÃŸlich Ã¶ffentliche Kataloge, keine Umgebung/DB. Bereits
unverÃ¤nderte Videos werden anhand des Quellenhashs wiederverwendet. Android-
Aufnahmeanleitung im App-Repository docs/WIKI-CAPTURE.md. Generierte Bilder,
Videos und Audio-Zwischendateien werden nicht in Git committed. Reproduzierbare
Quellen und SHA256-Inventar: CLUBUNIO-WIKI-MEDIA-MANIFEST.json. Sichere Ã¶ffentliche
Medien separat mit dem Release Ã¼bertragen; keine Upload-/Laufzeitdaten beimischen.

## Rollout, PrÃ¼fung und RÃ¼ckweg

Gezieltes Delta:16 Backend-/View-/Katalogdateien plus569 Ã¶ffentliche Medien-/
Player-/Manifestdateien, insgesamt585 Dateien je Umgebung. Live-Dateihashes vor
Installation geprÃ¼ft, fremde Live-Deltas erhalten; keine DB-Migration, kein
Datenimport, keine neuen Versand-/KI-Freigaben. Blade-Cache erneuert.
Backups: /var/backups/clubunio-wiki-{dev|stage|prod}-20261123T1935*Z auf jeweiligem
Host; enthalten gezielte Quellen und Inventar. RÃ¼ckweg: nur gesicherte Ã„nderungen
zurÃ¼ckspielen; neue eigene Dateien anhand Inventar entfernen, keine fremden
Dateien oder Triage-Historie lÃ¶schen. Blade-Cache danach erneuern.

FÃ¼r Untertitel fehlte Nginx text/vtt. Auf Pi und VPS MIME-Zuordnung text/vtt vtt
ergÃ¤nzt, nginx -t erfolgreich und Dienst sanft neu geladen. Sicherungen:
/var/backups/clubunio-wiki-mime-20261123T19364*Z. Zuordnung nach Nginx-/RunCloud-
Updates prÃ¼fen; Musterdatei deploy/nginx/support-webvtt.types. Kein neuer Dienst.

PrÃ¼fung:9 Backendtests/3323 Assertions, DE/EN3075 zentrale SchlÃ¼ssel sowie neue
Guide-ParitÃ¤t geprÃ¼ft;87 PostgreSQL-RollbackprÃ¼fungen je Umgebung;18 Fluttertests;
gezielte Analyse sauber;2176 Apptexte in fÃ¼nf Katalogen. Alle138 vertonten Videos
vollstÃ¤ndig mit ffmpeg decodiert, alle8 Android-Beispiele geprÃ¼ft. HTTP200 fÃ¼r
Hilfe/Player/Bilder, korrektes text/vtt, HTTP206 fÃ¼r Video-Segmente. Bestehender
Gmail-Zugang auf Stage/PROD erhÃ¤lt25 App-Guides. Browseransicht und Player auf
Stage per isoliertem Headless-Chrome geprÃ¼ft; Desktop-Browsersteuerung war in
dieser Sitzung nicht verfÃ¼gbar. Neues Android-Debug-Demo-APK erstellt/getestet.

Backend-/Webinhalte sind sofort verfÃ¼gbar. FÃ¼r neue Auswahl, strukturierte
Schritte und Medienanzeige in der nativen App ist ein aktualisierter App-Build
nÃ¶tig. Kein Store-Release oder signierter Produktivbuild durchgefÃ¼hrt.
