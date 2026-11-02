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
Deployment-/Browserabnahme werden nachgefuehrt.
