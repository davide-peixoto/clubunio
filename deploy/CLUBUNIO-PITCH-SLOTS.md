# Platzanfragen aus dem Kalenderslot

Stand 19.11.2026.

In der Tagesansicht öffnen freie Kalenderflächen im 30-Minuten-Raster das
Anfrageformular. App und Webkalender übernehmen das angeklickte Spielfeld,
Datum und Beginn. Die Dauer startet mit 120 Minuten und ist im Formular
änderbar. Erst das Absenden erstellt die Anfrage. Bestehende Belegungen
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
