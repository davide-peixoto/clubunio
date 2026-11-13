# Veo-Aufnahmeplanung

Die Filament-Seite **Veo-Aufnahmeplanung** ist unter **Anlagen & Kommunikation** für Vereinsadmins und die Geschäftsstelle mit Änderungsrecht für Wettkämpfe verfügbar. Benutzer wechseln in den gewünschten Verein, hinterlegen je Platz den Namen der fest montierten Kamera und aktivieren Aufnahmen je Mannschaft der aktuellen Saison. Die Einstellungen gelten für den gewählten Verein.

ClubUnio berechnet den Wochenplan bei jedem Aufruf aus den vorhandenen SFV-Spielen und der ClubUnio-Platzzuteilung. Bei einer neuen Anspielzeit bleibt die Aufnahme offen, bis eine passende Platzzuteilung vorliegt. Ein bestätigter Platzwechsel führt zur Kamera des neuen Platzes. Fehlende Kameras und überlappende Aufnahmefenster erscheinen im Plan. Absagen entfallen. Die Mannschaftsfreigabe gilt je Saisonzuordnung.

Der Plan dient der Vorbereitung in der Veo Camera App. Automatisches Starten, Stoppen und Rückmelden einer Aufnahme setzt eine für das eingesetzte Veo-Modell und Abo bestätigte Schnittstelle voraus.

## Migration und Rückweg

Die additive Migration `database/clubunio/2026_11_13_002100_veo_recording_plan.php` ergänzt die mandantenbezogenen Tabellen für Kamerazuteilungen und Mannschaftsfreigaben. Der Wochenplan wird aus vorhandenen Daten berechnet. Nach einer Rückkehr zum vorherigen Web-Release bleiben die Einstellungen in den Tabellen erhalten.

## Abnahme

- Kamera A und B auf unterschiedlichen Plätzen konfigurieren und eine Testmannschaft aktivieren.
- Für ein SFV-Spiel mit bestätigter Zuteilung Kamera A im Plan prüfen. Das Spiel auf Platz B umteilen und Kamera B prüfen.
- SFV-Spielzeit verschieben und offene Platzzuteilung prüfen. Nach Bestätigung der neuen Zeit das aktualisierte Aufnahmefenster prüfen.
- Absage, fehlende Kamera und überlappende Aufnahmefenster prüfen. Einen zweiten Mandanten auf getrennte Einstellungen und Daten prüfen.
