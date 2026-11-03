# Persoenliche Dokumente und gezeichnete Empfangsquittungen

Stand03.11.2026, Umsetzung in Arbeit, standardmaessig geschlossen.
Keine permanente Anwendung von Migration89, kein DEV-Release oder native
Gesamtabnahme dieses Abschnitts behauptet. Root koordiniert Sicherung,
Schema-/Abhaengigkeitsauslieferung und gezielte Freischaltung.

## Bestehender Ablauf

Die originalen MemberDocumentsScreen/MemberDocumentScreen, Dokumentzuweisung
mit Datei/Fotos/Kamera und DocumentSignatureScreen bleiben erhalten. Die
Signatur ist die bestehende gezeichnete elektronische Empfangsbestaetigung;
keine Zertifikats-/qualifizierte Signaturbehauptung. Der persoenliche Adapter
uebernimmt keine Legacy-HTTP-Pfade oder Zahlrollen.

Das hochgeladene PDF bleibt bytegenau als Original erhalten. JPG/PNG werden
wie im SVS-Ablauf reproduzierbar zu PDF konvertiert. Nach malware-clean wird
das Original privat verschluesselt und mit SHA256/Seiten-/Bytezaehlern
gespeichert. Die gezeichneten normalisierten Linien werden auf einer neuen
Evidenzseite nach allen Originalseiten gerendert, einschliesslich persoenlichem
Zugang, wirksamer Selbst-/Elternvertretung, Mitglied, UTC-Zeit und Originalhash.
Original und unterschriebene Fassung werden getrennt aufbewahrt.

Migration89 erstellt mobile_member_receipts mit Mandanten-/Mitglieds-/
Dokumenttyp-/Account-Fremdschluesseln, optionalem Originaldokumentbezug,
verschluesselten PDF-/Liniennachweisen, Revisionen und Datenbankpruefungen fuer
vollstaendige Signaturzustaende. Keine Datenimporte, Tabellenrefreshs oder
Bestandssignaturen. Der vorhandene LegacyMaterialReceiptService bleibt fuer
bereits migrierte historische Original-/Signiertpaare unveraendert lesend;
Archivdaten werden nicht als neue Unterschrift oder Checkbox umgedeutet.
Ein additiver UNIQUE(tenant_id,id)-Index auf legacy_material_receipts erlaubt
den strikten zusammengesetzten Fremdschluessel des neuen Nachweises.

Offene bestehende Archivauftraege koennen im selben Originalscreen gezeichnet
werden. Erst der ausdrueckliche Signieraufruf erzeugt einen neuen privaten
Nachweis mit Archiv-ID, unveraenderlicher SourceRevision und Originalhash;
GET erzeugt nichts. Die bestehende Archivzeile bleibt vollstaendig unveraendert.
Ein UNIQUE(tenant_id,archive_receipt_id) verhindert doppelte Nachweise bei
konkurrierender Selbst-/Elternunterzeichnung. Anzeige und Download bleiben
unter der urspruenglichen Archivquelle; die Liste zeigt keinen Doppelbeleg.
Bereits signierte historische Paare bleiben ausschliesslich lesbar.

Zuweisen verlangt documents.create+upload; Kader-/Trainerkontakte reichen
nicht. Unterschreiben verlangt eine frisch wirksame Selbst-/Elternvertretung,
auch ein Admin darf nicht stellvertretend beliebig unterschreiben. Mandant und
persoenliche Sitzung werden unter Sperre erneut geprueft. Angezeigte Revision
und Originalhash sind verbindlich; gleiche Wiederholung nach unklarer Antwort
liefert denselben Nachweis, abweichende Wiederholung wird abgelehnt. Kein
automatischer Write-Retry und keine vertraulichen Inhalte im Auditlog.

## Grenzen und Quellen

Upload maximal10 MiB; PDF maximal50 Originalseiten; JPG/PNG maximal20 Megapixel.
Signatur maximal100 Linien/4000 Punkte und begrenzte JSON-Anfrage. Formulare,
Anmerkungen, aktive Aktionen, verschluesselte oder nicht importierbare PDFs
werden abgelehnt; keine stillschweigende Flatten-/Inhaltsaenderung. Signed-PDF
maximal20 MiB. Verschluesselter Receipt-Speicher begrenzt auf1000 Nachweise und
512 MiB Klartextumfang pro Mandant; historische Belege werden nicht geloescht.

