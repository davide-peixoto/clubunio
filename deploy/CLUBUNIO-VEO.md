# Veo-Aufnahmeplanung

## Kalender und Vereinsdesign – 24.11.2026

DEV verwendet die gemeinsame Kalenderoberfläche der Platzplanung: Tag/Woche/Monat,
Zeitraster/Agenda, Platz-/Kamera- und Statusfilter, Drag-and-drop mit Vorschau im
Bestätigungsdialog. Mobil öffnet die Agenda; Zeiten, Dauer und Kamera lassen sich
ohne Ziehen ändern. Kamera- und Mannschaftseinstellungen sind einklappbar.
Die bisherige Zugriffsprüfung bleibt erhalten: Admin/Geschäftsstelle mit
Wettkampf-Änderungsrecht, keine Schreibaktionen während einer Simulation.

**Fachliche Abgrenzung:** Verschoben wird ausschliesslich das Aufnahmefenster.
SFV-Spiel, Mannschaftskalender und Platzbelegung bleiben unverändert. Keine
Kamerasteuerung, kein Versand und keine zusätzlichen externen API-Aufrufe.
Aufnahmen sind maximal sieben Tage vom Spieltermin entfernt und 15–360 Minuten
lang. Überschneidungen auf derselben Kamera sowie fremde/inaktive Plätze werden
beim Speichern serverseitig abgewiesen. Die bestehende feste Kamera-je-Platz-
Zuordnung bleibt massgeblich; dies ist keine Verwaltung frei beweglicher Kameras.

Zwischenzeitliche Änderungen werden über einen Fingerabdruck der SFV-/Platzbasis
und der Aufnahmeversion erkannt. Geänderte Basisdaten setzen manuelle Planung auf
„Erneut prüfen“; sie werden nicht still weiterverwendet. Rücksetzen auf den
automatischen Plan ist ebenfalls protokolliert. Audit unter `veo_recording`.

### Migration und Rückweg

Additive Migration `2026_11_24_020000_veo_recording_adjustments`: eigene Tabelle
`veo_recording_adjustments`, Primärschlüssel `(tenant_id,match_id)`, zusammengesetzte
Fremdschlüssel für Spiel/Platz/Benutzer, Zeitfensterindex und kein PUBLIC-Zugriff.
Bestehende Spiele und Einstellungen werden nicht umgeschrieben. Bei späteren
Mandantenimporten zuerst SFV-Spiele, Plätze und Benutzer über ihre Legacy-Zuordnung
migrieren; manuelle Aufnahmeplanung nur anhand dieser aufgelösten Verknüpfungen
übernehmen, niemals numerische IDs zwischen Mandanten kopieren. Der Basis-Hash ist
zielabhängig und muss nach Import neu bestätigt werden.

Nur `clubunio_dev` aktualisiert, Stage/PROD unverändert. Vorab verschlüsselte
Vollsicherung auf dem VPS, vollständig lesbar geprüft (SQL nur nach `/dev/null`,
keine Wiederherstellung ausgeführt):
`/root/clubunio-releases/veo-calendar-20261124/dev.dump.enc`.
Schlüssel bleibt separat im selben privaten Verzeichnis, niemals im Git.
`deploy/emit-veo-calendar-migration.php` erzeugt ausschliesslich DEV-SQL mit
Datenbank-/Owner-Prüfung; die Anwendung erhält nur DML für die neue Tabelle.

DEV-Release `20261124-clubunio-veo-calendar-v4`, auf Basis des aktiven Releases.
Die Schema-Abbildung `clubora` → `clubunio` erfolgt vor Aktivierung; Konfiguration,
Composer und privater Storage bleiben erhalten. Fachlicher Rückweg auf
`20261124-clubunio-daily-v2` über den atomaren `current`-Symlink und FPM-Reload.
Die additive Tabelle und ihr Audit dabei erhalten, keine Datenrücksetzung.
Der alte Plan ignoriert manuelle Aufnahmefenster, daher Rückweg fachlich abstimmen.

