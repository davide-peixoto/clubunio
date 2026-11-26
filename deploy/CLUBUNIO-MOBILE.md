# ClubUnio: Flutter-App-Uebernahme

## Freigabe und Stand 26.10.2026

Benutzerentscheid: bestehende Flutter-App als Code-/Prozessbasis uebernehmen,
an die eigene ClubUnio-API anbinden und schrittweise redesignen. Kein Expo-
Neubau. Lokaler Auftrag zum Start ausdruecklich erteilt. Keine Freigabe fuer
Store-Veroeffentlichung, SaaS-Betrieb, echte Bankzahlungen oder Aenderungen
an PHPMaker DEV/PROD. Die bestehende SVS-App wird nicht umgestellt.

Erstes lokales Paket: 151 allowlist-gepruefte Dateien uebernommen (104 Dart-
Quelldateien, 42 Dart-Testdateien, drei Paket-/Analyse-Dateien, zwei Logos).
`mobile/adoption-manifest.json` enthaelt SHA256 des Originals und Erstimports.
Keine Git-Kopie, Native-Secrets, Firebase-Dateien, Store-Screenshots, Laufzeit-
daten, SQL-Importe oder Backendquellen uebernommen. Original-.git ist in dieser
Sitzung nicht lesbar; deshalb kein unbestaetigter Commit als Quellstand genannt.
Alle 151 Originalhashes nach der Uebernahme nochmals verifiziert.

Flutter Android/iOS/Web frisch initialisiert, eigene App-Kennungen; vorhandene
Abhaengigkeitsversionen/Lockdatei beibehalten. SVS-Bootstrap zu `legacyMain()`
umbenannt, Standardhosts auf `.invalid`, Session-Speicherschluessel getrennt.
Kein Legacy-Login im neuen Einstieg. Alte Module bleiben Referenzbestand.

Der neue Einstieg ist eine deutlich beschriftete lokale Designvorschau mit
Heute, Terminen, Verein und wiederverwendeten Teilnahme-Widgets. Keine echten
Mitglieder, kein Login/Push, keine Provideraufrufe; Aenderungen nur im Speicher.
Vereinsfarbe ueber Theme-Parameter konfigurierbar, kein behauptetes vollstaendiges
Mandantenbranding. Weitere Vereinsmodule sind sichtbar als noch nicht verfuegbar.

## Prozess-/API-Abgleich

Die nachfolgende Tabelle ist eine erste fachliche Lueckenanalyse, keine
Vollabnahme aller Methoden/Felder. Exakte v1-Ressourcen siehe API-Registry und
Swagger. `CLUBUNIO-MOBILE-ENDPOINTS.json` inventarisiert 174 statische Legacy-
Pfadreferenzen inklusive dynamischer Platzhalter; das sind keine 174 geprueften
Operationen. Zusammengesetzte Pfade und Prozessvarianten brauchen Einzelpruefung.

| Bestehende App | ClubUnio-Grundlage | Offener Migrationsschritt |
| --- | --- | --- |
| Login, Mehrprofile, Biometrie, Registrierung/Aktivierung, Passwortreset | Web-/Portalidentitaeten; v1 bisher technische Integrationen | Persoenlicher Mobilvertrag, widerrufbare Geraetesitzungen, Vereinskontext, Kontowechsel und sichere Tokenhaltung; kein gespeichertes Passwort als neue Loesung |
| App-Konfiguration, Modulrechte, Heute | Bestehende Profile/Modulrechte, v1-Datenressourcen | Benutzerbezogener App-Kontext, Branding und serverseitige Faehigkeiten; keine Ableitung aus alten numerischen Rollen |
| Mitglieder, eigene Daten, Eltern/Kinder | members, families, family_members; bestehende Portal-Zugriffsregeln | Eigene/vertretene Identitaeten explizit aufloesen, Feldumfang und Aenderungsantraege als persoenlicher Benutzer |
| Mitgliederanmeldungen/Pruefung | registrations, Review-/Acceptance-Aktionen und private Anhaenge | Feldumfang, Statusuebergaenge, TL-/Teamzuordnung, Annahme/Wiedereintritt und Uploadfortsetzung abgleichen |
| Teams, Spieler, Staff, TL | teams, team_seasons, team_players, team_staff, technical_leads | Meine Teams/Staff/Kontakte mit Benutzerrechten und gebuendelter Darstellung |
| Termine, Zu-/Absagen, Aufgebot, Gaeste, Absenzen, Statistik | team_events, team_participations, team_guests, team_absences und Aktionen | Persoenlicher Kontext, Teilnahmen/Zaehler, Revisionen, Fristen, Serienvorschau, Statistiken und Rechte an Kindern abgleichen |
| Trainingsplanung, KI, PDF | training_plans und durable Providerarbeit | App-DTOs, Statusverfolgung, Qualitaetsgates und Freigabeablauf anbinden |
| Platzstatus | field_availability, facility_statuses | Darstellung, frischer Revisionsstand, Schreibrechte und Statusbearbeitung adaptieren |
| Spesen/Anhaenge | expenses, expense_lines, Kataloge, private Dateien, Upload-Recovery | Formulare/Status/CHF, eigene Bankkonten, Masterfilter, Freigaben und unterbrochene Uploads abgleichen |
| Dokumente, Unterschriften, Dokumentenserien | Metadaten, private Downloads und Uploads vorhanden | Persoenliche Listen, Unterschrift/Rueckgabe und Serienadapter fehlen noch |
| Bus/Schluesselbox | buses, bus_reservations | Eigene Reservationen/Verlauf und Freigaben abgleichen; Hardware-/Codeadapter fehlen |
| Mitteilungen/Mehrprofil-Push | announcements als Entwurf, bestehende Webzustellung | Persoenlicher Posteingang, gelesen-Status, Geraetezuordnung, Push/Deep Links und Versandvertrag fehlen |
| Sponsorenlauf | Webmodul vorhanden | Eigener App-Adapter fuer Kinderanmeldung, Zusagen, Helfer und Abrechnung fehlt |
| Penalty/Loader/Diagnose | Lokale App-Funktionen | Beibehalten; Diagnose auf neue Fehler/Trace-IDs anpassen, Redesign ohne Providerdaten |

