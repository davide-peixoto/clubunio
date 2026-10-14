# ClubUnio: Bexio-Versandpruefung, Release41

Aktueller Zusatz Release46: allgemeine Webpruefung kann registrierte synthetische
Belege tatsaechlich in der Sandbox uebertragen. Normaler Adapter bleibt Mock-only.
Verbindlicher Umfang/Tests/Grenzen: CLUBUNIO-GENERAL-SANDBOX.md. Historische
Offen-Vermerke unten beziehen sich auf fruehere Releases.

## Sandbox-Verbindung 14.10.2026

Sandbox SVS Api Test bestaetigt. Kontakte und ein steuerbefreiter Testentwurf
automatisch angelegt; Lesen/PDF/Refresh lokal und Pi bzw. separat erfolgreich
geprueft. Details/IDs/Grenzen in CLUBUNIO-BEXIO-SANDBOX.md.
Kein produktiver Schreibadapter aktiviert. Importierte PROD-IDs duerfen NICHT
in der Sandbox wiederverwendet werden; naechster Schritt lokale Testfixtures
und explizite Sandbox-Zuordnung fuer die Web-Fachtests.

## Erweiterung 13.10.2026: Simulierter Uebertragungsablauf

Anhangpruefung -> Multipart-Upload -> Datei-UUIDs -> Dokumentanlage werden jetzt
als zusammenhaengender Testlauf ausgefuehrt. Der Transport akzeptiert technisch
nur Guzzle MockHandler, keine Einstellung kann echten Versand einschalten.
Provider-Vertrag: https://docs.bexio.com/, POST /3.0/files und
POST /4.0/purchase/bills bzw. /2.0/kb_invoice. Keine echten Provider-Schreibtests.
Vor jedem simulierten Upload werden die Azure-Bytes erneut gegen die gepruefte
Groesse und SHA-256 verglichen. Keine Azure-Reads unter neuen DB-Sperren.

Migration000038 ergaenzt running, Ausfuehrungstoken, Startzeit, explizit mit
simulation: markierte Referenzen und bexio_dispatch_steps. Schritte werden vor
dem Transport protokolliert. HTTP-Fehler, ungueltige Antworten und Timeouts
werden konservativ behandelt; niemals automatischer POST-Retry. Ein anderer
laufender/unklarer Versuch sperrt auch alte noch bereite Vorschauen.
Originalrechnungen, Spesenstatus und echte Bexio-IDs bleiben unveraendert.
Keine Anhangbytes, IBANs, Token, SAS oder Provider-Fehlertexte im Schrittjournal.

Web: Ablauf aufklappen, fehlgeschlagenen Testlauf explizit klaeren. Gefunden
schliesst nur eine unklare Dokumentanlage ab; Nicht angelegt erlaubt erneute
Pruefung/Simulation. Laufende Versuche fruehestens nach 15 Minuten klaerbar.
Dies ist eine simulierte Entscheidung, KEIN echter Remote-Abgleich.
Vor Rollback auf40 alle running-Versuche beenden/klaeren, da dessen Anzeige
den neuen Status nicht kennt. Zusatzspalten/Schrittjournal bleiben bestehen.

Offen vor echtem Betrieb: OpenID-Schreibadapter, providergepruefter Abgleich
unklarer Ergebnisse, Zahlungsdaten/Bill-Freigabe/Zahlungsauftrag, reale
Abnahmetests und gesonderte Freigabe. Steuerpflichtige Rechnungen sind nicht
implementiert; vorerst gilt ausschliesslich das vorhandene steuerbefreite Modell.
Dieses Paket ist deshalb kein Abschluss der gesamten Bexio-Fachintegration.

Die folgenden Abschnitte dokumentieren fruehere Entwicklungsstaende.

## Erweiterung 13.10.2026: Spesenanhaenge pruefen

Finanzen -> Bexio-Versandpruefung zeigt bei Spesen Anzahl und Gesamtgroesse
der geprueften Anhaenge. Vor Vorschau und Bestaetigung werden die aktuellen
Dateiinhalte aus Azure gelesen. Die Simulation sendet weiterhin NICHT an Bexio.
Keine neuen Einstellungen; vorhandene Azure-Anbindung/Umgebungsbindung gilt.

