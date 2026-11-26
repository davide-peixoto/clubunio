# Clubhaus

25.11.2026. Clubhaus auf DEV, Stage und PROD verfügbar.
DEV-Modulstand `20261125-clubunio-clubhouse-ui-v1`, technischer Rückweg auf Clubhaus-v2;
fachlicher Rückweg ohne Modul `20261124-clubunio-veo-calendar-v5`.

## Oberfläche

**Anlagen & Kommunikation > Clubhaus**, `/clubhouse`: Übersicht, Öffnungszeiten,
Menükarten, für Admins Berechtigungen. Gemeinsame Filament-Tabs/Dialoge,
BackendTheme-Tokens, Vereinsfarben und Dark Mode; zentraler Designparameter bleibt.
Trainer verwenden dieselbe Komponente über den Clubhaus-Menüpunkt im Portal.
Bestehender Portal-/App-Zugang bleibt Voraussetzung; die Zusatzrolle öffnet nicht
die allgemeine Backoffice-Verwaltung. App verwendet AppTheme/AppSectionHeading,
Vereinsfarben und bestehende Navigation statt eines eigenen Designs.

## Fachregeln

- Einzelöffnung/Wochenserie mit ISO-Wochentagen, Gültigkeit und bis acht Fenstern.
  Über Mitternacht ausdrücklich „Folgetag“ wählen. Änderung gesamte Serie, nur ein
  Vorkommen oder Folge ab Datum. Die Vergangenheit bleibt erhalten.
- Tagesausnahmen ersetzen den ganzen Tag einschließlich Vortagsüberhang;
  Serienausnahmen betreffen nur ihre Serie. Zurücknehmen entfernt die Ausnahme,
  ihr alter Datensatz bleibt im Audit. Überlappende Öffnungen werden vereinigt.
  Vereinszeitzone, Standard Europe/Zurich; DST folgt Zeitzonenbibliothek, keine
  separate zweite Öffnung während der doppelten Herbststunde.
- Menükarten: DE/EN-Text oder PDF bis 10 MiB, Gültigkeit und Kanäle Web/App.
  Fehlende Übersetzung fällt gekennzeichnet auf vorhandene Sprache zurück.
  Freitexte werden escaped, niemals automatisch übersetzt.
- Entwürfe privat; Freigabe erstellt unveränderlichen Snapshot. Entwurf/PDF
  bearbeiten ersetzt keine Veröffentlichung. Erst erneute Freigabe übernimmt.
  Entziehen/Archivieren beendet Veröffentlichung, Wiederherstellen ergibt Entwurf.
  Redakteure dürfen aktive Veröffentlichungen nicht über Archivieren zurückziehen.
- Revisionsprüfung/Mandantensperre und Audit bei Änderungen. Arbeitsliste:
  Filter und 25 Menüs je Seite, App lädt nach. Kalender maximal 90 Tage/500 Regeln,
  Vorschau 31 Tage, UI sieben Tage; Verwaltungslisten Regeln/Ausnahmen maximal 200.

## Rechte

Nur Vereinsadmin vergibt unter Clubhaus > Berechtigungen oder Benutzerverwaltung
die Zusatzrechte Öffnungszeiten pflegen, Menükarten bearbeiten, Veröffentlichen.
Je Verein/Benutzer getrennt aktivierbar, ohne bestehende Rollen zu ändern.
Modulprofile: `clubhouse_hours.view/update`, `clubhouse_menus.view/update/send`.
Keine bestehenden Benutzer zugeordnet. Simulation nur lesend; fremde Mandanten,
inaktive/API-only-Konten gesperrt. Kein zusätzlicher Finanz-/Benutzerzugriff.

## API, App, Website und Speicher

Persönliche API `/api/mobile/v1/clubs/{tenant}/clubhouse`, Swagger
`/api-docs?surface=clubhouse`. App meldet `clubhouse-v1`; ältere Builds erhalten
keinen unfertigen nativen Einstieg. **Clubhaus** im App-Modulprofil für Zielgruppen
aktivieren; Zusatzberechtigte erhalten den Einstieg ohne andere Module zu ändern.
Profile ohne explizite Modulbeschränkung erben wie bisher alle aktiven Optionen
und sehen damit auch die veröffentlichte Clubhaus-Übersicht automatisch.
Leser sehen Veröffentlichungen, Pflegeberechtigte zusätzlich Editoren.
PDF-Vorschau über authentifizierte Bytes ohne Dateisystem-Tempverzeichnis.
Neuer nativer Build erforderlich; kein Store-Release. Fünf App-Kataloge vollständig,
aktivierte Sprachen bleiben DE/EN. Fehlende EN-Menütexte fallen auf DE zurück.

