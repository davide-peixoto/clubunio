# ClubUnio Bexio-Sandbox: Verbindungs- und Grunddatentest

## Abschluss nach erneuter Anmeldung, 14.10.2026

Benutzer hat SVS Api Test mit file-Scope erneut verbunden. Verbindungsbindung
auf Pi erfolgreich geprueft; abgelehnten Upload kontrolliert freigegeben.
Spesen1083: PDF-Upload201 und Bill-Anlage201, UUID
7b605483-9abb-4e9f-a5d8-86b7348886aa, Anhang
ceb66fd3-a3bb-4b1b-a412-f8ca2747bd63. Ruecklesen bestaetigt CHF15, DRAFT,
Supplier4, eindeutige Referenz und Anhang. Native Verknuepfung accepted.
Bexio liefert amount_calc statt gross. Rueckpruefung und Testantwort korrigiert,
zusaetzlich CHF und manual_amount=false verlangt; vorhandenen Beleg ausschliesslich
lesend abgeglichen, KEIN zweites POST. Rechnung7688/Bexio2 weiterhin accepted.
Gezielte Regression9 Tests/42 Assertions erfolgreich. Release45 auf Pi gezielt
gepatcht, FPM neu geladen. Keine weitere Migration. DEV/PROD unveraendert.
Damit ist der unten dokumentierte Scope-Blocker behoben. Keine Zahlung oder
E-Mail ausgeloest; allgemeiner Versand weiterhin Simulation. Liveaufrufe erfolgten
ueber dasselbe Service wie die Webaktion; Browser-Klickversand nicht separat getestet.

## Echter Webversand (14.10.2026, Release45)

Finanzen > Bexio-Sandbox, nur aktive Admins: feste synthetische Testrechnung
CHF25 und Testspesen CHF15 vorbereiten, bestaetigt in SVS Api Test uebertragen,
Protokoll anzeigen und unklare Ergebnisse ausschliesslich lesend abgleichen.
Keine allgemeine Freigabe fuer importierte/oder frei waehlbare Dokumente.
Je Mandant/Belegart genau ein Webtestfall; wiederholte Klicks erzeugen keinen
weiteren Beleg. BEXIO_ACTIVE/Outbound bleiben aus, allgemeiner Versandpruefungs-
Adapter bleibt Simulation. Kein E-Mail-Versand, kein Booking-/Payment-Endpunkt.

Migration43: bexio_sandbox_dispatches + bexio_sandbox_steps, additive Registry
mit mandantengebundenen Fremdschluesseln. Lokale Rechnungs-/Spesendatensaetze und
Positionen werden ausschliesslich fuer die bestehenden synthetischen Mitglieder
angelegt. Rechnung bleibt Entwurf, Spesen Offen, keine Zahlungsfreigabe simuliert.
Payload und Empfaenger stammen ausschliesslich aus Sandbox-Stammdaten und festen
Testwerten. Bankauswahl nur bei genau einem Sandbox-Bankkonto; aktuell Musterbank1
statt frueher Example Bank. Keine IBAN- oder Bankstammdaten geaendert.

Sicherheit: erneute aktive Adminpruefung, Connection-Hash, Profilname/ID,
synthetischer Kontaktmarker, Snapshot von Beleg und Positionen; Claim unter
Tenant-DB-Lock, dauerhaftes Journal vor POST, kein automatischer POST-Retry.
PDF-Anhang wird synthetisch erstellt, nur dessen UUID an die Lieferantenrechnung
uebergeben; niemals echte hochgeladene Mitglieder-/Spesendokumente verwenden.
Remote-ID wird vor Ruecklesen gespeichert. Annahme erst nach Pruefung von
Dokumentmarker, Empfaenger, Betrag, Entwurfsstatus und Anhang/Steuerbefreiung.
Bei unbekanntem Ausgang bleibt Wiederholung gesperrt. Abgleich sucht eindeutige
Marker, bricht bei unvollstaendiger/mehrdeutiger Liste ab; kein Reset bei Nichtfund.
Lokal nachtraeglich geaenderte Belege bleiben gesperrt. Keine unklare Antwort
wird als sicher fehlgeschlagene Anlage ausgegeben. Protokoll ohne Tokens/Bodies.

Livebefund: lokale Rechnung7688 -> Sandbox-Rechnung2 erfolgreich angelegt (201)
und verifiziert (CHF25, Steuer0, Entwurf). Spesen1083: PDF-POST403, kein Upload-ID,
keine Lieferantenrechnung angelegt. Scope `file` fehlte im ausgegebenen Token.
In der ClubUnio-Konfiguration inzwischen auditiert ergaenzt; erneute Anmeldung
steht noch aus. GET Bills funktionierte. Spesen-End-to-End deshalb NOCH OFFEN.
Die offizielle Bexio-Dokumentation verlangt `file` fuer /3.0/files; siehe
https://docs.bexio.com/#operation/v3CreateFile und Bills /4.0/purchase/bills.

