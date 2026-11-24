# Laravel-E-Mail-Themes

## Workflow-Mails auf DEV korrigiert24.11.2026

Der aeltere TestOutboxDispatcher lieferte Spesen-, Anmeldungs- und Platzanfragen
noch als reinen Klartext aus. Er und der separate Vorlagen-Testversand verwenden
jetzt ebenfalls ClubUnioMailTheme: Vereinsfarben/-logo, HTML plus Klartext,
Vorgangslink und Powered by ClubUnio. Inhalte/Vorlagen bleiben editierbar und
unveraendert; Empfaenger, Test-Sink, Reply-To, Versandgrenzen und Idempotenz bleiben.
DE-/EN-Vorlagensprachen werden vor dem Layout auf die aktivierte UI-Sprache abgebildet.

Beim ersten SMTP-Nachtest wurde ein bereits beschaedigter deutscher Runtime-
Sprachkatalog entdeckt (UTF-8 als Windows-Zeichen interpretiert). DE/EN-mail_theme.php
werden nun bytegetreu ausgeliefert. Der native Pruefer verlangt ausdruecklich
den korrekten Unicode-Text von mail_theme.open, statt nur vorhandene Keys zu pruefen.
Keine pauschale Ersetzung von Benutzerdaten oder gespeicherten Mailvorlagen.

DEV-Basis bei Bereitstellung:20261124-clubunio-daily-v2; parallelen Release erhalten.
Gezielte vier Dateien, keine Migration und keine globalen PHP-/SMTP-Aenderungen.
Sicherung unter storage/app/private/workflow-mail-releases/dev-20261124-160443.
Rueckweg: die beiden Service-Dateien und DE-/EN-mail_theme.php aus diesem Ordner
gemeinsam zuruecknehmen; keine bereits akzeptierte Nachricht erneut einreihen.

Zwoelf lokale Tests/126 Assertions pruefen echte In-Memory-MIME-Nachrichten:
DE/EN, Umlaute/UTF-8, HTML/Klartext, Vereinsfarben/-logo, Escaping, Reply-To,
entfernte CC/BCC, feste Testempfaenger und keine doppelte Zustellung. Sechs native
Layoutchecks plus strikter Umlautcheck auf DEV erfolgreich. Zwei getrennte
Vorlagen-Testmails an ausschliesslich admin@svsissach.ch von SMTP angenommen;
die zweite nach der UTF-8-Korrektur. SMTP-Annahme ist keine visuelle Zustellabnahme.
Ein weiterer Aufruf mit derselben Testkennung versendet nicht erneut.

Lokale HTML-Browserpruefung durch URL-Sicherheitsrichtlinie blockiert, keine
Umgehung. Benutzer bestaetigte das neue Layout im Screenshot und meldete den
Umlautfehler; erneute Darstellung der zuletzt korrigierten Mail noch offen.
Stage/PROD fuer dieses Korrekturpaket vorerst nicht veraendert. Bexio-Vollabnahme
und deren Stage-/PROD-Rollout bleiben ein getrennter noch laufender Auftrag.

## Release vom 19.11.2026

ClubUnio verwendet das blaue Standarddesign. Vereine mit freigegebener
Custom Domain verwenden Vereinsname, Farbe und ein geeignetes gehostetes
PNG-/JPEG-Logo aus ihrem vorhandenen Design. Der Fussbereich nennt
«Powered by ClubUnio». Die Auswahl erfolgt anhand des jeweiligen
Mail-Mandanten, auch im Hintergrundprozess. Fehlende Custom-Domain-Zuordnung
verwendet ClubUnio. Externe und SVG-Logos werden im Mail-Layout ausgelassen.

Die gemeinsame HTML-Vorlage gilt für App-Kontomails zur Registrierung,
Recovery-E-Mail-Bestätigung und Passwortwiederherstellung sowie Kampagnen.
HTML wird escaped. Die Klartextfassung bleibt verfügbar. Neue App-Aufträge
speichern die angefragte DE/EN-Sprache; Aufträge ohne Sprachangabe verwenden
Deutsch. Die bestehenden Identity-Proofs und Versandgrenzen bleiben erhalten.

DEV wurde als Release
/var/www/svs-laravel/releases/20261119-clubunio-laravel-mail-theme aktiviert.
Rückweg ist /var/www/svs-laravel/releases/20261119-clubunio-support-launcher
über den current-Symlink und FPM-Reload.

Auf Stage wurden acht Theme-/Kontomail-Dateien und der HTML-Aufruf im dort
aktiven CampaignOperations-Pfad gezielt ergänzt. Sicherung der vorherigen
Dateien und Manifest unter
/root/clubunio-stage-laravel-mail-20261119-k9Pn2y. Die zusätzlich gesicherte
CampaignOperations.php ebenfalls wiederherstellen und php83rc-fpm neu laden.
Das Manifest unterscheidet vorhandene Dateien von neu hinzugefügten Dateien.
Beim Rückweg die neu hinzugefügten Dateien entfernen, nachdem die alten
Aufrufer wiederhergestellt sind.

Kein Schemawechsel und keine Migration. Die Laufzeitpakete behalten das
bestehende clubunio-Schema und die jeweiligen DEV-/Stage-Versandgrenzen.

## Gezielte Prüfung

Nach Aktivierung je Umgebung zehn Prüfungen für DE/EN, ClubUnio-/Vereinsdesign,
Escaping sowie Registrierungs-, Recovery- und Reset-MIME mit HTML,
Klartext und stabiler Identity-Proof-Signatur. Alte Sprachpayloads verwenden
erfolgreich Deutsch. Nur In-Memory-Transport, kein SMTP-Testversand.

DEV: Bestehender Kampagnencheck bestätigte Versandgrenzen, blockierte
Nicht-Testadressen und den In-Memory-Ablauf. Stage: Der aktuelle
check-campaign-operations.php bestätigte Testversand, Erinnerungen,
Rückmeldungen, Vorlagen und Freigaben mit zurückgerollten synthetischen Daten.
Der ältere check-stage-member-campaigns.php ist für den aktuellen
Kampagnenablauf überholt und lieferte keinen gültigen Gesamtnachweis.

Login und öffentliche Mobile-Bootstrap-Endpunkte antworten nach Rollout
auf DEV und Stage mit HTTP 200. Lokale Sprachprüfung und vier gezielte Tests
mit 18 Assertions bestanden. Neun lokal datenbankabhängige Tests übersprungen.
[Server-Prüfergebnisse](evidence/laravel-mail-theme-20261119.json).