Website-Ausgabe standardmäßig für alle Vereine **aus**. Admin aktiviert unter
Clubhaus > Berechtigungen > Einstellungen. Danach HTML `/public/clubhouse/{slug}`,
JSON `/public/clubhouse/{slug}/data` mit PDF-Links. Nur gültige Web-Snapshots;
keine Entwürfe, internen Notizen, Kontodaten oder Speicherpfade. Domainbindung
geprüft. JSON CORS-lesbar/no-store ausschließlich für öffentlich freigegebene Daten.
Konsumierende Webseite muss den Feed noch einbinden; kein Website-Repo geändert.

TenantDocumentStorage: private UUID-PDFs im Namensraum `clubhouse`, Objekt- und
Mandantenbindung, Größen-/Hashprüfung. Alte Snapshot-PDFs erhalten. Speicherobjekte
aus fehlgeschlagenen DB-Transaktionen ggf. später kontrolliert bereinigen.
Keine Mails, Pushes oder externen Publikationsaufträge ausgelöst.

## Migration und Rückweg

Sieben additive Tabellen: `clubhouse_settings`, `clubhouse_grants`,
`clubhouse_opening_rules`, `clubhouse_opening_exceptions`,
`clubhouse_menu_statuses`, `clubhouse_menus`, `clubhouse_events`.
Mandanten-/zusammengesetzte Eigentümer-FKs, Datums-/Revisionsconstraints,
eindeutige Ausnahmedaten; Statuswerte als mandantenbezogene Lookup-Tabelle.
Initiale Settings/Statuswerte und App-Moduloption, keine Refreshes/PHPMaker-Importe.

Owner-Migration ausschließlich `clubunio_dev`, Runtime-Schema `clubunio`.
`emit-clubhouse-migration.php clubunio_dev`: Transaktion, Advisory Lock, Zeitlimits,
begrenzte neue Tabellen-/Sequenzrechte für Webrolle, kein DDL. Verschlüsselter
vollständiger Owner-Dump vorher lesbar geprüft unter
`/root/clubunio-releases/clubhouse-20261124`; Schlüssel bleibt dort.
41 Runtime-Dateien per SHA-256, private Konfiguration/Storage/Composer erhalten.
Route-/View-Cache erneuern, FPM graceful reload. Rückweg: Vorgänger aktivieren,
neue Tabellen/Audit behalten, kein Down/Restore laufender Vereinsdaten.

## Prüfungen und Grenzen

19 lokale Clubhaus-Tests / 97 Assertions, Rollen/Isolation/Widerruf, Serien/Ausnahmen,
Übernachtöffnung, Revisionen, Snapshots/PDF, Escaping/Paging/API-Vertrag.
Regression: 59 Tests / 24723 Assertions, 14 externe DB-Tests übersprungen.
Acht weitere Storage-Tests / 51 Assertions bestanden. Bestehender Plattform-Rendertest
scheitert unabhängig an fehlendem `$metrics` in monitoring.blade.php; nicht im
Fachpaket verändert oder als bestanden ausgewiesen.
DEV PostgreSQL: 17 Prüfungen mit synthetischem Verein/Admin/Trainer, vollständig
zurückgerollt, keine ausgehenden HTTP-Aufrufe. Flutter: zehn neue Tests, 20 mit
Nachbarmodulen; DE/EN, 320/390 px, Textscale 1.5, ClubUnio-/SV-Farben.
Analyse und i18n-Prüfung bestanden.
Physische Datei-/PDF-Abnahme nach neuem App-Build und Website-Einbindung offen.
HTTP Health 200, geschützte Seite 302, persönliche API 401 und öffentlicher
deaktivierter Feed 404 bei der Bereitstellung auf DEV bestätigt.

## Angemeldete Browserprüfung (25.11.2026)

Chrome, vorhandenes Admin-Konto, SV Sissach auf ClubUnio DEV. Geprüft:

- Menüeinstieg und alle vier Clubhaus-Tabs laden im bestehenden Vereinsdesign.
- Öffnungsdialog: Wochentagsauswahl und zusätzliches Zeitfenster funktionieren.
- Tagesausnahme: Wechsel von Schließtag zu Sonderöffnung blendet Zeitfenster ein.
- Speisekarten: DE/EN-Eingaben, Gültigkeit und Web-/App-Kanäle vorhanden.
  Ein leerer Pflichttitel verhindert das Absenden bereits im Browser.
