# Bexio-Fachabnahme und gezielter Rollout24.11.2026

## Reale neue Sandbox-Fachtests

Ausschliesslich ClubUnio DEV/Mandant4 sv-sissach und Bexio SVS Api Test/Profil1.
Keine echten Empfaenger, Bankauftraege, Produktiv-Bexio-Verbindung oder Datenkopie.

| Ablauf | ClubUnio | Bexio | Ergebnis |
| --- | --- | --- | --- |
| Steuerhaltige Rechnung, Rabatt, Freigabe, Uebertragung, Testzahlung |11175| RE-00018 | CHF12.35, Status9, Rest0 |
| Zweite Rechnung, Uebertragung, Freigabe, Stornierung |11176| RE-00019 | Status19, lokaler Workflow cancelled |
| Spesen, Position, privater PDF-Upload, O/F/Z, Uebertragung, Buchung, MANUAL |2448| Lieferantenrechnung im Dispatchjournal | CHF12.35, PAID, lokal B, Rest0 |

Die Rechnungssummen stammen aus CHF14.35 minus CHF2 Rabatt inklusive8.1%.
Beide neuen Bexio-PDFs ueber den berechtigten InvoicePdfService erfolgreich.
Synthetischer Spesenbeleg ueber ExpenseDocumentService/DocumentUploadService
veroeffentlicht, vor Freigabe gegen die normalen Beleg-/Revisionsregeln geprueft.
Der Versand verwendet dieselben Fachservices wie die Webaktionen, keine direkten
Statusupdates als Ersatz. Diese CLI-Fachabnahme ist kein neuer vollstaendiger
Browser-Erfassungsnachweis oder Nachweis physischer Kamera-/Handybedienung.

Ausgangszahlungsnachweis: payment_type MANUAL, Betrag1235Rappen, passende
Lieferantenrechnung, banking_payment_id leer. Bexio-Testkonto1 ist derzeit type bank,
nicht cash; ein anfangs auf cash beschraenkter Pruefer hat deshalb vor jeder Aktion
abgebrochen. Danach ausschliesslich die unterstuetzte manuelle Sandbox-Buchung,
keine Banking-/Bankauftrags-API. Keine echte Geldbewegung.

Wiederholte Uebertragung nutzt dieselben Rechnungs-/Dispatch-IDs. Statuslauf ueber
SyncWorkService und anschliessender frischer Providervergleich erfolgreich;
Spesen-Bezahlt-Ereignis genau einmal. Keine Scheduler-/Betriebsaktivierung daraus
ableiten. Der Auftrag richtet keinen neuen automatischen Status-Scheduler ein.

## Gefundene Luecken

1. Aeltere Sprach-/Datenbank-Testfixtures: eigenes PostgreSQL16-QA-Schema aus
   dem aktuellen DEV-Schema aufgebaut, keine Vereinsdaten kopiert. Nur globale
   account_roles-Lookupdefinitionen aus DEV uebernommen; ohne diese scheitern
   die alten Fixtures vor der Fachpruefung an Fremdschluesseln. Alte Tests werden
   privat an die feste QA-Datenbank und eine geschuetzte QA-Basisklasse gebunden.
2. QA-Standardsprache ausdruecklich Deutsch; DE/EN-Fachtests koennen weiterhin
   selbst die Sprache wechseln. Keine Abschwaechung der eigentlichen Sprachtests.
3. PDF-Leistungsfixture beruecksichtigt nun die getrennten frischen Modul-/Profil-
   Mitgliedschaftspruefungen: hoechstens3 Queries, weiterhin keine Mitglieder-,
   Saison-, Positions- oder Workflow-Anzeigerelationen. Weitere Queries muessen
   Mitgliedschaftspruefungen sein. Berechtigungslogik unveraendert.
4. Fremdmandantenfixture fuer SyncWork deklariert Bexio explizit; ein Verein ohne
   Buchhaltungsanbieter darf zu Recht keinen Bexio-Katalogauftrag erfassen.
5. Schema-Umbenennung hatte in Stage/PROD auch historische Provider-Marker
   umbenannt. BexioSandboxDispatchService vergleicht nun die exakte persistierte
   Payloadkennung, nur mit dem eigenen UUID und den zwei bekannten historischen
   Namenspraefixen. Empfaenger, Firma, Register, Betrag, Steuer und Mandant bleiben
   unabhaengig zwingend. Keine Marker im Provider/DB umgeschrieben.
6. Spesenmail war reiner Text; zentrale HTML-Vorlage und bytegetreue DE/EN-Dateien
   auf DEV nachgezogen. Separater Stand: CLUBUNIO-EMAIL-THEME.md.

