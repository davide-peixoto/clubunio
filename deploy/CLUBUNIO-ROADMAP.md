# ClubUnio: fortlaufender Aufbau- und Migrationsplan

## Fachpaket58: PMP-Fachabgleich und gezielte Dossierluecken

104 Objekte/814 Felder/31 Beziehungen/218 Eventreferenzen inventarisiert,
ohne Zugangsdaten oder Codeexport. Vollstaendige Objektzuordnung in
CLUBUNIO-PMP-COVERAGE.md; kein automatisches Vollstaendigkeitsversprechen.
Vereinsfunktionen/Qualifikationen/Veranstaltungen pflegbar, Dossierpflege fuer
Funktionen mit Mehrfachqualifikation, Schluessel, Sponsoring und Gaeste ergaenzt.
Saisondossier um Beitragsregeln erweitert; Lookup-Display2-Export korrigiert.
Eigene Migration58 nur Revisionsfelder; keine Bestandsdaten neu zugeordnet.
Weiter offen: eigene Web-Aenderungsantraege, allgemeine Mitglieder-Uploadpflege,
redaktionelle Ankuendigungen und Quittungs-Fachvertrag sowie Benutzerabnahme.
Details: CLUBUNIO-PMP-ACCEPTANCE.md. Alte Offen-Vermerke unten sind Historie.
API/SaaS/Betrieb und PHPMaker DEV/PROD unveraendert.

## Fachpaket57: Mitgliederanmeldungen und Wiedereintritt

Webworkflow samt TL/Pool/Probetraining/Annahme/Ablehnung, Dublettenpruefung,
Wiedereintritt auf vorhandener Mitglied-ID, atomarer Mannschaftsuebernahme und
Elternvorschlaegen ohne automatische Berechtigungsfreigabe vervollstaendigt.
Private UUID-Azure-Uploads, Dokumentverwaltung, Vorlagen/Protokoll und gezielter
Testmailversand nur an admin@svsissach.ch. Eigene additive Migration57.
Keine Bestands-Annahme oder Rueckmigration nach PHPMaker. Spaeteren neuen
Produktionssnapshot, fachliche Abnahme und Cutover separat planen.
Details: [Anmeldungen und Wiedereintritt](CLUBUNIO-REGISTRATION-COMPLETION.md).
DEV/PROD, App-API, SaaS und Betrieb unveraendert.

## Fachpaket56: Altplaene, Schluesselbox-Simulation, Instagram

Eigene Migrationen55/56. 41 Quellen geprueft, ein Plan privat archiviert,
29 gueltige Plaene warten auf Mannschaftszuordnung; elf Quellen ausgeschlossen.
18 Altplaene benoetigen aktuelle Qualitaetspruefung. Keine Original-SVG-Ausfuehrung.
Instagram live acht Beitraege; Schluesselbox nur nichtphysische Simulation.
Echter Hardwareadapter und manueller Bexio-Teilgutschrifttest bleiben offen.
Details: [Fachpaket56](CLUBUNIO-TRAINING-KEYBOX-INSTAGRAM.md).
PHPMaker DEV/PROD unveraendert; API/SaaS/Betrieb weiterhin ausgenommen.

## Fachpaket55: Gutschriften und Rueckerstattungen im Web

Eigene Rechnungen: Teil-/Vollgutschriften, Originalpreis-/Steuersnapshots,
Freigabe, manuelle Bexio-Zuordnung mit technischer Gesamtbetragspruefung,
Rueckerstattungsvormerkung und synthetische manuelle Nachweise ohne Banktransfer.
Listen/Statuslookups/Ansichten/Dossiers/Dashboard, Summe/Revision/UUID/Audit.
Live Storno-Gutschrift33 zugeordnet; Bexio7 Eingang10+5, Zahlungsliste15 und
Guthaben5 lesend bestaetigt. Lokaler Testnachweis5, keine echte Rueckzahlung.
Neue Teilgutschrift10 mit Steuer lokal freigegeben; manuelle Bexio-Anlage noch
offen, da kein API-Anlage-Endpunkt. Nicht als automatischen Kreditadapter werten.
Migration52/53/54 eigeneDB; PHPMaker DEV/PROD, API/SaaS/Betrieb unveraendert.
Details und Abnahmegrenzen: CLUBUNIO-CREDITS-REFUNDS.md. Danach Altplan-
Konvertierung/Zuordnungen, Bus-Keybox und Instagram-Fachabnahme separat.

## Verwaltungs-URLs und Mandantenwahl

14.10.2026 Release54: Verwaltung ohne /clubunio/{slug}, automatische
Mandantenwahl, Dropdown bei mehreren Berechtigungen. Filament-Scoping
erhalten; Schutz alter Browser-Tabs und CSRF-Formulare beim Wechsel.
Keine DB-Migration. Standalone Portal-/Dokument-/Kalenderlinks kompatibel.
Tests/Framework-Upgrade-Hinweise: CLUBUNIO-TENANT-NAVIGATION.md.

## Zentraler Bexio-Callback

14.10.2026: ClubUnio-Bexio nach /integrations/bexio umgestellt. Callback
ohne Mandantenslug, serverseitiger benutzergebundener Einmalkontext und
erneute Rechtepruefung. APP_URL dev.clubunio.ch. Keine DB-Migration,
PHPMaker unveraendert. Restliche mandantenlose Webnavigation bleibt offen.
Details und Testgrenzen: CLUBUNIO-BEXIO-DOMAIN.md.

## Domainwechsel: Infrastruktur vorbereitet, Routing offen

14.10.2026: Pi erlaubt dev.clubunio.ch, .com wird weitergeleitet. Proxy/TLS
getestet; oeffentliche DNS-Eintraege fehlen noch. Keine DB-Umbenennung.
Naechster Anwendungsschritt: mandantenlose Webpfade mit autorisiertem
Mandantenwechsel und Schutz alter Livewire-/Browserkontexte. Filament-
Scoping bleibt zwingend erhalten. Noch NICHT umgesetzt. Migrationsfolge,
Abnahmetests und Custom-Domain-Zielbild siehe CLUBUNIO-DOMAINS.md.

## Paket52: freie Rechnungen und Steuer-/Rundungsabgleich

Manuelle Webrechnungen fuer Bussen, Dressbezug, Merchandising und weitere
Leistungen; Kategorien pflegbar, gespeicherte Ansichten/Filter erhalten.
Explizite Steuerbehandlung, unveraenderliche Positionssnapshots, CHF-Rundung,
integrierter PDF-/Portal-/Dashboard-/CSV-/Status-/Zahlungsabgleich umgesetzt.
Live gemischter Beleg8440/Bexio6 CHF40.25 bisBezahlt/Rest0; drei weitere
Rundungs-/exempt-Belege angenommen. Keine echten Zahlungen/Emails.
Eigene Migration50/51; Altbetraege nicht umgerechnet. Details und Grenzen:
CLUBUNIO-GENERAL-INVOICES.md. API/SaaS/Betrieb bleiben ausgeklammert.
Eigenstaendige Gutschriften/Rueckerstattungsprozess bleiben noch offen.