- Statusfilter plus Suche behalten beide Werte; danach auf leer/Alle zurückgesetzt.
  Mangels vorhandener Menüs keine Filterung gefüllter Bestandslisten abgenommen.
- Zusatzrechte und Einstellungen öffnen getrennte Dialoge. Keine Person ausgewählt
  oder berechtigt, öffentliche Ausgabe blieb auf Nein, Europe/Zurich unverändert.
- Desktop sowie responsive Webansicht bei 390 × 844 und 320 × 740 Pixeln,
  Deutsch/Englisch und Dark Mode geprüft. Kein horizontaler Seitenüberlauf;
  mobile Dialoge lassen sich bis Absenden/Abbrechen scrollen, Aktionen erreichbar.
  Globale Browser-Datums-/Pflichtfeldhinweise folgen der Browser-/OS-Sprache.
- Alle geöffneten Formulare abgebrochen, keine Clubhaus-Datensätze gespeichert,
  hochgeladen oder veröffentlicht. Sprache Deutsch, ursprüngliches Systemthema
  und normale Fenstergröße am Ende wiederhergestellt. Stage/PROD nicht angefasst.

Keine blockierenden Fehler in diesen Ansichten beobachtet. Zwei kleine Befunde:
der automatisch erzeugte Repeater-Text lautet noch „Zu zeitfenster hinzufügen“
statt „Zeitfenster hinzufügen“. Beim Sprach-/Seitenwechsel wurde einmal
`InvalidStateError: Transition was aborted because of invalid state. ViewTransition
opt-in disabled` in Chrome erfasst; die Zielansicht und Sprachumschaltung
funktionierten trotzdem. Ursache dieser allgemeinen Navigationsmeldung nicht
eingegrenzt; nicht als behobener Fehler oder als Fachfehler ausgewiesen.

Prüfgrenze: responsive Browserprüfung ist kein nativer iPhone-/Android-Test.
PDF-Upload, PDF-Vorschau mit echtem Speicher und Veröffentlichung wurden in dieser
Sitzung nicht erneut Ende-zu-Ende ausgeführt; Fachtests bleiben deren bisheriger
Nachweis. Keine Veröffentlichung nur für eine Sichtprüfung aktivieren.
Screenshots lokal unter `storage/app/clubhouse-review-20261125/` (gitignoriert).
Nur Prüfprotokoll geändert, kein neuer Runtime-Release oder Datenbankeingriff.

## Korrekturen und Nachprüfung (25.11.2026)

Die beiden oben protokollierten Befunde sind im neuen DEV-Release behandelt:

- Öffnungs- und Ausnahmedialoge verwenden explizite DE/EN-Beschriftungen
  „Zeitfenster hinzufügen“ / „Add time slot“ statt automatisch erzeugter Texte.
