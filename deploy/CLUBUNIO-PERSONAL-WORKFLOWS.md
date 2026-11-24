# Persönlicher Arbeitsbereich und zentrale Arbeitsabläufe

Stand: 24.11.2026. DEV-Release: `20261124-clubunio-personal-workflows-v4`.
Direkter Vorgänger: `20261124-clubunio-personal-workflows-v3`.
Fachlicher Rückweg: `20261124-clubunio-module-workspaces-v1`.

## Sechs Pakete

1. **Mein Verein** (`/my-club`): rollenabhängige Schnellaktionen, offene
   Freigaben, bis zu acht berechtigte Termine der nächsten sieben Tage,
   Favoriten, zuletzt verwendete Objekte und gespeicherte Listenansichten.
2. **Freigabezentrale** (`/approval-center`): getrennte Warteschlangen für
   eingereichte Spesen, Mitgliederänderungen, zukünftige Busanfragen und
   Platzanfragen. Zehn Einträge je Seite, Vorschau, Originalaufruf,
   Prüfvermerk und ausdrückliche Bestätigung des Einzelentscheids.
   Spesen werden von eingereicht nach zahlungsbereit bzw. abgelehnt bewegt;
   es gibt keine Bankzahlung. Nicht erlaubte Entscheide sind ausgeblendet
   und werden zusätzlich im Fachservice abgewiesen.
3. **Mitglieder-Zeitleiste**: Aktion im Dossier öffnet die neuesten 60
   Ereignisse aus berechtigten Quellen. Rechnungs-/Beitragserfassung,
   Dokumenterfassung, Stammdatenänderungen und vergangene Termine mit
   Teilnahmeeintrag. Der Teilnahmeeintrag behauptet keine Anwesenheit.
   Änderungsprotokolle zeigen keine Rohdaten oder geschützten Vorher-/Nachherwerte.
4. **Planungskonflikte** (`/planning-conflicts`): Wochenübersicht über
   Mannschaftsüberschneidungen, gleichzeitig angefragte/bestätigte Busse,
   Anlagen-/Trainingsbelegung und Sperren. Nutzt die vorhandenen erlaubten
   Planungsquellen und deren Geometrie-/Konfliktregeln. 24 Hinweise je Seite.
   Angrenzende Zeitintervalle gelten nicht als Überschneidung. Vorschläge
   umgehen keine verbindlichen Prüfungen beim Speichern oder Genehmigen.
5. **Mobile Schnellaktionen**: feste, beschriftete Aktionsleiste mit „Mein
   Verein“ und bis zu drei erlaubten Aktionen. Spesen- und Terminerfassung
   öffnen die bestehenden Formulare. Die Leiste berücksichtigt Safe Areas,
   wird bei Texteingabe ausgeblendet und überdeckt den Supportknopf nicht.
6. **Persönliche Ansichten**: bestehende Filter-/Spaltenspeicherung bleibt
   erhalten. Alle 22 unterstützten Listen sind aus dem Arbeitsbereich mit
   eigener gespeicherter Ansicht aufrufbar; 20 Ansichten je Übersichtsseite.
   Favoriten und zuletzt verwendete Mitgliederdossiers, Rechnungen und
   Spesen werden zusätzlich je Benutzer und Verein gespeichert.

## Rechte und Datenhaltung

Die drei neuen Seiten gehören zur Navigation „Mein Arbeitsbereich“. Sie
verwenden die vorhandenen Module/Rechte; kein bestehendes Benutzerprofil
erhält zusätzliche Fachrechte. API-Konten, inaktive Benutzer und Support-
Simulationen sind ausgeschlossen. Livewire hält einen gesperrten
Mandanten-/Sitzungsstand und prüft ihn bei Aktionen erneut.

Freigaben delegieren an `ExpenseService`, `MemberChangeService`,
`BusReservationService` und `PitchPlanningService`. Die bestehende Revision,
Mandantensperre, Konfliktprüfung, Protokollierung und Benachrichtigungsregel
bleiben verbindlich. Veraltete Vorschauen können keinen neueren Stand
überschreiben. Platzfreigaben bieten hier keine Sperrstatus-Ausnahme.

