# Zentraler React-Parameter und Menü

Aktuell: Einheitliche Backend-Komponenten und flexible Vereinsfarben verwenden
das bestehende Mandantendesign. [Umfang, Farben und Abnahme](CLUBUNIO-BACKEND-THEME.md).

Stand: 24.11.2026. Der Vereinsparameter `general / REACT_ENABLED` ersetzt
die drei unabhängigen Designentscheidungen für Dashboard, Team- und
Anlagenplanung. Die alten Sitzungswerte und URL-Parameter werden ignoriert;
separate Designschalter sind entfernt. Die bisherigen Ansichten bleiben
über React = Nein erreichbar.

## Bedienung

Unter **Einstellungen → Parameter & Integrationen → Allgemein** den Parameter
**React** über „Wert ersetzen“
auf **Ja** oder **Nein** setzen. Er gilt für alle Benutzer des ausgewählten
Vereins und alle angeschlossenen UI-Pakete, einschliesslich Menü. Änderungen
greifen beim nächsten vollständigen Seitenaufruf; die Einstellungsseite lädt
nach dem Speichern automatisch neu. Bereits offene Seiten anderer Benutzer
werden nicht ungefragt neu geladen. Keine Änderung der Modulberechtigungen.

Werte sind sprachunabhängig `1` und `0`, Anzeige und Hilfetext DE/EN.
Verwaltung und Audit verwenden den bestehenden SettingsService. Die
verschlüsselte Speicherung in tenant_settings bleibt erhalten. Keine neue
Tabelle. Fehlender oder ungültiger Parameter bedeutet Nein. Der Wert wird
nur innerhalb eines Requests und getrennt je Verein zwischengespeichert.

Der DEV-Installer erstellt den fehlenden Parameter ausschliesslich für
SV Sissach mit Ja und protokolliert die Anlage. Bestehende Werte werden nie
überschrieben. Andere Vereine bleiben ohne Parameter bei Nein; eine spätere
Einführung benötigt deren explizite Konfiguration. Die bisherigen drei
ENV-Gates werden nicht mehr gelesen. Historische Release-Dokumente beschreiben
den damaligen Stand; dieses Dokument ersetzt deren Schalterbeschreibung.

## Menüpaket

Neue CI-Seitenleiste mit aktiven Karten, abgerundeten Elementen und Hover-
bewegung; native Menüpunkte, Untermenüs, Suche, Rechte und Breitensteuerung
bleiben erhalten. React ergänzt eine Suchpalette in der oberen Leiste:
Ctrl/⌘ + K, Pfeiltasten, Enter und Escape. Suche berücksichtigt Bezeichnung,
Gruppe, mehrere Wörter und Akzente. Nur sichtbare, bereits autorisierte
Filament-Menüpunkte werden bereitgestellt. Keine externen Suchanfragen,
keine eigenen Berechtigungsabfragen im Browser. Ohne React keine Menü-
React-Assets. Native Navigation bleibt auch bei JavaScriptfehlern bedienbar.
Mobile Suchschaltfläche mit Barrierefreiheitslabel; Dialog mit Fokusführung.
Reduzierte Bewegung des Betriebssystems wird berücksichtigt.

## Auslieferung und Prüfung

DEV-Release: `20261124-clubunio-navigation-motion-v3`.
Rückweg: React = Nein; vorheriges Release `20261124-clubunio-team-ui-v1`.
Stage und PROD unverändert. Keine Schema-Migration oder Fachdatenimporte.

21 lokale Tests / 111 Assertions sowie Node-Menüsuche und Übersetzungsprüfung
erfolgreich. `deploy/check-react-ui.php` prüft beide Werte über den echten
SettingsService und rendert Kalender und Anlagenplanung in DE/EN; Änderungen
am Parameter und Audit verbleiben in einer zurückgerollten Transaktion.
Die früheren beiden UI-Prüfskripte delegieren an diese gemeinsame Prüfung.
24 DEV-Renderprüfungen erfolgreich; PHP-FPM aktiv, Menü-CSS HTTP 200.
Browserabnahme nach erneuter Anmeldung erfolgreich: React = Nein über den
Parameter gespeichert, Dashboard/Teamplanung/Anlagenplanung/Menü gemeinsam
klassisch und ohne separate Schalter. React = Ja wieder gespeichert und alle
vier Bereiche erneut modern. Zwei tatsächliche Einstellungsänderungen samt
bestehendem Audit; Endwert Ja.

