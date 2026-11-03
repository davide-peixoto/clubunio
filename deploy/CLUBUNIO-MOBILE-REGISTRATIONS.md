# Persoenliche Anmeldungen und Aufnahme

Stand 03.11.2026: lokal geprueftes geschlossenes Paket, DEV-Freigabe ausstehend.
Kein DEV-/Schema-Release dieses Abschnitts durch den Teilagenten.

Die bestehenden SVS-Masken MemberRegistrationsScreen,
MemberRegistrationAdminDetailScreen, MemberRegistrationEditScreen,
RegistrationReviewScreen und Anhangsvorschau werden weiterverwendet.
Persoenliche Repositories sind je Detailroute gebunden und verwenden keinen
Legacy-Transport oder AppState-Zahlrollen.

MobileRegistrationData verwendet echte RegistrationReviewService-Sichtbarkeit.
Die Liste liefert nur Name, Status, Team und Revision; private Details werden
frisch geladen. Allgemeines Namenslistenrecht erteilt kein Anmeldungsrecht.
Technische Leiter sehen nur die wirklich zugewiesenen Anmeldungen; Datenpflege,
TL-Zuweisung, Mitgliedsauswahl, Upload und Entfernen brauchen ihre jeweiligen
vorhandenen Modulrechte. Ausgewiesene Faehigkeiten ersetzen keine Schreibpruefung.

Intake und Entscheidung sind getrennt: RegistrationDataService speichert die
Antragstellerdaten; RegistrationReviewService uebernimmt Pruefung, Probetraining,
Pool, Ablehnung und Aufnahme mit Pflichtbegruendung und angezeigter Revision.
RegistrationAcceptanceService prueft Vollstaendigkeit, ausstehende Uebertragungen,
echte Mitglieder-/Dokumenttypen, Dubletten und die angezeigte Mitgliederversion.
Elternvorschlaege laufen ausschliesslich ueber RegistrationParentService.
Keine Rollen-/Mitgliedschaftsbehauptung durch den Client und kein direkter
Mitgliederimport. AHV ist im persoenlichen Intake weder lesbar noch editierbar;
der bestehende autorisierte DataService-Vertrag wird nicht erweitert.

Migration 000300 erzeugt nur mobile_registration_requests: UUID je
Mandant/Account, kanonischer Payloadhash, strikter zusammengesetzter
Registrierungs-FK. Erneute gleiche Erfassung liefert dieselbe Anmeldung;
abweichende Eingaben unter demselben Schluessel werden abgelehnt. Der Client
behaelt bei unklarer Antwort den Schluessel und bei bestaetigter Teilanlage die
echte Registrierungs-ID/Revision. Kein automatischer Schreibretry.

Dateien werden an eine gespeicherte Registrierung gebunden, niemals an freie
URLs. Der kleine Stage-Einstieg in RegistrationDocumentService erhaelt die
vorhandene Zweiphasigkeit: geschuetzten Uploadauftrag zuerst persistent
speichern, dann ueber DocumentUploadService/SyncWorkService uebertragen und
ueber den echten RegistrationDocumentService publizieren. Ein Providerfehler
laesst den Auftrag sichtbar ausstehend; die Originaldetailmaske bietet eine
ausdrueckliche Wiederholung. Keine falsche Meldung einer fertigen Uebertragung.
Entfernen ist revisionsgeprueft und vor dem Ersetzen eines Portraits erforderlich.
PDF/JPG/PNG/WebP maximal 20 MiB, Portrait ohne PDF, entsprechend den vorhandenen
Dateiservices. Persoenliche Downloads pruefen feste IDs, frische Rechte,
Sitzung, MIME/Dateikennung, Streamgroesse, Content-Length und SHA256.

Routen unter /api/mobile/v1/clubs/{tenant}/registrations:
GET/POST Basis, GET options, GET {id}, GET {id}/members,
POST {id}/intake, POST {id}/review, POST {id}/files,
GET {id}/files/{file}, POST {id}/files/{file}/remove,
POST {id}/uploads/{upload}/retry. Alle elf Routen sind persoenlich geschuetzt
und zusaetzlich durch native_registrations_enabled defaultfalse geschlossen.
check-mobile-registrations hat getrennte --foundation/--enabled-Stufen.

Lokal bereits acht neue Flutterfaelle fuer reale SDK-Pfade, UUID-/Teilupload-
Vertrag, angezeigte Versionen, Mandantenlookups, sichere Dateistreams,
originale Liste/Detail/Erfassung und Originalreviewmaske erfolgreich. Bestaetigte
Dateien werden nach einem spaeteren Teilfehler anhand von Auftrag/Dateihash
wiedererkannt; erneutes Speichern legt weder neue Anmeldung noch Kopien der
bereits bestaetigten Anhaenge an. Nach ausdruecklichem Entfernen darf dieselbe
Datei als neuer, an den historischen entfernten Beleg gebundener Auftrag wieder
zugewiesen werden; der alte Beleg bleibt unveraendert inaktiv. Bestehende zehn
Registrierungs-/Bereitschaftsfaelle unveraendert gruen. Fuenf neue echte
PostgreSQL-/HTTP-Faelle mit107 Assertions erfolgreich: Intake/UUID/Versionen,
echte Aufnahme und Ablehnung, bestehende Mitgliedsversionen, TL-Scope,
geschuetzter Uploadfehler, Wiederholung, Download, Entfernen und Wiederzuweisen.
Zwei bestehende RegistrationCompletion-Uploadregressionen/13 Assertions bleiben
gruen. Ausschliesslich synthetische zurueckgerollte Daten, TEMP-300/FK-Eltern
und gemockter Blobtransport; kein DDL an gemeinsam genutzten Bestandstabellen.
Keine echte Aufnahme, Nachricht oder Dateiuebertragung durch Tools.
