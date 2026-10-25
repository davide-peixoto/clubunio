# ClubUnio Dokumentenserien

Stand 25.10.2026: eigenstaendige ClubUnio-Webimplementierung. Legacy ausschliesslich
lesend als Fachreferenz verwendet. Keine API, SaaS, Betriebsumstellung, Mail-/Push-
Zustellung oder Bankzahlung. Keine Bestandsdateien, Excel-Formeln oder Datenimporte.
Migration/Deployment/Commit erfolgen als gemeinsamer ClubUnio-Release.
Schema73 am25.10.2026 nach Parent-Review angewendet; Datei seit Freigabe eingefroren.

## Schema und Integration

Additive Migration: `database/clubunio/2026_10_25_000073_document_series.php`.
Guard: nur `db_acdf8d_dclubora`, Schema `clubora`. Drei Tabellen:
`document_series_templates`, `document_series`, `document_series_recipients`.
Mandantenschluessel und Fremdschluessel fuer Mitglied, Vorlage, Original/Rueckgabe
und Materialquittung; pro Serie/Mitglied genau ein Empfaenger. Definitionen und
Serien unveraenderlich; DB-Trigger schuetzen Empfaengersnapshots, publizierte
Originalverknuepfungen und Rueckgabeprovenienz. Empfaenger-/Seriensnapshots sind
verschluesselt. Kein Seed und keine Rechteerweiterung. Migration nach Freigabe
nicht nachtraeglich veraendern. `down()` verweigert verlustbehaftetes Entfernen.

Parent registriert `App\ClubUnio\Pages\DocumentSeries` im vorhandenen Panel und
ordnet die Navigation nach bestehenden Dokumentkonventionen ein. Slug:
`document-series`, View: `clubunio.document-series.page`.
`canAccess()` nutzt `documents.view`, jede Operation prueft serverseitig erneut.
Neue Models brauchen keine automatische Policy-/Rechtekatalog-Zuordnung:
alle Seitenmutationen und Downloads gehen durch den expliziten Dokumentservice.

Private GET-Routen (numerische Parameter, bestehende Web-/Sessionmiddleware):

| Route | Name | Serviceaufruf |
| --- | --- | --- |
| `/document-series/{tenant:slug}/{series}/{recipient}/original` | `clubunio.document-series.original` | `download($request->user('clubunio'), $tenant, $series, $recipient)` |
| `/document-series/{tenant:slug}/{series}/{recipient}/signed` | `clubunio.document-series.signed` | gleicher Aufruf mit `signed: true` |

Beide durch Parent integriert mit `auth:clubunio`; UI uebergibt
`[tenantSlug, seriesId, recipientId]`. Panel und Navigation ebenfalls integriert.
Service: `App\ClubUnio\Services\DocumentSeriesService`.
Portal optional gleiche private Dateien unter
`/portal/{tenant:slug}/document-series/{series}/recipients/{recipient}/original`
bzw. `/signed`, mit `auth:portal`, Portalaccount und `portal: true`. Dabei wird
frisch auf vertretenes Mitglied, Portalzugang und Freigabe eingeschraenkt.
Keine Blob-URLs, keine oeffentlichen Dateien; Antwort `private, no-store`,
Dateihashpruefung, MIME-/Sicherheitsheader ueber `MemberDocumentService`.
Original/Rueckgabe liegen auch im bestehenden privaten Mitgliederdossier.

Kein neuer POST-Endpunkt erforderlich: gepruefte Rueckgabe per Livewire mit CSRF.
Optionaler HTTP-Adapter muss explizit validierte Werte uebergeben:
`signedReturn(Account, Tenant, int $series, int $recipient, UploadedFile $file,
bool $checked, string $originalHash)`; Uploadfeld PDF max.10 MB, gepruefte
Zuordnung bestaetigt, Originalhash aus geschuetzter Detailansicht.
`finishSignedReturn(Account, Tenant, int $series, int $recipient)` setzt die
bereits dauerhaft gestagte Rueckgabe ohne neue Datei fort.

## Rechte

Admin/Geschaeftsstelle nach vorhandener ClubUnio-Logik oder ausdruecklich
berechtigte aktive Funktionsprofile; Trainer/Mitglied erhalten keine pauschalen
Serienrechte. Alle Funktionen erfordern zusaetzlich `documents.view`.

| Aktion | Bestehende Funktionen |
| --- | --- |
| Lookups/Vorschau/Status | view |
| Neue Vorlage / vier Standards einmalig uebernehmen | create |
| Neue Version einer Vorlage | update |
| Serie verbindlich anlegen / naechstes Dokument verarbeiten | create + upload + send |
| Privates Original/Rueckgabe oeffnen | download |
| Gepruefte PDF-Rueckgabe / Fortsetzen | upload + update |

Portaloriginale unterliegen den bestehenden Vertretungsregeln einschliesslich
Elternfreigabe, Volljaehrigkeit und Widerruf; keine administrativen Serienrechte
werden dadurch vergeben. Bestehende digitale Materialbestaetigung bleibt beim
`MaterialReceiptPortalController` mit dessen frischen Mitglieds-/Elternpruefungen.