## Paket51: Katalogverwaltung und Artikelzuordnung

Artikel-/Steuerkatalog atomar aus bestaetigter Sandbox, lokale Labels und
Aktivierung, Such-/Statusfilter, Gueltigkeitsdaten, Rollen/Revisionsschutz.
Artikel in Rechnungspositionen mit aktuellem Providercheck, Preise/Rabatte
bleiben individuell. Synthetischer Testartikel1 und Rechnung8371/Bexio5
CHF10 live angenommen, Wiederholung ohne Duplikate. Migration48/49 eigeneDB.
Details CLUBUNIO-BEXIO-CATALOG.md. Steuerpflichtige Rechnungen bleiben
gesperrt: Steuersnapshots, Rundung und Finanzabgleich bilden naechsten Schritt.
PHPMaker DEV/PROD, API, SaaS und Betriebsentwicklung unveraendert.

## Paket49 Teil1: PDF-Vorschau

14.10.2026: lokale PDF.js-Vorschau mit Seitenwechsel/Zoom/Download ersetzt
Browser-PDF-Plugin im Rechnungs-SlideOver. Keine DB-Migration erforderlich.
Authentifizierung und Dokumentberechtigungen bleiben. Desktop/Mobiltest mit
synthetischem PDF bestanden; Live-Dossierabnahme angemeldet noch offen.
Artikel-/Steuersonderfaelle nur katalogseitig geprueft, NICHT abgeschlossen;
steuerpflichtiger Versand bleibt gesperrt. Details CLUBUNIO-PDF-PREVIEW.md.
API/SaaS/Betrieb und PHPMaker DEV/PROD unveraendert.

## Paket48: Zahlungen, Finanzabgleich und Spesen bis Bezahlt

14.10.2026: Rechnung7765/Bexio3 live mit Teilzahlung10 und Restzahlung10
bis9/Bezahlt getestet. Spesen1114 live BOOKED -> MANUAL -> PAID, lokal B,
Rest0 und ein Nachrichtenereignis. Keine Bankuebermittlung.
Finanzstand/Gutschriften/Restbetrag auditiert, Vorschau vor Uebernahme frisch
pruefen; offene Listen/Dashboard/CSV nutzen den Rest. Gutschrift33 aus Storno
der Rechnung7950 live abgeglichen; unabhaengige Gutschriftanlage weiter offen.
Migration47 auf eigener gemeinsamer DB; keine neuen ini-Parameter/Jobs.
Details CLUBUNIO-FINANCIAL-SANDBOX.md, Test-/Deploymentnachweis PI.md.
Naechste Fachabnahme: Artikel-/Steuersonderfaelle und unabhaengige Gutschrift.
API/SaaS/Betrieb und PHPMaker DEV/PROD weiterhin ausgeklammert.


## Paket47: Elternrechnung bis QR-PDF und Stornierung

14.10.2026: Elternempfaenger, sichtbare CHF-Rabatte, lokale Freigabe,
echte Bexio-Freigabe, QR-PDF und Stornierung mit Statusrueckfluss umgesetzt.
Sandbox-Rechnung7950/Bexio4 CHF33 erfolgreich von Entwurf ueber8 nach19,
Rechnung/Positionen/lokaler Workflow storniert. Keine Zahlung oder Mail.
Migration45 Aktionsjournal;46 fehlender Positionslookup P. Eigene ClubUnio-DB.
Details und Webablauf: CLUBUNIO-INVOICE-SANDBOX.md. Deploymentnachweis: PI.md.
Die historischen Offenpunkte unten sind nicht der aktuelle Gesamtstand.
Weiter offen: Zahlungs-/Teilzahlungs-/Gutschriftabnahme, Bill-Freigabe und
Zahlungsauftrag, steuerpflichtige Sonderfaelle. API/SaaS/Betrieb bleiben geparkt.


## Sandbox-Abnahme begonnen 14.10.2026

Bexio-Sandbox SVS Api Test ist verbunden. HTTP-Lesetests lokal/Pi, OpenID-Refresh,
3 synthetische Kontakte und1 steuerbefreiter Entwurf samt PDF erfolgreich.
Wiederholung ohne doppelte Anlage. Details CLUBUNIO-BEXIO-SANDBOX.md.
Damit ist "auf Sandbox warten" erledigt, nicht die gesamte Bexio-Fachabnahme.
Offen: isolierte lokale Sandbox-Testdaten/IDs, echter geschuetzter Schreibadapter,
Lieferantenbeleg/Upload/Freigabe/Zahlung/Abgleich. Kein PROD-ID-Recycling.

## Fachpaket43: Altlisten-Abgleich

14.10.2026: Schluesselliste und Spots-Versandliste im Web, Suche/Filter,
persoenliche Ansichten und auditiertes CSV. Migration40/41 eigene ClubUnio-DB,
Status-Spotsregel pflegbar. Exakte Regeln/Unterschiede zum Alt-SQL stehen in
CLUBUNIO-LEGACY-REPORTS.md. Dies schliesst den technischen Altlisten-Abgleich aus42;
fachliche Anwenderabnahme bleibt offen. Training-Snapshot:31 erfolgreiche Plaene,
davon30 ohne TeamSaison, weitere10 Auditfehler/Zwischenstaende. Kein Import geraten.
Naechstes Fachpaket: bestaetigte Altplan-Konvertierung mit Besitzer-/Teamzuordnung.
Bexio bis Sandbox unveraendert. API/SaaS/Betrieb und PHPMaker DEV/PROD ausgenommen.
Test- und Deploymentnachweis siehe PI.md.

## Fachpaket42: Web-Abschlussrunde Listen, Signage, Bus, Training

14.10.2026: aktuelle Abdeckungsmatrix und verbleibende Grenzen stehen in
CLUBUNIO-WEB-COMPLETION.md. Historische Offen-Vermerke unten nicht isoliert lesen.
Neu: Mitgliederlisten-CSV, separate Spieler-Elternkontaktliste, Bankadress-
Abgleich/Editor/Export, angemeldetes Signage-Vollbild und Admin-Instagram-Schalter,
auditierte historische Buszuordnung, KI-Planarchiv/Wiederherstellung/private Kopie.
Migration000039 auf eigener gemeinsamer ClubUnio-DB ausgefuehrt, 2838 Banken mit
importiertem Domizil.14 unvollstaendige Buszuordnungen bewusst nicht geraten.

