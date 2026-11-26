# DEV / Stage / PROD: Konsolidierung 22.11.2026

## Ergebnis

Stage und PROD sind auf nachvollziehbaren Git-Releases, alle zuvor losen
Serverdateien sind als Featurepakete konsolidiert. DEV hat einen separaten
synchronisierten Quellstand mit Dateipruefsummen; seine bestaetigten
Umgebungsabweichungen werden nicht automatisch nach Stage/PROD uebernommen.

- Stage: 55f2369 vor diesem Nachweispaket, sauberer Server-Arbeitsbaum.
- PROD: 7208417 vor diesem Nachweispaket, sauberer Server-Arbeitsbaum.
- DEV: unveraendertes aktives Release 20261119-clubunio-social-insights-v3,
  danach gezielt eine fehlende historische Migrationsquelldatei ergaenzt.
  Branch codex/dev-runtime-20261122 dokumentiert die tatsaechlichen Quellen.
  deploy/evidence/dev-runtime-20261122.json: 1301 Dateipruefsummen;
  alle auf dem Pi unabhaengig erfolgreich abgeglichen.

DEV unterscheidet sich in 87 Quelldateien von der konsolidierten Stage-Basis
und hat 21 zusaetzliche Dateien. Darunter persoenliches Profil, Keycloak-
Migrationspiloten und umgebungsspezifische Integrations-/Anmeldewege.
Der Snapshotbranch dient der Nachvollziehbarkeit dieses bestehenden Betriebs,
nicht als pauschale Freigabe dieser Unterschiede fuer Stage oder PROD.
Quellen gegen konfigurierte Secretwerte geprueft: kein Treffer.
Secrets, Cache, Uploads und vendor sind nicht Teil des Commits.

## Datenbanken und Journale

| Instanz | Vorher | Nachher | Erklaerung |
|---|---:|---:|---|
| DEV | 138 | 147 | 8 vorhandene Migrationen nach Strukturpruefung journalisiert; 1 fehlende Migration additiv umgesetzt |
| Stage | 145 | 145 | Vollstaendiger Quell-/Journalabgleich ohne Schemaeingriff |
| PROD | 143 | 145 | Social-Auswertung und Event-Check-in additiv migriert |

DEV: Tabellen fuer Account-Sicherheit, Passkeys, Kopplung und Support
jeweils einschliesslich Spalten, Defaults, Identitaeten, Constraints und
Indizes gegen Stage verglichen. Ebenso Mitgliedschafts-Tracking mit
registration_id, Fremdschluessel, Purpose-Check und partiellem Index sowie
security_stamp in mobile_sessions. Acht zugehoerige Journalnamen fehlten
trotz uebereinstimmender Struktur. Nur diese Journalzeilen nachgetragen.

Die Tabellen account_profile_photos und member_profile_photos fehlten
tatsaechlich auf DEV. Nur diese zwei Tabellen aus der Stage-Schemadefinition
ohne Daten uebernommen, Laufzeitrechte fuer clubunio_dev_app erteilt und
den zugehoerigen Migrationseintrag angelegt. Probelauf mit ROLLBACK vor
Commit bestanden. Werkzeug: deploy/reconcile-dev-journal.py.
Alle 147 DEV-Journalnamen passen danach exakt zu den Migrationsquellen.

Die beiden weiterhin ausschliesslich auf DEV registrierten Migrationen:
2026_11_16_000091_keycloak_migrations und
2026_11_16_000092_platform_keycloak_invitations.
Ihre Quellen erlauben ausdruecklich nur clubunio_dev; daher keine
Scheinangleichung oder unfreigegebene Ausfuehrung auf Stage/PROD.

PROD: genau 2026_11_19_020000_social_insights und
2026_11_22_000120_event_checkin in einer Transaktion mit exakter
Datenbankpruefung, Lock-/Statement-Timeout und Advisory Lock ausgefuehrt.
Vorher identischer ROLLBACK-Probelauf erfolgreich. Neue Objekte gehoeren
userunio. Sechs neue Tabellen unabhaengig hinsichtlich Spalten,
Constraints und Indizes exakt gegen Stage geprueft.
Stage-/PROD-Journale sind identisch; keine Quelle ohne Journal und kein
Journaleintrag ohne Quelle. Keine Altmigration erneut ausgefuehrt.

