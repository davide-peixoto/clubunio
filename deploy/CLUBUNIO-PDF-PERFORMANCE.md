# ClubUnio: Bexio-PDFs und Dokumentvorschauen

16.10.2026. Nur ClubUnio, bestehendes PHPMaker DEV/PROD unveraendert.
Keine neue API, SaaS- oder Betriebskonfiguration; keine Buchung/Bankzahlung.

## Befund und Umsetzung

Bexio-PDFs luden lokal Mitglied, Saison, Workflow und Positionen, obwohl
der externe Abruf nur die berechtigte Rechnung benoetigt. Diese Relationen
werden jetzt nur fuer lokale PDF-Erzeugung geladen. InvoiceAccessService,
aktive Rechnung und Vereins-/Benutzerfilter unveraendert. Bexio-Aktivierung
steuert weiterhin nicht die Berechtigung zum PDF-Lesen.

Vorschau bisher: Livewire-Dialog -> Viewer-HTML im iframe -> geschuetztes PDF.
Jetzt verwendet der Dialog denselben PDF.js-Viewer direkt; ein HTML-Request
samt seinen Berechtigungs-/DB-Abfragen entfaellt. Historische Viewer-URLs
bleiben unveraendert geschuetzt und verwenden dieselbe Teilansicht.
CSS ist vollstaendig auf den Viewer beschraenkt, keine globalen nav-/Formstile.
Aktuelle Assetversion v2 verhindert alte Viewer-Module im Browsercache.

Viewer arbeitet je DOM-Instanz, ohne globale Control-IDs. Paging/Zoom nutzen
das aktuell geoeffnete Dokument ohne neue PDF-Anfrage. Range-Fetches sind
deaktiviert: der Dokumentendpunkt bleibt ein kompletter autorisierter Read,
kein mehrfach ausgeloester Providerproxy. Renderflaeche bleibt auf12MP begrenzt.
Beim Schliessen, Entfernen oder Dokumentwechsel werden Ladevorgang, Worker,
Renderauftrag, Listener und ResizeObserver beendet bzw. Canvas freigegeben.
Beim erneuten Oeffnen startet ein frischer Dokumentabruf. Livewire ignoriert
den aktiven Canvas; unterschiedliche Dokument-URLs erhalten eigene Rootkeys.

Downloads bleiben eigene frische GETs ueber den geschuetzten Dokumentendpunkt,
keine Blob-URL, kein LocalStorage, kein Cache API, kein Server-/Session-PDFcache.
Dadurch gibt es keine veraltete PDF-Kopie und keinen Download nach Rollenentzug
aus einem wiederverwendeten Dokumentcache. private/no-store bleibt bestehen.
Der offene Viewer enthaelt wie bisher bereits angezeigte Bytes bis zum Schliessen.

OIDC-Refresh mit persistierter Tokenrotation, Sandbox-Organisationspruefung,
Rechnungs-/Kontaktidentitaet und zugeordnete Verbindung bleiben unveraendert.
Auch bei erneutem Oeffnen/Download werden diese Pruefungen nicht gecacht.
Max20MB, striktes Base64/PDF-Magic, begrenzte Timeouts, keine Redirects und
sanitisierte Fehler mit Korrelationsreferenz bleiben erhalten. Kein Abschalten
von Sicherheitspruefungen fuer eine bessere Performancezahl.

Azure-Reader und bedarfsgesteuerte Anmeldungsanhaenge waren bereits begrenzt
und geprueft; keine neue lokale Fallbacksuche oder Speicherverdopplung eingefuehrt.

## Messung

tools/clubunio-pdf-performance-check.php: CLI, nur vorhandene ClubUnio-Sandbox-
Fixture, eigene DB-Pruefung, aktiver Admin. Ausgabe nur Status/Dauer/Queryzahl/
Speicher/PDF-Bytes und aggregierte Provider-Kategorien; keine IDs, Tokens,
PDF-Dateien, Responsebodies oder SQL-Bindings. Kein aeusserer Rollback, weil
eine normale OIDC-Tokenrotation dauerhaft gespeichert werden muss. Keine
Remote-Schreibaufrufe ausser normalem OAuth-Tokenrefresh und keine neue Fixture.

Pi vor Delta: Bexio-PDF mit Tokenrefresh2266ms/13 Queries; warm2134ms/11 Queries,
je105930 PDF-Bytes. Warm je ein Organisations-, Identitaets- und PDF-Read,
zusaetzlicher Tokenrefresh nur im ersten Lauf. DB-Anteil warm277ms; der grosse
Rest ist extern. Lokales PDF430ms/7 Queries,22493 Bytes. Stichproben ohne
Browser/Proxy und ohne Lasttest; keine SLA oder Provider-Laufzeitgarantie.

Nach Delta auf Pi: Bexio HTTP200/1698ms/8 Queries/212ms DB/46.5MiB,
105930 PDF-Bytes. Alle drei Provider-Reads weiterhin vorhanden. Lokales PDF
HTTP200/391ms/7 Queries/182ms DB/60.5MiB,22493 Bytes. Queryreduktion Bexio
11 -> 8; Dauer ist eine Stichprobe und unterliegt externer Schwankung.

## Verifikation und Release

Release 20261016-clubunio-pdf-performance auf dev.clubunio.ch aktiviert.
36 gezielte Featuretests/479 Assertions erfolgreich, zusaetzlich bestehender
PDF-Zugriffstest 1/19 und Sandbox-Rechnungsprozess 1/24. Frontend-Lifecycletest
erfolgreich: ein Read je Oeffnung, keine Reads bei Zoom/Paging, Cleanup bei
Verstecken/Entfernen/URLwechsel, getrennte Viewer, Cross-Origin abgelehnt.
Pint und git diff --check erfolgreich; Release-/Routen-/Tenantchecks erfolgreich.

Angemeldeter Chrome-Test auf ClubUnio mit vorhandener Sandbox-Rechnung8440:
PDF sichtbar rechts, zwei Seiten, Seitenwechsel und Zoom75% erfolgreich,
kein iframe. Schliessen entfernt den Viewer; erneutes Oeffnen zeigt Seite1
korrekt. Geschuetzter separater Downloadlink vorhanden; kein Konsolenfehler.
Download-Neuautorisierung und private Header automatisiert geprueft.
AzureDocumentReaderTest im Featuretestlauf enthalten; Azure-Logik unveraendert.

## Migration und Ruecknahme

Keine DB-/Indexmigration, keine neuen Parameter oder Datenimporte. Bestehende
Rechnungs-/Mitglieder-/Bexio-Verknuepfungen und Verbindungshashes beim spaeteren
Import erhalten. Neue Blade-Teilansicht und statische Viewer-Assets gemeinsam
ausrollen, Viewcache erneuern. Vorherigen Release per Symlink/FPM reload
wiederherstellen; kein Schema-Rollback. Bereits erfolgte Tokenrotation nicht
zuruecksetzen. Keine Uploads/PDFs/Secrets in Git oder Releasearchiv.