Vor Cutover: Altplan-Konvertierung (41 Auditzeilen sind nicht41 fertige Plaene),
Spots-Familien-/Schluessellisten-Detailabgleich, Bus-Keybox-Integration/Widerruf,
Instagram-Livetest und fachliche Benutzerabnahme. Oeffentlicher Kioskbetrieb,
API und SaaS bleiben ausgeklammert. Bexio bis Sandbox unveraendert.
Deploymentnachweis/Tests siehe PI.md; kein pauschaler Vollstaendigkeitsabschluss.

## Fachpaket41: Bexio-Testablauf und Finanzkorrekturen

Lokal/Pi als20261013-clubora-41 aktiv, Ruecksprung40.63 gezielte Tests mit359
Assertions erfolgreich, HTTP200/302 und Dienste geprueft. Visuelle Pruefung
wegen CDP-Timeout nicht moeglich. Kein Gesamttest, kein echter Bexio-Schreibtest.

13.10.2026: zusammenhaengende Simulation von Anhang-Upload/UUIDs/Dokumentanlage,
persistentem Schrittjournal, Wiederholungsschutz und expliziter Testklaerung.
Migration000038 auf eigener ClubUnio-DB: nur technische Testtabellen erweitert.
Finanzen: manuelle Entwurfspositionen wiederherstellen, stornierte eigene
Rechnungen archivieren/wiederherstellen; Historie/Teamverknuepfungen erhalten.
Centgenaue Konsistenzpruefung bei Freigabe und frische Kontoberechtigung.

Migrationsschritt:000038 anwenden, neuen Code ausliefern; keine Bestandsdaten-
oder Dateimigration, keine neuen ini-Parameter. Vor Rollback auf40 neue running-
Testlaeufe klaeren. API/SaaS/Betrieb und PHPMaker DEV/PROD bleiben unveraendert.
Echter Versand technisch gesperrt. Details:CLUBUNIO-BEXIO-DISPATCH.md und
CLUBUNIO-INVOICES.md. Deployment-/Testnachweis in PI.md.

Weiter offen, nicht als abgeschlossen behandeln: echter Bexio-Schreibadapter
und Remote-Abgleich, Bill-Freigabe/Zahlungsdaten/-auftrag, fachliche Steuerfaelle,
QR-/Zahlungsrechnung und Ersatzlauf stornierter Beitraege. Reale Buchungen und
Benachrichtigungen bleiben bis separater Freigabe verboten.

## Fachpaket40: Spesenanhaenge in der Bexio-Versandpruefung

Lokal/Pi als20261013-clubora-40 aktiv, Ruecksprung39 erhalten.
45 gezielte Tests/162 Assertions erfolgreich, HTTP200/302,Dienste aktiv.
Visuelle Pruefung wegen Browser-Timeout nicht moeglich; kein Gesamttest.

Azure-Inhaltspruefung, Pflichtbelege, Dateitypen/Groessen, Deduplizierung und
Checksumme in Fingerprint-Version4. Azure-Reads ohne neue Datenbanksperren;
danach erneuter Fach-/Rechte-/Zuordnungsvergleich. Web:Anzahl/Groesse.
Migration37 additiv, keine Dateimigration oder neue Parameter. Grenzen und
Migrationshinweise: CLUBUNIO-BEXIO-DISPATCH.md; Deployment/Tests: PI.md.
Noch kein Upload an Bexio, keine Datei-UUIDs oder echten Buchungen.
API/SaaS/Betrieb und PHPMaker DEV/PROD bleiben ausgeschlossen.

## Fachpaket39: Bexio-Rechnungsempfaenger

Lokal/Pi als20261013-clubora-39 bereitgestellt, Ruecksprung38 erhalten.
HTTP200/302, Dienste aktiv; Migration36 erfolgreich angewendet.

Explizite Mutter-/Vater-Kontaktzuordnung in Mitgliederliste und Dossier,
separat vom Mitglieds-/Spesenlieferantenkontakt. Identitaetsbindung,
Revisionsschutz und datensparsames Audit; keine automatische Remote-Zuordnung.
Migration36 additiv auf eigener ClubUnio-DB, keine Bestandskontakte veraendert.
31 gezielte Tests/99 Assertions erfolgreich, inklusive Webaktion.
Browserpruefung wegen Verbindungs-Timeout nicht moeglich, kein Gesamttest.
Deploymentabschluss siehe PI.md. Details CLUBUNIO-BEXIO-DISPATCH.md.
Noch offen: Anhaenge/Zahlungsdaten, weitere Steuermodelle, echter Transport
und Abgleich unklarer Ausgaenge. API/SaaS/Betrieb/PHPMaker ausgeschlossen.

## Fachpaket38: Bexio-Payload-Aufbereitung

Lokal/Pi bereitgestellt als20261013-clubora-38, Ruecksprung37a erhalten.
29 gezielte Tests/149 Assertions erfolgreich, Migration35 angewendet,
HTTP200/302, Dienste aktiv. Kein Gesamttest oder echter Provider-Schreibtest.

Rechnungen/Spesen werden nach lokaler Fachpruefung in vorbereitete Provider-
Anfragen ueberfuehrt. Keine HTTP-Aufrufe oder Originaldokument-Aenderungen.
Pflichtparameter, Empfaenger, Adresse, Positionsgrenzen und Mapping geprueft;
Konfiguration fliesst in Fingerprint-Version2 ein. Details/Grenzen siehe
CLUBUNIO-BEXIO-DISPATCH.md. Migration35 ergaenzt nur fehlende leere Einstellungen.
Auslieferung/Pruefergebnis siehe PI.md. Vor echtem Versand fehlen Transport,
Provider-Abnahme, weitere Empfaenger-/Steuerfaelle, Anhaenge und Zahlungsdaten.
API/SaaS/Betrieb und PHPMaker DEV/PROD bleiben ausgeschlossen.

## Fachpaket37: Bexio-Versandpruefung (Simulation)

Lokal/Pi bereitgestellt als20261013-clubora-37a,vorheriger Fachstand36 erhalten.
21 Tests/120 Assertions und Nachtest10/37 nach Seitenregistrierung erfolgreich.
HTTP200/302 und Dienste geprueft. Kein Gesamttest/visueller Browsertest.

Web-Vorschau fuer freigegebene eigene Rechnungen/Z-Spesen, lokale Validierung,
Fingerprint/Frist/Erstellerbindung, atomare Testlaeufe und Wiederholungsschutz.
Annahme/Ablehnung/Unklar simuliert, keine HTTP-Schreibaufrufe oder Status/ID-
Aenderungen an Dokumenten. Migration34 nur eigene ClubUnio-DB.
Noch KEIN fertiger Versandadapter: Provider-Payload/Empfaenger/Steuerparameter,
HTTP-Transport und Abgleich unklarer realer Ausgaenge bleiben offen.
Details CLUBUNIO-BEXIO-DISPATCH.md; Deploymentabschluss PI.md.
API/SaaS/Betrieb und PHPMaker DEV/PROD ausgeschlossen; echte Writes gesperrt.

