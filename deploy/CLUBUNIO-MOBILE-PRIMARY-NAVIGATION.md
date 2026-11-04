# Mobile Hauptnavigation — 04.11.2026

Neue Priorität nach dem SVS-Vorbild des Benutzers:

**Spesen · Mitglieder · Chat · Termine · Mehr**

Die drei Fachmodule erscheinen ausschliesslich bei Freigabe im persönlichen
Modulvertrag. Die App verwendet keine pauschale Rollenannahme und vergibt keine
Rechte. Die ausgewählten persönlichen ClubUnio-Adapter und Serverprüfungen
bleiben unverändert. Ein frisch entzogener Einstieg verschwindet mit seiner
zugehörigen Hauptansicht.

Der hervorgehobene Chat bleibt auch bei ausgeblendeten Modulen exakt mittig.
Andere Ziele verwenden die ruhige Symbolansicht und gemeinsame aktive
Beschriftung. Farben bleiben an ClubUnio/AppBrand gebunden. „Mehr“ öffnet das
vorhandene vollständige Modulmenü mit Heute, My Teams, Profil und weiteren
berechtigten Funktionen. Die Glocke oben öffnet weiterhin den Posteingang.

Spesen und Mitglieder verwenden ihre vorhandenen Screens jetzt im Hauptbereich
mit sichtbarer unterer Leiste. Sitzung und Mandant binden die Repositories;
Konto-/Mandantenwechsel setzt sie zurück. Keine neuen API-, Schema-, Daten-
oder Betriebsänderungen. DEV bleibt auf `mobile-functional-v2` aus `9cf83de`;
für diese App-Anpassung ist kein Backend-Deployment erforderlich.

Appdetails und Abnahme im separaten Mobile-Repository unter
`docs/PRIMARY-NAVIGATION.md`. Geprüft werden echte Modulwechsel, Rechteentzug,
Mehr-/Profileinstieg, acht Berechtigungskombinationen sowie 320/390/1024 Pixel
mit grosser Schrift. Die native iPhone-Simulatorabnahme bleibt getrennt.

Abgenommen:535/535 Fluttertests, komplette Analyse ohne Befund.
App-Commit:`3792e56`. Keine native iPhone-Abnahme behauptet.