Nach der erfolgten Ergaenzung von `file` muss der Benutzer Bexio neu verbinden und
SVS Api Test waehlen. Danach im Sandbox-Bereich "Neue Verbindung pruefen":
Dateizugriff/Profil, Basis-Testrechnung und vorhandene Remote-Marker pruefen,
erst danach atomar die Verbindungshashes aktualisieren. Laufende/unklare Vorgaenge
blockieren diese Neuverknuepfung. Importierte PROD-Verknuepfungen bleiben gesperrt.
"Upload wieder freigeben" erlaubt ausschliesslich 401/403 beim Upload, wenn
weder Anhang-ID noch Dokument-ID noch ein gestarteter Create-Schritt existiert
und Dateizugriff mittlerweile erfolgreich ist. Danach explizit erneut senden.
Unbekannte Uploads/Timeouts erhalten diese Freigabe NICHT.
Bis zur neuen Anmeldung sperrt der geaenderte Konfigurationsfingerprint die
bisherige Verbindung bewusst, einschliesslich PDF-Abrufen. Keine Zugangsdaten
aus PHPMaker DEV/PROD uebernehmen oder deren Verbindung veraendern.

36 Tests/239 Assertions erfolgreich: Webaktion/Livewire, Rollen, Snapshot,
Duplikatschutz, Anhangbindung, Timeout/Abgleich, Reconnect und kontrollierter
Upload-Neustart plus bisherige OpenID/Fixture/Abgleich/Mock-Transport-Tests.
Tool tools/clubunio-bexio-web-sandbox-check.php verwendet dieselben Services;
ohne Flags nur Metadaten, --prepare/--send explizit, --bill-access lesend,
--enable-file-scope ergaenzt Scope auditiert (neue Anmeldung notwendig),
--verify-connection/--resume-upload pruefen oben genannte Bedingungen.
Noch keine allgemeine Rechnungs-/Spesenuebernahme, Zahlung, QR-/Steuermodell-
Freigabe. PHPMaker DEV/PROD, API, SaaS und Betrieb unveraendert.

## Lokale Web-Testdaten (14.10.2026, Folgepaket)

Migration42 fuegt ausschliesslich eine mandantengebundene Fixture-Registrierung
mit Fremdschluesseln hinzu. Drei neue synthetische Mitglieder (Member/Parent/
Supplier) und Rechnung7640 samt Position CHF25 wurden in der eigenen ClubUnio-DB
angelegt. Keine echten Mitglieder veraendert, keine Bankverbindung erfunden,
keine Elternzugriffsrechte oder Benutzerkonten angelegt. Testmitglieder haben
keinen Mitgliedsstatus/-typ und Spots=false; Namen klar mit Clubora Sandbox.
Rechnung7640 ist mit Sandbox-Rechnung1 verbunden und als nicht versendbarer
Testentwurf beschriftet. Keine Mitgliedernachrichten oder externen Writes.

Werkzeug: tools/clubunio-bexio-local-fixtures.php --apply --verify-pdf.
Der Service prueft Adminberechtigung, Sandbox-Profil, eindeutige Remote-Marker,
Entwurfsstatus, Empfaenger und Betrag vor einer lokalen atomaren Transaktion.
Tenant-Row-Lock verhindert doppelte lokale Anlage auch zwischen Hosts.
Wiederholung veraendert vorhandene Fixtures nicht. Neue Autorisierung erfordert
bewusste erneute Zuordnungspruefung; normale Tokenrotation behaelt die Bindung.
Der Hash enthaelt keine Tokens und bindet Clientkonfiguration/Verbindungszeit.
Profil-ID1 ist nicht global eindeutig; deshalb zusaetzliche Profilnamen- und
Dokumentmarkerpruefung. Kein allgemeines Provider-Mandantenmodell behauptet.

PDF im bestehenden InvoicePdfService funktioniert trotz deaktiviertem BEXIO_ACTIVE.
Vor dem PDF werden Profil und Dokumentmarker/Empfaenger erneut gelesen. Andere
importierte Rechnungen werden waehrend dieses Sandbox-Testbetriebs bewusst
blockiert, auch bei gleicher numerischer Bexio-ID. Rueckmeldung erklaert dies.
Statusabgleich ist fuer diesen Mandanten vorerst komplett gesperrt, einschliesslich
der Anwendung alter Vorschauen. So kann die Sandbox keine echten importierten
Rechnungs-/Spesenstatus veraendern. Entsperrung nicht durch Loeschen der Registry,
sondern erst mit einem geprueften objektbezogenen Sandbox-Abgleich umsetzen.