## Fachpaket36: Speziallisten-Exporte

Lokal/Pi bereitgestellt als20261012-clubora-36,Ruecksprung35.
10 gezielte Tests/137 Assertions erfolgreich,HTTP200/302 und Dienste geprueft.
Kein Gesamttest oder manueller Export echter Personendaten.

CSV fuer Mitglieder ohne Mannschaft und Passivmitglieder; Saison/Suche/Filter/
Sortierung und persoenliche Ansichten werden erhalten. Erneute fachliche und
Rollenpruefung vor Download, feste sparsame Spalten,5000-Zeilen-Limit und Audit.
Keine Migration/Parameter; Details CLUBUNIO-REPORTS.md, Deploymentabschluss PI.md.
API/SaaS/Betrieb und PHPMaker DEV/PROD unveraendert ausgeschlossen.
Weitere Altlisten/Bankadress-Abgleich,Anlagen-Fachprozesse,ausgehende Bexio-
Vorgaenge und Fachabnahme bleiben offen; Instagram-Freischaltung separat.

## Fachpaket35: Wetter und Instagram

Lokal/Pi bereitgestellt als20261012-clubora-35, Ruecksprung34 erhalten.
Migration33 abgeschlossen.37 gezielte Tests/332 Assertions erfolgreich;
Desktop/Mobil lokal, angemeldete Pi-Anzeige und Wetterabruf erfolgreich,
HTTP200/302,Dienste aktiv. Kein Gesamttest aller Testsuiten; Versand aus.
Signage-Vorschau mit isoliertem Nachladen, Cache/Fehlerpause, sichtbarer Aktualitaet,
validierten Daten und ohne Secrets im Browser/Log. Vier Wetterparameter additiv,
kein Cronjob. Wetter live erfolgreich, Instagram implementiert und automatisiert
getestet, bleibt aber deaktiviert: Aktivierung und echter Feed-Test noch offen.
Details: CLUBUNIO-SIGNAGE-FEEDS.md; Test-/Deploymentabschluss PI.md.
Oeffentlicher Displaybetrieb, weitere Anlagenprozesse, ausgehende Bexio-Vorgaenge,
Speziallisten und Fachabnahme bleiben separate Themen. API/SaaS/Betrieb und
PHPMaker DEV/PROD weiterhin ausgeschlossen. Historische Abschnitte bleiben erhalten.

## Fachpaket34: Vereinsstamm und Signage-Logos

Lokal/Pi bereitgestellt als20261012-clubora-34, Ruecksprung33 erhalten.
28 gezielte Tests/289 Assertions erfolgreich. Desktop/Mobil lokal und angemeldete
Vereinsliste auf Pi geprueft, HTTPS200/302 und Dienste aktiv. Kein Gesamttestlauf.

Vereine/Logos verwaltbar, Gastgeber/Gast-Vereinsauswahl statt freier Nummer,
Prueffilter und persoenliche Ansichten, feste SFV-Logoquelle mit Platzhalter.
Keine Aenderung an Mannschaftsnamen, Garderoben oder Match-ID durch Auswahl.
Migration32 und einmaliger Import in eigener ClubUnio-DB:1447 Vereine;
Konflikte3108/3120/3538 bewusst zur Fachklaerung offen. Keine Wettkaempfe migriert
oder automatisch mit Vereinen verknuepft. Detail: CLUBUNIO-FOOTBALL-CLUBS.md.
Test-/Deploymentabschluss PI.md. Weitere Pakete: Instagram/Wetter, weitere
Anlagen-Fachprozesse, ausgehende Bexio-Vorgaenge, Speziallisten und Fachabnahme.
API/SaaS/Betrieb und PHPMaker DEV/PROD bleiben ausgeschlossen.

## Fachpaket33: Benutzerverwaltung und SFV-Sammelpruefung

Lokal/Pi bereitgestellt als20261012-clubora-33, Ruecksprung32 erhalten.
Regression der beiden Fachtestsuiten:151 Tests/1487 Assertions; anschliessend
8 gezielte Tests/62 Assertions fuer letzte Ergaenzungen erfolgreich.
Desktop/Mobil lokal sowie HTTPS200/302 und aktive Dienste nach Deployment
geprueft. Keine realen Konten oder Wettkaempfe beim Funktionstest veraendert.

Verwaltung -> Benutzer und Rollen: Admin-only Kontenanlage/Bearbeitung,
Aktivierung, Rollen-/Portalvergabe und Passwortsetzen. Passwortfreies Audit,
Revisionsschutz, letzter-Admin-Schutz und Mandantentrennung. Rollen-Lookup
account_roles; keine frei editierbare Rechteengine. Migration31 additiv auf
eigener ClubUnio-DB, keine bestehenden Benutzerrollen/Aktivflags geaendert.
Benutzerzuordnungen zu Mitgliedern/Kindern bleiben separate Maske.
Details/Restpunkte CLUBUNIO-ACCOUNTS.md.

SFV: Zeitraum-Sammelvorschau mit Konfliktliste, atomare bestaetigte Uebernahme,
Signage-Ausblendvorschlaege fuer abgesagte/wegverlegte verknuepfte Spiele.
Keine automatische Wiederanzeige, keine Loeschung, Garderoben bleiben erhalten.
Details CLUBUNIO-FACILITIES.md, Test-/Deploymentabschluss PI.md.
Offen bleiben Logo-/Instagram-/Wetterintegration, weitere Anlagen-Fachprozesse,
ausgehende Bexio-Vorgaenge, restliche Speziallisten und fachliche Abnahme.
API/SaaS/Betrieb und PHPMaker DEV/PROD weiterhin ausgeschlossen.

## Fachpaket32: SFV nach Wettkaempfe

Lokal/Pi bereitgestellt als20261012-clubora-32, Ruecksprung31.16 Tests/139
Assertions erfolgreich; echter SFV-Abruf und Vorschau lokal erfolgreich.
Keine realen Wettkaempfe beim Test veraendert. HTTPS200/302,Dienste aktiv.

