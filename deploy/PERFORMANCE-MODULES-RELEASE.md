# Performance aller persoenlichen Appmodule: DEV-Delta

04.11.2026. Basis ist exakt20261104-clubunio-mobile-performance-v1;
Ziel20261104-clubunio-mobile-modules-performance-v1. Ein begrenztes Paket aus
16 PHP-Produktdateien und einem privaten CLI-Messrunner. Bestehende15 Dateien
gegen Git-Historie und SHA256, zwei neue Dateien vorher abwesend verifiziert.
Ausschliesslich committete Bytes werden gepackt. Unbekannte parallele Aenderungen
stoppen die Bereitstellung. Env, Composer, Schema und Providerflags bleiben gleich.

Release kopiert den bisherigen Stand, prueft Baseline/Paket/Syntax/Cache und
alle bestehenden Common-/Modul-/Auth-/Kernel-Rollbackgates. Danach wiederholt
der identische CLI-Messrunner echte DEV-Reads mit synthetischem Admin und
Technical Lead, jeweils Rollback. Erst danach atomarer current-Wechsel/FPMreload.
Baseline und neue Zeiten werden in PERFORMANCE-API-MEASUREMENTS.md nachgetragen;
alle rohen Daten bleiben im ignorierten storage/app bzw. privaten shared.

Produktumfang: Teamplanungseite/Einladungssnapshots, My Teams/Dossiermetadaten,
Dokument-/Sponsor-/Training-/Notificationsleseprojektionen, gemeinsamer streng
requestlokaler GET-Rechterahmen und numerische Server-Timing-Diagnose. Einzel-
objekt-/Eltern-/Datei-/Schreibpruefungen bleiben verbindlich. Keine Imports,
Datenbankauffrischung, Bankzahlungen oder Providerfreigabe.

Nachweise: PERFORMANCE-PLANNING.md, PERFORMANCE-OTHER-MODULES.md,
PERFORMANCE-API-MEASUREMENTS.md und mobile/docs/PERFORMANCE-ALL-MODULES.md.
Bereitstellung und Abschlusszahlen werden nach erfolgreichem Lauf ergaenzt.
Rollback: vorheriger performance-v1-Ordner, kein Schemarueckbau erforderlich.
