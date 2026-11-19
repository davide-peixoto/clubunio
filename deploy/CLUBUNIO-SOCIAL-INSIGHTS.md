# Instagram und Facebook: Konten und Auswertung

Social Media → Konten & Auswertung (`/social-media/insights`). Vereinsbezogene
Leserechte `social_media.view`; Kontoverbindung/Trennung mit `update`,
Import mit `sync`. Keine neuen Rechte in bestehenden Profilen. Simulation,
API-only-Konten und unberechtigte Mandanten bleiben gesperrt.

## Umfang und Kennzahlen

- Verbindung ueber Facebook Login mit expliziter Auswahl der freigegebenen
  Testseite. Instagram setzt in dieser Version ein mit der Facebook-Seite
  verbundenes professionelles Instagram-Konto voraus. Der bisherige
  Instagram-Login des separaten Testpublishers bleibt unberuehrt.
- OAuth-State einmalig, zehn Minuten gueltig, an Konto, Mandant,
  Arbeitskontext und Host gebunden. Nur lesende Berechtigungen:
  `pages_show_list`, `pages_read_engagement`, `read_insights`, bei Instagram
  zusaetzlich `instagram_basic`, `instagram_manage_insights`.
- Page-Tokens verschluesselt, nie in HTML oder Livewire-Zustand. Konservatives
  Ablaufdatum aus dem langlebigen Benutzertoken; danach erneut verbinden.
  Trennen entfernt das lokale Token und sperrt ausstehende alte Auftraege
  ueber die Verbindungsrevision. Es widerruft nicht die gesamte Meta-App.
- Import maximal 100 aktuelle Beitraege je Konto aus maximal vier Seiten,
  Veroeffentlichungsdatum innerhalb der letzten 90 Tage. Kein unbegrenzter
  historischer Vollimport. Externe Original-Links ohne Tokenparameter.
- Likes/Reaktionen, Kommentare, Shares, Saves, Views und Beitragsreichweite
  beziehungsweise eindeutige Zuschauer, soweit verfuegbar. Facebook verwendet
  `post_media_view` und `post_total_media_view_unique`, keine abgeloesten
  Impressions-Metriken. Unverfuegbare Werte bleiben NULL, echte Nullwerte 0.
- Filter 7/30/90 Tage, Plattform, Beitragssuche und absteigende Sortierung.
  Summen sind kumulierte Beitragswerte fuer das Veroeffentlichungsfenster,
  keine im Fenster neu hinzugekommenen Interaktionen. Reichweite wird nicht
  kanal-/beitragsuebergreifend dedupliziert; Datenabdeckung wird angezeigt.
- Tages-Snapshots in UTC, letzter Tagesstand, plus Followerstand und Delta
  zum letzten vorhandenen Stand vor Beginn des Zeitfensters. Noch fehlende
  Historie wird nicht erfunden. Keine Namen einzelner reagierender Personen,
  keine Kommentartexte oder Privatnachrichten im aktuellen Paket.

## Testkonfiguration und Freischaltung

Private Konfiguration auf jeder Instanz, keine Wiederverwendung der
Signage- oder Publisher-Tokens:

```
CLUBUNIO_SOCIAL_READ_ENABLED=false
CLUBUNIO_SOCIAL_READ_APP_ID=
CLUBUNIO_SOCIAL_READ_APP_SECRET=
CLUBUNIO_SOCIAL_READ_TEST_TENANT=
CLUBUNIO_SOCIAL_READ_FACEBOOK_IDS=
CLUBUNIO_SOCIAL_READ_INSTAGRAM_IDS=
```

IDs kommasepariert; nur genau freigegebene Konten im angegebenen Mandanten.
Zusaetzlich ausschliesslich Umgebung `staging` mit exakter DEV-/Stage-URL.
PROD wird durch dieses Paket nicht freigeschaltet. Callback-Adressen:

- `https://dev.clubunio.ch/social-connect/callback`
- `https://stage.clubunio.com/social-connect/callback`

In der Meta-Test-App als gueltige OAuth-Redirects aufnehmen, Testbenutzer
und Konten zuordnen und die benoetigten Berechtigungen konfigurieren.
ClubUnio fragt weder Publishing- noch Messaging-Berechtigungen an.
Keine OAuth-Secrets im Chat oder Repository. Fuer fremde Vereinskonten
ist spaeter gegebenenfalls Meta App Review/Advanced Access erforderlich.

## Hintergrundimport und Fehler

`clubunio:social-insights` im vorhandenen Scheduler, nur bei Aktivierung.
Automatisches Anfordern nach sechs Stunden, maximal zwei offene Importe
je Lauf. Import nur mit frischen Rechten des urspruenglichen Benutzers.
Persistierte Auftraege und Ereignisse liegen in der bestehenden
Synchronisationswarteschlange mit eigenem Social-Media-Filter.
Fehler gehen auf `attention`; keine automatische Endlosschleife.
Manuelle Wiederaufnahme nach Klaerung in Synchronisation; ein neuer
Import ist auch am verbundenen Konto anforderbar. Alte Beitraege bleiben
bei Fehlern sichtbar. Keine Netzwerkabfragen beim normalen Seitenaufruf.
Timeout-Budget 120 Sekunden plus maximal eine laufende HTTP-Anfrage;
Paging-URLs werden niemals direkt aufgerufen. Tokenfehler 401,
Limits 429, Dienstfehler und nicht unterstuetzte Metriken sind getrennt.
Keine Providerantworten, Tokens oder personenbezogenen Kommentare im Log.