## Stage-Abnahme und PROD-Bereitstellung

47 gezielte lokale Featuretests / 278 Assertions erfolgreich
(Social, Event-API/Web/Drosselung/App-Links, Wallet/Passpaket).
Echte PostgreSQL-Fachtests auf Stage vor und nach Konsolidierung:
- Social: verschluesselte Tokens, beide Kanaele, Snapshot-/Nullwertsemantik,
  Dublettenschutz, Rechte-/Mandantengrenzen, Fehlernachweis und Trennen.
  Provideraufrufe synthetisch abgefangen; keine echten Meta-Aufrufe.
- Check-in: ausdrueckliche eigene Bestaetigung, Idempotenz, RSVP-Trennung,
  Rollen-/Vereinsgrenzen, veraltete Revision, Schliessen und Tokenrotation.
Alle synthetischen Fachdatensaetze zurueckgerollt.

Beim Zusammenfuehren die VPS-Schemanamen clubunio erhalten. Eine
zwischenzeitliche Fachtestabweichung durch lokale clubora-Quelldateien
vor PROD-Uebernahme korrigiert und Stage erneut erfolgreich geprueft.
Vorhandene Mail-/Identity-Schutzregeln auf Stage erhalten.
Zwei fehlerhaft kodierte Quelltexte auf bestehende DE/EN-Schluessel korrigiert;
keine Benutzereingaben oder gespeicherten Freitexte ersetzt.
DE/EN auf Stage und PROD: 3060 Schluessel fehlerfrei. Templates kompiliert,
Configcache erneuert, PROD-Livewire byteweise verifiziert.

PROD Event-Check-in technisch aktiviert, Origin ausschliesslich
https://app.clubunio.com, keine Vereinsdomain-Ausnahmen. App-Link-Zuordnung
nur Core-App. Nginx gibt nur die beiden exakten Association-Dateipfade frei.
Apple-AASA enthaelt XP7296D478.com.my.clubunio.core fuer /event-check-in/*.
Android assetlinks bleibt [] ohne erfundene Signierfingerabdruecke.
Social-Auswertung als Code/Schema vorhanden, aber externer Meta-OAuth-/Import
bleibt deaktiviert; bestehender Service begrenzt reale Provideraufrufe auf
die bisher freigegebenen DEV-/Stage-Testziele. Keine Live-Providerfreigabe
aus der Codepromotion ableiten. Ein aktiver PROD-Verein existiert noch nicht.

HTTPS Stage/PROD: Plattformlogin und geschuetzte Social-Seite anonym 302,
unbekannter Check-in 404, beide Association-Dateien 200, /.env 403.
PROD bleibt ausserhalb des Wartungsmodus.
Bestaende nach Abschluss: DEV 11 Vereine/84 Konten, Stage 3/153,
PROD 0/1. Keine Vereinsdaten zwischen Umgebungen uebernommen.

## Sicherung und Rueckweg

Private VPS-Sicherung /root/clubunio-consolidation-20261122T203701Z:
drei PostgreSQL-Custom-Dumps jeweils mit pg_restore --list geprueft,
Journale, 61 Stage-/26 PROD-Dateien samt SHA256, private ENV-Sicherungen,
DEV-Abgleich und genau ausgefuehrte SQL-Pakete.
Serverdeltas zusaetzlich vor Fast-Forward in eigenen Git-Stashes erhalten.
Unmittelbar vor Ersetzung jede gesicherte Dateipruefsumme erneut geprueft.

Pi: /var/www/svs-laravel/shared/consolidation-20261122 mit Quellarchiv und
Dateimanifest. DEV-Laufzeit nicht pauschal durch Stage-Code ersetzt.
Hauptarbeitsordner C:/ClubUnio und parallele Import-/Mobilearbeiten erhalten.

Bei Ruecknahme zuerst neue Featureflags deaktivieren, dann voriges
Code-/Configpaket aus privater Sicherung wiederherstellen. Neue Tabellen
und Journale nicht automatisch loeschen. Sobald Fachdaten vorhanden sind,
keinen kompletten DB-Abzug ungeprueft zurueckspielen.
Offen bleiben native iOS-/Android-Abnahme und eine eigenstaendige
Produktivfreigabe der externen Social-Provider fuer den ersten PROD-Verein.