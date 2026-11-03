# Koordinierte mobile Migrationsdateien

Mehrere Chats arbeiten im kanonischen Repository. Vor neuer Dateinummer die
bereits vorhandenen Dateien und diese Reservierungen pruefen. Keine Datei
eines anderen Pakets ueberschreiben. Es wird ausschliesslich die explizit
gepruefte Pfadliste angewendet, niemals das ganze historische Verzeichnis.

| Datei | Eigentum / Zustand |
| --- | --- |
|000088_mobile_expense_receipts|Persoenliche Spesenentwuerfe/Befehle; vorbereitet, nicht permanent angewendet|
|000089_mobile_member_receipts|Persoenliche Dokumente/Signaturbeweise und additive Archiv-FK; vorbereitet, nicht permanent angewendet|
|000090_active_training_plan|Anderer Chat, bereits vorhandener Web-/Signage-Release; nicht fuer mobile Freigaben verwenden|
|000091_retire_general_parameters|Anderer Chat, eigener Bereinigungsrelease; unveraendert erhalten|
|000100_mobile_training_shares|Reserviert fuer native Trainingsfreigabezuordnungen; Jobs nutzen vorhandene82|
|000200_mobile_sponsor_commands|Reserviert fuer erforderliche persoenliche Sponsorenlauf-Befehle|

Alle Dateien liegen unter database/clubunio und tragen den Praefix2026_11_03_.
Nicht fortlaufende Nummern sind beabsichtigt. Keine pauschalen down-, refresh-,
Import- oder Bestandskopieraktionen. Vor DDL Backup-/Metadaten-/Ledgerpruefung,
danach fachliche positive/negative Tests, Dokumentation und Commit&Sync.
