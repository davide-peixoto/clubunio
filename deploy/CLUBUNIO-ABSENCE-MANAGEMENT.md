# Absenzen erfassen und bearbeiten (02.11.2026)

## Fachlicher Umfang

Unter Teamplanung > Absenzen stehen die native Aktion **Absenz erfassen**
und die Bearbeitung je Zeile zur Verfuegung. Beide oeffnen den gemeinsamen
seitlichen Formulardialog. Die Mitgliederauswahl ist suchbar und begrenzt
Ergebnisse auf 50; die komplette Mitgliederliste wird nicht vorab geladen.

- Admin/Geschaeftsstelle: aktive Mitglieder des eigenen Vereins, auch ohne
  eigene Mitgliedsverknuepfung und ohne Mannschaftszuordnung des Mitglieds.
- Trainer: eigene/vertretbare Mitglieder sowie aktive Spieler und Staff der
  aktuell zugeordneten Mannschaften.
- Technische Leiter: entsprechende Mitglieder ihrer aktuell zugeordneten Teams.
- Spieler/Eltern: weiterhin ausschliesslich eigene/berechtigt vertretene Mitglieder.
- Berechtigungsprofile: bestehende explizite planning.create/update-Rechte;
  reine Leseprofile erhalten keine Schreibmoeglichkeit.

Keine erweiterten Loeschrechte. Fremde Vereine, nicht zugeordnete Mannschaften
und entzogene Zuordnungen bleiben serverseitig gesperrt. Die Berechtigung wird
beim Speichern erneut geprueft, nicht nur beim Anzeigen des Formulars.
Bemerkungen bleiben verschluesselt; die bestehenden Datenschutzregeln gelten.

Der vorhandene Fachservice uebernimmt die Nachfuehrung der Teilnahmen.
Fristgesperrte/vergangene Termine und manuelle Trainerentscheidungen bleiben
unveraendert. Revision, Audit, Ueberlappungen und Wiederherstellung bleiben erhalten.

## Migrationsfolge und Rueckweg

1. Nur Controller, Fachservice und die drei betroffenen Ansichten sowie die
   native Absenztabelle als geprueftes Delta auf ClubUnio uebertragen.
2. Keine DB-Migration, Datenkopie, Seeder, Profilzuweisung oder neue Einstellung.
3. Vorherigen Release erhalten; Konfigurations-, Routen- und Viewcache im neuen
   Release aufbauen, bestehende Schluessel/Storage unveraendert weiterverwenden.
4. Vor Aktivierung alle bestehenden neun Release-Gates ausfuehren.
5. Angemeldete Formularpruefung ohne echte Absenz erfassen; synthetische
   Schreibtests werden vollstaendig zurueckgerollt.

Rueckweg: vorherigen Release aktivieren; keine Schema-Rueckmigration erforderlich.
Bereits fachlich erfasste Absenzen bleiben gueltige Datensaetze des bestehenden
Schemas. Bestehendes PHPMaker DEV/PROD und parallele Mobile-Arbeiten werden
nicht mit diesem Delta ausgerollt. Keine Provider-/Zahlungsaktion.

## Pruefungen

Gezielte Tests decken Trainer/TL, Admin ohne Selbstverknuepfung, reine Leseprofile,
fremde Mitglieder/Vereine, entzogene Zuordnungen und unveraenderte Loeschrechte ab.
Der native Dialog wird mit echten aufeinanderfolgenden Livewire-HTTP-Anfragen
fuer Erstellen und Bearbeiten geprueft. Der Testhelfer setzt dabei auch den
requestbezogenen Livewire-Zustand zurueck; die produktiven Sitzungs- und
Mandantenpruefungen wurden nicht abgeschwaecht.

Gezielte Regression: 13 Tests/157 Assertions erfolgreich (Workspace-Bus-/Planungs-
tabellen plus Absenzen, Fristgrenzen und Ueberlappungen). Zusaetzliche Negativ-
pruefungen fuer fremde bestehende Absenzen und entzogene TL-Zuordnung separat:
1 Test/17 Assertions erfolgreich (ueberlappt mit der obigen Gruppe).
Alle sechs betroffenen PHP-Quellen/Tests ohne Syntaxfehler.

## Auslieferung und Sichtpruefung

Ausgeliefert als `20261102-clubunio-absence-management`, Quellrevision
`a0d59a6b16069090e8594dc1cb47e49cd0c57941`. Vorheriger Release
`20261102-clubunio-administration-ui-responsive` bleibt erhalten.
Alle sechs Laufzeitdateien vor Ueberlagerung mit dem bisherigen Git-Stand und
nachher mit der Quellrevision abgeglichen. Alle neun Release-Gates bestanden;
Konfigurations-, Routen- und Viewcache neu erstellt, PHP-FPM neu geladen.

Angemeldete Chrome-Pruefung auf dev.clubunio.ch:
- Erfassungsaktion und Bearbeitung beider vorhandenen Zeilen sichtbar.
- Mitgliedersuche liefert begrenzte Treffer; Auswahl nur lesend getestet.
- Bearbeitung laedt Mitglied und bestehende Von-/Bis-Daten korrekt vor;
  Mitglied bleibt im Aenderungsdialog gesperrt.
- Desktop1440x900 und Mobil390x844 geprueft. Mobil Dokumentbreite390px bei
  Viewport390px, alle Felder und Formularaktionen innerhalb des Dialogs.
- Alle Dialoge abgebrochen, keine echte Absenz angelegt/geaendert und kein
  Provider-/Push-/E-Mail-Aufruf durch diese Sichtpruefung.
- Temporaere Viewportvorgabe anschliessend aufgehoben.

Rollenpruefungen fuer Trainer/TL erfolgten mit isolierten Rollback-Testkonten,
die angemeldete Live-Sichtpruefung mit dem bestehenden Adminkonto.
Screenshots nur lokal unter storage/framework/testing/absence-management-*.png,
nicht im Repository. Bestehendes PHPMaker DEV/PROD unveraendert.