Live-Servicepruefung lokal: Rechnung7640, PDF200/105930 Bytes mit PDF-Signatur.
Release44 auf Pi aktiv: gleicher PDF-Servicecheck erfolgreich, Wiederholung ohne
Duplikate, Login200/geschuetzte Rechnung302. Tests24/181 Assertions erfolgreich
(Fixture/ID-Kollision/Reconnect/Rolle/Fremdfirma/Abgleich plus OpenID-Regression).
Dies ersetzt keine angemeldete visuelle Webabnahme. Native Entwurfsrechnung
und Bexio-PDF bleiben getrennte Anzeigen. Noch kein echter Web-Schreibadapter.
Naechster Schritt: native lokale Rechnungs-/Spesenentwuerfe mit Sandbox-Mappings,
geschuetzter Live-Uebertragung und Wiederanlaufjournal; danach Freigabe/Zahlung.

14.10.2026. Benutzer hat Sandbox und automatische synthetische Testdaten freigegeben.
Verifizierter company_profile: Name SVS Api Test, Profil-ID1. OpenID-Verbindung
am14.10.2026 neu verbunden; keine Token oder Schluessel in dieser Dokumentation.

## Ergebnisse

- Lokal und Pi: company_profile, Kontakte und Rechnungen HTTP200.
- Erzwungener OpenID-Refresh auf Pi erfolgreich, verschluesselt gespeichert.
- Stammdaten (Sprache,Waehrung,Zahlungsart,Einheit,Bank,Konten,Steuern) lesbar.
- Sandbox war nicht komplett leer: bereits ein Kontakt und Standard-Stammdaten.
- Drei synthetische Kontakte erstellt und wieder gelesen: Member2,Parent3,Supplier4.
  Namen beginnen Clubora Sandbox; Mailadressen ausschliesslich example.invalid.
- Ein Entwurf erstellt: Bexio-Rechnung1, CHF25.00, mwst_type2, total_taxes0,
  kb_item_status_id7. Keine echte Forderung, keine Freigabe/Versand/Zahlung.
- PDF HTTP200, base64 erfolgreich dekodiert, PDF-Signatur/42160 Bytes geprueft.
  Keine visuelle PDF-Abnahme in diesem Verbindungstest.
- Wiederholung erkennt bestehende Kontakte und api_reference der Rechnung,
  erstellt keine weiteren Datensaetze. Kein automatischer POST-Retry.

## Sandbox-Lookups und Steuerbefund

IDs ausschliesslich aus dieser Sandbox gelesen, nicht aus importierten ini-Werten.
Deutsch1, CHF1, Rechnung4, Stk1, Example Bank1 (type cash), Konto3200/ID101.
Steuer U00/ID8 ist nicht aktiv und wurde beim Test mit HTTP422 abgelehnt;
NULL ebenfalls abgelehnt (Pflichtfeld). Aktive UN81/ID14 wird von Bexio auch bei
mwst_type2 akzeptiert. Ruecklesen bestaetigt steuerbefreit, Steuer0 und Total25.
Das ist ein technischer Sandboxbefund, keine Freigabe des steuerpflichtigen
Fachmodells und keine Aenderung der ClubUnio-Steuerparameter.

## Werkzeuge und Grenzen

tools/clubunio-bexio-sandbox-inspect.php liest Firmenidentitaet und Stichproben.
tools/clubunio-bexio-sandbox-fixtures.php liest standardmaessig nur. Optionen:
--create-contacts legt die drei markierten Kontakte an;
--create-invoice ergaenzt den markierten Entwurf und prueft PDF/Total.
Ziel-DB und exakter Firmenname/Profil werden vor Schreiben geprueft. Keine
allgemeine Versandfreigabe. Lokale Prozesssperre, kein hostuebergreifender Lock;
schreibendes Tool deshalb nur lokal und nie gleichzeitig auf zwei Hosts starten.
Bei Timeout POST nicht blind wiederholen: anhand Marker/Remote-Zustand klaeren.

Seed-Test hat keine lokalen Mitgliederdatensaetze, Rechnungen, Spesen oder deren
Bexio-IDs veraendert. Importierte PROD-IDs sind NICHT fuer diese Sandbox gueltig,
auch wenn zufaellig gleiche Zahlen existieren. Fuer weitere Web-Fachtests zuerst
isolierte lokale Fixtures mit expliziter Sandbox-Zuordnung anlegen. Bestehende
PROD-Dokumente nicht als Sandbox-Testobjekte verwenden.

Noch nicht getestet/aktiviert: Artikelanlage, Lieferantenrechnung/Dateiupload,
Freigabe, Zahlung, Abgleich unklarer Antworten, QR-Bankdaten und durchgaengiger
ClubUnio-Web-Schreibadapter. Der bestehende Versandablauf bleibt simulationsgebunden,
Outbound aus. Example Bank ist kein Nachweis fuer funktionierende QR-Zahlungen.
PHPMaker DEV/PROD unveraendert, keine neuen Datenbankmigrationen.

Vertrag fuer diese Testaufrufe: https://docs.bexio.com/
