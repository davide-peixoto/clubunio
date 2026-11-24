# Veo-Aufnahmeplanung

## Stage und PROD – 24.11.2026

Das geprüfte Veo-Kalenderpaket einschliesslich automatischer Spiel-/SFV-Nachführung
ist auf `https://stage.clubunio.com/veo-planning` und
`https://app.clubunio.com/veo-planning` aktiv. Fachstand `a358eaaa`; nur elf
Laufzeit-/Migrationsdateien plus Paketmanifest gezielt übernommen, kein pauschaler
Branchwechsel und keine fremden Arbeitsstände. DEV blieb bei seinem aktiven Release.

Beide Datenbanken hatten bereits Kamera-/Mannschaftseinstellungen, aber noch keine
Aufnahme-Anpassungstabelle. Beide additiven Migrationen 020000/021000 sind jetzt
verbucht; Schema `clubunio`, keine Snapshot-/Legacy-Schemas geändert. Vor jeder
Migration vollständige verschlüsselte Sicherung einschliesslich lesbarer Prüfung:
`/root/clubunio-releases/veo-stage-20261124` beziehungsweise
`/root/clubunio-releases/veo-prod-20261124`. Dort liegen auch Quellarchiv,
Vorher-/Nachherdateien, neue Pfade, Migrations- und Abnahmeprotokolle.
Konfiguration und Composer-Lock per SHA-256 unverändert bestätigt.

Auf beiden Instanzen nativ geprüft: Speichern, veralteten Schreibversuch abweisen,
Rücksetzen, Audit, unveränderte Spielzeit bei manuellem Aufnahmeverschieben,
automatischer Kamerawechsel bei bestätigtem Platzwechsel sowie zwei tatsächliche
SFV-Abgleiche auf neue Tage/Plätze mit erhaltenem Vorlauf und erhaltener Dauer.
DE/EN-Rendering erfolgreich, alle synthetischen Daten zurückgerollt; null
verbliebene `veo-test-*`-Mandanten. HTTP 302 zur geschützten Anmeldung und CSS 200,
beide Anwendungen ausserhalb des Wartungsmodus. Identische Laufzeithashes der
Fachservices, Kalenderansicht und gemeinsamen JS-Datei auf Stage/PROD.
Kein echter Versand, SFV-Netzabruf, Kamerabefehl oder Bankvorgang.

Rollout mit `deploy/promote-veo-calendar.sh`, Zielprüfung/Migrations-SQL über
`deploy/emit-veo-promotion-migration.php`. Wiederanlauf verwendet die ursprüngliche
Sicherung, migriert idempotent und bricht nach einem fertigen Lauf ab. Fehler nach
Codeinstallation stellen die vorherigen Dateien und den Anwendungsstatus wieder
her; additive Tabellen bleiben erhalten. Für einen manuellen Rückweg zuerst
anwendungsbezogenen Wartungsmodus setzen, `source.tar.gz` wiederherstellen,
ausschliesslich in `new-files` gelistete neue Dateien entfernen, Bootstrap-Cache
zurückspielen und die Veo-View neu kompilieren; Anwendung freigeben/FPM neu laden.
Keinen vollständigen Datenbankrestore für einen Code-Rückweg durchführen.

Beim ersten Stage-Vorladen aller Views fiel eine bestehende Leseberechtigung für
`tenant-ai-settings.blade.php` auf. Der Code wurde automatisch zurückgesetzt;
keine pauschale Rechteänderung. Der endgültige Rollout kompiliert gezielt Veo und
prüft dessen DE/EN-Rendering. Die fremde Dateiberechtigung ist damit nicht behoben.
Ebenso blieb der bestehende ungültige PROD-Git-HEAD unberührt: dies war ein
gezielter Runtime-Release, keine Reparatur der Hosting-Git-Integration.
Angemeldete visuelle Desktop-/Mobile-Abnahme bleibt separat offen.

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
und der Aufnahmeversion erkannt. Bei einer bestätigten Spiel-/Platzverschiebung
folgt auch manuelle Aufnahmeplanung automatisch dem neuen Platz und dessen
Kamera. Der relative Vorlauf zum Anpfiff und die Aufnahmedauer bleiben erhalten.
Das gilt ebenso nach automatischer SFV-Übernahme, auch bei wiederholtem Wechsel
des Datums. Fehlende/mehrdeutige Platzzuteilung bleibt „Nicht zugeteilt“, fehlende
Zielkamera „Keine Kamera“; niemals die alte Kamera als gültiges Ziel behalten.
Neue Überschneidungen werden als Konflikt angezeigt, nicht durch stilles Festhalten
am alten Termin kaschiert. Rücksetzen ist protokolliert, Audit `veo_recording`.

