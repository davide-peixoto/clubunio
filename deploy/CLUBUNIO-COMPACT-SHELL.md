# Kompakte Navigation und Dashboard (02.11.2026)

## Bedienung

Menuesuche ueber Lupen-Icon aufrufbar, mit benanntem Button und aria-expanded.
Beim Oeffnen Fokus im Suchfeld. Erneuter Klick oder Escape schliesst das Feld,
leert die Suche und stellt die vorherigen Gruppen-/Untermenuezustaende wieder her.
Keine dauerhafte Suchbeschriftung und kein Breitenregler mehr in der Seitenleiste.

Desktopbreite per Maus am rechten Seitenrand ziehen:240 bis420px, pro Benutzer
unter dem bestehenden lokalen Speicherschluessel erhalten. Pointer-Capture,
Abbruchbehandlung, RTL-Richtung und Tastaturbedienung (Pfeile/Home/End) ergaenzt.
Doppelklick setzt280px. Mobiler Seitenrand ist nicht ziehbar; bestehende
responsive Menuebreite bleibt erhalten. Keine Server-/Profilkonfiguration.

Dashboard ohne separate AccountWidget-Karte. Ueberschrift Willkommen, Vorname
aus dem ersten Wort des Konto-Anzeigenamens, bei leerem Namen nur Willkommen.
Keine weitere Mitgliederabfrage notwendig. Dashboard-Link und Kennzahlen bleiben.
Abmelden ausschliesslich ueber das bestehende Profilmenue, kein neuer Logoutweg.

## Migration und Pruefung

Keine DB-/Daten-/Rechte-Migration, keine Provideraktion. Eigene Dashboardklasse
statt der unveraenderten Filament-Basisklasse im ClubUnio-Panel registrieren,
AccountWidget dort entfernen. CSS/JS versioniert, Resizer ueber vorhandenen
Sidebar-Footer-Hook; keine Vendor-Dateien geaendert. Bestehende fachliche
Zugriffskontrollen und PHPMaker DEV/PROD unveraendert.

9 gezielte PHPUnit-Tests/81 Assertions sowie3 Node-Verhaltenstests erfolgreich.
Node prueft die tatsaechliche Navigationsdatei in einer isolierten DOM-Fixture:
Suche/Fokus/Escape, Pointerziehen/Speichern/Grenzen/Abbruch, Tastatur/Reset/Mobil.
Syntaxpruefungen ohne Fehler.

Nur eigenes Delta auf ClubUnio ausliefern, vorherigen Release als Rueckweg
erhalten, releasebezogene Caches erneuern. Parallel bereits aktivierten
mobile-communication-Release als Basis erhalten; dessen private .env und
Storage beibehalten. Alle elf vorhandenen Gates inklusive Mobile/Simulation
pruefen. Keine Mobile-Dateien im eigenen Delta, keine erneute Migration/Provision.

## Auslieferung und Abnahme

Release20261102-clubunio-compact-shell, Quellrevision
b595257752f970aeeee8e085b8c3717da4b5825f. Basis/Rueckweg ist der parallel
bereits aktivierte20261102-clubunio-mobile-communication. Dessen private
Aktivierung, Schema, Storage und Scanner bleiben erhalten. Alle elf Release-
Gates inklusive Mobile/Simulation bestanden; sieben Laufzeitdateien gegen
Git geprueft. Caches erneuert, keine Migration/Provideraktion ausgefuehrt.

Angemeldeter Chrome-Test: Dashboard-Ueberschrift Willkommen, Davide sichtbar,
Menuesuchbutton statt sichtbarem Suchfeld/Slider. Suche ueber Lupe geoeffnet,
Staff eingegeben, Escape geschlossen; aria-expanded danachfalse und Begruessung
weiterhin sichtbar. AccountWidget wird nicht mehr registriert; vorhandenes
Filament-Benutzermenue bleibt unveraendert, kein Logout im Test ausgefuehrt.

Anschliessender nativer Maus-Ziehtest von Computer Use aus Sicherheitsgruenden
beendet: aktuelle Fenster-URL nicht sicher zuordenbar. Keine weiteren UI-
Aktionen danach, kein neuer Screenshot und keine erfolgreiche reale Maus-/
Mobilabnahme dieses Nachlaufs behauptet. Keine Viewportvorgabe gesetzt und
keine fachlichen Daten geaendert. Automatisierte Pointer-/Tastaturtests sind
oben separat dokumentiert. Reale Mausbedienung als Restpruefung festhalten.
PHPMaker DEV/PROD unveraendert. Doku-Nachlauf ohne weiteren Runtimewechsel.
