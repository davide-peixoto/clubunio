# Einheitliches Vereinsbackend und Mandantenfarben

Stand: 24.11.2026. DEV: `20261124-clubunio-backend-theme-v5`.
Voraussetzung ist der bestehende Vereinsparameter **React = Ja**.

## Gestaltung und Umfang

Gemeinsame Oberflächen für native Listen, Formulare, Dialoge, Tabs, Kennzahlen,
Dropdowns und Einstellungen. Einheitliche Flächen, Rahmen, Radien, Fokus- und
Hoverzustände. Die vorhandenen Dashboard-, Team-, Anlagen- und Menüpakete
verwenden dieselben Farbwerte. Eingebettete Saisonplanung, Profil, Chat,
Kampagnen, Auswertungen und Support erhalten passende gemeinsame Flächen.
Layout und Fachabläufe bleiben erhalten; keine Umstellung der Fachlogik auf
React. Die vorhandenen React-Inseln bleiben mit Filament/Livewire verbunden.

Die öffentliche Webseite, Signage-Ausgabe, PDF-Layouts, API, Flutter-App und
separate Plattformverwaltung werden nicht durch dieses Backend-CSS gestaltet.
Fachliche Status-/Lookupfarben, Warnungen und Fehler werden nicht pauschal
auf die Vereinsfarbe umgefärbt.

## Eine vorhandene Quelle je Verein

`BackendTheme` liest `SignageBrandService::design()` des aktuell ausgewählten
Vereins. Damit gelten die bereits über Vereinsdesign bzw. Plattformverwaltung
konfigurierten Farben und sicheren Logos auch auf der zentralen Domain.
Es gibt keine neue Farbtabelle und keine duplizierten Designparameter.
Änderungen werden nach einem vollständigen Seitenaufruf sichtbar.

Der TenantBoundaryServiceProvider verbindet die bestehende Domain-Gestaltung
mit der ausgewählten Backend-Gestaltung. Auf Vereinsdomains bleiben die
Domainbindung und deren Rechte unverändert. Logo, Favicon und Namen passen
zum ausgewählten Verein, ClubUnio bleibt als Produktattribution sichtbar.

Aus einer validierten sechsstelligen Hexfarbe entstehen Primärpalette,
Akzentfarbe, dezente Flächen, Rahmen, Navigation und passende Schriftfarben.
Auch helle Vereinsfarben sind zulässig. Text auf Primär-/Hero-Flächen und
Navigationssymbolen wird mit mindestens 4,5:1 berechnet; die Navigation ist
für helle Beschriftungen abgedunkelt. Dark Mode hat eigene kontrastgeprüfte
Akzenttexte. Die helle aktive Markierung bleibt auch im Dark Mode hell.
Das sind gezielte Farbkontrastprüfungen, keine vollständige WCAG-Zertifizierung.

Branding-Caches liegen ausschliesslich in Request-Attributen, getrennt nach
Mandanten-ID. Keine statischen, sitzungs- oder browserweiten Vereinsfarben.
Der Wechsel in einen Verein mit React = Nein entfernt das Backend-Theme;
die bestehende Domain-Gestaltung und klassische Oberfläche bleiben verfügbar.

## Abnahme

- 23 lokale Tests / 332 Assertions: neun Farbextreme, Kontraste, ungültige
  CSS-Eingaben, getrennte Mandantencaches, Logo/Name, React-Rückweg,
  bestehende Branding-/Dashboard-/Planungstests.
- DE/EN-Kataloge geprüft, 3075 JSON-Schlüssel und keine offenen Literaltexte
  im dokumentierten Übersetzungsrückstand.
- `deploy/check-backend-theme.php`: 14 DEV-Renderprüfungen in DE/EN mit Rot,
  Grün und Gelb sowie Mitglieder-, Rechnungs-, Spesen- und Parameterlisten.
  Temporäre Designwerte liegen in einer zurückgerollten Transaktion; keine
  dauerhafte Änderung der bestehenden Vereinsfarben oder Fachdatensätze.
- Browser: SV Sissach rot (#cf1032), korrektes geladenes SV-Logo und Vereinsname;
  Mitgliederliste, Bearbeitungsdialog (20px Radius, Felder 11px), Trainingsliste
  und neues Trainingsformular geprüft. Alle Formulare ohne Speichern verlassen.
- Dark Mode geprüft und ursprüngliches Systemthema wiederhergestellt.
- Wechsel zu FC Horizon (React nicht aktiviert): keine SV-Farben/Logos oder
  Backend-Theme-Styles. Rückwechsel zu SV Sissach korrekt, Endzustand SV Sissach.
- 390 Pixel: Seiteninhalt 375 Pixel; 320 Pixel: Seiteninhalt 305 Pixel,
  Tabellen intern scrollbar. Normale Fensterbreite wiederhergestellt.
  Kein physischer Handy-/Safari-Test. Prüfbilder unter `backend-theme-check/`
  ausserhalb des Repositorys im Vorschauverzeichnis dieses Chats.

## Betrieb und Rückweg

Reines Code-/CSS-Release ohne Schema-Migration, Import oder dauerhafte
Parameteränderung. Stage und PROD unverändert. React = Nein ist der
funktionale Rückweg. Vorherige DEV-Basis: `20261124-clubunio-navigation-motion-v3`.
Vor späterer Übernahme auf andere Instanzen deren bestehende Designwerte,
Logo-Adressen, Rechte und Browserabläufe erneut prüfen.
