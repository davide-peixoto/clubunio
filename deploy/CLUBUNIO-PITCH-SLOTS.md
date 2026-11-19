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
