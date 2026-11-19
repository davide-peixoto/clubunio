# Platzanfragen aus dem Kalenderslot

Stand 19.11.2026.

In der Tagesansicht öffnen freie Kalenderflächen im 30-Minuten-Raster das
Anfrageformular. App und Webkalender übernehmen das angeklickte Spielfeld,
Datum und Beginn. Die Dauer startet mit 120 Minuten und ist im Formular
änderbar. Ein aufgezogener Bereich übernimmt stattdessen die ausgewählte
Dauer. Erst das Absenden erstellt die Anfrage. Bestehende Belegungen
öffnen ihre Details. Verschieben bleibt über die bisherigen Kalenderaktionen
möglich. Kalender mit reinem Lesezugriff bieten keine Anfrageflächen an.

Die neue Webaktion `requestSlot` prüft Datum, Uhrzeit, die aktuelle
Anfrageberechtigung und das Spielfeld aus den frisch geladenen Vereinsdaten.
Beim Absenden gelten die bestehenden Teamrechte und Konfliktprüfungen.

Der Hinweis bei einem aktuell gesperrten Spielfeld erklärt jetzt den
Anfrageablauf. Eine Anfrage ist weiterhin möglich, die Verfügbarkeit für den
gewünschten Termin wird bei der Freigabe geprüft. Die App erlaubt deshalb
auch diese ausstehenden Anfragen entsprechend dem vorhandenen Serverablauf.
Der Hinweis erscheint neutral unter der Platzauswahl. Für Verschiebungen
und Genehmigungen gelten die jeweiligen bestehenden Freigaberegeln.

Die neuen Webtexte werden zentral in DE/EN gepflegt. Die Appkataloge enthalten
zusätzlich die bereits implementierten französischen, italienischen und
portugiesischen Varianten. Die barrierefreien Slotbeschriftungen nennen
Spielfeld, Datum und Uhrzeit.

## Prüfung

54 Fluttertests decken Kalender, kleine Displays mit grosser Schrift,
Slotauswahl, Vorbelegung, Anfrageversand, reine Leseansichten und bestehende
Freigaberegeln ab. 29 PHP-Tests mit 147 Assertions prüfen die Webkomponente
und Kalenderdarstellung. Ein optionaler visueller Fixtureexport ist
übersprungen. Der Katalogcheck bestätigt 2992 Schlüssel in DE/EN.
Die gezielte Dartanalyse und PHP-Syntaxprüfung sind grün.

## DEV und Stage

Das gezielte Oberflächendelta wurde auf beiden Umgebungen eingespielt.
Die Sprachkataloge wurden um die beiden neuen Einträge ergänzt und die
betroffenen Vorlagen neu kompiliert. Beide PHP-FPM-Dienste sind aktiv.
Die Customdomain-Bootstraps und das versionierte Kalender-CSS liefern HTTP
200. Beide Vorlagen kompilieren auf den Zielservern mit gültiger PHP-Syntax,
die deutschen und englischen Slotbeschriftungen werden korrekt aufgelöst.

Stage führt den adaptierten Commit `8c45b1e` im Branch `stage`.
DEV verwendet das Delta im aktuellen Release
`20261117-sv-sissach-mobile-oidc`. Die vorherigen sechs Quelldateien liegen
auf DEV unter `/var/www/svs-laravel/shared/pitch-slots-backup-20261119` und
auf Stage unter `/root/clubunio-backups/pitch-slots-20261119`.
Für einen Rückweg diese Dateien mit ihren ursprünglichen Besitzerrechten
wiederherstellen, Ansichten leeren und PHP-FPM neu laden.

Der iOS-Gerätelauf wird vom Benutzer auf dem Mac nach Aktualisierung des
Mobile-Checkouts durchgeführt. Die Anmeldung und native Buildumgebung
werden im separaten SV-Sissach-Testbuildprotokoll nachverfolgt.

## Mehrere Slots als Zeitraum auswählen

