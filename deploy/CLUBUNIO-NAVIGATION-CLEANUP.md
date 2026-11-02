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
moeglich. Dieser offene Punkt wurde durch den unten dokumentierten Nachlauf
abgeschlossen. Keine fachlichen Daten im Browser geaendert.
PHPMaker DEV/PROD und parallele Mobile-Entwicklung unveraendert gelassen.

## Mobile Sichtabnahme abgeschlossen (02.11.2026)

Angemeldete Pruefung auf dev.clubunio.ch mit Chrome in responsiven Ansichten
390x844 und320x740, bestehendes Adminkonto. Frischer Testtab nach veralteter
Tab-Verbindung; keine neue Anmeldung, keine Konten-/Profil-/Fachdatenmutation.

- Mobile Seitenleiste vom Dashboard aus geoeffnet. Saisonpfeil oeffnet/schliesst
  ohne Navigation; URL bleibt auf Dashboard und die Seitenleiste bleibt offen.
- Alle sechs Saison-Unterpunkte lesbar und innerhalb des Menues; native Pfeile
  mit korrektem aria-expanded. Finanzpfeil zeigt alle vier Rechnungsunterpunkte.
- Suche Gutschriften oeffnet zuvor geschlossenen Finanzknoten. Leeren der Suche
  stellt den vorherigen Zustand aria-expanded=false wieder her.
- Gutschriften und Trainingszeiten ueber die Untermenues aufgerufen: richtige
  Zielseite, mobile Seitenleiste schliesst automatisch, aktiver Finanzbereich
  ist beim erneuten Oeffnen aufgeklappt.
- Menuesuche findet Quittungen und Adressliste fuer Massenversand. Bei320px
  bricht der lange Adresslistentext in zwei Zeilen um: Linkbreite/clientWidth
  und scrollWidth jeweils224px; keine horizontale Seitenueberbreite.
- Einstellungen > Datenuebernahme > Altplaene uebernehmen auch mobil erreichbar.

Screenshots lokal, nicht versioniert:
storage/framework/testing/navigation-cleanup-mobile-season.png,
navigation-cleanup-mobile-finance.png, navigation-cleanup-mobile-search.png und
navigation-cleanup-mobile-320-address.png (jeweils im selben Verzeichnis).
Temporaere Viewportvorgabe aufgehoben, Testtab geschlossen, urspruenglicher
Benutzertab unveraendert erhalten. Keine Codekorrektur, kein neuer Runtime-
Release und keine DB-/Rechte-/Provideraktion notwendig. Nur Doku-Nachlauf;
bestehendes PHPMaker DEV/PROD unveraendert. Kein Test auf realer iOS-/Android-
Hardware; Nachweis betrifft die responsiven Web-Menues im Chrome-Browser.
