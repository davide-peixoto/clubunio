# Mobile Anlagenstatus: persoenlicher Lesezugriff

Stand: 05.11.2026. Backend umgesetzt, getestet und auf ClubUnio aktiviert.
Lesender Servercheck mit einem bestehenden Trainer erfolgreich; nativer
Geraetetest noch offen. Release: CLUBUNIO-FACILITIES-STATUS-RELEASE.md.

## Ursache Und Umfang

Die native Platzplanung erlaubte den bestehenden persoenlichen Vereinszugang,
waehrend Anlagenstatus und dessen Modulkennzeichnung ausschliesslich
`field_availability.view` verlangten. Dadurch konnten Trainer Kalender und
Anfragen nutzen, aber die bestehende Offen-/Gesperrt-Uebersicht nicht lesen.

`MobileFacilitiesData::canRead()` ist jetzt die gemeinsame Lesepruefung fuer
`GET /api/mobile/v1/clubs/{tenant}/facilities/fields` und die Facilities-
Kennzeichnung in `GET /api/mobile/v1/clubs/{tenant}/modules`:

- Bestehende explizite Verwaltungs-Leserechte bleiben unveraendert.
- Der neue persoenliche Zugang verlangt `MemberAccessService::canEnter()`
  im konkreten Verein und `Platzverfugbarkeit` in den persoenlich sichtbaren
  Modulschluesseln. Keine Freigabe allein aufgrund der Rollenbezeichnung.
- Ausgeschlossene Module und inaktive/ungueltige App-Profile gewaehrleisten
  keinen persoenlichen Fallback. Bestehende Verwaltungsrechte werden durch
  diese Aenderung weder erweitert noch umdefiniert.
- Aktive persoenliche Mitglieder-/Elternzuordnungen folgen denselben
  Bestandsregeln wie der persoenliche Vereins-/Kalenderzugang. Fremde Vereine,
  fehlende oder entzogene Zuordnungen und gesperrter Portalzugang bleiben zu.
- `can_edit` und PATCH verlangen weiterhin `field_availability.update`.
  Schreibcontroller, FacilityService und globale Modulrechte sind unveraendert.
- `native_facilities_enabled` bleibt erforderlich; das Modulflag verlangt
  ausserdem `facilities-v1`. Clientmerkmale erteilen keine Berechtigungen.
  Anlagenstatus bleibt unabhaengig von der Pitch-Freigabe nutzbar.

Mandantenfilter, Status-/Lookup-IDs, gespeicherte Namen und Notizen bleiben
unveraendert. Keine neuen UI-Texte oder Sprachfreigaben; DE/EN bleiben aktiv.

## Geaenderte Dateien

Runtime, abschliessende Liste:

- `app/ClubUnio/Mobile/MobileAccountController.php`
- `app/ClubUnio/Mobile/MobileFacilitiesData.php`

Gezielte Tests:

- `tests/Feature/MobileFacilitiesBusTest.php`
- `tests/Feature/MobilePitchControllerTest.php`

Deploymenthelfer, Releasegates, Flutter und Migrations-/Roadmapeintraege sind
im zugehoerigen Releaseprotokoll erfasst.

## Pruefnachweis

39 Tests / 686 Assertions erfolgreich, Laufzeit rund 72 Sekunden:
`MobileFacilitiesBusTest`, `MobilePitchControllerTest`, `MobileReadContextTest`.
Die erste Suite nutzt echte PostgreSQL-Transaktionen mit synthetischen Daten
und abschliessendem Rollback; keine echten Benutzerzuordnungen veraendert.
Die Adapter-/Lesekontexttests nutzen isolierte SQLite-Fixtures.

