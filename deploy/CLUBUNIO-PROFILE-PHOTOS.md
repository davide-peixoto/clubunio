# Profilfotos: Stage-Pilot

Die App liest `GET /api/mobile/v1/profile/photo` und sendet maximal 5 MiB
per Multipart `POST` an dieselbe Route. Ein eindeutig berechtigter eigener
Mitgliedslink ergibt einen Fotoantrag; ohne eigenen Link bleibt das Foto am
Benutzerkonto. Ein Elternlink ist kein eigener Link. Uneindeutige eigene
Links schalten den Upload ab. Die App liest die Freigabe aus `profile`.

Nur die Geschaeftsstelle kann den Fotoantrag im Mitgliederdossier pruefen.
Bis zur Freigabe bleibt das bisherige Foto sichtbar. Fotos liegen
verschluesselt in der Datenbank und werden privat ausgeliefert. GD prueft
und kodiert die Bilder als JPEG neu; Originale und EXIF werden nicht
gespeichert. Bestehende Ausweisbilder werden nicht uebernommen.

## Migration und Rueckweg

`2026_11_13_000600_profile_photos.php` ist additiv und nur auf verifizierter
Stage-DB lauffaehig. Nach Installation Routen- und Konfigurationscache
aktualisieren. `CLUBUNIO_PROFILE_PHOTOS_ENABLED` bleibt standardmaessig aus.
Rueckweg: Flag deaktivieren und Config-Cache neu bauen; Tabellen erhalten.
Kampagnenfotos bleiben bis zur Entscheidung verschluesselt im Antrag und
werden nur bei Freigabe publiziert. Migration
`2026_11_13_000610_campaign_portraits.php` ebenfalls gezielt auf Stage
anwenden. `deploy/check-stage-member-campaigns.php` prueft den Ablauf
mit synthetischen Daten und ohne Versand in einer Rueckrolltransaktion.
Das Web-Profil und das berechtigte App-Mitgliederdossier zeigen nur
freigegebene Fotos. Die visuelle Geraeteabnahme bleibt separat.

Stage: beide Migrationen angewendet und Flag nach bestandenem
synthetischem Freigabetest aktiviert. Nach Cacheaufbau Freigabetest
erneut bestanden; Login HTTP 200. Kein Versand, DEV und PROD unveraendert.

## Mein Profil im Web

Die Verwaltung verlinkt `/my-profile` im Benutzermenue, das Vereinsportal
`/portal/profile`. Beide arbeiten ausschliesslich mit dem eigenen Konto
der jeweiligen Sitzung. Das Web nutzt denselben Foto- und Freigabeablauf
wie Mobile. Ein Passwortwechsel verlangt das aktuelle Passwort, widerruft
Mobile-Sitzungen und beendet die aktuelle Web-Sitzung. Keine Migration.
