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

Aktivierung und Servernachweise werden nach Ausfuehrung hier nachgetragen.
Die vollstaendige native App-Freischaltung bleibt getrennt: persoenliche
Mitgliederdossiers und die weiteren im Paketstatus benannten Ablaeufe sind
noch in Umsetzung. Gesamtauftrag aktiv.