## Testumgebung und Sicherheit

QA-Datenbank clubunio_bexio_qa_20261124 auf dem VPS, eigenes Schema clubora
ausschliesslich fuer die vorhandenen Quelltests. Das echte DEV-/Stage-/PROD-Schema
bleibt clubunio. Keine Schemarueckbenennung in einer echten Instanz.
PUBLIC-CONNECT entzogen, private QA-Dateien unter /root/clubunio-bexio-qa-20261124,
Konfigurationsdatei0600, zufaelliger Test-APP_KEY, HTTP-Fakes und Array-Mailer.
Vorhandene Quelltests werden nicht durch globale Stringersetzung im Projekt
umgeschrieben; nur die private Testkopie bekommt die feste QA-DB/Basisklasse.
Weder Stage-Tokens noch Fachdaten werden fuer Tests kopiert. Ein geschuetzter
DB-Zugang dient nur der dedizierten QA-Datenbank. Keine Ausgabe von Zugangsdaten.

Windows-PHP laedt PDO-PgSQL bei Bedarf nur fuer den Testprozess. Der anschliessend
genutzte native VPS-Testlauf vermeidet langsame SQL-Roundtrips; der lokale Tunnel
wurde geschlossen. Alle Testprozesse/SSH-Verbindungen nach Laufende schliessen.

## Gepruefter Abschlussstand

223 Bexio-/Finanz-Featuretests mit1344 Assertions auf dem isolierten PostgreSQL16-
Schema erfolgreich. Zusaetzlich21 lokale Mail-/Steuer-/Kennungstests mit167
Assertions erfolgreich; DE/EN3075 Schluessel und PHP-Platzhalter geprueft.
DEV, Stage und PROD je18 native Schutzpruefungen mit Gesamtrollback erfolgreich.
Kein Test wurde wegen der alten Datenbankbindung als bestanden uebersprungen.
PostgreSQL16.15 bestaetigt; nach dem Test0 synthetische Mandanten in der QA-DB,
private Verbindungsdatei entfernt. Die leere QA-Struktur und privaten Testlogs
bleiben fuer die nachvollziehbare Wiederaufnahme erhalten. Beide Loginseiten
Stage/PROD antworten nach Rollout302 zur regulaeren Anmeldung.
[Kompakter Nachweis](evidence/bexio-verified-20261124.json).

Gezieltes Bexio-Dateidelta auf DEV, Stage und PROD bereitgestellt. Sicherungen:
- DEV: storage/app/private/bexio-verified/dev-20261124-161700.
- Stage: storage/app/private/bexio-verified/stage-20261124-162457.
- PROD: storage/app/private/bexio-verified/prod-20261124-162459.

Die getrennte Workflow-Mailkorrektur bleibt vorerst ausschliesslich auf DEV;
keine implizite Stage-/PROD-Mailfreigabe durch diesen Bexio-Code-Rollout.

## Migrationsschritte

Keine Fach-/Schemamigration fuer den Runtime-Fix, kein PROD-Abzug oder Refresh.
Nur BexioSandboxDispatchService als geprueftes Delta; SQL-Identifier clubora. werden
im Laufzeitpaket auf clubunio. abgebildet, KEINE pauschale Ersetzung in Payloads.
release-bexio-verified.sh sichert die vorherige Datei samt Hash, installiert
gezielt und fuehrt18 native Schutzpruefungen mit simuliertem Provider und
Gesamtrollback aus. Bei Fehler stellt das Script die vorherige Datei zurueck.
Keine ENV-/Schluessel-/Rechte-/Worker-/Scheduler-Aenderung.

Bereits erzeugte Sandboxbelege und deren Journale bleiben nachvollziehbar erhalten;
bei Ruecknahme des Codes niemals Buchungen, Zahlungen oder Uebertragungen erneut
erzeugen. Testbelege nicht in eine spaetere Produktivdatenmigration aufnehmen.

## Noch nicht als abgenommen behaupten

Stage/PROD melden bei configuration() HTTP401: erforderliche eigene Bexio-Client-
Konfiguration fehlt. Keine DEV-Keys/Tokens kopiert. Codepruefung ist kein Ersatz
fuer die je Instanz notwendige Kontoverbindung und echte lesende API-Abnahme.
Derzeit nur die DEV-Sandbox ist verbunden. Echte Bank-/QR-Verarbeitung,
produktiver Versand und neue automatische Statusjobs bleiben ausgeschlossen.
Die Darstellung der zuletzt UTF-8-korrigierten Testmail im Empfaengerprogramm
ist nach dessen erster Fehlermeldung noch vom Benutzer zu bestaetigen.