Geprueft: Trainer und persoenliche Mitglieder lesen ohne Verwaltungsrecht;
Offen/Gesperrt und `can_edit=false`; PATCH verweigert und Revision unveraendert;
fremde Anlagen/Status nicht enthalten; fremder Verein verweigert; unverbundene
Trainerrolle verweigert, auch mit gueltiger Sitzung eines anderen Vereins;
Zuordnungsentzug und Portal-/Profilabschaltung wirken beim naechsten Abruf;
ausdrueckliche App-Profilauswahl wird beachtet; Modulflags, Clientmerkmale und
Facilities-/Pitch-Schalter bleiben getrennt. Bestehender Admin-Schreibtest gruen.

Wiederholung unter Windows, ohne dauerhafte Konfigurationsaenderung:

```powershell
php -d extension=pdo_pgsql -r 'putenv("CLUBUNIO_TEST_DB=1"); require "vendor/phpunit/phpunit/phpunit";' -- tests/Feature/MobileFacilitiesBusTest.php tests/Feature/MobilePitchControllerTest.php tests/Feature/MobileReadContextTest.php
php tools/check-translations.php
```

DE/EN: 2670 Schluessel und Platzhalter erfolgreich geprueft. PHP-Syntax beider
Runtime-Dateien und `git diff --check` erfolgreich. HTTP-Provideraufrufe sind
in den Tests gesperrt, Mail wird simuliert; kein realer Versand.

## Bereitstellung

Gezieltes Zwei-Runtime-Dateien-Delta plus separat betreute Releasegates auf
Basis `/var/www/svs-laravel/releases/20261105-clubunio-pitch-usability-v3`.
Aktiver Nachfolger: `20261105-clubunio-facilities-status`.
Keine Migration, Seeds, Imports, DB-Benutzerrechte oder Rollen-/Profilzuordnung.
PHPMaker DEV/PROD bleiben unveraendert.

Die aktuelle private Firebase-Appregistry aus v3 unveraendert erhalten;
Credentialdatei bleibt ausserhalb des Releasepakets. Beide Pushflags
`CLUBUNIO_MOBILE_PUSH_ENABLED` und `CLUBUNIO_MOBILE_PUSH_DELIVERY_ENABLED`
bleiben false. Kein Registry-Leeren fuer ein historisches Closed-Gate.
Konfigurationsvergleich ohne Geheimnisausgabe, releaseprivate Caches und
gezielte Dateihashes vor/nach Aktivierung durch den Deploymentverantwortlichen.
Rueckweg ist v3 unter Erhalt derselben privaten Konfiguration, ohne Datenrollback.

## Trainer-Smoke-Test

Vor Aktivierung mit einem vorhandenen berechtigten Trainer im richtigen Verein
rein lesend auf dem Server geprueft, innerhalb
`READ ONLY / REPEATABLE READ` und ohne neue dauerhafte Sitzung:

1. Persoenlichen Vereinszugang und sichtbares Anlagenmodul bestaetigen;
   vorhandene Profile/Zuordnungen nicht veraendern.
2. Modulantwort mit `pitch-planning-v1,facilities-v1`: Anlagenmodul vorhanden,
   `facilities=true`; Pitchkennzeichnung bleibt gemaess bestehender Freigabe.
3. Statusprojektion liefert die echten vereinseigenen Anlagen und bestehenden
   Offen-/Gesperrt-Werte, fuer den Trainer durchgehend `can_edit=false`.
4. Bestehendes `field_availability.update` rein lesend als false bestaetigen.
   Kein echter PATCH, keine Status-/Terminmutation und keine Nachricht.
5. Health/unauthentifizierte API, aktive Releaseverknuepfung und unveraenderte
   private Registry mit beiden Pushflags false ueber das Deploymentgate pruefen.

Ergebnis: facilities=true, pitch_planning=true, drei Felder, can_edit=false.
Health200/API401 nach Aktivierung; kein nativer Benutzerlogin durchgefuehrt.
56 Fluttertests/Analyse und synchronisierter Appstand `baae0db` erfolgreich.
Die physische Geraeteabnahme mit dem neuen Build bleibt offen.