In der App ein Zeitfeld gedrückt halten und dann nach oben oder unten ziehen.
Im Webkalender wird der Bereich mit gedrückter linker Maustaste aufgezogen.
Die Markierung zeigt Beginn, Ende und Dauer. Beim Loslassen öffnet sich das
Anfrageformular mit Spielfeld, Datum, Beginn und ausgewählter Dauer. Das
30-Minuten-Raster gilt für beide Richtungen und bleibt innerhalb desselben
Spielfelds und Kalendertags. Ein gehaltener einzelner Slot entspricht
30 Minuten. Ein kurzer Klick übernimmt weiterhin die Standarddauer von
120 Minuten. Die Werte können vor dem Absenden angepasst werden.

Die App lässt normales Scrollen und abgebrochene Gesten ohne Anfrageformular
enden. Im Web beendet Escape, ein Fensterwechsel oder ein abgebrochener
Zeigerkontakt die Auswahl. Nachfolgende Klickereignisse derselben Mausgeste
werden abgefangen. Tastatur- und Touch-Klicks öffnen weiterhin das Formular.
Im Web bleiben Touch-Gesten zum Scrollen verfügbar. Die Bereichsauswahl im
Web erfolgt mit Maus oder Stift.

Die Webaktion prüft zusätzlich eine Dauer von 1 bis 1440 Minuten. Die
bestehenden Serverabläufe prüfen beim Absenden Rechte und Konflikte. Die
Auswahl selbst arbeitet lokal, ein Webaufruf erfolgt beim Loslassen.
Texte und barrierefreie Hinweise verwenden die zentralen Sprachkataloge.

59 gezielte Fluttertests, 25 PHP-Webtests mit 136 Assertions und sechs
JavaScriptfälle sind grün. Ein optionaler visueller Fixtureexport ist
übersprungen. Der Sprachcheck bestätigt 2994 Schlüssel in DE/EN. Die neuen
Apptexte sind zusätzlich in Französisch, Italienisch und Portugiesisch
gepflegt. Die native iOS-Prüfung bleibt auf dem Benutzer-Mac auszuführen.

DEV verwendet die Erweiterung im aktuellen Release
`20261119-clubunio-member-change-conflicts`. Die Quellcodeprüfung vor dem
Einspielen hat dessen zwischenzeitlich aktualisierte Basis berücksichtigt.
Stage führt den adaptierten Commit `ce5f4fd`. CSS-Version 6 und
JavaScript-Version 2 werden mit HTTP 200 ausgeliefert. Die öffentlich
abgerufene JavaScriptdatei stimmt auf beiden Customdomains mit der geprüften
Quelldatei überein. Sicherungen vor der Bereichserweiterung liegen auf DEV
unter `/var/www/svs-laravel/shared/pitch-range-backup-20261119` und auf Stage
unter `/root/clubunio-backups/pitch-range-20261119`. Deren Manifest nennt die
neu hinzugefügte JavaScriptdatei für den gezielten Rückweg.

## Automatisches Scrollen bei der Bereichsauswahl

Während des Aufziehens scrollt der Tageskalender am oberen und unteren
Rand automatisch weiter. Auch ein stillstehender Finger oder Mauszeiger
hält das Scrollen am Rand aufrecht. Die Markierung und die daraus berechnete
Dauer folgen der verschobenen Kalenderfläche. Beim Zurückziehen in die
Mitte stoppt die automatische Bewegung. Der Tag und das gewählte Spielfeld
bleiben die Grenzen der Auswahl.

Der aktive Randbereich umfasst bis zu 48 logische Pixel. Die Geschwindigkeit
steigt mit der Nähe zum Rand auf maximal 480 Pixel pro Sekunde. Die
Scrollschleife läuft im Takt der Bildschirmframes. Der Webkalender
berücksichtigt die feststehenden Spaltenköpfe. Kurze Maus-Klicks starten
erst bei einer tatsächlich aufgezogenen Auswahl das Randscrollen.

Loslassen und Abbrechen beenden die Scrollschleife. Die App räumt sie auch
beim Datums-/Datenwechsel, beim Verlassen der Ansicht und beim Wechsel der
App in den Hintergrund auf. Im Web gilt das beim Fensterwechsel, beim
Verbergen des Tabs und beim Entfernen der Kalenderkomponente. Die
Zeitbeschriftungen verwenden weiterhin die zentralen Sprachkataloge.