Web-Vorschau und bestaetigte Einzeluebernahme mit bestehender SFV-Verbindung,
Spielort-/Spielfeldzuordnung, revisionsgesicherter Verknuepfung, Dublettenschutz
und Audit. Manuelle Garderoben/Seiten/Bemerkungen/Kategorien/Vereinsnummern und
Sichtbarkeit bleiben erhalten. Migration30 ergaenzt getrennte SFV-Teamnamen;
auf eigener ClubUnio-DB ausgefuehrt. Bestehenden SFV-Cache danach neu abrufen.
Details/Grenzen: CLUBUNIO-FACILITIES.md. Deployment-/Testabschluss siehe PI.md.
API, SaaS, Betrieb/Cutover und PHPMaker DEV/PROD bleiben ausgeschlossen.
Offen: abgesagte/wegverlegte Spiele automatisch behandeln, Massenuebernahme,
Vereinslogo-Zuordnung, Instagram/Wetter und restliche Anlagen-Fachprozesse.

Historischer Stand32: Verwaltung -> Benutzerzuordnungen verwaltete nur
Konto-/Mitglied-/Elternverknuepfungen. Vollstaendige Benutzerkonten- und Rollen-
verwaltung wurde in Folgepaket33 ergaenzt (oben); keine automatische
Rechteerweiterung. Rollenvergabe nur Admin, mit Audit und Schutz des letzten
Admins planen. Nicht mit der vorhandenen Zuordnungsmaske gleichsetzen.

## Fachpaket31: Listenexporte

Release20261012-clubora-31 lokal/Pi aktiv, Ruecksprung30. Tests10/135 erfolgreich,
HTTPS-Login/CSS200 und Listenschutz302. Keine echte Exportdatei in der
Browserpruefung erzeugt; Downloads mit isolierten Testdaten automatisiert geprueft.

Spieler/Staff/Familien/Bankkonten: gefilterter und sortierter CSV-Export fuer
Admin/Office, zusaetzlicher Mandantenschutz, maskierte IBAN, Formelneutralisierung,
5000-Zeilen-Limit und metadatenarmes Export-Audit. Keine Schemamigration.
Bestehende persoenliche Ansichten bleiben wirksam. Spalten sind fest definiert.
Details CLUBUNIO-REPORTS.md; Test-/Deploymentabschluss PI.md.
Weitere Speziallisten/Bankadress-Abgleich und Fachabnahme bleiben offen.
API/SaaS/Betrieb unveraendert ausgenommen, keine PHPMaker-DEV/PROD-Aenderung.

## Aktuelles Fachpaket: Busreservierungen

Release30 lokal/Pi aktiv: auditierte Umbuchungen, interne Aenderungs-/Stornonachrichten,
optionale Busanzeige im Kalender samt ICS und geschuetzte Keybox-Zeitfenstervorschau.
Keine physische Codeausgabe; Provider-/Widerrufsprozess bleibt offen.
Keine neue Schemamigration; fehlende Nachrichtenvorlagen additiv seeden.
Tests19/254 erfolgreich; HTTPS-/Dienstpruefungen erfolgreich, Ruecksprung29.
Test-/Deploymentnachweis in PI.md, Fachdetails CLUBUNIO-BUS-RESERVATIONS.md.

12.10.2026: Web-Anfrage, Genehmigung/Ablehnung/Storno, Rollen-/Team-/Mitgliederschutz,
Konfliktpruefung, Revision/Audit, interne Benachrichtigungen und Auswahlpflege.
Migration29 additiv in ClubUnio ausgefuehrt, 1 Bus/18 Reservationen aus bestehendem
legacy_prod-Snapshot; 14 ohne vollstaendige Zuordnung bewusst unveraendert.
Details und verbleibende Bus-Themen: CLUBUNIO-BUS-RESERVATIONS.md.
Release29 lokal und auf ClubUnio Pi bereitgestellt, Ruecksprung28 behalten.
Gesamtregression175 Tests/1661 Assertions erfolgreich. Desktop-/Mobilmasken
lokal angemeldet geprueft, ohne reale Schreibaktion. HTTPS-Login/CSS200,
Busseiten ohne Anmeldung302; FPM/Scheduler aktiv. PHPMaker DEV/PROD unveraendert.
API, SaaS und Betrieb/Cutover weiterhin ausgenommen. Naechste Fachschritte:
Echte Bus-Keybox-Ausgabe, weiterer Anlagen-/Signage-Ausbau, verbleibende
Fachlisten sowie ausgehende Bexio-Prozesse ohne echten externen Schreibbetrieb.

## Vorheriges Fachpaket: Finanzeditoren, Exporte und Signage

Fortsetzung 12.10.2026: Mapping-/Positionsstatus-Editor, auditierte Entfernung
manueller Entwurfspositionen und gefilterte Finanz-CSV-Exporte umgesetzt.
Keine neue DB-Migration. Details: CLUBUNIO-FINANCE-EDITORS-EXPORTS.md.
Release28 auf ClubUnio-Pi bereitgestellt; Regression127/1248, finaler Nachlauf4/62.
Naechster groesserer Ausbau: Busreservierungen/Anlagen und restliche Fachlisten.
Ausgehende Bexio-Prozesse bleiben ebenfalls offen, nicht durch Mapping ersetzt.
Signage-Vorschau aktualisiert minuetlich, Heute-Modus mit Schweizer Tageswechsel
und Erhalt manuell gewaehlter Vorschau-Daten; kein oeffentlicher Displaybetrieb.

Generelle Entwicklungsfreigabe bestaetigt: Fachpakete ohne erneute Einzelabfrage
umsetzen und testen. API und Betrieb/Cutover bleiben ausgenommen, ebenso SaaS.
Keine echten Bexio-Schreiboperationen oder Mitgliedernachrichten ohne separate
Freigabe. Testserver-Deployment bedeutet keine Produktiv-Betriebsfreigabe.
Offene Pakete nicht pauschal als abgeschlossen markieren.

12.10.2026: Release27 mit lesender Vorschau und bestaetigter lokaler Uebernahme
fuer Rechnungen/Positionen und bezahlte Spesen. Migration28 additiv ausgefuehrt.
Eigene OpenID-Verbindung, Audit, Zehn-Minuten-Ablauf und Wiederholungsschutz.
Details und offene Bexio-Schreibprozesse: CLUBUNIO-BEXIO-RECONCILIATION.md.
API/SaaS, automatischer Abgleich und physischer Push bleiben ausgenommen.

## Vorheriges Fachpaket: KI-Trainingsplanung

12.10.2026: Web-Erstellung, Bearbeitung, Teamfreigabe, explizit bestaetigte
KI-Pruefung und PDF mit sicheren schematischen Positionsgrafiken umgesetzt.
Migration27 nur ClubUnio; bestehende OpenAI-Konfiguration/Vector Store verwendet.
32 Tests/305 Assertions erfolgreich; neutraler echter OpenAI-Test mit vier
Uebungen erfolgreich. Desktop/Mobil-Erfassung geprueft, fachliche/PDF-Abnahme
und historischer Planimport offen. Details: CLUBUNIO-TRAINING-PLANS.md.

