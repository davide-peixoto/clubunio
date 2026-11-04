# Mobile Performance: begrenzter DEV-Release (04.11.2026)

Bereitstellung auf Basis von `20261104-clubunio-mobile-parity-v1`.
Paketquelle ist ausschliesslich der nach Tests committete Gitstand; sieben
explizite PHP-Dateien, jede vorhandene Runtimequelle gegen Git-Historie und
exakte SHA256 geprueft. Unbekannte lokale/Serveraenderungen stoppen das Paket.
Aktivierung und realer Iststand werden nach erfolgreichem Lauf nachgetragen.

Produktumfang: gebuendelte Chatlisten/Nachrichten-/Aenderungsprojektionen,
blobfreie Anhangmetadaten, gebuendelte OCR-Quellmetadaten und ausdrueckliche
requestlokale Lese-Capabilities fuer die begrenzte persoenliche Spesenliste.
Kein Rechtecache ueber Requests, keine Aenderung der Mutationspruefungen.
Details: PERFORMANCE-BACKEND.md und PERFORMANCE-EXPENSES-BACKEND.md.

Das Release kopiert den unveraenderlichen bisherigen Stand vollstaendig,
prueft die sieben Baselinehashes vor und nach Kopie und entpackt ausschliesslich
das begrenzte Paket in einen neuen Releaseordner. PHP-Syntax, Config-/Route-/
Viewcache und bestehende Modul-/Frische-/Kernel-Rollbackgates muessen vor
atomarem current-Wechsel erfolgreich sein. Env und Composer bleiben exakt
gleich; kein Schema-/Datenimport oder Provider-/Runtimeflag wird geaendert.
Rollback bleibt der vorherige parity-v1-Pfad mit unveraendertem Schema.

Appnachweise separat in mobile/docs/PERFORMANCE.md. Betroffene Umgebung laut
Benutzer: iPhone-Simulator. Kein native iOS-/Xcode-Lauf in dieser Windows-
Umgebung; Query-/Requestcounts sind keine gemessenen iPhone-Bildraten.
