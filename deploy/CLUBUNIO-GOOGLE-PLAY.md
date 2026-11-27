# ClubUnio Google Play Test – 27.11.2026

Auftrag: Store vorbereiten, Android bauen, hochladen und als Test bereitstellen;
Zielregion nach ausdrücklicher Präzisierung EU plus Schweiz.
Keine öffentliche Produktionsveröffentlichung beauftragt.

## Gespeicherter Console-Stand

- Bestehende App ClubUnio, Paket `com.my.clubunio.core`.
- App/Sport, Kontakt `info@clubunio.com`, Webseite `https://clubunio.com`.
- Deutscher Storetext als Entwurf gespeichert.
- Geschlossener Alpha-Test: genau 28 Länder (EU27 + Schweiz) gespeichert und
  als „Targeted (28)“ kontrolliert. Keine automatische weltweite Freigabe.
- Produktionszugang gesperrt; dort noch keine Länder konfiguriert. Console
  verlangt derzeit mindestens 12 Tester über mindestens 14 Tage im geschlossenen
  Test, danach separater Antrag. Wallet-Publishingfreigabe ersetzt dies nicht.
- Noch keine früheren App-Bundles oder Releases in der Console vorhanden.

Interne Tests ignorieren die Länderauswahl und verwenden eingeladene Konten.
Der Benutzer hat den INTERNEN Test gewählt. Die konkreten Google-Testkonten sind noch zu bestätigen.
Länder richten sich nach dem Google-Play-Kontoland; einzelne Territorien werden
von Google mit dem jeweiligen Land gruppiert.

## Mobile Vorbereitung

Storematerial und Releasewerkzeug liegen im separaten Mobile-Repository:
`store/google-play/README.md`, `tools/build_play_bundle.py`.
ClubUnio-Icon, Titelgrafik und vier Android-Demoaufnahmen ohne echte Mitgliederdaten
sind als SVG/PNG vorbereitet. DE/EN-Texte und Länder-Manifest sind versionierbar.
Die bestehende Datenschutzerklärung `https://clubunio.com/de/privacy` wurde im
Browser geöffnet und beschreibt explizit die App sowie den Löschanfrageweg.
Die URL wurde noch nicht in der Play Console gespeichert.

Release-Signierung fällt nicht mehr auf den Debug-Schlüssel zurück. Upload-
Schlüssel und Firebase-SDK-Konfiguration bleiben lokal ausserhalb von Git;
Googles bestehender App-Signierschlüssel wird nicht verändert. Firebase erhielt
die bisher fehlende Android-Core-Registrierung im bestehenden Projekt.

## Noch keine Testfreigabe

Chrome blockiert Datei-Uploads ohne die Erweiterungsfreigabe „Zugriff auf
Datei-URLs zulassen“ für ChatGPT. Daher weder Storegrafiken noch AAB-Upload oder
Rollout als abgeschlossen melden. Die Erweiterung verlor bei der weiteren
Vorbereitung ausserdem vorübergehend ihre Browserverbindung.

Offen: Testkanal/Tester, Upload/Verarbeitung, App-Zugriff für Review, Datenschutz-
und Alters-/Inhaltsdeklarationen samt tatsächlichem Löschweg, native Android-
Abnahme von Login, Push, Wallet und Eventlinks. Historisch sind eigene Konten für
unter 13-Jährige gewünscht; keine Erwachsenen-Zielgruppe als Umgehung deklarieren.
Keine Änderungen an Serverbetrieb, Datenbanken oder PROD-Konfiguration nötig.

[Google-Länderregeln](https://support.google.com/googleplay/android-developer/answer/7550024?hl=en)

## Lokaler Build geprüft

Android-Release 0.1.0 (19), Paket `com.my.clubunio.core`, 88.862.400 Bytes,
min API 24 / target API 36. PROD-Origin und persönliche Firebase-Konfiguration.
AAB-SHA256: `4d5713c53db0867107bd7ac509627ae8363195fc3270a21fdedd08a2055062f8`.
Bundletool validiert; Manifest/Identität/Version und Signatur geprüft. Signierer
entspricht dem geschützt abgelegten Upload-Zertifikat. Alle zwölf 64-Bit-Libraries
im finalen Bundle besitzen mindestens 16-KB-LOAD-Ausrichtung; BundleConfig 16K.
Release ohne Schlüssel wird abgewiesen; Debug funktioniert weiterhin ohne ihn.
15 Markenprüfungen bestanden, eine plattformbedingt übersprungen; Iconprüfung
bestanden. Backend-Sprachkatalogprüfung: 3075 Schlüssel in DE/EN ohne Fehler.

Buildpfad lokal: `C:\ClubUnio\mobile\build\app\outputs\bundle\release\app-release.aab`.
Kein Bundle/Schlüssel in Git. Kein erfolgreicher Upload und keine Testverteilung
behauptet. Mobile-Prüfmetadaten: `store/google-play/build-review.json`.
