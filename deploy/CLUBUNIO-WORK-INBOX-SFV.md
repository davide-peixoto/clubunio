# Arbeitskorb und SFV-Spielverschiebung

Der gezielte SFV-Abruf für den 11.–13.09.2026 lieferte 13 Spiele. Auf DEV wurden die sieben fehlenden Quellzeilen für die IDs 4361475, 4366882, 4358726, 4358565, 4314470, 4359298 und 4358991 nachgeladen. Datum, lokale Anspielzeit und Platz stimmten bei allen sieben mit dem jeweils aktiven ClubUnio-Wettkampf überein. Tannenbrunn 1 entspricht dem Hauptfeld, Tannenbrunn 2 dem Kunstrasen. Der SFV meldet 4358565 als Forfait und die übrigen sechs als ausgetragen.

Auf DEV wurden beim SV Sissach acht archivierte Dubletten von sieben SFV-Spielnummern getrennt. Je Spielnummer blieb genau der bisher aktive SFV-Wettkampf verknüpft. Es gab weder bestätigte kurzfristige Ausnahmen noch aktuelle SFV-Quellzeilen zu diesen September-Spielen. Die ursprünglichen Datensätze liegen als private Sicherung unter `sfv-duplicate-links-20261115-130151.json` im DEV-QA-Verzeichnis. Die neue Saisonvorschau meldete null mehrdeutige Zuordnungen und weiterhin 23 eindeutige Mannschaftszuordnungen.

Die ergänzende DEV-Webabnahme nutzte echte HTTPS-Sitzungen mit beiden TOTP-Testkonten, gerenderte Filament-Seiten und deren Livewire-Aktionen. Vor der letzten Stunde lehnte das Speichern den Platzwechsel mit HTTP 422 ab. Innerhalb des Fensters bestätigte die Spielleitung den Wechsel im Platzeditor. Nach erneutem Laden zeigte die Veo-Seite für dasselbe Spiel Kamera B und die neue Anspielzeit. Das Konto des zweiten Vereins erhielt beim Öffnen der fremden Belegung HTTP 404 und sah das Spiel im Veo-Plan nicht. Die synthetischen Spieldaten wurden anschliessend entfernt.

Nach Wiederherstellung des DEV-Datenbanktunnels bestanden beide Testkonten erneut den HTTPS-Login mit TOTP und den Dashboard-Aufruf. Die Fachabnahme lief mit denselben Konten über die Anwendungsdienste auf der DEV-Datenbank mit synthetischen Daten und kontrollierter Uhr. Bei SFV-Anpfiff 14:00 wurden die Änderung um 12:59:59 sowie eine neue Änderung ab 14:00 abgelehnt. Um 13:00 durfte die Spielleitung ändern. Das Konto des anderen Vereins erhielt HTTP 403. Kamera und Teamtermin folgten der bestätigten Änderung. Der SFV-Import schützte sie bis zum Belegungsende und bot danach wieder die SFV-Zuteilung an. Die Testtransaktion wurde vollständig zurückgerollt.

Am 15.11.2026 wurden im aktiven DEV-Release `20261115-clubunio-support-zammad` die zuvor geprüften Einstellungen für starke Anmeldung wiederhergestellt. Beide isolierten Testkonten bestanden den HTTPS-Login mit Passwort und TOTP sowie den Dashboard-Aufruf. Anschliessend konnte der DEV-Datenbanktunnel den VPS auf SSH-Port 22 nicht erreichen. Der isolierte Spieltest brach vor der Anlage der Testdaten ab. Bis zur Wiederherstellung des Tunnels ist die vollständige angemeldete Abnahme offen.

Die 60-Minuten-Regel ist auf DEV im Release `20261115-clubunio-sfv-hour` aktiv. Rückweg ist `20261115-clubunio-platform-monitoring`. Der Release benötigt keine Datenbankmigration. Der DEV-Datenbankcheck und der Zeitgrenzentest im aktiven Release bestanden. Der bisherige TOTP-Testclient fand das erwartete Formular nicht. Eine neue angemeldete Abnahme steht aus.

Für SFV-Spiele kann die Spielleitung am Spieltag in der Platzplanung eine Änderung zwischen 60 Minuten vor Anpfiff und dem SFV-Anpfiff bestätigen. Die Bestätigung schreibt die lokale Platzbelegung und berechtigte verknüpfte Teamtermine zusammen fort. Veo übernimmt die lokale Uhrzeit und Kamera. Der SFV-Import schützt die Ausnahme bis zum Ende des längeren Belegungsfensters aus SFV-Termin und bestätigter lokaler Zuteilung. Die Bestätigung wird im vorhandenen Änderungsjournal gespeichert.