64 Fluttertests sind grün, darunter stationäres Randscrollen in beide
Richtungen auf schmalen Displays mit normaler und grosser Schrift. Sie
prüfen auch die exakt übernommene Dauer und das Ende der Scrollbewegung.
Zehn JavaScriptfälle decken unter anderem die Scrollgrenzen, stationäre
Zeiger, kurze Klicks und das Aufräumen der Frame-Schleife ab. Die 25
PHP-Webtests mit 136 Assertions sind grün. Ein optionaler Fixtureexport
ist übersprungen. Dartanalyse und DE/EN-Katalogcheck sind grün.
Die native iPhone-Abnahme erfolgt weiterhin auf dem Benutzer-Mac.

Das Oberflächendelta ist auf DEV und Stage eingespielt. Beide Customdomains
liefern Bootstrap und JavaScript-Version 3 mit HTTP 200. Die öffentlich
abgerufene JavaScriptdatei stimmt mit dem geprüften Quellstand überein.
Stage führt Commit `caa3e20`. DEV verwendet weiterhin das aktuelle Release
`20261119-clubunio-member-change-conflicts`. Quelldateisicherungen liegen
unter `/var/www/svs-laravel/shared/pitch-edge-scroll-backup-20261119` auf
DEV und `/root/clubunio-backups/pitch-edge-scroll-20261119` auf Stage.
Für den gezielten Rückweg die beiden gesicherten Quelldateien mit ihren
ursprünglichen Besitzerrechten wiederherstellen, Ansichten leeren und
PHP-FPM neu laden.

## Randscrollen beim Verschieben bestehender Belegungen

Auch bestehende Belegungskarten nutzen während des Verschiebens das
automatische Scrollen am oberen und unteren Kalenderrand. In der App wird
die Karte wie bisher gedrückt gehalten und gezogen, im Web per Drag-and-drop
verschoben. Ein stillstehender Finger oder Zeiger am Rand hält das Scrollen
aufrecht. Dafür wird dieselbe Frame-Schleife wie bei der Bereichsauswahl
verwendet. Die Scrollgrenzen und die Geschwindigkeitssteuerung gelten für
beide Abläufe.

Beim Ablegen wird die Zielzeit aus der aktuellen, gescrollten Spalte
berechnet. Die App behält das bisherige 15-Minuten-Raster, das Web das
bisherige 30-Minuten-Raster bei. Dauer und Teilfläche werden entsprechend
dem bestehenden Verschiebeablauf übernommen. Beim Wechsel des Spielfelds
wird die alte Teilfläche zurückgesetzt. Die App speichert über den
vorhandenen Ablauf, das Web öffnet den vorhandenen Verschiebedialog.

Abbruch, Fenster-/Hintergrundwechsel und das Verlassen der Ansicht räumen
die Scrollschleife auf. In der App wird ein dadurch verworfener Drag beim
nachfolgenden Loslassen ignoriert. Im Web beendet das browserseitige
Pointer-Abbruchereignis beim Start eines HTML-Drags nur eine eventuell
aktive Bereichsauswahl. Der native Belegungs-Drag läuft danach weiter.
Bestehende Berechtigungs-, Revisions- und Konfliktprüfungen bleiben für den
eigentlichen Schreibvorgang massgeblich.

72 Fluttertests, 14 JavaScriptfälle und 25 PHP-Webtests mit 136 Assertions
sind grün. Ein optionaler Fixtureexport ist übersprungen. Die neuen
Flutterfälle prüfen die Zielzeit nach stationärem Randscrollen in beide
Richtungen mit normaler und grosser Schrift, Dauer-/Teilflächenübernahme,
den Wechsel auf einen anderen Platz sowie Abbruch und Hintergrundwechsel.
Die JavaScriptfälle prüfen zusätzlich native Pointer-Abbrüche,
abgebrochene Drags, frische Drop-Koordinaten und genau einen Verschiebeaufruf.
Dartanalyse und Sprachcheck mit 2994 Schlüsseln in DE/EN sind grün.
Die native iPhone-Abnahme wird auf dem Benutzer-Mac durchgeführt.

