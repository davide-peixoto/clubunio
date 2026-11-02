# Aufklappbare Fachnavigation (02.11.2026)

## Bedienung und Struktur

Saisons und Rechnungen erhalten je einen separaten Pfeilknopf. Der Knopf
klappt nur die bereits berechtigten Unterpunkte auf/zu; der benachbarte
Seitenlink bleibt unveraendert. Kein Seitenaufruf zum Oeffnen notwendig.
Aktive Unterbereiche sind initial aufgeklappt, andere initial geschlossen.
Menuesuche oeffnet die Untermenues voruebergehend und stellt beim Leeren
ihren vorherigen Zustand wieder her. Tastaturbedienbare Buttons mit
aria-expanded/aria-controls, benannte Heroicon-Pfeile; Mobilbedienung erhalten.

Finanzen hat drei direkte Einstiege: Rechnungen, Spesenbelege, Bexio-Abgleich.
Unter Rechnungen: Beitragsberechnung, Gutschriften, Kundenguthaben und
Rueckerstattungen. Ohne Rechnungsrecht ist der Elternknoten nur strukturell;
es entstehen keine zusaetzlichen Leserechte fuer Rechnungen.

Altplaene uebernehmen ist unter Einstellungen > Datenuebernahme erreichbar,
nicht mehr im Sport-Hauptmenue. Keine automatische Uebernahme ausfuehren;
die bewusste fachliche Zuordnung der bestehenden Importfunktion bleibt erhalten.
Die Listen heissen jetzt Quittungen und Adressliste fuer Massenversand.
Vorhandene URLs, Daten, Exporte und Dokumentberechtigungen unveraendert.

## Umsetzung und Migrationsfolge

Nur die zwei markierten Fach-Elternknoten verwenden eine schmale angepasste
Sidebar-Komponente. Alle uebrigen Eintraege delegieren auf die originale
Filament-Komponente ueber eigenen View-Namespace. Keine Vendor-Datei editiert.
Bei einem Filament-Update die Sidebar-Props und die Childitem-Schnittstelle
mitpruefen; der bestehende Render-/Navigationsvertrag ist durch Tests geschuetzt.

Gezieltes ClubUnio-Delta mit Navigation, View-Namespace, Sidebar-Vorlage,
beiden Ressourcenbezeichnungen und versionierten CSS/JS-Assets.
Keine DB-Migration, Datenkopie, Seeder, Profilzuweisung oder Provideraktion.
Neuen Release auf bisherigem season-navigation aufbauen und neun bestehende
Release-Gates vor Aktivierung ausfuehren. Vorherigen Release als Rueckweg
erhalten. PHPMaker DEV/PROD und parallele Mobile-Arbeiten nicht mit ausrollen.

10 gezielte Tests/80 Assertions erfolgreich: vollstaendige Linkerhaltung,
Saison-/Finanzverschachtelung, unabhaengige Profilrechte und Sidebar-Vertrag.

## Auslieferung und Abnahme

Ausgeliefert `20261102-clubunio-navigation-cleanup`, Quellrevision
`f5c3ce27f4a386a34db8f0a91b149f722c6a30d7`, Rueckweg
`20261102-clubunio-season-navigation` erhalten. Alle neun Release-Gates
erfolgreich, acht Laufzeitdateien gegen die Quellrevision abgeglichen.
Konfiguration/Routen/Views im neuen Release neu aufgebaut; keine DB-Aenderung.

Angemeldete Live-Browserpruefung:
- Quittungen als Seiten-/Tabellentitel, neue Adresslistenbezeichnung im Menue.
- Saisonpfeil auf/zu: URL bleibt unveraendert, aria-expanded wechselt korrekt,
  Spielerzuordnungen werden ohne vorherigen Seitenaufruf sichtbar.
- Rechnungspfeil per Enter oeffnet ohne Navigation; Gutschriften sichtbar.
- Gutschriftensuche oeffnet den zuvor geschlossenen Finanzknoten, Leeren der
  Suche stellt aria-expanded=false wieder her.
- Einstellungen > Datenuebernahme und Altplaene uebernehmen sichtbar.

Danach Browsersteuerung bei Vorbereitung der abschliessenden Mobil-/Screenshot-
pruefung unterbrochen; trotz erneutem Verbindungsversuch keine weitere Kontrolle
moeglich. Fuer diesen Nachlauf kein neuer Screenshot und keine abgeschlossene
mobile Sichtabnahme behauptet. Keine fachlichen Daten im Browser geaendert.
PHPMaker DEV/PROD und parallele Mobile-Entwicklung unveraendert gelassen.