## Fachablauf

Vier bereinigte Legacy-Standards: Spieler H1/F1 (sechs Artikel), Trainer (sieben
Artikel), Schluessel, Materialabgabe Mannschaft. Uebernommen sind ausschliesslich
Texte, Artikelnummern und Integer-Rappenpreise, keine Personendaten/Unterschriften.
Vorlagentypen `MATERIALQUITTUNG`/`SCHLUESSELQUITTUNG` muessen bereits aktiv vorhanden
sein; fehlende Typen werden nicht still angelegt. Benutzer kann stattdessen
eigene Vorlagen mit einem vorhandenen Dokumenttyp erstellen. Am25.10.2026 beide
erforderlichen Typcodes bei `sv-sissach` lesend als aktiv bestaetigt. Fehlende
Typen in anderen Mandanten ergeben eine erklaerende Validierungsmeldung statt404;
keine automatische Anlage oder neue Katalogberechtigung.

Vorlagen besitzen Familienkennung und fortlaufende Version; jede Bearbeitung
ist ein neuer Datensatz. Kein beliebiges HTML/PHP oder dynamischer Vorlagencode:
Plaintext und strukturierte Artikel/Menge/Preis/Groesse/Initialen/Marke/Referenz.
Markup wird escaped; unbekannte, fehlerhafte oder fehlende Platzhalter werden
abgelehnt. Platzhalter gelten auch in Artikeltexten. PDF: lokale Schrift,
Dompdf ohne Remotezugriff, PHP oder Javascript, A4 und umbrechende Bedingungen.

1. Vorlage/version waehlen, Saison/Mannschaften filtern und markieren.
2. Spieler, Staff oder beide; maximal30 Teams/500 Personen/40 Positionen.
3. Mitglied einmal pro Serie; Teambezeichnungen werden deterministisch vereint.
   Individuelle Schluesselnummer/Grundnummer/Verantwortlicher duerfen keine
   Identitaet oder Mannschaftszugehoerigkeit ueberschreiben.
4. Vorschau, Empfaenger ggf. abwaehlen und fehlende Angaben pro Mitglied ergaenzen.
   Aenderungen entwerten die UI-Vorschau; verbindliche Anlage berechnet sie erneut.
5. Explizite Bestaetigung; kanonischer frischer Hash und stabiler Request-Key.
   Identische Wiederholung liefert die bestehende Serie, abweichende Verwendung
   desselben Keys409. Spaetere Stammdaten aendern die freigegebenen Snapshots nicht.
6. Je Aufruf hoechstens ein Original/eine Quittung. Nach Abbruch dieselbe Serie
   fortsetzen. Kein neuer Cronjob oder automatischer Serienlauf.

## Dauerhafte Verarbeitung

Claim/Stage erfolgt unter Mandantenlock. Ein zeitlich begrenztes Lease verhindert
gleichzeitige Serienverarbeitung; nach Prozessabbruch ist Fortsetzen moeglich.
`DocumentUploadService::stage()` speichert verschluesselte Bytes und festen
Zielpfad dauerhaft. Empfaenger behaelt Upload-ID/Originalhash vor dem Providercall.
Danach ausserhalb der Verarbeitungstransaktion
`SyncWorkService::runDocument()` -> vorhandener `DocumentUploadService::process()`.
Dessen eigener Synchronisationslease und Wiederanlaufpruefung bleiben erhalten.
Erst nach erfolgreicher Publikation wird unter Lock das Original verknuepft und
bei Portal-Freigabe ueber `MaterialReceiptService` eine Quittung erstellt/ausgestellt.
Die Standardquittung traegt die eingefrorene Identitaet und vollstaendigen
Bedingungen; Originaldefinition/-Dokument-ID bleiben im Quittungssnapshot.

Ohne Portal-Freigabe entsteht bewusst nur das private Dossierdokument, keine
ausgestellte Materialquittung: bestehende ausgestellte Quittungen sind immer im
Portal sichtbar. Damit kann die private Freigabe nicht durch einen zweiten
Portal-Datensatz umgangen werden. Fortschritt zaehlt publizierte Originale.

Scheitert Azure oder Quittungsanlage, bleibt derselbe Upload erhalten; ein Retry
publiziert keinen neuen Anhang und erstellt keine zweite Quittung. Bereits
publizierte Uploads werden wiederverwendet. Keine Netzwerkarbeit innerhalb der
Serien-/Quittungstransaktionen. Aufrufer duerfen keine aeussere Runtime-Transaktion
um die komplette Verarbeitung legen; nur Tests behalten den aeusseren Rollback
bei vollstaendig simuliertem Provider. Der bestehende Azure-Mandanten-Slug und
Storage-Fingerprint bleiben unveraendert, insbesondere kein Verbreitern der
`sv-sissach`-Freigabe. Offene Uploads behalten ihren tatsaechlichen Ersteller:
Fortsetzen nur durch diesen oder durch bestehende Upload-Adminberechtigung;
kein Identitaetswechsel, keine Wiederherstellung entzogener Quellrechte.