Die drei betroffenen Webquelldateien sind auf DEV und Stage eingespielt.
Bootstrap und JavaScript-Version 4 liefern auf beiden Customdomains HTTP
200. Die öffentlich geladene JavaScriptdatei ist bytegleich zum geprüften
Quellstand, beide betroffenen Bladevorlagen kompilieren auf den Zielservern.
Das Stage-Fachdelta ist `bc2faab`, der Git-Abgleich mit dem aktuellen
Stage-Branch ist `99ba847`. DEV wurde auf der aktuellen Basis
`20261119-clubunio-support-panel` aktualisiert. Die Quelldateisicherungen
liegen unter `/var/www/svs-laravel/shared/pitch-move-scroll-backup-20261119`
auf DEV und `/root/clubunio-backups/pitch-move-scroll-20261119` auf Stage.
Der gezielte Rückweg stellt diese drei Dateien mit ihren ursprünglichen
Besitzerrechten wieder her und lädt Ansichten sowie PHP-FPM neu.


## 19.11.2026 � Horizontaler Randlauf beim Verschieben

Bestehende Belegungen scrollen beim Ziehen nahe dem linken oder rechten
Kalenderrand automatisch �ber weitere Spielfelder. Das gilt f�r Flutter
und den Webkalender; diagonales Scrollen bleibt gleichzeitig m�glich.
Die Geschwindigkeit steigt zur Kante hin bis 480 Pixel pro Sekunde.
Die fixierte Zeitachse geh�rt nicht zur nutzbaren Ablagefl�che.
Ein stillstehender Finger oder Mauszeiger h�lt den Randlauf aufrecht.

Beim Loslassen wird das Zielspielfeld aus der aktuellen Bildschirmgeometrie
ermittelt. Damit wird auch nach station�rem Scrollen das neue Feld unter
dem Zeiger verwendet, statt eines zuvor getroffenen Drag-Ziels. Dauer,
Zeit-Raster und bestehende Berechtigungs-/Konfliktpr�fungen gelten weiter.
Beim Feldwechsel wird die bisherige Teilfl�che zur�ckgesetzt. Abbruch,
Ansichts- und Hintergrundwechsel stoppen beide Scrollrichtungen.
Die Bereichsauswahl bleibt beim urspr�nglich gew�hlten Spielfeld.

Pr�fung: 63 Fluttertests (Kalender, Bereichsauswahl und Belegungs-Drag),
18 JavaScriptf�lle sowie 25 PHP-Webtests mit 136 Assertions erfolgreich;
ein optionaler Fixtureexport �bersprungen. Dart-Analyse ohne Befund,
Sprachcheck 2994 Schl�ssel DE/EN. Neue Regressionen pr�fen station�re
Links-/Rechtsbewegung mit veraltetem Drag-Ziel, diagonale Bewegung,
Scrollgrenzen, Zeitachse und Abbruch. Native iPhone-Abnahme auf dem
Benutzer-Mac steht aus.

DEV auf Basis `20261119-clubunio-support-management` und Stage aktualisiert.
Nur JavaScript und Kalender-Bladevorlage ausgerollt, keine Migration.
Beide Vorlagen kompilieren; JavaScript v5 liefert �ber beide Customdomains
HTTP 200 und den gepr�ften SHA256
`c85241d801ef4899f1f1d50806f856c49b54663403c4a8dd1ba1e6ccf2576d4c`.
Stage-Fachdelta und synchronisierter Stage-Stand: `3ac81c0`.
Sicherung: DEV `shared/pitch-horizontal-scroll-backup-20261119`,
Stage `/root/clubunio-backups/pitch-horizontal-scroll-20261119`.
R�ckweg: die zwei gesicherten Dateien inklusive urspr�nglicher
Besitzerrechte wiederherstellen, Bladecache leeren und PHP-FPM neu laden.