Unter **Verwaltung → Arbeitskorb** sehen Vereinsadmins und Geschäftsstelle die nach Modulrechten verfügbaren Platz- und Busanfragen sowie Spesen zur Freigabe. Vereinsadmins können über den Arbeitskorb Bexio-Prüffälle in der Synchronisation öffnen. Die Bearbeitung erfolgt im jeweiligen Fachbereich.

In **SFV-Wettkämpfe** zeigt die Einzelvorschau bei einem Platz- oder Terminwechsel betroffene Platzbelegungen und über die SFV-Spiel-ID verknüpfte aktive Teamtermine. Die Bestätigung prüft die Vorschau erneut. Blockierende Konflikte oder fehlende Rechte für betroffene Teamtermine verhindern die Übernahme. Bei erfolgreicher Übernahme werden Wettkampf und verknüpfte Teamtermine gemeinsam aktualisiert. Die bestehenden Dienste erfassen Änderungen und verwalten Benachrichtigungen. Eine Termin- oder Platzänderung an einem bestehenden Spiel erfordert im Web eine Einzelprüfung. Die externe SFV-API behält ihren Importvertrag.

Für die Änderung ist keine Migration erforderlich. Rückweg ist der vorherige Web-Release. Bereits bestätigte fachliche Änderungen bleiben im Änderungsverlauf erhalten.

Auf DEV aktiv als `20261114-clubunio-inbox-sfv-v2`. Rückweg ist `20261113-clubunio-veo-planning`. Die angemeldete HTTPS-Abnahme mit zwei getrennten TOTP-Testkonten hat alle Arbeitskorbbereiche geprüft. Ein synthetisches Spiel wurde in der Sammelvorschau zur Einzelprüfung verwiesen und erst nach bestätigter Einzelvorschau verschoben. Der separate PostgreSQL-Test für die gemeinsame Aktualisierung von Spiel und Teamtermin bestand.

## Für Stage vorbereitet

Arbeitskorb und SFV verwenden die vorhandenen Tabellen. Die Veo-Migration
legt zwei leere Konfigurationstabellen im Schema `clubunio` an. Die dafür
benötigten eindeutigen Schlüssel in `training_options` und `team_seasons`
sind auf Stage vorhanden. Beide Veo-Tabellen und der Migrationseintrag
fehlten bei der lesenden Vorprüfung.

Beim freigegebenen Rollout zuerst den Stage-Stand und die Sicherung prüfen.
Die Veo-Migration mit `php deploy/apply-stage-veo.php` aus dem vorbereiteten
Release gezielt auf `clubunio_stage` anwenden und den Eintrag im Journal
prüfen. Danach den Webcode aktivieren. Beim Rückweg die beiden additiven
Veo-Tabellen und ihre Konfiguration stehen lassen.

Stage-Abnahme mit zwei Testvereinen und synthetischen Spielen:

1. Arbeitskorb als Vereinsadmin und Geschäftsstelle öffnen. Platz, Bus,
   Spesen und Bexio-Prüffälle mit den jeweiligen Modulrechten und Links prüfen.
2. Einen SFV-Terminwechsel aus der Sammelvorschau zur Einzelprüfung führen.
   Auswirkung auf Platz und Teamtermin prüfen, Konflikte ablehnen und die
   Änderung erst nach Bestätigung gemeinsam übernehmen.
3. Die Grenzen der letzten Stunde prüfen. Vor dem Fenster und ab Anpfiff
   blockieren, am Fensterbeginn erlauben. Den Schutz der lokalen Zuteilung
   beim SFV-Import bis zum Belegungsende prüfen.
4. Zwei Kameras hinterlegen und eine Saisonmannschaft freigeben. Nach dem
   bestätigten Wechsel müssen Zeit und Kamera im Veo-Plan folgen.
   Absage und Mandantentrennung ebenfalls prüfen.

Die DEV-Bereinigung und der gezielte SFV-Datenbackfill gehören nicht zum
Stage-Codepaket. Ein allfälliger Datenabgleich braucht einen eigenen
Nachweis anhand der Stage-Daten.

### Isolierte PostgreSQL-Abnahme (17.11.2026)

Alle 26 Fachtests aus `PitchPlanningDomainTest` und
`PitchRequestInboxPostgresTest` bestanden. Der Testcluster verwendete
PostgreSQL 16, das Stage-Schema ohne Vereinsdaten und die Rollen aus den
versionierten Migrationen. Die beiden Profiltests wurden nach Ergänzung
der späteren Profilrollen-Aktivierung erfolgreich wiederholt. Die sechs
Veo-Tests liefen ergänzend mit SQLite und bestanden ebenfalls. Der
temporäre Cluster wurde nach den Läufen entfernt. Die angemeldete
Stage-Abnahme bleibt ein eigener Schritt.