Menüpalette in DE/EN, Mehrwortsuche, Leersuche/kein Treffer, Ctrl+K,
Pfeiltasten und Enter geprüft. Escape-Verhalten im Suchfeld nachgeschärft;
V2 im Browser geprüft: Dialog geschlossen und Fokus am Auslöser. Native
mobile Seitenleiste öffnet und schliesst weiterhin. Bei 390 Pixeln Breite:
Seite 375 / Dialog 337 Pixel; bei 320: Seite 305 / Dialog 267 Pixel.
Kein horizontaler Seitenüberlauf. Kein physischer Handy-/Safari-Test.
Sprache Deutsch und normale Fensterbreite wiederhergestellt.

Prüfbilder unter `react-menu-check/` im chatbezogenen Vorschauverzeichnis
liegen ausserhalb des Repositorys. Neue UI-Pakete müssen ebenfalls
`ReactUi::enabled()` verwenden; keine eigenen ENV- oder Sitzungsschalter.

## Menübereinigung

Die alte Suchlupe im Seitenmenü entfällt bei React = Ja. Die neue Suchpalette
bleibt verfügbar. Bei Nein oder fehlendem React-Menübundle bleibt die bisherige
Suche erhalten. Die Breitensteuerung bleibt unabhängig von der Suche initialisiert.
DEV-Browser bestätigt: alte Lupe fehlt, React-Suche und Breitensteuerung vorhanden.
Vier PHP-Tests / 27 Assertions, drei Navigation-JS-Tests und DE/EN-Prüfung erfolgreich.

## V35-inspirierte Navigationsbewegung

Referenz im angemeldeten Chrome angesehen: Code XR, Beitrag vom 16. November,
Dashboard V35. ClubUnio verwendet dessen Bewegungsprinzip mit eigener CI:
dunkelblaue, schwebende Icon-Leiste (76 Pixel), helle kreisförmige Markierung
und aufklappende Beschriftungen bei Hover oder Tastaturfokus. Die Markierung
folgt Hover/Fokus und bleibt beim Scrollen am Ziel. Keine Navigationslinks
oder Modulberechtigungen ersetzt. Mobile Ansicht bleibt eine vollständige
Seitenleiste mit lesbaren Beschriftungen und normalem Öffnen/Schliessen.

Gleichoriginige Seitenwechsel erhalten progressive CSS View Transitions;
Browser ohne Unterstützung behalten normale Navigation. Die Umsetzung folgt
der [Chrome-Dokumentation](https://developer.chrome.com/docs/web-platform/view-transitions/cross-document).
Keine Verzögerung von Links oder Änderung an Formularaktionen.

„Animationen“ in der Menüpalette steuert die Navigationsbewegung je Browser.
Standard ist die Systempräferenz; eine bereits ausdrücklich gewählte
Dashboard-Bewegungseinstellung wird beim ersten Aufruf übernommen.
`clubunio.ui.motion` speichert die ausdrückliche Wahl. Reduziert: 0 ms und
View Transitions aus. Aktiviert: 360 ms. Diese Wahl beeinflusst die Navigation;
bestehende Diagrammanimationen haben weiterhin ihre Dashboard-Einstellung.
React = Nein schaltet das gesamte Menüpaket weiterhin aus.

DEV v3, vier PHP-Tests/27 Assertions, Übersetzungsprüfung und Produktionsbuild
erfolgreich. Browser: 76-Pixel-Leiste, Aufklappen auf gespeicherte 318 Pixel,
Fokusmarker nach automatischem Scrollen ausgerichtet, Link zur Teamplanung
funktioniert. Bewegungsauswahl auf reduziert (0 ms / navigation:none) geprüft
und wieder aktiviert. Mobile Breite 390: Seite 375 Pixel, Sidebar x=0 geöffnet;
Logo und Vereinsauswahl auf dunklem Grund korrigiert. Fensterbreite danach
zurückgesetzt, Endzustand React Ja / Animationen aktiv. Keine Schema- oder
Fachdatenänderung, Stage/PROD unverändert. Prüfbilder `navigation-motion-check/`
liegen ausserhalb des Repositorys im Vorschauverzeichnis dieses Chats.
