# SV Sissach: Zielvertrag155, 25.11.2026

Betreiberfreigabe: neue Schemaaenderungen pruefen und anschliessend Delta laden. Richtung weiterhin ausschliesslich PHPMaker PROD nach ClubUnio PROD, Verein sv-sissach. Keine Kontofreischaltung, Schluesseluebernahme, Zustellung oder Providerjobs.

## Schemaabgleich

Die Sicherung vor Lauf221b543d85a4410d875a0a22cb581d9d reproduziert den bisherigen152er-Vertrag exakt. Aktuelle PROD-Sicherung separat restauriert.155 Migrationen, keine bestehenden Mappingfelder geaendert oder entfernt, keine bisherigen Constraints entfernt.

Drei gepruefte Migrationen:

- 2026_11_24_010000_work_hub: workspace_tasks, workspace_task_events, chat_mentions und workspace_capture_commands mit mandantengebundenen FKs, Statusregeln und Indizes.
- 2026_11_24_020000_veo_recording_adjustments: lokale VEO-Zeit-/Platzkorrekturen mit SFV-Spiel-, Anlagen- und Benutzerreferenzen.
- 2026_11_24_021000_veo_fixture_anchor: nullable fixture_starts_at als lokaler Spielanker.

Alle fuenf Tabellen sind native ClubUnio-Daten, keine PHPMaker-Importziele. Das bestehende Mapping, der Quellvertrag und die Baselineversion bleiben unveraendert. Nur der exakte Zielvertrag wird angepasst. Die pruefenden Guards werden nicht deaktiviert. Runtime-Dateien entsprechen weiterhin ihren freigegebenen Hashes.

## Abnahme und Betrieb

Privates Review: /root/clubunio-svs-production/reviews/20261125-schema. Alte und neue Sicherung auf isolierten Kopien verglichen. Livefreigabe waehrend des ersten vollstaendigen Probelaufs entfernt. Negative Pruefungen bestaetigen: PROD ohne Permit verboten, alter152er-Zielstand abgelehnt und absichtlich abweichende Betragsgenauigkeit auf einer155er-Testkopie abgelehnt.

Das erweiterte tools/test-svs-schema-extension.php prueft14 native Tabellen, einschliesslich synthetischer Aufgaben/Verlauf/Erfassungsbefehle und VEO-Korrektur. Alle Testaenderungen erfolgen ausschliesslich auf isolierten Datenbanken und werden zurueckgerollt. Bisherige Eltern-/Kontostatus-/Winterplan-, Feldmerge- und Konfliktrollback-Pruefungen bleiben erhalten.

Fuer den echten Delta-Lauf bleibt die gesamte Kette verbindlich: frischer Quelldump, neues PROD-Backup, Restore, Merge/Wiederholung auf Kopie, root-eigener Permit, Rollback-Vorschau, atomarer Commit. Einseitiger12-Stunden-Agent bleibt bestehen; unbekannte zukuenftige Aenderungen stoppen weiterhin.

Migrationplan: keine PROD-Schemaaenderung und kein Baselineupgrade durch diesen Release. Lokale Aufgaben, Chat-Erwaehnungen und VEO-Korrekturen bleiben erhalten. Rueckweg: Livefreigabe entfernen und den vorherigen Vertrags-/Release-Stand aus der privaten Reviewakte wiederherstellen. Der alte152er-Vertrag bleibt gegen155 bewusst gesperrt. Keine manuelle Daten-/Baseline-Ruecksetzung.