Pflichtbelege nach Spesentyp duerfen nicht fehlen. Ein Anzeigename ohne
Speicherreferenz, unsichere Pfade, fehlende Dateien oder nicht erreichbares
Azure sperren die Pruefung. Mehrfachreferenzen derselben Datei werden dedupliziert.
Kein lokaler Fallback. Konservative ClubUnio-Grenzen (keine Aussage ueber Bexio-
Providerlimits):10 unterschiedliche Dateien,10 MiB je Datei,20 MiB gesamt.
Inhaltsbasierte MIME-Erkennung:PDF,JPEG,PNG. Das ist keine vollstaendige
PDF-/Bildintegritaetspruefung oder Malware-Pruefung. Andere Formate blockieren.
20 Sekunden Pruefbudget zwischen/nach Reads; ein bereits laufender Azure-Read
bleibt durch die bestehenden Reader-Timeouts begrenzt und kann das Budget
ueberschreiten. Keine automatischen Wiederholungen bei Fehlern.

Azure-Reads laufen vor Transaktion/Datenbanksperren. Anschliessend werden Rechte,
Dokument, Positionen, Parameter und Dateizuordnung frisch verglichen. SHA-256
der Inhalte ist Teil des Fingerprint-Version4; auch geaenderte Inhalte unter
unveraendertem Dateinamen entwerten eine alte Vorschau. Ein spaeterer echter
Upload muss exakt die geprueften Bytes verwenden bzw. erneut vergleichen.
Originalbelege werden nicht verschoben/umbenannt; ein kanonischer kuenftiger
Uebertragungsname wird nur im Arbeitsspeicher vorbereitet.

Migration000037 ergaenzt attachment_count/attachment_bytes in lokalen
bexio_dispatch_trials. Altpruefungen zeigen Nicht geprueft (NULL), nicht0.
Dateiinhalt, Quellpfad, SAS und Einzeldatei-Manifest werden nicht gespeichert
oder im Browser ausgegeben. Fehlerlog nur Referenz, Mandant, Spesen-ID, Code.
Ruecksprung39 kann Zusatzspalten behalten. Vorhandene Vorschauen neu pruefen.

Noch KEINE Anhanguebertragung, keine Bexio-Datei-UUIDs: attachment_ids bleibt
leer im vorbereiteten Bill-Payload. Echter Upload/Transport samt Wiederaufnahme,
Zahlungsdaten und Provider-Abnahme sind weitere Pakete. API/SaaS/Betrieb und
PHPMaker DEV/PROD bleiben ausgeschlossen. Kein echter Cloud-Schreibtest.

Die folgenden Abschnitte dokumentieren die vorherigen Entwicklungsstaende.

## Erweiterung 13.10.2026: Abweichende Rechnungsempfaenger

Mitgliederliste (Verknuepfungssymbol) oder Mitgliederdossier:
Aktion Bexio-Rechnungsempfaenger. Aktive Admin-/Office-Konten sehen Adressquelle,
Name, E-Mail, Zuordnungsstatus und eine separat gepflegte Bexio-Kontakt-ID.
Explizite Bestaetigung erforderlich. Leer speichern entfernt die Zuordnung.
Es erfolgt KEINE automatische Kontaktsuche, Neuanlage oder Remote-Verifikation;
die Verwaltung muss den richtigen Kontakt in Bexio fachlich pruefen.

Fuer Mutter/Vater muss der aktive eigene Lookup die Adressquelle mother_email
oder father_email verwenden; Name und gueltige E-Mail sind erforderlich.
Zuordnung ist an Lookup-ID, Adressquelle, Name und E-Mail gebunden (HMAC).
Aenderungen daran sperren neue Vorschauen bis zur erneuten Bestaetigung.
Auch APP_KEY-Rotation erfordert eine erneute Bestaetigung. Wechsel auf
Mitglied verwendet wieder ausschliesslich dessen bisherigen Bexio-Kontakt.
Der separate Kontakt aendert nie den Mitglieds-/Spesenlieferantenkontakt.
Beim Elternempfaenger ist keine eigene Bexio-Kontakt-ID des Kindes erforderlich.
Adressdaten fuer die Rechnung stammen weiterhin aus dem zugeordneten Bexio-
Kontakt; abweichende lokale Postadressen sind hier nicht neu implementiert.

