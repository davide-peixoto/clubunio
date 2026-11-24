# ClubUnio: Alltagsabläufe auf DEV

Stand: 24.11.2026. Release `20261124-clubunio-daily-v1`, Basis
`20261124-clubunio-work-hub-v3`. Keine Schemaänderung und keine Migration.
Die bestehenden Work-Hub-Tabellen, Profile und Fachservices werden verwendet.
Stage und PROD sind nicht Bestandteil dieses Releases.

## Verhalten

- **SFV abgleichen** verbindet den interaktiven Backend-Abruf mit der Übernahme.
  Eindeutig zugeordnete zukünftige Heimspiele werden atomar als Wettkampf und
  Mannschaftstermin gespeichert; Auswärtsspiele erhalten nur einen Teamtermin.
  Konflikte, lokale Ausnahmen, widersprüchliche Verknüpfungen, fehlende Rechte,
  verschobene oder abgesagte Spiele bleiben einzeln zur Prüfung stehen.
  Spiele ohne Datum erscheinen unabhängig vom gewählten Zeitraum als Prüffälle.
  Wiederholungen erzeugen keine Dubletten. Maximal 500 Quellspiele pro Abgleich,
  zehn Prüffälle pro Seite. Der Abrufzähler und Übernahmezähler sind getrennt.
  API- und Hintergrundaufträge behalten ihren bisherigen Quellabrufvertrag;
  die automatische Übernahme ist auf den angemeldeten Backend-Kontext begrenzt.
- **Mein Verein** zeigt maximal sechs eigene heute fällige/überfällige Aufgaben
  und vier aktuelle Konflikte von heute bis Ende Woche, jeweils mit Gesamtzahl.
  Die vorhandenen berechtigten Freigaben bleiben Teil des Cockpits.
- **Aufgabenboard** zeigt offen, in Bearbeitung und erledigt. Drag-and-drop und
  Statusauswahl verwenden denselben Fachservice mit Revisionsprüfung. Die Karte
  wechselt erst nach erfolgreichem Speichern die Spalte. Je 20 Karten pro Spalte
  und Seite; Liste, eigene Aufgaben, Archiv und Wiederherstellung bleiben verfügbar.
- **Alternativen** bieten bis zu fünf freie Optionen am selben Tag innerhalb
  von zwei Stunden. Mannschaft und zugeordnete Fläche sowie Busbelegungen werden
  erneut unter Mandantensperre geprüft. Nur der einzelne Serientermin ändert sich.
  Es entsteht keine neue Platzreservierung; geschützte SFV-Termine werden nicht
  darüber verschoben. Ein Buswechsel erhält den vorhandenen Freigabestatus.
- **Meldungen** bündeln eigene zugängliche Hinweise nach Objekttyp und Objekt-ID.
  Die Glocke zählt ungelesene Gruppen. 20 Gruppen pro Seite, maximal zehn ältere
  Details pro Gruppe. Gelesen werden nur eigene Meldungen bis zur angezeigten
  neuesten Meldung; inzwischen eintreffende Meldungen bleiben ungelesen.
  Rechte werden auch für Zähler und Direktlinks frisch geprüft.
- **Spesenentwurf mit Beleg** akzeptiert Foto/Datei (JPEG, PNG, PDF, maximal 10 MB).
  Der Scanner muss vor dem Speichern einen sauberen Befund mit Version liefern.
  Entwurf, Position, verschlüsselte Upload-Metadaten und Wiederholschutz werden
  gemeinsam gespeichert. Erst danach erfolgt die bestehende private Übertragung.
  Bei Übertragungsfehler bleibt der Beleg sicher dem Entwurf zugeordnet und wird
  ausdrücklich als ausstehend angezeigt. Derselbe Auftrag erzeugt keinen zweiten
  Entwurf; geänderte Daten zum selben Auftrag werden abgewiesen. Ohne konfigurierte
  private Ablage ist das Speichern ohne Beleg möglich. Keine neue OCR-Freischaltung.

Die Glocke und ein Hinweis auf neue Planungsdaten prüfen alle 30 Sekunden im
sichtbaren Browser. Der Hinweis bietet bewusstes Neuladen, damit laufende Eingaben
nicht überschrieben werden. Das Signal wird erst nach erfolgreichem Commit gesetzt.
Mandantenfarben und der zentrale React-Parameter bleiben maßgebend. DE/EN werden
über eigene Kataloge mit identischen Schlüsseln und Platzhaltern gepflegt.

