# Event-Check-in: Vorstand, QR-Aushang und Anwesenheit

Stand 22.11.2026. Erweiterung der vorhandenen Event-Dossiers; keine Übernahme
oder Veränderung bestehender Gäste-/RSVP-Zusagen.

## Bedienung im Backend

Ein Event öffnen und **Check-in einrichten** wählen. Aktivieren sowie Beginn und
Ende des Check-in-Zeitraums einstellen; Anzeige Europe/Zurich, Speicherung UTC.
Speichern prüft frische Events-Änderungsrechte und die aktuelle Eventrevision.
Anschliessend **QR-Aushang drucken** öffnen. Ohne aktiven/konfigurierten Event
bleibt die Druckaktion deaktiviert. Beim Aufruf werden Verein und Events-
Änderungsrecht erneut geprüft; ein Druck-GET erstellt oder dreht keinen Token.

Der A4-Aushang enthält Vereinslogo/-farbe, Eventname/-ort, grossen QR, drei
Bedienungsschritte und den Check-in-Zeitraum. Die Browser-Druckaktion verschwindet
beim Drucken. Der Code entsteht lokal durch die bereits installierte
chillerlan-Bibliothek; keine Weitergabe an externe QR-Dienste.

**Event-QR-Code ersetzen** verlangt Bestätigung und eine aktuelle Revision.
Bereits gedruckte Codes sind danach ungültig. **Event-Check-in schliessen** stoppt
neue Bestätigungen. Diese Aktionen benötigen ausdrücklich `events.update` und
sind auch für entsprechend freigegebene Funktionsprofile korrekt zugeordnet.

## Anwesenheit und bisherige Gäste

Die zusätzliche Registerkarte **Bestätigte Anwesenheit** liest die eigene,
eindeutige Event-Check-in-Tabelle, einschliesslich Mitgliedern ohne bisherigen
Gästeeintrag. Sie zeigt Name, Anwesenheit und Bestätigungszeit. Nur die sichtbare
Liste aktualisiert sich alle 15 Sekunden (`wire:poll.15s.visible`); kein globales
Polling und kein keep-alive bei versteckter Ansicht. Gäste-/RSVP-Zeilen erhalten
nur eine zusätzliche tatsächliche Bestätigungszeit; ihre Aktivität, bestehenden
Dubletten und Bearbeitungsabläufe bleiben erhalten.

## Öffentlicher Einstieg

`/event-check-in/{64hex}` registriert sich ausserhalb der Cookie-Anmeldung.
Die Seite liest/schreibt keine Anwesenheit und verrät weder Eventdetails noch
Mitgliederlisten. Ein bewusster App-Link öffnet die zur aktuellen konfigurierten
HTTPS-Domain passenden Builds. Custom-Domains bieten zuerst den Vereinsbuild,
danach die Core-App als ausdrückliche Alternative. Keine automatische
Weiterleitungsschleife, keine erfundenen oder aus fremden Hosts übernommenen
Referenzen. Bestätigung und Berechtigung prüft anschliessend die App/API frisch.

Sprachauswahl ausschliesslich aus der zentralen Backendfreigabe; DE/EN. Private
no-store-/noindex-/no-referrer-Antworten. App-Link-/Association-Konfiguration
wird zentral unter `clubunio-event-links` verwaltet.

## Prüfung

Neun Webtests mit 63 Assertions bestanden: Einstieg ohne Login/DBzugriff,
explizite Appwahl, HTTPS-/Host-/Portgrenzen, lokale SVG-Ausgabe, Ablehnung
ungültiger QR-URLs, Mandantenabgleich vor privater Ausgabe, Update-Recht vor
Tokenzugriff, deaktivierter Druck ohne Konfiguration und escaped A4-Inhalte.

Synthetischer A4-Druck mit vorhandenem SV-Sissach-Logo erzeugt: eine Seite
(595,28×841,89pt), mit Poppler gerendert und visuell geprüft. Der aus dem
vollständigen Rasterbild gelesene QR dekodiert exakt zur synthetischen Event-URL.
Beleg: [QR-Aushang](evidence/event-checkin-20261122/qr-poster-synthetic.png).
Interne HTML-/PDF-Zwischenstände unter storage bleiben uncommitted. Der Beleg ist
eine lokale Druckrendererprüfung, keine Browser-/iPhone-Ende-zu-Ende-Abnahme.