- Der Navigations-Opt-in liegt stabil im Head-Stylesheet. Die React-Initialisierung
  ersetzt keine `@view-transition`-Regel mehr während des Seitenaufbaus. Die
  vorhandene Bewegungseinstellung wird vor dem ersten Frame übernommen;
  reduzierte Bewegung setzt alle Übergangsdauern auf null. Alte gespeicherte
  Einstellungen und der Fallback bei blockiertem localStorage bleiben erhalten.
  Keine globale Fehlerunterdrückung. Grundlage: [Chrome Cross-document View
  Transitions](https://developer.chrome.com/docs/web-platform/view-transitions/cross-document).

Angemeldet in Chrome auf DEV geprüft: DE/EN-Öffnungsdialog, zweites Zeitfenster,
Tagesausnahme mit Sonderöffnung, Dashboard-/Clubhaus-Navigation und Sprachwechsel.
„Animationen aus“ bleibt nach einem vollständigen Sprachwechsel wirksam
(`--cu-nav-duration: 0ms`); anschließend ursprüngliche Einstellung wieder aktiviert.
Kein dynamisches Opt-in-Element vorhanden, keine neuen Konsolenfehler im
Nachprüfungszeitraum. Die zuvor einmalige Chrome-Meldung trat dabei nicht erneut
auf. Keine Formulare gespeichert; Deutsch wiederhergestellt, Rechte und
Veröffentlichung unverändert. Screenshot unter
`storage/app/clubhouse-fixes-20261125/zeitfenster-korrigiert.png` (gitignoriert).

22 gezielte PHP-Tests / 125 Assertions, acht JS-Tests und DE/EN-Katalogprüfung
(3075 Schlüssel, kein Rückstand) bestanden. DEV: 17 synthetische PostgreSQL-
Prüfungen vollständig zurückgerollt. Native PDF-/Geräteabnahme bleibt offen.

Zehn Runtime-Dateien per SHA-256 geprüft, unveränderlicher Release über
`release-clubhouse-ui.sh`; View-Cache erneuert und FPM graceful reload.
Keine neue Schema-/Datenmigration, keine Änderung privater Konfiguration,
Composer-Lock oder gemeinsamer Ablage. Direkter Rückweg durch Aktivieren von
`20261124-clubunio-clubhouse-v2`, ohne Datenrücksetzung. Stage/PROD unverändert.

## Stage-/PROD-Promotion (25.11.2026)

Auf ausdrücklichen Auftrag nach der DEV-Abnahme übernommen. Clubhaus-Quellstand
`46404d35`, einschließlich korrigierter Zeitfenster und stabiler Navigation.
Je Instanz 43 gezielte Dateien; kein vollständiger DEV- oder Datenbankimport.
Die aktuellen servereigenen Sicherheits-, Passkey-, Profilbild-, Portal- und
Azure-Leseregeln wurden im Dreifachvergleich erhalten. Die zwei Konflikte bei
Benutzeraktionen und Seitenregistrierung gezielt zusammengeführt, nicht durch
eine alte Datei ersetzt. Instanzbezogene Unterschiede sind als
`clubhouse-stage-runtime.patch` und `clubhouse-prod-runtime.patch` festgehalten.
Diese Patches beschreiben den Abgleich dieses Releases, keine Neuinstallation.

Jeweils vor Liveänderungen vollständiger verschlüsselter PostgreSQL-Dump und
erfolgreiche Wiederherstellung in einer isolierten temporären Datenbank;
Prüfdatenbanken danach entfernt. Sieben additive Clubhaus-Tabellen samt
Migrationsjournal und begrenzten Tabellen-/Sequenzrechten für die jeweilige
Anwendungsrolle. Keine DDL-Rechte für Webprozesse; keine bestehenden
Benutzer-/Profilzuordnungen geändert. Die App-Moduloption Clubhaus wurde ergänzt,
aber keine Zusatzrolle an bestehende Benutzer vergeben. Website-Ausgabe bleibt aus.

Code root-eigen, private Konfiguration, Composer und Laufzeitverzeichnisse
erhalten. Neue öffentliche CSS-Datei braucht zusätzlich Leserecht für
`runcloud-www`; bei Stage nach der HTTP-Prüfung ergänzt und im PROD-Ablauf
berücksichtigt. Route-/View-Cache erneuert, FPM sanft neu geladen; vorher aktiver
PROD-Pushdienst anschließend wieder aktiv. Keine echten Nachrichten ausgelöst.

Je Umgebung bestanden: 17 Fachprüfungen mit synthetischen Datensätzen und Rollback,
acht Renderprüfungen der vier Clubhaus-Tabs in DE/EN, sieben HTTPS-Prüfungen.
Health/Assets 200, Verwaltung ohne Anmeldung 302, persönliche App-API 401,
deaktivierte öffentliche Ausgabe 404. 43 Runtime-Hashes nach Tests erneut geprüft.
Der bestehende Sicherheitscheck bestätigt Runtime-/DB-Trennung und private
Konfiguration; dessen bekannte Offsite-/Wartungspendenzen bleiben unverändert offen.
Lokal 26 PHP-Tests / 158 Assertions, acht JS-Tests und Sprachprüfung erfolgreich.
Keine neue angemeldete Stage-/PROD-Browser- oder native Geräteabnahme behauptet;
DEV-Sichtprüfung oben bleibt der visuelle Nachweis. Kein App-/Store-Build erstellt.

Root-private Sicherungen, Vorherdateien, Konfigurationshashes und Logs:
`/root/clubunio-releases/clubhouse-promotion-20261125/{stage,prod}`.
Bereinigte Nachweise: [Stage](evidence/clubhouse-promotion-20261125/stage.json),
[PROD](evidence/clubhouse-promotion-20261125/prod.json).
Rückweg je Instanz: nur gesicherte betroffene Dateien aus `before` zurückspielen,
explizit neue Dateien laut `baseline.json` entfernen, Route-/View-Cache neu bauen
und FPM neu laden. Neue Tabellen, Veröffentlichungen und Audit erhalten; kein
blindes Down oder vollständiger Datenbankrestore über zwischenzeitliche Änderungen.
DEV-Anwendung und vorhandene Vereinsdaten durch diese Promotion nicht verändert.