## Zwei Verschiedene Nachweisarten

Digitale Empfangsbestaetigung: unveraenderter bestehender Materialquittungsablauf,
Name, Konto, Zeit, self/parent und bestaetigter Text. Das ist keine handschriftliche
Unterschrift und erzeugt keine behauptete unterschriebene PDF-Datei.

Gepruefte PDF-Rueckgabe: separate private Datei, Originalhash muss stimmen,
identisches Original abgelehnt, bestaetigte manuelle Sichtpruefung erforderlich.
Der Upload zeichnet das pruefende/hochladende Konto und Zeit auf, nicht einen
behaupteten Unterzeichner. Identischer Hash ist idempotent, andere Zweitrueckgabe
wird mit409 abgelehnt. Fehlgeschlagene Uebertragung ist separat fortsetzbar.
Original bleibt unveraendert. Digitale Annahme und gescannte Rueckgabe koennen
getrennt koexistieren; die Materialquittung wird nicht als Upload-Account signiert.
Keine automatische Unterschrifterkennung, keine Zertifikatspruefung und kein
neuer Zeichen-/Elternsignaturablauf. Mail/Push werden nicht aufgerufen;
vorhandene Dossierpublikation darf bestehende interne Benachrichtigungen anlegen.

## Pruefung und Restabnahme

`tests/Feature/DocumentSeriesRendererTest.php`: sieben Tests/33 Assertions
(Definitionen, Preise, Escaping, Platzhalter, echte Dompdf-Ausgabe, Bladecompiler
einschliesslich PHP-Parsing). Alle vier Standards mit synthetischen Daten als
je eine A4-Seite gerendert und visuell geprueft, ohne Ueberlappung/Abschneiden.
`tests/Feature/DocumentSeriesTest.php`: transaktionale synthetische Fixtures,
keine Migration im Test, keine RefreshDatabase-Nutzung. Provider-/Stage-Doubles,
keine echten Blobs/Mitgliederdokumente ausser zurueckgerollten Testmetadaten.
Prueft unter anderem frischen Hash, Request-Replay/-Konflikt, Versionierung,
Dedup/Staff/Spieler, Lease, Storageausfall/Retry, Immutable-Trigger, getrennte
Funktionsrechte, Portal-Fremdeltern/Widerruf, digitale Bestaetigung und
Rueckgabeprovenienz sowie Livewire-Preview/Anlage/Editor.

Lokaler Lauf:
```powershell
$env:CLUBUNIO_TEST_DB = '1'
php -d extension=pdo_pgsql vendor/phpunit/phpunit/phpunit --filter=DocumentSeries
```

Erster DB-Aufruf ohne `pdo_pgsql` scheiterte vor Fixtures; mit Erweiterung wurden
Tests korrekt uebersprungen, bis der Parent Schema73 angewendet hatte.
Erster vollstaendiger DB-Lauf fand Lease-Zeitzonen- und Bladefehler, anschliessend
korrigiert (Model-Zeitformat mit explizitem Offset, Platzhalteranzeige ohne
Blade-Delimiterkonflikt, gefilterte Empfaenger ohne Livewire-Propertykollision).
Gezielte Lease-/Livewire-Nachpruefung bestanden. Abschliessender Gesamtlauf nach
deutscher UI-Sprach-/Badgepflege:18 Tests/146 Assertions bestanden, keine Fehler
oder uebersprungenen Tests. Sichtbare Texte mit
Umlauten, technische Platzhalter unveraendert; Statusbadges verwenden die
vorhandene `StatusAppearance`-Palette. Alle vier PDFs danach erneut gerendert und
visuell geprueft, weiterhin je eine A4-Seite. Keine Aussage ueber bereits bestandene integrierte
Browser-/Azure-End-to-End-Abnahme. Angemeldete Desktop-/Mobilpruefung und
Deployment sind gesondert zu bestaetigen. Keine echten Dateien/Mails/Pushs
zur Abnahme ausstellen.

Integrierter Release20261025-clubunio-sponsor-documents auf Pi aktiv. Panel,
Navigation, private Routen und Server-/HTTP-Pruefungen erfolgreich. Angemeldete
Browserpruefung am Login gestoppt; bleibt ebenso wie ein neuer echter Azure-
Durchlauf offen. Kein Bestandsimport oder PHPMaker-Deployment.

Nachtrag25.10.2026: neue echte Azure-Serie41/Dokument388 erfolgreich ueber
private Ablage und geschuetzten Webabruf geprueft, auch direkt auf Pi.
Keine Duplikate, anonymer Azure-Zugriff abgewiesen, Originalbestand unveraendert.
Details: CLUBUNIO-SPONSOR-DOCUMENT-ACCEPTANCE.md. Manuelle Browser-/Mobilpruefung
anschliessend abgeschlossen (25.10.2026), siehe denselben Abnahmenachweis.
Checkboxbreite und mobile Tabellen/Statusbeschriftungen dort korrigiert und
auf ClubUnio deployed; kein echter unterschriebener Ruecklauf behauptet.