## Migration und Rueckweg

Additiv `2026_11_19_020000_social_insights`: vier Tabellen
`social_connections`, `social_imported_posts`, `social_metric_snapshots`,
`social_account_snapshots`, mit Mandanten-Fremdschluesseln und Indizes.
Keine Uebernahme/Aenderung von Bestandsbeitraegen oder Mitgliederdaten.
Vorher je DEV/Stage ein privates PostgreSQL-Custom-Backup erstellen und
`pg_restore --list` pruefen. SQL mit
`php deploy/emit-social-insights-migration.php clubunio_dev` bzw.
`clubunio_stage` erzeugen und auf dem VPS als PostgreSQL-Eigentuemer
ausfuehren. Datenbank-/Identitaetspruefung, Transaktion und Ledger sind
enthalten. Keine pauschale Ausfuehrung anderer Migrationen.

DEV-Release als Delta auf die gerade aktive Pi-Basis; Schema-Mapping,
DB-Pflichtcheck, Config-/Route-/View-Caches vor dem atomaren Wechsel.
Stage gezielt ueber Branch stage. PROD unveraendert.
Rueckweg: vorigen Code aktivieren, Import deaktivieren, neue Tabellen und
Snapshots erhalten. Keine automatische Down-Migration oder Datenloeschung.

## Abnahme

Lokale HTTP-Tests ohne externe Anfragen sowie
`deploy/check-social-insights.php` auf DEV/Stage. Letzterer legt nur
synthetische Konten/Mandanten an, simuliert beide Plattformen und rollt
alles zurueck. Prueft Verschluesselung, Mandantengrenzen, Konto-Whitelist,
Import, Tages-Snapshots, Wiederholung, Fehlerprotokoll, Erhalt gecachter
Beitraege und Trennung. Keine Publikation und kein echter Versand.

Echter OAuth-/Meta-Liveabruf bleibt offen: Der Benutzer hat bestaetigt,
dass Meta-Test-App, Instagram-Testkonto und Facebook-Testseite noch
erstellt werden muessen. Simulierte Tests ersetzen diesen Nachweis nicht.

## Releaseabnahme 19.11.2026

DEV und Stage: vier Tabellen nach privater Custom-Sicherung migriert;
Backups `/root/clubunio-backups/clubunio_dev-before-social-insights-20261119.dump`
und entsprechend `clubunio_stage-before-social-insights-20261119.dump`,
beide mit `pg_restore --list` geprueft. Keine PROD- oder Snapshot-Aenderung.
DEV-Release `20261119-clubunio-social-insights-v3`; unmittelbarer Rueckweg
`20261119-clubunio-social-insights-v2`, vor dem Paket `20261119-clubunio-campaign-visual`.
Stage gezielt auf Branch stage. Bestehende parallele Mailtheme-Arbeiten erhalten.

18 lokale Tests / 83 Assertions, DE-/EN-Katalogpruefung erfolgreich.
Synthetischer Datenbank-Fachtest auf beiden Instanzen erfolgreich,
ohne PHPUnit-Laufzeitabhaengigkeit, ohne echte Meta-Anfragen.
Bei der Pruefung fehlte zunaechst die registrierte Aktion social_media.sync;
sie ist nun separat im Berechtigungskatalog verfuegbar. Keine Profil-Grants
nachgezogen; Vereinsadmins folgen der bestehenden obersten Rollenlogik.
Der Trennvorgang nutzt explizit die ClubUnio-Verbindung statt der nicht
vorhandenen Default-/Legacy-Datenbank. Token-/Fehlerpruefung und gecachte
Beitraege nach Fehler sowie Ablehnung deaktivierter Konten abgedeckt.

Angemeldete Dashboard-Leeransicht auf DEV/Stage ohne Fehler geladen.
DEV zusaetzlich bei 390 Pixel Breite geprueft: keine Seitenverbreiterung,
lesbare horizontal scrollbare Tabellen und kompakte fehlende KPI-Werte.
Dashboardaggregation, Plattform-/Textfilter und KPI-Sortierung mit
synthetischen importierten Beitraegen auch im Server-Fachtest geprueft.
Meta-Verbindungsschaltflaechen bleiben deaktiviert, solange die private
Testkonfiguration fehlt. Echte OAuth-Abnahme weiterhin offen wie oben.

## Quellen

Primaerquellen, geprueft am 19.11.2026:
[Meta Page Insights](https://developers.facebook.com/docs/graph-api/reference/insights/),
[Instagram Insights](https://developers.facebook.com/docs/graph-api/reference/shadow-ig-user/insights/),
[Meta Login](https://developers.facebook.com/docs/facebook-login/guides/advanced/manual-flow/).
