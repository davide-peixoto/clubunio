# Mobile: bestehende Dokumente und Mitgliederausweis

Stand 22.11.2026, ausschliesslich ClubUnio DEV und Stage.

## Ursache und Korrektur

Die mobile Vorschau für bestehende Mitgliederdokumente verwendete
`MobileMemberDocumentPdf::normalize()`. Dieser Import ist für neue
unterschreibbare Quittungen ausgelegt: maximal 50 Seiten, flache PDF1.4,
keine Annotationen/Formulare. Vorhandene, ansonsten abrufbare PDF-Dateien
wurden dadurch mit 422 abgewiesen.

`preview()` liefert bestehende PDFs unverändert aus (keine FPDI-Neuverarbeitung).
MIME, PDF-Kennung, EOF und 20MiB-Grenze bleiben geprüft; Bilder verwenden die
bestehende Konvertierung. Rechte, Mandanten-/Dokumentbezug, gespeicherter Hash,
private No-Store-Antwort und Transportdigest bleiben erhalten. Neue Uploads,
Virenscan und Unterschriften behalten `normalize()` und die bisherigen Grenzen.
Keine Dateiinhalte oder Mitgliederdaten in Prüflogs.

## Bereitstellung und Rückweg

Gezieltes Zwei-Dateien-Delta am 22.11.2026, vor Austausch Livebaseline geprüft.
Das in DEV/Stage vorhandene SQL-Schema `clubunio.` bleibt beim Paketbau erhalten;
lokale Quelle verwendet weiterhin `clubora.`. Keine Migration und kein Import.

- DEV: aktuelles Release `20261119-clubunio-social-insights-v3`, Sicherung unter
  `/var/www/svs-laravel/shared/mobile-document-preview-20261122-162045`.
- Stage: `/home/runcloud/webapps/ClubUnioStage`, Sicherung unter
  `/root/mobile-document-preview-20261122-152047`.
- Stage-Ausweisfreigabe war false, DEV bereits true. Nur auf Stage
  `CLUBUNIO_MOBILE_NATIVE_MEMBER_ID_CARD_ENABLED=true` gesetzt und Cache als
  runcloud neu erstellt. Private Rückwegsicherung unter
  `/root/mobile-card-config-20261122-152100`; nicht ins Repository übernehmen.

Rückweg: jeweilige beiden PHP-Dateien mit vorhandener Eigentümerschaft aus der
Sicherung zurücksetzen. Stage-Ausweisfreigabe separat zurücksetzen und
Konfigurationscache als runcloud erneut erstellen. Kein PROD/Store-Eingriff.

## Prüfung

Sechs lokale PDF-Unit-Tests, 31 Assertions grün; inklusive unveränderter
Link-PDF-Vorschau bei weiterhin abgewiesenem Signaturimport und unterschiedlicher
Seitenbegrenzung. PHPsyntax und zentrale DE/EN-Katalogprüfung 2994 Schlüssel grün.
Stage: acht vorhandene, autorisiert geladene Dateien mit 200 und passendem
SHA256; darunter zwei vor Korrektur reproduzierbar mit 422. Keine neuen Dateien
oder Testkonten angelegt. Ausweisdetails melden `CanReadIdCard=true`, direkte
Kartenprojektion bestätigt Mandant und 64-stellige SourceVersion.

Appdarstellung und native Prüfgrenzen: Mobile-Repository
`docs/PROFILE-MEMBERS-PLANNING-FIXES.md`.
