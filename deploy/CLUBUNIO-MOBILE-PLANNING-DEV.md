# Persoenliche Teamplanung und My Teams auf DEV

## Releasevorbereitung (03.11.2026)

Quellen: Backend550cc65, App15a30f4, anschliessende Freigabegates separat.
Die nativen Planungsrouten besitzen einen zusaetzlichen, standardmaessig
geschlossenen Schalter. Bestehende persoenliche Terminleserouten bleiben
erhalten. My Teams verwendet ausschliesslich persoenliche Sitzungen.

Reihenfolge: schemafaehige Grundlage20261103-clubunio-mobile-foundation,
private verschluesselte Sicherung aller vier betroffenen Planungstabellen,
atomare additive Migrationen84-87, danach separater aktivierter Release.
Der Grundlage-Release bleibt als Rueckweg mit geschlossenem Schalter und
dem neuen Sichtbarkeitsscope fuer entfernte Termine erhalten.

Die Sicherung wird entschluesselt und gegen gespeicherte Zeilenhashes geprueft.
Sie ist ein privater Tabellenexport mit Spaltenmetadaten und Migrationsledger,
kein vollstaendiger PostgreSQL-Restore-Nachweis. Der Hoster hat zusaetzlich
einen Gesamtbackupauftrag fuer postgresql-5836 angenommen; dessen Ergebnis
database_backup_queued bestaetigt nur die Warteschlange, keine fertige Datei.
Auftrag087bf874-45c7-4ea9-8cbc-98e9e2baf874.

Die Schemaanwendung prueft Datenbank/DEV-Release, frische Sicherung, unveraenderten
Ledger, exklusive sofortige Tabellensperren und unveraenderte Bestandsfelder.
Nur die vier benannten Migrationen werden ausgefuehrt. Kein Refresh, Import,
Rueckmigrieren oder Eingriff in PHPMaker DEV/PROD.

Lokal:30 Kommunikationstests/344 Assertions sowie synthetischer persoenlicher
HTTP-Kernelablauf fuer My Teams und geschlossene native Planung erfolgreich.
Keine dauerhaft angelegten Konten, Zuordnungen oder Sitzungen.

## Aktiviert und geprueft (03.11.2026)

Aktiv:20261103-clubunio-mobile-planning, sicherer Rueckweg:
20261103-clubunio-mobile-foundation mit gecachtem native_planning_enabled=false.
Quellen90d1ac0 (Backendbasis550cc65); Aktivierungsskript ecefc4f.
Der einzige Env-Unterschied im neuen Release ist
CLUBUNIO_MOBILE_NATIVE_PLANNING_ENABLED=true. Die bestehende Env-Datei im
Rueckweg wurde nicht geaendert. Beide Releases verwenden dasselbe Storage.

Neun vorhandene Dateien gegen die aktive Vorversion geprueft,14 Dateien neu,
23 Dateihashes vor und nach Aktivierung identisch. Bei config/routes waren
ausschliesslich alte CRLF-Zeilenenden gegenueber Git vorhanden; vor Ueberlagerung
inhaltlich normalisiert verglichen und anschliessend die echten Bytehashes
festgehalten. Kein fremder Code ueberschrieben.

Private Exportdatei auf DEV:
/var/www/svs-laravel/shared/backups/mobile-planning-before-84-87-20261103.cuenc,
Rechte0600, SHA256
7b134a262c12881d18942f44bc0667bf2c0b0c74c8a49fe604447db61d64a71e.
Entschluesselung/Zeilenhashes vor Schemaanwendung und Aktivierung geprueft.
Migrationen84-87 gemeinsam committed; bisherige Spaltenwerte exakt unveraendert,
neue optionale Spalten leer und neue Befehlsledger ohne Eintraege.

In beiden Stufen alle zwoelf bestehenden Servergates bestanden, zusaetzlich
15 native Routen und Schema-/Freigabestatus geprueft. Synthetische persoenliche
HTTP-Kerneltests direkt auf DEV: Login/Profil/11 Module/Passwort/Widerruf sowie
My Teams, versionierte Zuordnungen, Einzeltermin, zeitgenaue Absenz mit echter
An-/Abwendung, Gast, idempotente Serie, Aenderung ab hier und Serienentfernen.
Fixtures jeweils vollstaendig zurueckgerollt; keine Provideraufrufe/Zahlungen.
Abschliessend HTTPS login200, My Teams401 und Planung401 ohne Anmeldung;
PHP-FPM aktiv und alle23 Quellhashes erneut bestaetigt.

Rueckweg nur zur foundation mit geschlossenem nativen Planungszugriff.
Aeltere Releases ohne Entfernen-Scope duerfen nach echten Entfernaktionen
nicht als normaler Rueckweg verwendet werden. Additive Migrationen bleiben
bestehen; niemals automatische down/Refresh-/Importaktion ausfuehren.

Die vollstaendige native App-Freischaltung bleibt getrennt: persoenliche
Mitgliederdossiers und die weiteren im Paketstatus benannten Ablaeufe sind
noch in Umsetzung. Gesamtauftrag aktiv.