Favoriten/Verlauf verwenden die vorhandene Tabelle `saved_views`, getrennt
durch `tenant_id`, `account_id`, `list_key=workspace`, `name=personal`.
`state_encrypted` bleibt verschlüsselt. Änderungen sperren das Konto wie die
bestehende Listenansichtsspeicherung. Der Verlauf speichert höchstens zwölf
Typ-/ID-Referenzen. Titel und Zugriff werden neu aufgelöst; fremde oder
inzwischen gesperrte Objekte erscheinen nicht. Der Verlauf lässt sich leeren.
Keine zusätzliche Migration und keine Änderung an `.env` oder Importdaten.

Der bestehende React-Parameter steuert weiterhin das Vereinsdesign.
Mit React werden die vorhandenen Mandantenfarben und Kontrastwerte genutzt;
ohne React erhalten die neuen Funktionen eine neutrale, bedienbare Oberfläche.
DE/EN, Dark Mode, Tastaturfokus und reduzierte Bewegung werden berücksichtigt.

## Prüfung und Release

Lokale Tests: `WorkspaceServicesTest`, bestehende Präsentations-/Theme-/React-
und Social-Regressionen; `php tools/check-translations.php`.
Die zusätzlichen PostgreSQL-Profiltests sind lokal weiterhin opt-in.

`php deploy/check-workflows.php` prüft auf DEV die drei Seiten in DE/EN und
beiden Design-Modi. Zusätzlich: persönliche Speicherung, direktes Laden einer
Listenansicht, Dossier-Zeitleiste, vorhandene Freigabevorschauen, reales
eingeschränktes Profil, fremdes Dossier und Entzug der Profilrechte.
Eine synthetische Ablehnung prüft den vollständigen Fachservice und die
Aktualisierung der Warteschlange. Alle Testdaten werden zurückgerollt;
externe HTTP-Aufrufe sind blockiert. `--security` wiederholt nur die Fachchecks.

Abnahme: 30 lokale Tests / 438 Assertions und Übersetzungsprüfung erfolgreich;
27 DEV-Prüfungen inklusive DE/EN, beiden Designs und echtem Profilzugriff
sowie 15 erneut ausgeführte Fachchecks nach den Browserkorrekturen erfolgreich.

Browserabnahme vom 24.11.2026: Chrome, Desktop und responsive Handyansicht
390 × 844. Geprüft: primäre Navigation/Ctrl+K, Favoriten einschliesslich
Neuladen und Rücksetzen, offener Modulbereich nach Änderung, Verlauf nach
Dossieraufruf, Sandbox-Zeitleiste mit Rechnungs-/Änderungsereignissen,
Spesenvorschau samt Bestätigungsabbruch, Warteschlangenwechsel, Wochenwechsel
und Konfliktlinks, Spesen-/Termin-Schnellformulare samt Abbruch, Listen-Speicherdialog sowie
DE/EN und Dark Mode. Speicherung und direktes Laden von Listenansichten
sind zusätzlich durch den zurückgerollten DEV-Fachtest belegt.
Keine bestehende Freigabe, Zahlung oder Fachdatenerfassung ausgeführt.
Testfavorit entfernt, Testsuche zurückgesetzt; Deutsch/Systemtheme und
normale Desktopgrösse wiederhergestellt. Der Verlauf darf die besuchten
Dossiers weiterhin enthalten.

Korrekturen aus der Sichtprüfung: Die drei Seiten stehen nun im primären
Menü; Spesen-Schnellaktion öffnet das vorhandene Tabellenformular statt
auf eine nicht vorhandene Create-Seite zu verweisen. Die Modulauswahl bleibt
beim Favoritenwechsel geöffnet. Lange Kartenbeschriftungen umbrechen, mobile
Vorschauen scrollen in den sichtbaren Bereich und erhalten den Fokus;
Konflikthinweise sind chronologisch sortiert. Mobile Schnellziele sind
mindestens 48 Pixel hoch. Es gibt keine horizontale Seitenüberbreite.
Die Prüfung verwendete die responsive Chrome-Ansicht, kein physisches Handy.

Neue Filament-Seiten benötigen einen aktualisierten Route-Cache im Release.
DEV behält sein bestehendes Schema `clubunio`, die private `.env`, Storage
und Composer-Abhängigkeiten. Rollback durch Aktivieren des genannten fachlichen Rückwegs und
Neuladen von PHP-FPM. Persönliche Datensätze dürfen erhalten bleiben;
der Vorgänger ignoriert den zusätzlichen `workspace`-Schlüssel.
Stage und PROD bleiben unverändert.