### Migration und Rückweg

Additive Migration `2026_11_24_020000_veo_recording_adjustments`: eigene Tabelle
`veo_recording_adjustments`, Primärschlüssel `(tenant_id,match_id)`, zusammengesetzte
Fremdschlüssel für Spiel/Platz/Benutzer, Zeitfensterindex und kein PUBLIC-Zugriff.
Bestehende Spiele und Einstellungen werden nicht umgeschrieben. Bei späteren
Mandantenimporten zuerst SFV-Spiele, Plätze und Benutzer über ihre Legacy-Zuordnung
migrieren; manuelle Aufnahmeplanung nur anhand dieser aufgelösten Verknüpfungen
übernehmen, niemals numerische IDs zwischen Mandanten kopieren. Der Basis-Hash ist
zielabhängig und muss nach Import neu bestätigt werden.

Zusatzmigration `2026_11_24_021000_veo_fixture_anchor` speichert den verlässlichen
Spieltermin beim manuellen Planen als nullable `fixture_starts_at`. Bei späterer
Verschiebung werden Startversatz und Dauer relativ zu diesem Anker berechnet;
die gespeicherte Originalplanung bleibt für Audit und Revisionsprüfung erhalten.
Alte Zeilen werden nicht durch erfundene historische Anpfiffzeiten aufgefüllt:
bis zu einer Verschiebung bleibt ihr bisheriges Fenster, danach beginnt die
Aufnahme am neuen Anpfiff mit erhaltener Dauer. Für einen besonderen Vorlauf
diese alten Aufnahmen einmal neu speichern. Bei Importen den tatsächlichen Anker
mitführen; ist er unbekannt, NULL beibehalten. Kein heuristisches Backfill.

Nur `clubunio_dev` aktualisiert, Stage/PROD unverändert. Vorab verschlüsselte
Vollsicherung auf dem VPS, vollständig lesbar geprüft (SQL nur nach `/dev/null`,
keine Wiederherstellung ausgeführt):
`/root/clubunio-releases/veo-calendar-20261124/dev.dump.enc`.
Schlüssel bleibt separat im selben privaten Verzeichnis, niemals im Git.
`deploy/emit-veo-calendar-migration.php` erzeugt ausschliesslich DEV-SQL mit
Datenbank-/Owner-Prüfung; die Anwendung erhält nur DML für die neue Tabelle.

Die Zusatzmigration wurde ausschliesslich auf DEV nach erneuter verschlüsselter
Vollsicherung unter `/root/clubunio-releases/veo-follow-fixture-20261124` angewandt.
Emitter: `deploy/emit-veo-follow-fixture-migration.php`. Keine neue Rolle oder
erweiterte Rechte; Stage/PROD bleiben unverändert.

DEV-Release `20261124-clubunio-veo-calendar-v5`, auf Basis des aktiven Releases.
Die Schema-Abbildung `clubora` → `clubunio` erfolgt vor Aktivierung; Konfiguration,
Composer und privater Storage bleiben erhalten. Fachlicher Rückweg auf
`20261124-clubunio-daily-v2` über den atomaren `current`-Symlink und FPM-Reload.
Die additive Tabelle und ihr Audit dabei erhalten, keine Datenrücksetzung.
Der alte Plan ignoriert manuelle Aufnahmefenster, daher Rückweg fachlich abstimmen.

### Prüfstand

53 lokale PHP-Tests / 311 Assertions erfolgreich; ein separater externer DB-Test
ohne Opt-in übersprungen. Drei JavaScript-Layouttests erfolgreich. DE/EN-Kataloge
geprüft. Native PostgreSQL-Prüfung auf DEV: Speichern, Revisionsschutz, Rücksetzen,
unveränderte Spielzeit und Audit sowie DE/EN-Seitenrendering erfolgreich; alle
synthetischen Daten in einer Transaktion zurückgerollt.
Zusätzlich nativ auf PostgreSQL: bestätigter Platzwechsel und zwei tatsächliche
`SfvReconcileService`-Durchläufe auf verschiedene Tage/Plätze. Neue Kamera,
30 Minuten Vorlauf und 150 Minuten Dauer korrekt; keine alten Kalendereinträge.
Auch diese Fachprüfung vollständig zurückgerollt, kein echter SFV-Abruf/Versand.
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