Migration000036: drei additive Felder in clubora.members (Kontakt-ID,
Identitaets-HMAC, Revision), keine Bestandszuordnung oder Datenuebernahme.
Speicherung mit Mandanten-/Mitgliedslock, frischer Berechtigung und Versions-
vergleich; fremde/archivierte Mitglieder gesperrt. Audit enthaelt Kontakt-ID
und Revision, keine Empfaenger-E-Mail oder HMAC. Snapshot-Version3 umfasst
Revision: Zuordnungsaenderung entwertet offene Versandvorschauen. Alte
Vorschauen neu pruefen. Echtversand bleibt hart gesperrt.
Ruecksprung38 kann Spalten behalten, lehnt Elternempfaenger wieder ab.
API/SaaS/Betrieb/PHPMaker DEV/PROD bleiben unveraendert.

Die folgenden Abschnitte beschreiben den Stand vor dieser Erweiterung.

## Erweiterung 13.10.2026: Payload-Aufbereitung

BexioPayloadBuilder bereitet ausschliesslich im Speicher POST /2.0/kb_invoice
und POST /4.0/purchase/bills vor. Kein HTTP-Aufruf, keine echte Neuanlage.
Vertragsgrundlage: https://docs.bexio.com/ ; Provider-Abnahme steht noch aus.
Die Anfrage geht in Fingerprint-Version2 ein, nicht in DB/Audit/Browser.
Parameter-/Adressaenderungen nach Vorschau erzwingen eine neue Pruefung.
Alte offene Vorschauen ebenfalls neu pruefen; unklare Testlaeufe bleiben gesperrt.

Rechnungen: maximal150 Positionen, nur steuerbefreites Modell MWST_TYPE=2 und
eindeutig eigener Rechnungsempfaenger (email). Abweichende/Elternempfaenger
werden gesperrt. Nettobetrag nach Rabatt wird als fester Positionspreis mit
Menge1 verwendet; kein separater sichtbarer Rabatt. Optional Artikel-ID,
sonst freie Position. Rechnungsdatum aus created_at, Faelligkeit plus Frist.
Automatische Bexio-Nummerierung und korrekt zugeordnete CHF-Waehrung sowie
steuerbefreite Steuer-ID muessen vor echtem Versand beim Provider geprueft werden.

Spesen: maximal100 Positionen, Datum der Zahlungsfreigabe plus Frist,
vollstaendige Lieferantenadresse/ISO-2-Land, CHF, Buchungskonto pro Position.
attachment_ids und discounts sind leer. Anhangtransfer, Zahlungsanweisung und
abweichende Steuerfaelle sind NICHT umgesetzt. Kontakt-/Artikel-/Konto-IDs
werden lokal validiert, nicht remote auf Existenz geprueft.

Migration000035 ergaenzt fehlende Einstellungen unter Bereich bexio, ohne
bestehende Werte oder Aktivierung zu veraendern. Neue Werte sind absichtlich
leer und muessen in der Einstellungen-Oberflaeche fachlich gepflegt werden:
USER_ID, LANGUAGE_ID, BANK_ACCOUNT_ID, CURRENCY_ID, PAYMENT_TYPE_ID, UNIT_ID,
TAX_ID, MWST_TYPE, INVOICE_DUE_DAYS, BILL_CONTACT_PARTNER_ID, BILL_DUE_DAYS
(jeweils Praefix BEXIO_). Fehlende/ungueltige Werte blockieren die Vorschau.
Keine automatische Uebernahme aus PHPMaker DEV/PROD. Additive Migration auf
eigener ClubUnio-DB; Ruecksprung37a kann die zusaetzlichen Parameter behalten.

Die folgenden Abschnitte dokumentieren die unveraenderte Basis von Release37.

13.10.2026. Erster Vorbereitungsschritt fuer ausgehende Fachprozesse.
Dies ist KEIN fertiger Bexio-Versandadapter und keine Betriebsfreigabe.

## Web

Finanzen -> Bexio-Versandpruefung. Aktive Admin-/Office-Konten sehen eigene
freigegebene, noch nicht verknuepfte Rechnungen bzw. Spesen im Status Z.
Mitgliedersuche, Typauswahl und Pagination. Pruefen erstellt eine serverseitige
Vorschau mit Positionszahl/Betrag, zehn Minuten gueltig. Erneutes Pruefen eines
unveraenderten offenen Entwurfs erneuert die Frist, nicht den Auftrag.
Danach ausdruecklich bestaetigter Testlauf: Annahme, Ablehnung oder unklarer
Ausgang. Sichtbare Kennzeichnung Simulation/Echtversand gesperrt.