### Prüfstand

49 lokale PHP-Tests / 277 Assertions erfolgreich; ein separater externer DB-Test
ohne Opt-in übersprungen. Drei JavaScript-Layouttests erfolgreich. DE/EN-Kataloge
geprüft. Native PostgreSQL-Prüfung auf DEV: Speichern, Revisionsschutz, Rücksetzen,
unveränderte Spielzeit und Audit sowie DE/EN-Seitenrendering erfolgreich; alle
synthetischen Daten in einer Transaktion zurückgerollt.
Angemeldete Desktop-/Mobile-Sichtprüfung noch offen: Browser-Sitzung abgelaufen.

Die Filament-Seite **Veo-Aufnahmeplanung** ist unter **Anlagen & Kommunikation** für Vereinsadmins und die Geschäftsstelle mit Änderungsrecht für Wettkämpfe verfügbar. Benutzer wechseln in den gewünschten Verein, hinterlegen je Platz den Namen der fest montierten Kamera und aktivieren Aufnahmen je Mannschaft der aktuellen Saison. Die Einstellungen gelten für den gewählten Verein.

ClubUnio berechnet den Wochenplan bei jedem Aufruf aus den vorhandenen SFV-Spielen und der ClubUnio-Platzzuteilung. Bei einer neuen Anspielzeit bleibt die Aufnahme offen, bis eine passende Platzzuteilung vorliegt. Ein bestätigter Platzwechsel führt zur Kamera des neuen Platzes. Fehlende Kameras und überlappende Aufnahmefenster erscheinen im Plan. Absagen entfallen. Die Mannschaftsfreigabe gilt je Saisonzuordnung.

Der Plan dient der Vorbereitung in der Veo Camera App. Automatisches Starten, Stoppen und Rückmelden einer Aufnahme setzt eine für das eingesetzte Veo-Modell und Abo bestätigte Schnittstelle voraus.

## Migration und Rückweg

Die additive Migration `database/clubunio/2026_11_13_002100_veo_recording_plan.php` ergänzt die mandantenbezogenen Tabellen für Kamerazuteilungen und Mannschaftsfreigaben. Der Wochenplan wird aus vorhandenen Daten berechnet. Nach einer Rückkehr zum vorherigen Web-Release bleiben die Einstellungen in den Tabellen erhalten.

ClubUnio DEV verwendet den Release `20261113-clubunio-veo-planning`. Die Migration wurde nach geprüfter Sicherung auf `clubunio_dev` als Batch 105 verbucht. Rückweg ist `20261113-clubunio-account-security`.

## Abnahme

- Kamera A und B auf unterschiedlichen Plätzen konfigurieren und eine Testmannschaft aktivieren.
- Für ein SFV-Spiel mit bestätigter Zuteilung Kamera A im Plan prüfen. Das Spiel auf Platz B umteilen und Kamera B prüfen.
- SFV-Spielzeit verschieben und offene Platzzuteilung prüfen. Nach Bestätigung der neuen Zeit das aktualisierte Aufnahmefenster prüfen.
- Absage, fehlende Kamera und überlappende Aufnahmefenster prüfen. Einen zweiten Mandanten auf getrennte Einstellungen und Daten prüfen.

Auf DEV per HTTPS mit zwei separaten Passwort- und TOTP-Sitzungen geprüft. Livewire speicherte die Kameras und die Mannschaftsfreigabe. Der zweite Mandant sah das Testspiel nicht und ein Schreibversuch mit der fremden Platz-ID ergab HTTP 404. Nach bestätigtem Wechsel erschien Kamera B. Die geänderte SFV-Anspielzeit löste die veraltete Zuteilung ab und die Absage entfernte das Spiel aus dem Plan. Lokal bestanden vier Regressionstests mit 18 Assertions sowie die DE/EN-Sprachprüfung.