Die beauftragte Reihenfolge bleibt: KI-Trainingsplanung, Bexio-Fachprozesse,
Finanzen, Signage, Bus/Anlagen, Listen/Bedienung. Dieses erste Paket schliesst
die restlichen Fachpakete nicht ab. API/SaaS bleiben geparkt.

Stand 12.10.2026. Verbindliche Richtungsentscheidung des Auftraggebers:

- PHPMaker DEV und PROD bleiben eigenstaendig im Betrieb, auf absehbare Zeit.
- ClubUnio wird parallel mit eigener PostgreSQL-Datenbank entwickelt. Kein
  automatischer Rueckabgleich und keine stillschweigende Umschaltung der App.
- API-Design wird spaeter entschieden: kompatibel, angepasst oder neu. Eine
  koordinierte App-Pause/Store-Veroeffentlichung ist moeglich, kein Versprechen
  eines sofortigen Ersatzes ohne App-Anpassungen.
- SaaS-Produktisierung und mobile API haben vorerst letzte Prioritaet. Vorhandene
  Mandantentrennung bleibt verbindlich; Sicherheit wird nicht aufgeschoben.
- Vor jedem Paket Ist-Zustand, fachliche Regeln, Import, Tests und offene Punkte
  dokumentieren. Produktivmigration erst nach separater Entscheidung und Abnahme.

## Arbeitsfolge

### Bexio OpenID und INI-Bereinigung 12.10.2026

Release25: OpenID-Verbindung via Jumbojett, Admin-Consent mit State/Nonce/PKCE,
automatische atomare Tokenrotation und ein PDF-Retry bei401. Alte kopierte
Tokens/Endpunkt/Cron-Parameter werden durch Migration26 aus den aktiven ClubUnio-
Settings entfernt, vorher privat verschluesselt gesichert. Client-Konfiguration
und fachliche Bexio-Einstellungen bleiben erhalten. Details und Migrationsfolge:
CLUBUNIO-BEXIO-OIDC.md. Benutzer hat Pi-Callback freigegeben; Consent erfolgreich.
PDF6790 lokal/Pi200, eigener Refresh auf Pi und PDF danach ebenfalls erfolgreich.
Regression115/1165 und fokussiert9/96 bestanden. Kein PHPMaker-Eingriff,
keine externen Rechnungs-/Spesenschreibvorgaenge.
API/SaaS unveraendert. SFV-Pagination lokal ebenfalls korrigiert, Bestandteil25.

### Kalender-Webpaket 12.10.2026

Release24: Monats-/Wochenkalender, Mannschafts-Wochenmatrix, kombinierbare
Saison/Team/Art/Status/Suchfilter und geschuetzter manueller ICS-Export.
Direktlinks zu Teamterminen; Ueberschneidungen nur fuer lesbare aktive Termine
derselben TeamSaison. Mehrtagestermine/DST und Einzeleinladungen beruecksichtigt.
Keine Datenmigration; neue Composer-Lock-Abhaengigkeiten fuer standardkonforme
ICS-Dateien. Details/Abnahmegrenzen: CLUBUNIO-CALENDAR.md. Kalenderabo und
Kalenderclient-spezifische Importpruefungen nicht mit manueller ICS-Datei
gleichsetzen. Naechstes Fachpaket: KI-Trainingsplanung im Web. API/SaaS bleiben
geparkt; PHPMaker DEV/PROD und App nicht umgestellt.

### SFV / Gastspieler / Serien 12.10.2026

Webpaket Release23: lesender SFV-Liveimport mit bestaetigter Termin-Uebernahme,
Revisions-/Dublettenschutz; Gastspieler und zusaetzliche Mitgliedereinladungen;
Serien-Massenbearbeitung mit Vorschau, Konfliktschutz und geschuetzten Ausnahmen.
Migration24/25 nur neue ClubUnio-DB. Bestehende17 Serientermine und7 SFV-Referenzen
abgebildet, historische externe Teilnehmer erhalten nur Einzelevent-Zugriff.
Live-SFV-Lesetest63 Spiele, keine realen Teilnahmen/Termine geaendert. Lesende
SFV-Konfiguration aktiviert, andere Integrationen/PHPMaker bleiben unveraendert.
Details und Rechte: CLUBUNIO-TEAM-PLANNING.md. Naechste Abnahme: Trainerrollen,
aktuelle Saison-Zuordnung, bewusstes Absagen/Verlegen aus SFV und Serien-Ausnahmen.
Vor spaeterem Cutover aktuellen Bestand erneut abgleichen, Gaeste/Einladungen,
SFV- und Serien-IDs sowie Zeitsemantik pruefen; keine automatische PROD-Neukopie.
Kalenderexport/KI-Fachfunktionen bleiben offen. API und SaaS bleiben geparkt.
Release23 auf Pi aktiviert, vorher22. HTTPS/Anmeldung/Zugriffsschutz/Scheduler
und echter SFV-Abruf vom Pi erfolgreich geprueft. Fachliche Benutzerabnahme
bleibt vor einem spaeteren Cutover obligatorisch; keine App-Umschaltung.

### Teamplanung-Webpaket 12.10.2026

Teamtermine/Teilnahmen/Anwesenheit und eigene/Eltern-Absenzen sind als neues
Webmodul implementiert. Import nur aus vorhandenem legacy_prod; Migration22/23
auf neuer ClubUnio-DB. Rechte, Fristen, Revisionsschutz, Absenzherkunft und
Wiederherstellung sind zentrale Service-Logik. Wiederholte Einzeltermine,
editierbare Auswahlwerte, Statusfarben und interne Erinnerungen vorhanden.
Details, Tests, Importgrenzen und Cutover-Pruefpunkte: CLUBUNIO-TEAM-PLANNING.md.
SFV-Liveimport, Gastspieler/Einladungen und Serien-Massenbearbeitung sind im
Folgepaket23 ergaenzt. KI bleibt offen. API/SaaS und Cutover nicht im Umfang.

### Gesamtfolge

1. Bestehende Dashboards und Fachlisten uebernehmen, Filter/Lookups und Details
   abgleichen. Zentrale Farblogik fuer gleiche Werte, Text bleibt immer sichtbar.
2. Anlagen, Wettkaempfe, Trainingszeiten, Garderoben und Signage vervollstaendigen.
3. Beitragsberechnung, Rechnungsworkflow/PDF und Bexio-Prozesse vervollstaendigen.
4. Dokumente/Uploads, Anmeldungen, Spesen und Benachrichtigungsablaeufe abschliessen.
5. Teamplanung, Absenzen, SFV und KI-Trainingsplanung fachlich migrieren.
6. Fachliche Abnahme, Fehlerfaelle, Rollen, Betrieb/Backup und Wiederherstellung.
7. Erst danach API-Entscheidung/App-Integration und SaaS-Produktisierung planen.

