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
Offen: Kampagnen-Upload, Fotoanzeige im App-Mitgliederdossier, Web-Profil-
Avatar und visuelle Abnahme.