## Prüfung

- Lokal: 69 Tests, 874 Assertions bestanden; zwei vorhandene Tests übersprungen.
  Gezielte Fach-, Rechte-, Mandanten-, Übersetzungs- und Präsentationsprüfungen.
  JSON-Übersetzungsprüfung: 3075 Schlüssel DE/EN; PHP-Kataloge separat geprüft.
- DEV: 39 Prüfungen mit `php deploy/check-daily-workflows.php` bestanden; native Fachservices und
  Livewire-Rendering in DE/EN sowie React Ja/Nein. Synthetische Daten laufen in
  einer zurückgerollten Transaktion. HTTP, Mail, Queue und externe Übertragung
  sind isoliert; der vollständige SFV-Abruf verwendet ausschließlich Fake-Antworten.
- Ein früherer breiter Lauf enthält einen bestehenden Fehler in
  `WorkspaceLegacyFormComponentsTest::test_bexio_connection_keeps_workspace_token_and_configuration_guard`
  wegen fehlender Variable `$active` im Bexio-View-Fixture. Dieser Bereich wurde
  nicht geändert; die gezielten Prüfungen dieses Releases bestehen.
- Freischaltung auf DEV erfolgt; Chrome mit angemeldeter Sitzung abgenommen:
  Cockpit mit fälliger Aufgabe, tatsächliches Drag-and-drop offen → in Bearbeitung,
  mobile Statusauswahl → erledigt, Archivierung, zwei Meldungen als eine Gruppe,
  Details und sofortiger Glockenwechsel 1 → 0. Eigene DEV-Testaufgabe #23 archiviert,
  ihre beiden eigenen synthetischen Meldungen gelesen. Keine fremden Empfänger.
- Desktop und responsive 390 × 844, DE/EN, dunkles/System-Design und Wechsel von
  SV Sissach (React) zu FC Horizon (bisheriges Design) geprüft. Keine Aufgabe und
  kein Meldungszähler aus SV Sissach im anderen Mandanten. Wieder SV Sissach,
  Deutsch, System-Design und normale Fenstergröße eingestellt.
- Aktualisierungshinweis im bereits geöffneten Browser nach geändertem
  Planungs-Cache-Signal sichtbar; keine fachlichen Datensätze dafür verändert.
- SFV-Hauptaktion im Browser vorhanden; keine echten Spiele durch die Abnahme
  abgeglichen oder umgeplant. Vollständige Übernahme einschließlich API-Abgrenzung
  über synthetische native DEV-Prüfung abgenommen. Team-/Busänderungen ebenfalls
  nur in der zurückgerollten Fachprüfung.
- Mobile Spesenmaske und englische Texte geprüft. Browser-Dateiauswahl erreicht,
  Upload durch fehlende Chrome-Erweiterungsfreigabe „Allow access to file URLs“
  blockiert. Deshalb keine erfolgreiche Browser-Belegvorschau behauptet. Scanner,
  private Belegzuordnung und identische Wiederholung über native Tests geprüft;
  Kamera und physisches Handy bleiben offen. Kein echter Spesenentwurf angelegt.
- Ein vorhandener Browserhinweis zur abgebrochenen ViewTransition beim Wechsel
  der Seite beobachtet; Drag-and-drop und Speichern liefen erfolgreich weiter.

## Betrieb und Rückweg

37 Runtime-Dateien werden im inaktiven Release per SHA-256 geprüft. Konfiguration,
Composer-Lock, gemeinsame private Ablage und bestehende Schemaabbildung bleiben
erhalten. `deploy/release-daily-workflows.sh` bereitet nur den Kandidaten vor;
Aktivierung erst nach Fachprüfung über den atomaren `current`-Symlink und
anschließendes Neuladen von PHP-FPM. Keine Datenbankmigration erforderlich.

Rückweg: `current` atomar auf `20261124-clubunio-work-hub-v3` zurückstellen und
PHP-FPM neu laden. Bereits gespeicherte Aufgaben/Entwürfe bleiben über die
bestehenden Fachseiten zugänglich. Keine Datenrücksetzung oder Löschung nötig.
Die neue automatische SFV-Übernahme entfällt nach dem Rückweg; bereits übernommene
Spiele bleiben normale vorhandene Wettkämpfe und Mannschaftstermine.