Gezielt neu installiert: setasign/fpdi2.6.9 und setasign/fpdf1.9.0; keine breiten
Updates. Offizielle [Setasign-Installation](https://manuals.setasign.com/fpdi-manual/v2/installation/)
und [Projektmetadaten](https://github.com/setasign/fpdi) geprueft.
Zusaetzlich symfony/mime7.4.19 auf7.4.22 gezielt gepatcht, gemaess
[primaerer Sicherheitsmeldung](https://symfony.com/cve-2026-45070). Composer audit
danach ohne Advisories/aufgegebene Pakete; keine Audit-Ausnahmen oder Scripts.

## Vertrag und lokale Nachweise

Alle Routen unter /api/mobile/v1/clubs/{tenant}/member-documents:
GET/POST Basis, GET types/members, GET {receipt}, GET {receipt}/pdf,
POST {receipt}/sign; GET files/{document}[/pdf] und archive/{archive}[/pdf],
POST archive/{archive}/sign. Archivsignieren verlangt zusaetzlich die angezeigte
SourceRevision (64 Hexzeichen), nicht eine neu nachgeladene Ersatzversion.
SourceKind ist strikt receipt/file/archive, nie eine freie URL. PDFs erhalten
X-Content-SHA256 und Content-Length; der Client prueft MIME, Signatur,
Streamgroesse, Hash und unveraenderten Accountkontext. Dateizugriffe werden
frisch autorisiert; signierte historische Archive und generische Anhaenge
bleiben lesend. Geaenderte Archivquelle, Originalbytes, Vertretungsrechte oder
abweichende Wiederholungen schliessen die Signatur sicher.

native_documents_enabled bleibt defaultfalse. Dossierlink zusaetzlich nur mit
Clientmarker member-documents-v1 und echten Serverrechten. Der Marker erweitert
keine Zugriffsrechte. Servergate check-mobile-documents hat getrennte Stufen
--foundation/--enabled, prueft zwoelf geschuetzte Routen und aktiviertes Schema/
Runtime. Keine echten Dokumente werden dabei geoeffnet oder unterschrieben.

Pure PDF-/MIME-Tests5/30 Assertions erfolgreich. Synthetisches zweitseitiges
Portrait-/Landscape-Original plus Evidenzseite gerendert; beide Originalseiten
pixelidentisch, Evidenzseite visuell geprueft. Acht persoenliche PostgreSQL-
Fachtests auf temporaerer89-Tabelle und temporaeren FK-Eltern mit209 Assertions
erfolgreich; zusammen mit PDF/MIME13 Tests/239 Assertions. Die unveraenderte
Dossiersuite bleibt4 Tests/92 Assertions gruen (Gesamtlauf17/331).
Sie pruefen auch Archivfortfuehrung, SourceRevision/Hash, Wiederholungen,
konkurrierende Selbst-/Elternversuche, Widerruf, eindeutige Archivnachweise
und den mandantenuebergreifenden Archiv-Fremdschluessel. Acht neue Flutterfaelle und zehn
bestehende Dokumentenfaelle bestanden: Originalscreen/Uploadauswahl, typisierte
Quellen trotz gleicher IDs, Multipartschluessel, angezeigte Revisionen, echte
Hashpruefung, unsichere Antworten und Stream-/Accountwechsel; der neue
Archivfall zeichnet im echten bestehenden SignatureScreen. Analyse ohne
Befund. Kein DDL auf gemeinsam
genutzten Bestandstabellen im Test, keine echten Provider oder Signaturen.

Noch offen: DEV-Auslieferung einschliesslich89 und
Abhaengigkeiten, echte Android-/iPhone-Abnahme und Benachrichtigungszustellung.
Offene historische Unterschriftsanforderungen verwenden den oben beschriebenen
expliziten Fortfuehrungsvertrag; keine implizite Neuerteilung durch Lesen.

DEV-Freigabe04.11.2026:20261103-clubunio-native-followups-v4,
persoenliche Originalvertraege aktiviert. Synthetische reale Kernelablaeufe
mit Rollback erfolgreich; externe Provider-/Dateigroessen-/Geraeteabnahme
weiter offen. Gemeinsamer Auslieferungsnachweis in PI.md.