## Bereits implementierte neue API-Basis

`ClubUnioApi` trennt den v1-Vertrag von PHPMaker: HTTPS-Origin, relative v1-Pfade,
keine Weiterleitungen, Token pro Anfrage, keine eingebauten technischen Tokens,
redigierte Fehlermeldungen, 401-Sitzungsereignis und keine automatische
Schreibwiederholung. PATCH verlangt gueltigen Idempotency-Key. Es gibt bewusst
noch keinen erfundenen mobilen Loginendpunkt.

`ClubUnioPlanningRepository.events()` liest `team_events` mit
`filters[team_season_id]`, `page`, `per_page`; verarbeitet das echte
`data`/`meta`-Envelope und behaelt Revision sowie Zeitoffsets. Falsches Team,
abweichende Seitennummer, fehlerhafte Felder und Legacy-Envelopes werden nicht
als leere/erfolgreiche Liste dargestellt. Das DTO ist unabhaengig von der UI;
es erfindet keine CanManage-/Elternrechte oder Teilnahmezaehler.

Der Adapter ist noch nicht live angemeldet und nicht mit der Designvorschau
verbunden. Eine erfolgreiche Testabfrage beweist keinen persoenlichen Zugriff.

## Naechstes Umsetzungspaket

1. Vorhandene Account-/Portalzuordnung und Authentifizierung wiederverwenden;
   persoenliche Sitzung mit Token-Widerruf, Fristen und Vereinskontext getrennt
   von technischen Integrationen einfuehren. Bestehende API-Scopes behalten.
2. Eigenen/Eltern-/Trainerkontext serverseitig aufloesen und mit isolierten
   Konten auf unberechtigte Vereins-/Kind-/Teamzugriffe testen.
3. Login -> eigene Teams -> Termine -> Zu-/Absage end-to-end anbinden. Dabei
   Fristen, Konflikte, Idempotenz, Abmeldegrund und Trainerrechte uebernehmen.
4. Uebrige Module anhand dieser Matrix migrieren; fehlende API-Adapter getrennt
   vervollstaendigen. Native Berechtigungen/Firebase/Deep Links separat pruefen.
5. Erst nach physischer Android-/iPhone-Abnahme separater Pilot-/Storeauftrag.

## Pruefstand

- 10/10 API-Vertragsszenarien erfolgreich mit ausschliesslich synthetischen
  Antworten: echter Pfad/Filter/Pagination, Revision/Zeitzone, fehlender Token,
  Tokenwechsel, Origin-/Pfadgrenzen, 401, 409/Idempotenz ohne Retry, ungueltige
  Schluessel, Legacy-Envelope, fremdes Team/abweichende Seite und kaputte DTOs.
- Alle 151 Originalhashes nach der Arbeit unveraendert; uebernommene Lockdatei
  am Ende bytegleich zum Original. Kein Abhaengigkeitsupgrade.
- Statische Analyse ueber `mobile/lib`, `mobile/test`, `mobile/tool` erfolgreich:
  `No issues found!`.
- Webbuild `flutter build web --no-pub --no-web-resources-cdn --no-wasm-dry-run`
  erfolgreich. Das JavaScript-Preview buendelt CanvasKit/Schriften lokal.
  Kein Wasm-Versprechen: uebernommene Secure-Storage-Webabhaengigkeit nutzt
  weiterhin dart:html/dart:js_util.
- Widgettests fuer 390/1024 px sowie uebernommene SVS-Tests vorhanden, aber hier
  NICHT erfolgreich ausgefuehrt: der Flutter-Tester kann die lokale WebSocket-
  Verbindung zu 127.0.0.1 nicht aufbauen (SocketException10057 vor Teststart).
  Keine visuelle Abnahme oder Gruenmeldung fuer die Gesamtsuite behauptet.
- Ein Secret-Musterscan der Dart-Quellen/Pruefwerkzeuge ohne Fund. Das ist kein
  umfassendes Sicherheitsaudit.
- Standard-SDK/Cache schreibgeschuetzt; fuer die Pruefung wurde ein ignorierter,
  beschreibbarer Laufzeitcache unter storage/app/mobile-toolchain verwendet.
  Native PDFium-Datei aus vorhandenem Testcache kopiert und SHA256 identisch
  verifiziert; keine Netzwerkdownloads oder Secrets uebernommen.
Kein Deployment, kein Schemaeingriff, kein Import/Refresh, keine echte
Benutzersitzung und kein Store-Build durch diesen Auftrag.

## Eigenes App-Repository (27.10.2026)

Auf Benutzerauftrag eigener Git-Ursprung https://github.com/davide-peixoto/clubunio_mobile.git
direkt in C:\ClubUnio\mobile eingerichtet. Das Backend ignoriert /mobile/;
App-Dokumentation/Audit sind im App-Repository eigenstaendig enthalten.
Keine Projektkopie, kein Submodul, kein Deployment und keine Legacy-Aenderung.
