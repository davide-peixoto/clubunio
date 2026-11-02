# Einheitliche Verwaltungsoberflaeche

## Ziel und Abgrenzung

Alle normalen Verwaltungslisten sollen die bestehenden Filament-Tabellen,
Suchfelder, Filter, Sortierung, Seitenauswahl und Objektaktionen verwenden.
Die Darstellung ist kein neuer fachlicher Zugriff: bestehende Fachservices,
Vereinsgrenzen, Profile, Fristen und private Daten bleiben massgeblich.

Kalender, Saison-Wizard, grafische Trainingsinhalte, Vergleichsvorschauen und
oeffentliche Signage bleiben zweckgebundene Ansichten. Eine Vergleichsvorschau
ist keine bearbeitbare Liste und bekommt keine wirkungslosen Such-/Sortierknopfe.
Ihre Eingaben, Aktionen und Journalansichten folgen aber denselben Komponenten
und Abstaenden. PDFs und die oeffentliche TV-Darstellung bleiben eigenstaendig.

## Technischer Rahmen

- Bestehende Filament-Resources bleiben unveraendert.
- Fachlisten werden lesend an die vorhandenen Fachservices angebunden; keine
  Mutation durch neue tabellarische Eloquent-Adapter.
- Neue Livewire-Listen pruefen Benutzer und aktuellen Vereinskontext erneut.
  Ein gespeicherter Komponentenstand ersetzt keine frische Berechtigungspruefung.
- Native Tabellen liegen im Workspace im eigenen Abschnitt `native-content`.
  Die bisherige Workspace-CSS gilt nur noch innerhalb `workspace-content` und
  ueberschreibt keine Filter, Dropdowns oder Zeilenaktionen nativer Tabellen.
- Statische Vergleiche/Journale nutzen die gemeinsame `cu-admin-journal`-
  Darstellung; Eingaben und Aktionen verwenden Filament-Komponenten.
- Alle bestehenden POST-Ziele, CSRF-Pruefungen und verschluesselten Workspace-
  Momentaufnahmen bleiben erhalten. Reine Portalansichten bleiben getrennt.

## Migration

Keine Tabellen-, Daten- oder Rechte-Migration. Kein Datenbankrefresh, kein
Provideraufruf durch das Deployment, keine Aenderung an PHPMaker DEV/PROD,
Flutter, Abos oder SaaS-Abrechnung. Bereitstellung als geprueftes Delta auf dem
jeweils verifizierten ClubUnio-Release; gemeinsamen Storage und Konfiguration
erhalten. Vorheriger Release bleibt als Rueckweg verfuegbar.

## Abnahme

Dateiliste, gezielte Regressionen und angemeldete Desktop-/Mobilpruefung werden
nach Integration der Fachlisten hier nachgefuehrt. Ohne entsprechende Nachweise
keine pauschale Vollabnahme aller Fachprozesse ableiten.

## Abdeckung am 02.11.2026

| Bereich | Einheitliche Bedienung |
| --- | --- |
| Teamplanung, Trainingsplaene | Native Tabellen, Suche, Filter, Sortierung, Seitengroesse, Objektlinks und berechtigte Neuanlage |
| Busreservierungen | Native Tabelle mit Bus-/Team-/Status-/Datumsfiltern und bestehenden Detailwegen |
| Absenzen | Native Liste, berechtigte eigene Bearbeitung/Loeschung, private Bemerkungen weiter nach Fachscope |
| SFV-Spiele in Planung | Native Tabelle, Datumsfilter, Suche und weiterhin gepruefte Uebernahme |
| SFV-Wettkaempfe | Native Quelltabelle mit Auswahl, Zeitraum-/Einzelpruefung und unveraendertem Uebernahmeablauf |
| Sponsorenlauf | Native Anlass-, Teilnehmer-, Zusage-, Helfer- und Outboxtabellen, Geldwerte in CHF |
| Sponsoren-Abrechnung | Native Tabelle, Filter, bestaetigte Fachaktionen, keine automatische Provider-Ausfuehrung |
| Bexio-Abgleich und Versandpruefung | Native Dokumentlisten; begrenzte Nachweis-/Versuchsjournale einheitlich gestaltet |
| Bexio-Testbelege | Native Tabelle, bestehende explizite Freigaben und Provider-Schutzmassnahmen |
| Dokumentenserien | Native Historie; Vorlage-/Empfaenger-/Positionseditoren bleiben besondere Arbeitsablaeufe |
| Synchronisation | Native Auftragsliste, Suche, Status-/Providerfilter, Sortierung, Seitenauswahl, gepruefter Verlauf und bestaetigte Wiederaufnahme |
| API-Verwaltung | Native Integrations-/Token-Metadatenliste, Suche, Statusfilter und bisherige Admin-Grenze; begrenzte Auftrags-/Auditjournale bleiben Journale |
| Formulare und Vorschauen | Filament-Eingaben, Auswahlfelder, Aktionen, Abschnitte und gemeinsame Journalgestaltung; bestehende CSRF-/Workspace-Tokens bleiben erhalten |

Profile mit `sponsor_runs.view` koennen die lesenden neuen Anlass-/Teilnehmer-
Tabellenaktionen verwenden. Die globale Aktionspruefung ordnet diese Komponenten
dem vorhandenen Modul zu; keine Profilberechtigung oder Rollenvergabe wird
geaendert. Unbekannte/mutierende Aktionen bleiben weiterhin gesperrt und
Fachservices pruefen jede tatsaechliche Operation erneut.

## Bewusste Unterschiede

Spieler-/Elternportal und oeffentliche Anmeldung behalten ihren eigenen,
eingeschraenkten Darstellungsweg. Plattform-/Supportverwaltung behalten ihre
getrennte globale Autorisierung; Vergleichstabellen sind gemeinsam gestaltet.
Kalender und Saisonboard behalten die fuer diese Aufgaben erforderliche
Raum-/Zeitdarstellung. Das bedeutet keine Umstellung von API-Vertraegen oder App.
