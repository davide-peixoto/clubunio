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

Dateiliste, gezielte Regressionen und angemeldete Desktop-/Mobilpruefung sind
unten nachgefuehrt. Daraus keine pauschale Vollabnahme aller externen
Fachprozesse oder Provider-Schreibaktionen ableiten.

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

## Auslieferung und Nachweise

Aktiv: `20261102-clubunio-administration-ui-responsive`, Runtime-Quellstand
`168ff093fb35af27c85f36a118f401f36df4b69b`. Initiales UI-Delta auf
`20261102-clubunio-family-navigation`, danach zwei eng begrenzte Darstellungs-
Nachlaeufe. Sofortiger Rueckweg: `20261102-clubunio-administration-ui-final`;
vollstaendiger Rueckweg vor dieser UI-Umstellung: family-navigation.
Alle71 Laufzeitdateien gegen den Git-Blobhash abgeglichen. Vollstaendiges
Quell-Delta-Archiv SHA256:
`5df547337f628937697338c15328d673bdddf5074192d1b5c503a576c5b86cc0`.
Keine Env-, Secret-, Upload-, Vendor- oder Mobile-Dateien im Delta.

- Alle neun Releasegates erfolgreich, inklusive Profil-/Domain-/API-Grenzen,
 196 verschluesselter Settings und46 bestehender Nachrichtenvorlagen.
- Breiter Regressionstest:106 Faelle/1299 Assertions, zunaechst zwei veraltete
  HTML-Filtererwartungen in Bus/Teamplanung. Auf native Filter angepasst;
  beide betroffenen Fachtests anschliessend gruen (2 Tests/34 Assertions).
  Kein erneuter kompletter106er-Lauf nach den rein visuellen Nachkorrekturen.
- Neue Workspace-Bus-/Absenz-/SFV-HTTP-Gruppe:6 Tests/99 Assertions gruen,
  inklusive frischer Worker-Anfragen, Suche+Filter und strikter419 bei
  gewechseltem Domain-/Vereinskontext. Team-/Trainingslisten zusaetzlich mit
  echten HTTP-Livewire-Folgeanfragen geprueft (9 gezielte Tests/100 Assertions).
- SFV-/Synchronisationsgruppe14 Tests/343 Assertions, Vorlagen-/Formulargruppe
 28 Tests/505 Assertions und Sponsorentabellen5 Tests/95 Assertions gruen.
  Letztere prueft die tatsaechlichen lesenden Tabellenaktionen eines Nur-Lesen-
  Profils und weiterhin gesperrte Mutationen, nicht nur direkte Methodenaufrufe.
- API-Verwaltung18 Tests/102 Assertions plus neuer gezielter Filter-/Suchtest
  erfolgreich. Bexio-Abgleichgruppe inklusive Layouttest15/107, operative
  Bexio-Tabellen8/77 gruen. Gruppen ueberlappen; Zahlen nicht addieren.
- Alle43 betroffenen Blade-Vorlagen als kompiliertes PHP syntaktisch geprueft,
  geaenderte PHP-Quellen ohne Syntaxfehler. Layout-/Tabellenvertrag abschliessend
 2 Tests/21 Assertions gruen, mobile Gridbegrenzung als Regression festgehalten.
- Angemeldete Browserabnahme: Teamplanung mit Suche/Leerergebnis/Ruecksetzen
  bei erhaltenem Datum, Sponsorenanlass/Teilnehmer/Zusagen in CHF und SFV-
  Quelltabelle. Desktop1440px, Mobil390px; Tabellen innerhalb343px breiter
  Container mit lokalem Scrollen, keine Seitenueberbreite. SFV-Grid zuerst
  zu breit, explizite minmax(0,1fr)-Spur korrigiert und erneut bestaetigt.
- Weitere angemeldete Seitenaufrufe erfolgreich: Trainingsplaene, Bus,
  Absenzen, Planungs-SFV, Dokumentenserien, Bexio-Abgleich, API-Verwaltung,
  Synchronisation und Sponsorenabrechnung. Keine Anlage/Aenderung realer Daten,
  kein Sync-/KI-/Versand-/Zahlungsbutton betaetigt. Viewport zurueckgesetzt.

Ruecksprung nur als Symlinkwechsel plus PHP-FPM-Reload; gemeinsame Daten,
Konfiguration und Dateien nicht zuruecksetzen. Bestehendes PHPMaker DEV/PROD
und paralleler Mobile-Arbeitsstand unveraendert.
