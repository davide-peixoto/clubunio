# Modulspezifische Arbeitsansichten

Stand: 24.11.2026. DEV-Release: `20261124-clubunio-module-workspaces-v1`.

Alle Ergänzungen folgen dem bestehenden Vereinsparameter **React = Ja/Nein**
und den gemeinsamen Mandantenfarben aus `BackendTheme`. Es gibt keinen
zusätzlichen Designschalter. Fachliche Aktionen, Rechte, Datenquellen und
Versandfreigaben bleiben erhalten.

## Sechs Pakete

- Training/Saison: Kartenraster für Trainingspläne mit bestehenden Filtern,
  Sortierung und Aktionen; Kennzahlen und Übungskarten im Trainingsdetail;
  aktueller Planungsschritt und Dokumentzähler im Saisonassistenten.
- Mitglieder: Dossierkopf mit Initialen, Mitgliednummer, übersetztem Status/Typ
  sowie verfügbaren E-Mail-/Telefonaktionen; bestehende Register bleiben erhalten.
- Rechnungen/Spesen: Kennzahlen der **aktuellen Tabellenseite** aus bereits
  geladenen aktiven Positionen inklusive Rechnungsrundung. Im Detail tatsächlicher
  Status, Workflow, vorhandener Abgleichsaldo bzw. Freigabe-/Zahldaten.
  Fehlende Abgleichdaten werden ausdrücklich bezeichnet.
- Kampagnen/Social Media: tatsächliche Kampagnenphase und Empfängerzahl;
  Wochennavigation mit Tagesauswahl im Social-Tagesprogramm, Vorschaukarten
  vorhandener Beiträge. Der Kalender ist eine Tagesagenda der vorhandenen
  Wettkämpfe; Beiträge und Veröffentlichungsaufträge bleiben in ihren Registern.
- Chat/Arbeitseingang: Unterhaltungs-/Ungelesenzähler, kombinierbarer Filter
  für ungelesene Chats, Initialen und dezente Übergänge; berechtigte Prüfaufgaben
  als Kennzahlen im Arbeitseingang. Kein automatischer Nachrichtenversand.
- Synchronisation/Integration: tatsächliche Auftragszahlen über alle Anbieter
  des Vereins, Verlauf als Zeitleiste und berechtigter Synchronisationslink
  im Integrationsbereich. Tabellenfilter und Wiederholungsaktionen bleiben erhalten.

Animationen respektieren die bestehende Bewegungseinstellung und
`prefers-reduced-motion`. Gestaltung ist responsiv und für Dark Mode vorgesehen.
Statuswarnungen behalten ihre fachliche Bedeutung. PDF-/Portalansichten
erhalten keine Übungskarten. Die Fachansichten bleiben Filament/Livewire;
die gemeinsame Gestaltung ergänzt die vorhandenen React-Inseln.

## Build und Prüfung

`npm run build:social-workspace` erzeugt das eigene Social-Bundle samt CSS;
klassischer Modus verwendet weiterhin den vorhandenen Vite-Einstieg.
Neue Texte liegen parallel in `lang/de/module_ui.php` und `lang/en/module_ui.php`.

Lokale Prüfungen: `ModulePresentationTest`, `BackendThemeTest`,
`ReactUiParameterTest`, `ReactNavigationTest`, `SocialStudioTest`,
`WebChatGateTest`, `SeasonPlanningLayoutTest`; Node-Kalender-/Chat-/Navigationschecks
und `php tools/check-translations.php`.

`php deploy/check-module-workspaces.php` ist ausschließlich auf DEV ausführbar.
Es rendert alle sechs Pakete und drei Dossierarten in DE/EN mit React an/aus,
verbietet externe HTTP-Aufrufe und rollt die Datenbanktransaktion zurück.
Es führt keine Versand-, Zahlungs-, Publikations- oder Synchronisationsaktion aus.

Abnahme: 18 PHP-Tests / 323 Assertions, sechs Node-Tests, 48 DEV-Renders
und zwölf zusätzliche Fixture-Renders für gefüllte Trainingskarten/-details und
Kampagnenphasen. Browser: Social-Wochenwechsel und Grafikvorschau, Finanzkennzahlen,
Mitgliederdossier, Trainingsliste, Saisonplanung, Kampagnen, Chat-Ungelesenfilter,
Arbeitseingang und Synchronisation. Geprüft auf 390 × 844, ohne horizontale
Überbreite; DE/EN und Dark Mode. Ursprüngliche Sprache/Systemtheme wiederhergestellt.

`php deploy/check-module-workspaces.php --fixtures` legt synthetische Trainings-
und Kampagnendaten ausschließlich innerhalb der zurückgerollten Transaktion an.

Zusätzlich korrigiert: Der bestehende Social-Bild-Endpunkt setzt Cache-Header
nun über den gemeinsamen Response-HeaderBag, damit auch gestreamte Dateien
funktionieren. Regressionstest prüft Bildbytes und private/no-store-Header.
Bildfehler haben eine übersetzte Ersatzanzeige statt eines defekten Bildsymbols.

## Betrieb

Keine Migration, keine neuen Parameter, keine geänderte `.env`.
DEV nutzt weiterhin sein bestehendes Schema `clubunio`.
Rollback: vorheriges Release `20261124-clubunio-backend-theme-v5` aktivieren
und PHP-FPM neu laden. Alternativ steuert der bestehende Vereinsparameter
die klassische Darstellung nach einem vollständigen Seitenaufruf.
Stage und PROD bleiben unverändert.
