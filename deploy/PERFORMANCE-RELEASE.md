# Mobile Performance: begrenzter DEV-Release (04.11.2026)

Bereitstellung auf Basis von `20261104-clubunio-mobile-parity-v1`.
Paketquelle ist ausschliesslich der nach Tests committete Gitstand; sieben
explizite PHP-Dateien, jede vorhandene Runtimequelle gegen Git-Historie und
exakte SHA256 geprueft. Unbekannte lokale/Serveraenderungen stoppen das Paket.
Aktiv seit04.11.2026: current zeigt auf
`20261104-clubunio-mobile-performance-v1`, Quelle199d3f8e11af385c4bf2800891a69399a2c23d5f.
Alle sieben Baseline-/Paket-/nachtraeglichen Runtimehashes bestaetigt.
Env, Composer, Schema und Providerflags sind unveraendert.

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

## Erfolgreiche Freigabe

Alle bisherigen Releasegates bestanden, einschliesslich Common13, Planung21,
Training11, Registrierung11, Sponsor15, Billing4, Card1, Identity8+2,
Push6+Notifications4, Keybox6, Challenge5, Bootstrap1+2, OCR-Provenienz,
Admin18 und Modulmenue79. Persoenlicher Login/Profil/Passwort/Sessions sowie
My Teams/Planung mit synthetischen Rollbackdaten ebenfalls gruen.
Nach Aktivierung separat ueber HTTPS: Login200, PublicBootstrap200/no-store,
Chat und Spesen anonym401, geschlossene oeffentliche Identity503.
PHP-FPM aktiv und sieben Runtimehashes erneut geprueft.

Lokale gezielte Backendnachweise: Common/Boundary43 Tests/652 Assertions;
TEMP-PostgreSQL-Projektionen2/44; vier vorhandene persoenliche Spesen-/OCR-/
Indexfaelle; separate Rollen-/Status-/Widerrufsparitaet4/235. Ein neuer
DB::raw-Ausdruck verlangte zunaechst irrtuemlich die Defaultconnection;
auf verbindungsneutrale Query-Expression korrigiert und PG erneut gruen.
Kein ungepruefter Zwischenstand wurde aktiviert.
