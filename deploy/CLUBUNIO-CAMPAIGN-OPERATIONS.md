# Kampagnen: Versandcockpit und Ruecklauf

## Umfang

Im Kampagnenentwurf stehen Versandvorschau, Empfaengerpruefung, Vorlagen
und die Typen Stammdaten, Empfangsbestaetigung, Veranstaltung und
Sponsorenlauf zur Verfuegung. Neue Vorlagen werden aus den gespeicherten
DE-/EN-Texten erstellt. Vorlagen lassen sich anwenden, mit Revisionspruefung
aktualisieren und archivieren. Freigegebene Kampagnentexte bleiben fixiert.
Je Empfaenger ist die Ausgabesprache waehlbar; leere Texte verwenden den
Standard des Typs in dieser Sprache.

Das Cockpit trennt Eingereiht, Angenommen, Zustellmeldung, Ruecklaeufer,
Link geoeffnet, explizite Bestaetigung und geprueften Aenderungsantrag.
Ein GET ist niemals eine Lesebestaetigung. Druck erzeugt keine postalische
Versandbestaetigung. Versandhistorie und verschluesselte Begruendung
bleiben bei manuellem Wiederanlauf erhalten.

## Sicherheit und Testbetrieb

Die Erweiterung ist ausschliesslich auf ClubUnio DEV und Stage im
Environment staging freigeschaltet, dort auch ausserhalb der bisherigen
Pilotmandanten. Der Empfaenger muss ein synthetisches Mitglied mit eigener
Adresse campaign-test-<kennung>@example.invalid sein; eine E-Mail-Zustelladresse
muss ebenfalls diesem Muster entsprechen. Die gesamte Empfaengerliste
wird bei der Freigabe erneut geprueft. Maximal 1000 Empfaenger pro Kampagne.
Der neue Versandauftrag verwendet ausschliesslich ArrayTransport im
Speicher. Es gibt absichtlich keine Konfiguration fuer echten SMTP-Versand.
PROD wird nicht aktiviert oder aktualisiert.

Ein Auftragsdatensatz pro Empfaenger und Erinnerungsnummer verhindert
doppelte Einreihung. Erinnerungen gehen nur an nicht antwortende Empfaenger
nach dem konfigurierten Abstand von 1 bis 30 Tagen, maximal drei Mal und
nicht nach Linkablauf. Optionaler automatischer Lauf erfolgt stuendlich;
manuelle Einreihung erfolgt im Cockpit. Wiederanlauf von unklaren oder
fehlgeschlagenen Versuchen erfordert eine Begruendung und die ausdrueckliche
Feststellung, dass keine Zustellung erfolgte. Ruecklaeufer werden nicht
automatisch wiederholt und sperren weitere Auftraege derselben Adresse
innerhalb des Vereins. Die Berechtigung des ausloesenden Kontos und die
Empfaengerdaten werden vor jedem Versuch erneut geprueft.

## Eltern und Fachantworten

Die bestehende bestaetigte Elternkontoverknuepfung bleibt nutzbar.
Alternativ kann ein Vereinsadmin oder die Geschaeftsstelle im Entwurf
einen Elternkontakt ohne App-Konto verifizieren. Name und E-Mail muessen
den bestehenden Elternangaben entsprechen. Dokumentierter Nachweis,
Zustelladresse und pruefendes Konto sind erforderlich. Bei Aenderung der
relevanten Stammdaten oder Entzug der Prueferberechtigung wird die Bindung
ungueltig. Keine automatische Familien- oder Kontoverknuepfung.
Damit sind auch QR-Briefe an gepruefte Eltern Minderjaehriger moeglich.

Event-Antworten werden verschluesselt gespeichert und nach Pruefung ueber
DossierMaintenanceService in die Gaesteliste uebernommen. Bereits aktive
Teilnahmen werden nicht dupliziert. Eine Absage loescht keine anderweitig
gepflegte Teilnahme.
Sponsorenlauf-Zusagen werden in CHF erfasst, intern in Rappen gespeichert
und erst nach Pruefung ueber SponsorRunService hinzugefuegt. Bestehende
Fristen und Teilnehmerregeln bleiben bestehen. Keine automatische Rechnung,
kein Bexio-Versand und keine Zahlung.
Stammdatenantraege benoetigen weiterhin die Freigabe der Geschaeftsstelle
oder eines berechtigten Vereinsadmins.

## Ruecklaeufer-Schnittstelle

POST /campaign-feedback verarbeitet automatisch authentifizierte
Zustell-/Ruecklaeuferereignisse. Ohne einen separat bereitgestellten
CLUBUNIO_CAMPAIGN_FEEDBACK_SECRET mit mindestens 32 Zeichen bleibt der
Aufruf gesperrt. Keine Secrets in Git oder im Browser.

Header: X-Campaign-Timestamp (Unixzeit, maximal 300 Sekunden Abweichung),
X-Campaign-Signature (hex HMAC-SHA256 ueber timestamp + "." + unveraenderten
JSON-Body mit dem gemeinsamen Secret).
Body: event_id (UUID), dispatch_id (UUID), state (delivered oder bounced),
address_hash (HMAC-SHA256 der kleingeschriebenen Adresse mit dem App-Key).
Maximal 4096 Bytes. Wiederholte event_id wird idempotent behandelt;
spaete Zustellmeldungen koennen einen permanenten Ruecklaeufer nicht aufheben.
Die Nachricht erhaelt eine korrelierbare Message-ID aus der Dispatch-UUID.
Aktuell kein angebundener externer Ruecklaeuferdienst und keine IMAP-Abholung.
Diese Anbindung und der echte SMTP-Versand erfordern eigene Zugangsdaten
und eine ausdrueckliche Versandfreigabe; die implementierte Schnittstelle
wird mit synthetischen Ereignissen geprueft.

## Migration und Abnahme

Nur die additive Migration 2026_11_19_010000_campaign_operations anwenden.
Vorher private Sicherung der Kampagnenobjekte erstellen. Aeltere Kampagnen
bleiben workflow_version=1; neue/freigegebene Kampagnen nutzen Version 2.
Neue Tabellen: campaign_templates, campaign_dispatches, campaign_delivery_events.
Neue Rechte an Tabellen/Sequenzen nur fuer die App-Rolle der Zielumgebung.
Kein Import und keine Auffrischung von DEV, Stage oder PROD.

php deploy/check-campaign-operations.php prueft die exakte Ziel-DB und URL.
Synthetische Mandanten, Konten, Mitglieder und Fachobjekte werden in einer
Rueckrolltransaktion getestet. Http::preventStrayRequests und Mail::fake
zusaetzlich zum In-Memory-Transport. Die Tests prüfen Vorlagenrevisionen,
Fremdmandantensperre, doppelte Freigabe, Versand/Erinnerungs-Idempotenz,
Ruecklaeufer-Replays, gepruefte Eltern-QR-Briefe, Eventfreigabe und
Sponsorenlaufuebernahme ohne Rechnungen. Lokale Tests:
CampaignOperationsTest, MemberCampaignContentTest, CampaignMailOutcomeTest.

Rueckweg: vorherigen Release aktivieren und den neuen campaign-work-
Scheduler nicht weiter ausfuehren. Additive Felder und Versandhistorie
erhalten, keine automatische Down-Migration. Bereits freigegebene neue
Kampagnen vor einem Rueckweg sperren. Keine automatisch versendeten Testmails.