## Dashboard-Abgleich

Quelle: application/controllers/DashboardController.php sowie Dashboard2.

| Inhalt | Stand ClubUnio |
| --- | --- |
| Mitglieder aktiv, Anmeldungen in Pruefung, Spesen F/Z | Grundkennzahlen vorhanden |
| Mitglieder nach Status | Neu, ohne archivierte Mitglieder |
| Spesen nach Status | Neu, aktive Belege |
| Mitglieder Altersgruppen | Aktive Mitglieder, vollendetes Alter, unbekannte Daten separat |
| Neue Mitglieder 12 Monate / monatlich | Release17: recorded_at aus ErfasstAm, zwoelf Kalendermonate, unbekannte Quelldaten nicht als Import-Neuzugang zaehlen |
| Offene Rechnungen und offener Betrag | Vorhanden, aktive Positionen O, Direktlink zur Fachliste; alte Null/Brutto-Regel beibehalten |
| Rechnungen nach Saison/Status | Release17: Positionsbasierte Aggregation inkl. Ohne Position/Teilweise, aktive Zeilen und Null-Brutto-Regel des Altbestands |
| Spesen Saisonbetraege | Diagramm ueber alle Saisons, nur aktive Belege/Positionen; aktueller Saisonfokus noch offen |
| Tabellen offene Rechnungen/Spesen, Direktaufrufe | Release17: paginierte Dashboardtabellen, offene Positionen bzw. Spesen F/Z, Direktlinks |

## Fachlisten-Abgleich

Quellen: alte Liste*-Modelle/Controller und PMP-Views. Neue Tabellenlisten allein
ersetzen diese fachlichen Auswertungen nicht.

| Bisherige Liste | Stand |
| --- | --- |
| Ehrenmitglieder, Freimitglieder, Supporter, Sponsoren, Spots | Erste lesende Mitgliederlisten mit entsprechenden Flags, Suche und Filtern |
| Offene Beitraege, Spieler ohne Beitrag, zur Bezahlung | Offene Rechnungen und Z-Spesen als Fachlisten; Spieler ohne Beitragsposition filterbar. Beitrags-spezifische Rechnungsauswertung/Exporte offen |
| Mitglieder ohne Team, Passivmitglieder | Paket16: eigene lesende Listen, saisonbezogene Teampruefung, Mitgliederarten konfigurierbar; Details CLUBUNIO-REPORTS.md |
| Spielerliste, Saison-Spielerliste, TeamStaff Saison | Lesende Spieler-/Stafflisten mit Saison/Mannschaft/Suche und eigenen gespeicherten Ansichten |
| Familien mit Kindern, Bankkonten Staff | Paket18: lesende Listen, Filter/persoenliche Ansichten, Dossierlinks; IBAN maskiert, optionaler Staff-Saisonfilter. Vollstaendige Bankadressfelder/Exporte weiterhin offen |
| Spezialfelder/Joins/Exporte aller Altlisten | Abgleich und Abnahme offen |

## Bedienung

- Erste Status-Tabs mit Gesamtzahlen bei Anmeldungen und Spesen vorhanden.
  Zahlen sind mandantenweite Status-Gesamtzahlen vor zusaetzlicher Suche/Filtern.
- Zentrale Farbfunktion fuer Status und erste Lookup-Anzeigen in Filament sowie
  Diagrammen. Gleiche normalisierte Bezeichnung = gleiche Farbe; technische Codes
  aus verschiedenen Objekten werden nicht faelschlich gleichgesetzt.
- Release17: Farben als bearbeitbare Datenbank-Konfiguration; gleiche Bezeichnung
  in Backoffice-Listen/Details/Diagrammen/Tabs/Signage gleich. Portal-Abgleich offen.
- Release14: benannte persoenliche Filteransichten fuer sechs Verwaltungslisten,
  Standard setzen/entfernen, umbenennen/loeschen. Direkte URL-Auswahl hat Vorrang
  vor automatischem Standard; explizites Laden danach moeglich. Suche, Sortierung,
  Filter, Tabs und umschaltbare Spalten enthalten. Keine Rechteerweiterung;
  veraltete Lookups werden abgewiesen. Details: CLUBUNIO-SAVED-VIEWS.md.
- Weitere Listen und Portalansichten erhalten diese Funktion nach Fachabgleich.

## Migrationsjournal

Paket20 lokal/Pi bereitgestellt als20261012-clubora-20, Rollback19. Migration21.
Gesamttest120/1176 und abschliessender Finanztest13/148 bestanden; lokale PDF und
angemeldetes Seitenpanel visuell geprueft, HTTPS/Anmeldeschutz auf Pi geprueft.
Interne Rechnungsbearbeitung/Freigabe/Storno, paginierter
Saison-Beitragslauf und PDF-Vorschau. Bexio-PDF liest unabhaengig vom Aktiv-Schalter;
Live-Zugang aktuell401, gueltige ClubUnio-Zugangsdaten noch erforderlich.
Details/Grenzen: CLUBUNIO-INVOICES.md. Kein produktiver Bexio-Schreibbetrieb.

Paket19 lokal/Pi bereitgestellt: Beitragsvorschau und bestaetigte einzelne Rechnungsentwuerfe,
Familienrabatt/Befreiung, Eindeutigkeitspruefung, Dublettenschutz und Audit.
Release20261012-clubora-19, Rollback18, keine Migration. Gesamttest116/1129;
abschliessend vier fokussierte Tests/31 Assertions bestanden. Angemeldete lokale
Vorschau visuell geprueft, ohne echte Rechnung zu erstellen. HTTPS/Login geprueft.
Details CLUBUNIO-CONTRIBUTIONS-TRAINING.md. Kein abgeschlossener Finanzworkflow:
Massenlauf, Freigabe/PDF/Bexio und die weiteren Fachpakete bleiben offen.

Paket18: Familien-/Bankkontenberichte gemaess bisherigen PMP-Views, keine neue
Migration. Technische Beschreibung und verbleibende Detailabweichungen unter
CLUBUNIO-REPORTS.md. Gesamtauftrag und groessere Fachmodule weiterhin offen.
Lokal/Pi bereitgestellt als 20261012-clubora-18, Rollback17. 112 Tests/1098
Assertions bestanden; HTTPS-Anmeldung und Schutzredirects geprueft. Visuelle
Abnahme nach Anmeldung offen. PHPMaker DEV/PROD unveraendert.