Kein HTTP-Aufruf und kein Tokenzugriff im neuen Service. Kein Ini-Schalter kann
Echtversand einschalten. Bestehende OpenID-/PDF-/Statusabgleichwege unveraendert.
Simulation aendert weder Originaldokumente,Positionen,Status,Bexio-IDs noch
Nachrichten. Nur lokale Testlaufdaten und Audit werden geschrieben.

## Pruefregeln

- Aktive Admin-/Office-Zuordnung bei jedem Aufruf frisch laden; Mandantengrenze.
- Rechnung: eigener nicht importierter Beleg, aktiver Workflow Freigegeben.
  Spesen: aktiver Status Z. Jede nichtleere Remote-Referenz blockiert Neuanlage,
  auch ein ungeklaertes Platzhalterzeichen. Keine geratenen Bereinigungen.
- Nicht archiviertes eigenes Mitglied mit positiver Bexio-Kontakt-ID.
  Diese ID wird lokal geprueft, nicht beim Provider auf Existenz geprueft.
- Aktive eigene Positionen (Release38: maximal150 Rechnung/100 Spesen).
  Rechnung: Bezeichnung,Brutto,Rabatt,Netto;
  Netto muss Brutto minus Rabatt entsprechen. Bestehende Cent-Rundung verwendet.
- Spesen: positiver Betrag und aktiver eigener Spesentyp mit Bexio-Buchungskonto.
- Summe positiv; Nullrechnungen benoetigen separaten fachlichen Abschluss.
- Bei Bestaetigung Dokument,Kontakt,Positionen und relevante Zuordnungen erneut
  gegen Fingerprint pruefen. Geaenderte Grundlagen brauchen neue Vorschau.

## Wiederholungsschutz

DB-Transaktion mit bestehendem Mandantenlock und Dokumentlock. Eindeutiger
Datensatz je Mandant/Dokumenttyp/ID/Fingerprint, zufaellige Korrelations-ID.
Nur Ersteller darf bestaetigen. Andere Bearbeiter erhalten einen Konflikthinweis.
Terminale Ergebnisse bleiben unveraendert; erneuter Klick erzeugt kein Auditduplikat.
Unklarer Ausgang sperrt auch neue Vorschau nach Dokumentaenderung. Noch kein
manueller Entsperr-/Providerabgleich fuer Testlaeufe; nicht blind wiederholen.
Dieser lokale Schutz beweist keine Exactly-once-Zustellung beim Provider.

## Migration und Grenzen

Migration000034 bexio_dispatch_trials additiv auf eigener gemeinsamer ClubUnio-DB.
Keine Bestandsmigration,keine neuen Parameter,kein Cronjob. Audit enthaelt Modus,
Zustand,Korrelations-ID,keine Payload,IBAN,Tokens oder Providerantworttexte.
Code-Ruecksprung36 kann Tabelle behalten; keine automatische down-Migration.

Noch zu entwickeln vor echtem Versand:
- Weitere Payload-Faelle: abweichende Rechnungsempfaenger, Steuermodelle,
  Anhangtransfer/Zahlungsanweisungen und sichtbare Rabattdarstellung.
- Provider-Vertragspruefung, Transportadapter mit Simulation/Fakes auf HTTP-Ebene,
  OpenID-Schreibrechte, persistente Auftragszustandsmaschine um Netzaufruf herum.
- Abgleich unklarer Ausgaenge mit Bexio, Remote-ID-Uebernahme, sichere manuelle
  Wiederaufnahme und externe Stornierung. Keine automatischen POST-Retries.
- Fachabnahme und separate Freigabe echter externer Schreibvorgaenge.

API/SaaS/Betrieb und PHPMaker DEV/PROD bleiben unangetastet. Automatisierte
Tests mit Rollback pruefen Webaktionen,Rechte,Betrag,Mapping,Frist,Aenderungen,
Wiederholungen und fehlenden HTTP-Verkehr. Kein echter Bexio-Schreibtest.
Deployment-/Testergebnis in PI.md und fortlaufendem CLUBUNIO-ROADMAP.md.
