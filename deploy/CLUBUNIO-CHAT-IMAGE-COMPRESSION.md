# Chatbilder stark komprimieren

Stand05.11.2026. Neue Chatbilder werden vor dem Versand und serverseitig vor
der verschlüsselten Datenbankspeicherung neu kodiert. Keine öffentlichen
Originaldateien, keine neuen Tabellen/Spalten oder Speicher-/Providerumstellung.

## Grenzen und Qualität

Lange Seite maximal1600px, Ziel350KiB, feste Bilddateigrenze512KiB. JPEG70/58/46
mit weiterem Verkleinern auf1280/960/640 bei Bedarf; keine Vergrösserung kleiner
Bilder. Transparenz bleibt in WebP erhalten, serverseitig gegebenenfalls PNG.
EXIF-Ausrichtung vor Neukodierung berücksichtigt, Metadaten danach entfernt.
Quelle20MiB im App-Picker,25MP/10000px als Decodegrenze; Backend behält den
bisherigen5MiB-Eingang und prüft vor GD die Speicherreserve. Kamera und Dateien
laufen durch die Chatregel. HEIC wird mit vorhandenem nativen Codec konvertiert.
Ungültige/animierte Appbilder werden abgelehnt; kein ungeprüfter Originalfallback.
PDFs weiter byteidentisch bis5MiB. Spesen-/Registrierungsbilder nicht verändert.

Die App liest ausgewählte Dateien begrenzt im Stream; Kodierung im Worker-Isolate
auf nativen Geräten. Während Vorbereitung bleibt der Entwurf bestehen; parallele
Auswahl/Senden gesperrt, Fortschrittsanzeige sichtbar. Abgebrochener Konto-/
Chatkontext übernimmt keine späten Bildresultate. Lokale Vorbereitung erzeugt
keine Serverdatei. Explizites Senden nutzt unverändert Quarantäne und Scan.

## Speicherung und Wiederholung

Nur das kompakte Bild wird verschlüsselt in bytes_encrypted gespeichert;
byte_count und sha256 beziehen sich auf exakt diese Bytes. Der versionierte,
verschlüsselte Dateinamensumschlag hält Name und Original-Uploadhash getrennt.
Dieser Hash verhindert, dass verschiedene Originale mit gleichem komprimiertem
Resultat als identische Wiederholung akzeptiert werden. Er wird nicht im
Metadatenvertrag veröffentlicht. Gleicher Schlüssel/gleiche Quelle bleibt
auch über Codecänderungen stabil; alte Dateien replayen vor der Neukodierung.
Alte Klarname-Metadaten bleiben lesbar, PDFs behalten ihren bisherigen Vertrag.
Metadatenseiten laden keine Bildbytes. Bestehende Anhänge bleiben unverändert.

## Prüfung und Auslieferung

42 Appfälle erfolgreich: echte Fotoreduktion auf unter350KiB und weniger als
25% der Quelle, Transparenz, Orientierung/Metadaten, Grenzen/Streams, Kamera,
Dateiauswahl, Virenprüfung vor Nachricht und bestehende Belegbilder. Analyse
sauber;1997 Schlüssel in fünf lokalen Katalogen/Literalprüfung erfolgreich.
Backend69Tests/1052Assertions grün, einschliesslich3 echter PostgreSQL-Fälle
mit53Assertions und Rollback/Cleanup. Keine echte Nachricht/Provideraktion.

DEV-Kandidat basiert auf pitch-admin-status-override; nur zwei Runtimequellen
plus synthetisches Codecgate. ENV/Composer/Featureflags bleiben erhalten.
Neuer Appbuild für Kompression vor dem Netzwerk erforderlich; Server schützt
auch ältere Apps. Keine automatische Umschreibung bestehender Bilddateien.
Nach neuen Uploads muss ein Rückbau den neuen Dateinamensleser/Replaypfad
beibehalten: blindes Zurückschalten auf den alten Klarname-Leser ist ungeeignet.


## DEV-Nachweis05.11.2026

Aktiv `/var/www/svs-laravel/releases/20261105-clubunio-chat-image-compression`,
Runtime bd6a414, App5157a59. Basiskopie pitch-admin-status-override erhalten.
Drei Dateihashes vor/nach Aktivierung und bisherige ChatAttachments-Baseline
geprüft; komplette Konfiguration samt privater Firebase-Registry unverändert,
ENV-/Composerbytes gleich. Synthetisches, rein im RAM arbeitendes Codecgate:
118819->30690Bytes, maximal1600px; transparente Pixel nach Reencode erhalten.
Kein DB-Schreibzugriff oder echter Chat-/Malwareprovideraufruf für dieses Gate.
FPM aktiv, HTTPSup200/context401. Finale Manifest-SHA:
aae1686fc9d588962accd0d736be4c0a12e911ac2c2fa6f94a9ab5a02b3ce009.
Native Kamera-/Galerieabnahme benötigt den neu gebauten Appstand.