Release17: Anlagen/Wettkaempfe/Garderoben/Signage-Meldungen und interne Vorschau,
zentrale editierbare Farben sowie weitere Original-Dashboards. Migration18-20.
Lokal/Pi bereitgestellt, 107 Tests/1019 Assertions und abschliessender Filtertest
(23 Assertions) bestanden. HTTPS/Assets/Anmeldeschutz geprueft; visuelle Abnahme
nach erneuter Anmeldung offen. Current20261012-clubora-17, Rollback16.
Details und Grenzen: CLUBUNIO-FACILITIES.md. Live-SFV, oeffentliche Signage,
Busreservierungsworkflow, Portal-Farben und weitere Fachpakete NICHT abgeschlossen.
Gesamtauftrag bleibt offen; API/SaaS weiterhin bewusst ausgenommen.

Release16 lokal/Pi: Mitglieder ohne Mannschaft und Passivmitglieder, Migration17
fuer konfigurierbare Mitgliederarten-Merkmale. Saisonbezogene Suche/Filter und
persoenliche Ansichten. 97 Tests / 922 Assertions bestanden, HTTP/Deployment
geprueft. Visuelle Fachabnahme nach erneuter Anmeldung noch offen. Kein PHPMaker-
Eingriff; Familien-/Banklisten, restliche Dashboards und weitere Fachmodule offen.

Release12: Migration000015, Preise/Regeln/Trainingszeiten importiert, Grunddashboard.
Release13 lokal/Pi: erste Mitgliederlisten, zwei Statusdiagramme, zentrale Farben
und Status-Tabs. 86 Tests/799 Assertions bestanden. Keine DB-Migration,
keine Nachrichtenzustellung oder externen Writes.
Weitere Details/Tests/Serverstand werden in PI.md fortgeschrieben.

12.10.2026: Gesamtauftrag fuer alle Fachmodule bestaetigt, API und SaaS explizit
ausgenommen. Weiterarbeit ohne erneute Einzel-Freigabe der Entwicklung; kein
Produktiv-Cutover und keine Freigabe echten externen Nachrichten-/Zahlungsversands.
Fachlisten/Dashboard-Paket siehe CLUBUNIO-REPORTS.md. Der Gesamtauftrag bleibt offen.
Release15 auf Pi bereitgestellt: 93 Tests / 884 Assertions bestanden,
HTTPS und Konfiguration geprueft. Keine DB-Migration. Visuelle Browserabnahme
wegen abgelaufener Sitzung/Browsersteuerungsfehler noch offen.

Release14 lokal/Pi: persoenliche Ansichten in sechs Listen; Migration000016
auf neuer gemeinsamer DB. 90 Tests/857 Assertions bestanden. Browserdialog,
HTTPS und Konfiguration geprueft. Keine Aenderung am laufenden PHPMaker-System.
# 14.10.2026: Sandbox-Web-Testdaten

Lokale Fixture-Registrierung (Migration42), drei synthetische Mitglieder und
Rechnung7640 mit Position/PDF sind angelegt. Keine echten Datensaetze umgeschrieben.
Importierte Bexio-IDs sind im Sandbox-PDF und Statusabgleich gesperrt, um zufaellige
ID-Kollisionen auszuschliessen. Details: CLUBUNIO-BEXIO-SANDBOX.md.
Weiter offen: native Entwuerfe und geschuetzter Live-Webversand, Artikel/Bills/
Anhaenge, Zahlungs-/QR-Faelle und Timeout-Reconciliation. Kein Abschluss des
Bexio-/Finanzpakets. API/SaaS/Betrieb und PHPMaker DEV/PROD unveraendert.

# 14.10.2026: Echter Sandbox-Webversand

Admin-Testbereich mit nativen Testbelegen, echtem Invoice-/Bill-/PDF-Transport,
Journal und gesperrter Wiederholung bei unbekanntem Ausgang. Migration43 additiv.
Rechnung7688/Bexio2 live erfolgreich. Spesen1083 vor Bill-Anlage bei PDF-Upload403
gestoppt: Scope file und neue Benutzerautorisierung noetig. Danach verifizierte
Neuverknuepfung und kontrollierte Freigabe des eindeutig abgelehnten Uploads.
36 Tests/239 Assertions erfolgreich. Details CLUBUNIO-BEXIO-SANDBOX.md.
Spesen-Liveabnahme bleibt offen; kein allgemeiner Echtdatenversand oder Zahlung.
DEV/PROD und API/SaaS/Betrieb unveraendert.

# Sandbox-Nachtest 14.10.2026

Release45: Benutzer-Reconnect mit file-Scope abgeschlossen. Testspesen1083 samt
synthetischem PDF in SVS Api Test angelegt und verified/accepted; Rechnung7688
ebenfalls accepted. Rueckpruefung auf reales amount_calc-Feld korrigiert, CHF
und automatische Berechnung geprueft. Vorhandenen Beleg nur abgeglichen, kein
Duplikat. Gezielte Regression9/42, Pi-Patch/FPM-Reload erledigt. Keine weitere
DB-Migration, keine Zahlung/Mail, PHPMaker DEV/PROD unveraendert. Allgemeiner
Versand/Steuerfaelle/Zahlung bleiben ausserhalb dieser Sandbox-Testfreigabe.

# Allgemeiner Sandbox-Webversand 14.10.2026, Release46

Migration44: mehrere eindeutige Sandbox-Testbelege je Art, Verknuepfung zur
allgemeinen Versandpruefung und persistierte Datei-UUIDs. Admin-Webaktion nutzt
normale Payload-/Freigabe-/Azure-Pruefungen. Statusrueckfluss nur fuer registrierte
accepted-Testbelege, Originalimporte bleiben gesperrt. Rechnung7765/Bexio3 und
Spesen1114/Bill ebb98b7c-ac67-4ce0-bda4-b0e598c8a708 live erfolgreich (jeCHF20).
Rechnungsstatus7 uebernommen, SpesenDRAFT korrekt ohne Bezahlt-Markierung.
PAID wird automatisiert getestet, keine echte Zahlung. Details/Restpunkte und
Ruecksprunggrenzen: CLUBUNIO-GENERAL-SANDBOX.md. PHPMaker DEV/PROD,API,SaaS,Betrieb
unveraendert; keine Produktivfreigabe behaupten.

# ClubUnio: Namenswechsel 14.10.2026

Release50: technische Code-/Datei-/Konfigurationsnamen auf ClubUnio umgestellt.
Kein Schemawechsel und keine neue Datenmigration. Physische DB, Callback,
historische Identitaeten und Betriebsnamen bleiben getrennte Folgeschritte.
Nach Guardwechsel neu anmelden. Pruefgrenzen: CLUBUNIO-REBRAND.md.

Produktname und Verwaltungszugang werden auf ClubUnio umgestellt.
Keine Datenmigration; technische Kompatibilitaetskennungen bleiben erhalten.
Details und Grenzen: [CLUBUNIO-REBRAND.md](CLUBUNIO-REBRAND.md).

