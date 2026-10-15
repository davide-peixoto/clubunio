# Pi staging: dev.clubunio.ch

Release68 aktiv: `20261015-clubunio-68-workspace`, Ruecksprung67.
Bus und Teamplanung in gemeinsamer Filament-Navigation, saubere /bus- und
/planning-URLs, Sitzungsmandant samt Formular-/Livewire-Revisionsschutz.
Keine DB-Migration. 51 Tests/485 Assertions mit aktivem Routencache erfolgreich.
Zusaetzlicher Gast-/Deep-Link-Test1/14 erfolgreich; Planungslinks gehen ohne
Anmeldung auf /login statt auf den getrennten Portal-Login (live302 geprueft).
Angemeldete Desktoppruefung: Busliste, Busdetail /bus/16, Teamplanung und
Weiterleitung des alten Planungs-Lesezeichens erfolgreich. Mobile Browser-
Pruefung wegen Verbindungsabbruch der Browsersteuerung nicht abgeschlossen.
Config-/Routen-/Viewcache auf Pi erneuert, FPM geladen, Route-Checker erfolgreich.
Release67 als Basis komplett erhalten, nur eigenes Workspace-Delta hinzugefuegt.
Details/Migrationshinweise: CLUBUNIO-WORKSPACE.md. PHPMaker DEV/PROD unveraendert.

Release67 aktiv: `20261015-clubunio-67-customer-credits`, Ruecksprung66.
Eigene additive Migration66: Kundenguthaben/Verrechnungsjournal, Dossierwerte.
55 Tests392 Assertions plus abschliessend8/112 (ueberlappend) erfolgreich.
PMP-Metadatencheck104 Objekte OK, angemeldete neue Webliste auf Pi geprueft,
Servicehashes lokal/Pi identisch.195 Settings/46 Vorlagen/Outbound aus.
RE-00006/ClubUnio-Korrektur18 weiterhin Bexio-Gutschrift0: manuelle Anlage und
Live-Teilkorrektur nicht abgeschlossen; keine echten Bankzahlungen oder Mails.
Paket aus Basis66 plus explizitem Finanzdelta gebaut; nebenlaeufige lokale
Workspace-/Navigationsaenderungen bewusst nicht deployed/committed.
Details und Restgrenzen:CLUBUNIO-CUSTOMER-CREDITS.md.

Release66 aktiv: `20261015-clubunio-66-signage-design`, Ruecksprung65.
Vereinsdesign und kompakte TV-Ansicht, eigene additive Migration65 angewendet.
27 Tests/158 Assertions erfolgreich. Oeffentliche Signage/CSS HTTP200,
195 Settings/46 Vorlagen, Outbound aus. Layout mit synthetischen40 Spielen
bei1024x768 sowie Desktop/Mobil geprueft, automatischer Seitenwechsel bestaetigt.
Details und Bedienung: CLUBUNIO-SIGNAGE-DESIGN.md.

Release65 aktiv: `20261015-clubunio-65-public-web`, Ruecksprung64.
Signage-Alias repariert, oeffentliche Signage/Testanmeldung und Migration64.
Zugaenge/Schutz/Abnahmegrenzen: CLUBUNIO-PUBLIC-WEB.md. Outbound aus.
Regression29/168 und finaler Public-Web-Nachlauf12/77 (ueberlappend) erfolgreich,
letzter Lauf mit aktivem Routen-Cache. Beide oeffentlichen Seiten/CSS200,
POST ohne CSRF419. Quellhashes lokal/Pi gleich,191 Settings/46 Vorlagen geprueft.
69 Bestandsanmeldungen unveraendert, synthetische Testdaten zurueckgerollt.
Azure-Uploads gemockt; keine manuelle Browserabnahme (Chrome blockiert).

Release64 aktiv: `20261015-clubunio-64-invoice-transfer`, Ruecksprung63.
Direkter Sandbox-Rechnungsbutton, separate Testkontakte und additive Migration63.
Live synthetisch RE-00010/CHF1.00/Entwurf7, PDF-Guard erfolgreich.
Details: CLUBUNIO-INVOICE-TRANSFER.md. Outbound bleibt aus, keine Bankzahlung.
Regression32/246 und68/311 (teilweise ueberlappend), finaler Nachlauf8/45
erfolgreich. Pi-Lesetest/Quellhashes passen, Login200/geschuetzte Rechnung302,
189 Settings/46 Vorlagen geprueft. Keine manuelle Browserabnahme: Chrome-
Erweiterungsfenster blockiert Automation; Webaktion automatisiert getestet.

Release63: `20261015-clubunio-63-credit-allocation`, Ruecksprung62.
Konkreter GS-00004-Rueckfluss auf RE-00009, Webauswahl und Provider-ID-Pruefung.
Keine Migration, Outbound unveraendert aus. Details: CLUBUNIO-CREDIT-ALLOCATION.md.
59 Tests/394 Assertions plus ueberlappender Doppelschutz-Nachlauf1/2 erfolgreich.
Live-Sandboxwerte auf Pi lesend bestaetigt, Quellhashes identisch,
189 verschluesselte Settings/46 Vorlagen. Keine echten Bank-/Versandaktionen.

Release62: `20261015-clubunio-62-material-receipts`, Ruecksprung61.
Materialuebergaben/Empfangsbestaetigung und historische Quittung, Migration61/62.
Weitere18 private archivierte Altplaene, elf Zuordnungen offen. Outbound aus.
Details: CLUBUNIO-MATERIAL-RECEIPTS.md. Bexio-Gutschriftrueckfluss noch ausstehend.
53 Tests/496 Assertions erfolgreich, angemeldete neue Listen/Erfassungsmaske
geprueft. Beide historischen Azure-PDFs auf Pi200/Hash passend, keine Testbelege
oder Bestaetigungen gespeichert. 189 Settings/46 Vorlagen, Outbound aus.

Release61: `20261015-clubunio-61-announcements`, Ruecksprung60.
Interne Web-Ankuendigungen/Rundnachrichten, additive eigene Migration60.
Keine externe Zustellung, keine echten Nachrichten aus Tests. Branding60 bleibt.
Details: CLUBUNIO-ANNOUNCEMENTS.md.

Release60: `20261015-clubunio-60-blue-brand`, Ruecksprung59.
Blaue SVG-Identitaet, Login/Navigation/Portal/Favicon, versioniertes CSS.
Keine DB-/ENV-Aenderung. Details: CLUBUNIO-BLUE-BRAND.md.

Release59: `20261015-clubunio-59-member-changes`, Ruecksprung58.
Eigene additive Migration59, Mitgliederantraege und allgemeine Dokumentpflege.
Private Azure-Praefixe, explizite Portal-Freigabe, interne Notifications;
kein Versand an echte Empfaenger. Details: CLUBUNIO-MEMBER-CHANGES-DOCUMENTS.md.

Release58: `20261015-clubunio-58-pmp-parity`, Ruecksprung57.
Eigene Migration58: Revisionsfelder fuer Dossier-/Stammdatenpflege.
PMP-Abgleich/Abgrenzung: CLUBUNIO-PMP-ACCEPTANCE.md und CLUBUNIO-PMP-COVERAGE.md.

Release57: `20261015-clubunio-57-registration-completion`, Ruecksprung56.
Eigene additive Migration57. Mitgliederanmeldungen/Wiedereintritt, private
ClubUnio-Azure-Uploads und gezielter Testmailversand; externe Pushzustellung aus.
Details: CLUBUNIO-REGISTRATION-COMPLETION.md. PHPMaker DEV/PROD unveraendert.

Release56: `20261015-clubunio-56-training-keybox-instagram`, Ruecksprung55.
Eigene additive Migrationen55/56. Keine echte Schluesselboxausgabe, kein Versand
und keine Bankzahlung. Details: CLUBUNIO-TRAINING-KEYBOX-INSTAGRAM.md.

## Verbindlicher Releaseabschluss

Nach jedem Release Aenderungen und Secret-Ausschluesse pruefen, committen
und mit origin im ClubUnio-Repository synchronisieren. Arbeitsbaum und
Remote-Abgleich abschliessend kontrollieren; Pushfehler offen melden.
Private ENV-Dateien, Uploads, Logs und Testartefakte bleiben ausgeschlossen.

## Fachpaket55: Gutschriften und Rueckerstattungen

Aktiv20261014-clubunio-55-financial-corrections auf Basis54-tenant-navigation.
Eigene Migration52/53/54 angewendet, keine PHPMaker-DEV-/PROD-Aenderung.
Storno-Gutschrift33 bestaetigt, steuerhaltige Teilgutschrift10 lokal freigegeben;
manuelle Bexio-Anlage noch offen. Live Bexio7 Eingangslista15/Total10/Guthaben5,
manueller Rueckerstattungsnachweis5 ausdruecklich simuliert, keine Bankzahlung.
Details/Abnahmegrenzen: CLUBUNIO-CREDITS-REFUNDS.md.
Regression62Tests/440Assertions erfolgreich mit gecachten Routen, eigener
DB/Rollback-Fixtures. Nachlauf15/77 einschliesslich expliziter Zeitoffsets.
Angemeldete Listen/Dossiers und Gutschriftsdialog Desktop/390px geprueft.
Providerwerte/Journal idempotent, 189 Settings/42 Vorlagen/Outbound aus
verifiziert. Kurze URLs/Tenant-Middleware auf Pi geprueft, Ruecksprung54.
Zeitoffsets nur neue Modelle/Snapshotwrites; drei synthetische Belege und
deren Finanzsnapshot auditiert korrigiert. Kein globaler Altbestand-Update.

## Release54: mandantenlose Verwaltungs-URLs

https://dev.clubunio.ch/login und / als Dashboard; /members, /invoices usw.
Mandant serverseitig aus eigener Mitgliedschaft, Wechsel ueber Dropdown.
Keine DB/ENV-Aenderung; Ruecksprung53. Details/Testgrenzen:
CLUBUNIO-TENANT-NAVIGATION.md.

14.10.2026 Routingkorrektur: Assetordner public/clubunio darf Panelroute
nicht verdecken. Nginx try_files ohne Verzeichnispruefung; beide neuen
Domainwurzeln erreichen jetzt HTTPS-Login200 ohne internen Port. CSS200.
Details CLUBUNIO-DOMAINS.md. PHP-Release53 unveraendert.

## Zentraler Bexio-Callback Release53-bexio-domain

Hauptdomain https://dev.clubunio.ch, APP_URL entsprechend umgestellt.
Verbindung /integrations/bexio, Callback /integrations/bexio/callback.
Alte Routen kompatibel; Filament-Pfade unveraendert. Keine DB-Migration.
Details/Abnahmegrenzen: CLUBUNIO-BEXIO-DOMAIN.md.

## Domainvorbereitung 14.10.2026

dev.clubunio.ch am Pi zugelassen; dev.clubunio.com leitet auf .ch weiter.
HTTPS ueber Proxy mit DNS-Override erfolgreich (200/308), alter Login200.
Oeffentlich beide dev-Namen noch NXDOMAIN. APP_URL/Bexio und Release52
unveraendert; Mandantenpfade noch nicht entfernt. Details/weitere Schritte:
[CLUBUNIO-DOMAINS.md](CLUBUNIO-DOMAINS.md).

## Freie Rechnungen und Steuern Release52, 14.10.2026

Aktiv:20261014-clubunio-52, Ruecksprung51 erhalten. Migration50/51 bereits
auf der eigenen gemeinsamen PostgreSQL-DB ausgefuehrt. Manuelle Kategorien,
Steuersnapshots und CHF-Rundung inklusive Web/Portal/PDF/Finanzabgleich.
Live Bexio6 CHF40.25 bisBezahlt/Rest0, Bexio7/8/9 Draft-Pruefungen bestanden.
Regression41/324 und fokussierte Nachlaeufe erfolgreich (ueberlappend).
Webdialog in angemeldeter Sitzung auf Desktop und Mobilgeraet geprueft.
Keine realen Forderungen, Bankzahlungen oder Emails; PHPMaker DEV/PROD unveraendert.
Details CLUBUNIO-GENERAL-INVOICES.md.

## Artikel-/Steuerkatalog Release51, 14.10.2026

Aktiv: 20261014-clubunio-51, Ruecksprung50 erhalten. Quelle beruecksichtigt
fertige technische Umbenennung. Eigene Migration48/49 bereits angewendet.
Live Testartikel1 und Rechnung8371/Bexio5 CHF10 accepted, idempotent.
Keine echten Zahlungen/Emails. Steuerpflichtiger Versand bleibt gesperrt.
Details/Testgrenzen: CLUBUNIO-BEXIO-CATALOG.md.
Deployment mit Caches/Autoload,189 verschluesselte Settings/42 Vorlagen und
Outbound aus verifiziert. Login200/Katalog unangemeldet302. Server-CLI
bestaetigt dieselben Fixture-IDs, Quellhashes fuer Katalogservice/Fixture/
Resource stimmen lokal/Pi. Angemeldete visuelle Abnahme steht noch aus.
Finaler Nachlauf4 Tests/51 Assertions erfolgreich (Eltern-/Generalversand,
Timeout-Artikel und lokale Rechnungsfreigabe).

## Technische Umbenennung Release50, 14.10.2026

Aktiv: 20261014-clubunio-50; Ruecksprung49 erhalten. Namespace/Guards/Panel/
interne DB-Verbindung/Quelldateien/CLI jetzt ClubUnio. Physische DB/Schema,
Callback und bestehende Dokument-URLs unveraendert. Keine Migration.
Autoload/Caches neu,189 Settings/42 Vorlagen geprueft, HTTPS200 und alte
Login-Weiterleitung geprueft. Details: CLUBUNIO-REBRAND.md.

## PDF-Vorschau, Release49, 14.10.2026

Aktiv releases/20261014-clubunio-49, Ruecksprung Release48 erhalten.
Geschuetzte PDF.js-Vorschau mit lokalen Assets im Rechnungs-SlideOver.
Keine DB-Migration. Artikel/Steuern nur vorbereitet, Versand unveraendert.
Pruefung und offene Abnahme: CLUBUNIO-PDF-PREVIEW.md.
Regression 19 Tests/145 Assertions bestanden. Login-Redirect302, lokale
Renderer-/Worker-Assets200 mit application/javascript verifiziert.

## Finanzabgleich und Testzahlungen, Release48, 14.10.2026

Aktiv releases/20261014-clubunio-48, Ruecksprung clubunio-brand erhalten.
Neue Benennung und /clubunio-Zugang aus vorherigem Branding-Release uebernommen.

Rechnung7765/Bexio3 live: zwei Buchungen10/10, Status8->16->9, Rest10->0,
Zahlungs-IDs5/7, beide accepted. Spesen1114/Bill ebb98b7c-ac67-4ce0-bda4-b0e598c8a708:
BOOKED -> MANUAL -> PAID, Zahlung81261068-a919-413b-ac41-2aa89e81fb2b,
Rest0, lokal B und ein expense.paid-Ereignis. Zahlungsdetail MANUAL/PAID,
Datum/Konto/Notiz/Betrag stimmen, banking_payment_id fehlt. Keine Bankuebermittlung.
Eltern-Storno7950 mit Gutschrift33/Rest0 abgeglichen.
Migration47 auf gemeinsamer eigener DB: Finanzsnapshot und Aktionsjournal.
Offene Listen/Dashboard/CSV nutzen geprueften Restbetrag, alte Imports bleiben
mit Positionsregeln kompatibel. Keine neuen Parameter/Jobs, Outbound aus.
Details und verbleibende Grenzen: CLUBUNIO-FINANCIAL-SANDBOX.md.
Gezielte Regression26/179,5/55,3/33 (ueberlappende Laeufe), Dashboard2/21
erfolgreich. Finaler Nachlauf8/90 mit lokaler Freigabe/Positionsschutz und
neuem Verwaltungszugang bestanden. Dossier-Nachlauf2/29 mit Rechnung/Spesen
HTTP200 und sichtbaren Finanzfeldern bestanden. Quellhashes Finanzaktion/Abgleich/Dashboard
lokal/Pi identisch. Servernachtest: Rechnung9/Rest0, SpesenB/Rest0/1Paid-Ereignis,
alle4 Finanzaktionen accepted; MANUAL/PAID und keine banking_payment_id.
189 verschluesselte Einstellungen/42 Vorlagen verifiziert, Outbound deaktiviert.
Browser-Anmeldung nach Branding abgelaufen; angemeldete visuelle Nachpruefung
steht noch aus. Webaktionen/Ansichten automatisiert mit Livewire geprueft.
PHPMaker DEV/PROD,API,SaaS,Betrieb unveraendert; ClubUnio-Branding erhalten.


## Rechnungsprozess Sandbox, Release47, 14.10.2026

Aktiv releases/20261014-clubora-47, Ruecksprung46 erhalten. Migration45/46
auf eigener gemeinsamer ClubUnio-DB angewendet. Eltern-Testrechnung7950/Bexio4,
CHF33 nach7 Rabatt, Freigabe8 und Stornierung19 live erfolgreich. Positionsstatus
und Workflow cancelled. QR-PDF vor Storno200/106120 Bytes visuell und per
QR-Decoder geprueft; nach Storno Pi200/109159 Bytes. Browser-PDF-Request im
Nginx-Log200; Chrome-Vorschaufenster blieb in der Automationsansicht weiss,
daher eingebettete Darstellung dort nicht visuell bestaetigt. Das PDF selbst
ist lesbar. Sandbox-Seite/Rechnungsdossier angemeldet auf Pi geprueft.
Regression75 Tests/343 Assertions, anschliessend4 gezielte Lebenszyklus-Tests
mit35 Assertions (ueberlappend) nach Workflow-Nachbesserung erfolgreich.
Outbound aus;189 verschluesselte Einstellungen/42 Vorlagen geprueft.
Keine neuen Jobs, keine Zahlungen/Mails. PHPMaker DEV/PROD,API,SaaS,Betrieb ausgenommen.
Details: CLUBUNIO-INVOICE-SANDBOX.md; Migrationsplan: CLUBUNIO-ROADMAP.md.


## Allgemeiner Sandbox-Webversand, Release46, 14.10.2026

Aktiv releases/20261014-clubora-46. Migration44 auf eigener gemeinsamer DB
angewendet. Versandpruefung mit expliziter Admin-Sandboxaktion, mehrere Belege,
allgemeine Positions-/Rabatt-/Azure-Pruefung, Statusabgleich auf accepted-Fixtures
begrenzt. Rechnung7765 -> Bexio3, Spesen1114 -> Bill
ebb98b7c-ac67-4ce0-bda4-b0e598c8a708, jeCHF20/2Positionen, beide accepted.
Live Status7 auf Rechnung/Positionen uebernommen; DRAFT bei Spesen unveraendert.
Pi-Nachtest Checks180/181 beide unveraendert. FPM/Scheduler aktiv, Outbound aus,
189 verschluesselte Einstellungen und42 Vorlagen geprueft. Keine neuen Jobs.
Regression31 Tests/183 Assertions plus zweiter Lauf59 Tests/220 Assertions
(ueberlappende Suites, inklusive neuem Livewire-/Azure-PDF-/PAID-Test).
Angemeldete Desktopansichten Sandbox/Versand/Abgleich und mobile Versandansicht
390px geprueft. Tabellenabstaende/Live-Ergebnislink nachgebessert, Viewcache neu.
Archiv46 anschliessend mit finalen Quellen/Dokumenten aktualisiert.
Rollback45 vorhanden, aber kein Weiterbetrieb des Versands mit dessen alter
Ein-Beleg-Annahme. Auftraege zuerst pruefen, keine automatische DB-Rueckmigration.
Live-PAID bleibt separate Fachabnahme; automatisiert getestet, keine Zahlung.
Details/Migrationsgrenzen: CLUBUNIO-GENERAL-SANDBOX.md und CLUBUNIO-ROADMAP.md.
PHPMaker DEV/PROD,API,SaaS und Betrieb unveraendert.

## Release45 Nachtest nach OAuth-Anmeldung, 14.10.2026

Scope-Blocker behoben, neue Sandbox-Verbindung geprueft. Spesen1083 mit PDF
erfolgreich angelegt (beide POST201) und lesend verifiziert; accepted.
Bill-UUID7b605483-9abb-4e9f-a5d8-86b7348886aa. Korrektur Rueckpruefung:
amount_calc statt gross, CHF und manual_amount=false. Service auf aktuellem
Release45 gezielt ersetzt, php8.3-fpm reload erfolgreich; Archivstand enthaelt
diesen Nachpatch noch nicht. Regression9/42 erfolgreich. Keine erneute Bill-Anlage,
keine Zahlungen, kein allgemeiner Versand. PHPMaker DEV/PROD unberuehrt.

## Sandbox-Webversand 14.10.2026, Release45

Aktiv: releases/20261014-clubora-45, Ruecksprung44 erhalten. Migration43 auf
eigener gemeinsamer ClubUnio-DB angewendet; keine PHPMaker-Aenderungen.
Adminseite Finanzen > Bexio-Sandbox: feste Testbelege, bestaetigter Liveversand,
Schrittjournal und lesender Abgleich. Allgemeiner Versand bleibt Simulation.
Rechnung7688 -> Sandbox2 erfolgreich (201, CHF25, Entwurf); Spesen1083 noch
blockiert: PDF-Upload403 wegen fehlendem file-Scope, keine Bill angelegt.
file ist inzwischen konfiguriert; Benutzer muss erneut SVS Api Test verbinden,
dann Verbindung pruefen, Upload wieder freigeben und explizit senden.
Bis dahin einschliesslich PDF keine Nutzung der alten OAuth-Verbindung.
36 Tests/239 Assertions erfolgreich; Login200, anonymer Seitenzugriff302,
FPM/Scheduler aktiv. Angemeldete Desktopansicht auf Pi visuell geprueft.
Tabellenabstaende nach Archiv-Deployment gezielt in der Blade-Datei korrigiert
und Viewcache erneuert; urspruengliches Release45-Archiv enthaelt diesen kleinen
Darstellungspatch noch nicht. Aktuelle lokale Quelle und Pi-View stimmen ueberein.
Ruecksprung entfernt keine Testbelege/Migrationen und ersetzt keine OAuth-Neuanmeldung.
Details und Resttest in CLUBUNIO-BEXIO-SANDBOX.md, Migrationsplan CLUBUNIO-ROADMAP.md.
PHPMaker DEV/PROD unveraendert; Outbound aus, keine Zahlungen/Mitgliedernachrichten.

## Bexio-Sandbox-Verbindung 14.10.2026

Release43 unveraendert; Diagnosewerkzeug clubunio-bexio-sandbox-inspect.php auf Pi
ergaenzt. Sandbox SVS Api Test: Firmenprofil/Kontakte/Rechnungen HTTP200.
OpenID-Refresh auf Pi erfolgreich. Lokale Grunddatenanlage und PDF-Test separat
in CLUBUNIO-BEXIO-SANDBOX.md dokumentiert. Kein neuer Web-Schreibadapter,
keine Migration und kein allgemeiner Versand aktiviert.

## Altlisten-Abgleich 14.10.2026

Release20261014-clubora-43: Schluesselliste und Spots-Versandliste; Details in
CLUBUNIO-LEGACY-REPORTS.md. Migration40/41 eigene gemeinsame ClubUnio-DB angewendet.
20 von20 importierten Schluesseln vorhanden,509 Einzelmitglieder/88 Familien im
aktuellen Spots-Bericht. Keine PHPMaker-Aenderung, kein realer Versand/Hardwareaufruf.
12 gezielte Tests/190 Assertions erfolgreich: Berichte,Filter/CSV,gespeicherte
Ansichten,Statuspflege,Berechtigungen. Blade-Kompilierung erfolgreich.
Angemeldete visuelle Benutzerabnahme weiter offen. Trainingsplaene nur strukturell
geprueft, nicht importiert. Bexio unveraendert bis zur Sandbox.
Release43 aktiv, Ruecksprung42 erhalten. Login HTTP200, beide neuen Listen
HTTP302 ohne Anmeldung; PHP-FPM/Scheduler aktiv.189 verschluesselte Einstellungen
und42 Nachrichtenvorlagen geprueft, Outbound weiterhin aus.

## Listen, Signage, Bus und KI-Training 14.10.2026

Release20261014-clubora-42: Web-Ergaenzungen, Migration000039 auf gemeinsamer
eigener ClubUnio-DB angewendet. Archivfeld Training und sechs Bankadressfelder,
2838 Bankstammsaetze mit importiertem Domizil. Keine PHPMaker-DB veraendert.
Details, Abnahmematrix und verbleibende Punkte: CLUBUNIO-WEB-COMPLETION.md.
Migrationspfad/Ruecksprunggrenzen: CLUBUNIO-ROADMAP.md und Abschlussdokument.
14 historische Busreservationen weiter fachlich zuzuordnen. Keine Hardwarecodes,
kein Instagram-Token aktiviert, keine echten KI-/Bexio-Aufrufe in Tests.
Listenregression29 Tests/376 Assertions erfolgreich; ergaenzter Mitgliederlisten-
Exporttest1/15 erfolgreich. Trainings-PDF alle drei Seiten visuell geprueft.
Browser-Maskenabnahme noch offen: Chrome-Verbindung nicht nutzbar, im neuen
Codex-Browser fehlt die Anmeldung. Kein visueller Gesamt-Abnahmetest.
Finale Training-/Bus-/Signage-Regression33 Tests/225 Assertions erfolgreich.
Release42 aktiv; Ruecksprung41 erhalten. Login HTTP200, Bus/Training/Signage
HTTP302 ohne Anmeldung; PHP-FPM/Scheduler aktiv.189 verschluesselte Einstellungen
und42 Vorlagen geprueft, Outbound aus. Keine Bexio-Aenderung bis Sandbox.

## Bexio-Testablauf und Finanzkorrekturen 13.10.2026

Release20261013-clubora-41 aktiv, Ruecksprung40 erhalten. Migration000038 auf gemeinsamer
eigener ClubUnio-DB angewendet. Kein Finanz-Bestandsdatenumbau/keine neuen ini.
Transport ausschliesslich MockHandler; keine echten Bexio-Schreibaufrufe.
Finanzen: Position wiederherstellen, Storno archivieren/wiederherstellen,
Freigabe-Konsistenzpruefung. Details und Grenzen in CLUBUNIO-BEXIO-DISPATCH.md
und CLUBUNIO-INVOICES.md, Migrationspfad in CLUBUNIO-ROADMAP.md.

16 Rechnungstests/195 Assertions erfolgreich inklusive Livewire-Aktionen,
PDF und frischer Berechtigung. Blade-Kompilierung erfolgreich.
Visuelle Browserpruefung erneut durch CDP-Timeout bei
Emulation.setFocusEmulationEnabled blockiert. Kein visueller Gesamttest.
47 Transport-/Dispatchtests mit164 Assertions erfolgreich. Zusammen mit den
Rechnungstests63 Tests/359 Assertions; kein Gesamttest und kein echter Versand.
Deployment erfolgreich: Login200,geschuetzte Bexio-/Rechnungsseiten302 ohne
Anmeldung,PHP-FPM/Scheduler aktiv.189 verschluesselte Einstellungen und42
Vorlagen geprueft; Outbound weiterhin aus.

## Bexio-Spesenanhaenge 13.10.2026

Release20261013-clubora-40 aktiv, Ruecksprung39 erhalten.
Migration000037 auf gemeinsamer eigener ClubUnio-DB angewendet. Nur additive
Pruefstatistik, keine Bestandsdateien veraendert. Azure-Reads vor DB-Locks,
Fingerprint-Version4, Fehlermeldungen ohne SAS/Dateiinhalt. Echter Versand aus.
Visuelle Browserpruefung wegen CDP-Verbindungs-Timeout nicht moeglich.
Details/Grenzen und offene Transportarbeit: CLUBUNIO-BEXIO-DISPATCH.md.
45 gezielte Tests/162 Assertions erfolgreich (Dispatch, Azure-Reader,
Anhang-Grenzfaelle). Kein Gesamttest, keine echten Azure-Dateien im Test gelesen,
kein Cloud-Schreibtest. Blade-Kompilierung erfolgreich.
Login HTTP200,Versandpruefung302 ohne Anmeldung,FPM/Scheduler aktiv.
189 verschluesselte Einstellungen/42 Vorlagen geprueft,Versand aus.

## Bexio-Rechnungsempfaenger 13.10.2026

Release20261013-clubora-39 aktiv, Ruecksprung38 erhalten.
Migration000036 auf gemeinsamer eigener ClubUnio-DB angewendet; neue Felder
ohne Bestandszuordnung. 31 gezielte Tests/99 Assertions erfolgreich,
inklusive Filament-Aktion und Rollen-/Mandanten-/Revisionsschutz.
Kein Gesamttest oder echter Bexio-Schreibtest. Visuelle Browserpruefung wegen
zweier CDP-Verbindungs-Timeouts nicht moeglich. Versand weiterhin gesperrt.
Parameter unveraendert. Doku/Grenzen CLUBUNIO-BEXIO-DISPATCH.md.
Login200, Mitglieder302 ohne Anmeldung, FPM/Scheduler aktiv. 189
verschluesselte Einstellungen und42 Vorlagen geprueft, Versand aus.

## Bexio-Payload-Aufbereitung 13.10.2026

Release20261013-clubora-38 aktiv, Ruecksprung37a erhalten. Migration000035 auf
gemeinsamer eigener ClubUnio-DB angewendet; nur fehlende leere Parameter.
25 Tests/141 Assertions plus4 Grenzfalltests/8 Assertions erfolgreich.
Kein Gesamttest, keine visuelle Aenderung, kein echter Bexio-Schreibtest.
Provider-Anfragen werden ausschliesslich im Speicher vorbereitet; Parameter,
Adressdaten und Mapping im Fingerprint. Echtversand weiterhin gesperrt.
Grenzen/Parameterpflege siehe CLUBUNIO-BEXIO-DISPATCH.md. PHPMaker unveraendert.
Login HTTP200, geschuetzte Versandpruefung302 ohne Anmeldung, FPM/Scheduler aktiv.
189 verschluesselte Einstellungen/42 Vorlagen geprueft, Versand aus. Fuenf
fehlende Parameter ergaenzt; bestehende Werte unveraendert. Keine echte
Dokumentanlage, kein angemeldeter visueller Pi-Test dieses Pakets.

## Bexio-Versandpruefung 13.10.2026

Current:20261013-clubora-37a. Migration000034 auf gemeinsamer eigener ClubUnio-DB.
Release37 hatte fehlende Seitenregistrierung;37a korrigiert dies. Fuer
Ruecksprung auf vorherigen Fachstand Release36 verwenden (bleibt erhalten).
21 Tests/120 Assertions erfolgreich,anschliessend10/37 inklusive echtem
HTTP-Seitenaufruf im Integrationstest. Kein Gesamttest/visueller Browsertest.
Login200,Versandpruefung302 ohne Anmeldung,Dienste aktiv;184 Einstellungen und
42 Vorlagen geprueft,Versand aus. Kein echter Bexio-Schreibtest.
Neue Web-Simulation ohne HTTP/Tokenzugriff; keine Originaldokumente veraendert.
Kein fertiger echter Versandadapter. Grenzen siehe CLUBUNIO-BEXIO-DISPATCH.md.

## Speziallisten-Exporte 12.10.2026

Current:20261012-clubora-36, Ruecksprung35 erhalten. Keine Datenmigration
oder neuen Einstellungen.10 gezielte Tests/137 Assertions erfolgreich, inklusive
Livewire-Downloads mit Testdaten. Kein Gesamttest oder manueller echter CSV-Export.
Login200,geschuetzte Listen302 ohne Anmeldung,FPM/Scheduler aktiv.
184 verschluesselte Einstellungen/42 Vorlagen geprueft,Versand aus.
CSV fuer Ohne-Mannschaft-/Passivlisten mit bestehenden fachlichen Regeln,
Such-/Saison-/Ansichten-Erhalt und protokolliertem Download. Keine echten
Mitgliederdaten exportiert; Tests verwenden isolierte Daten mit Rollback.
Details CLUBUNIO-REPORTS.md und CLUBUNIO-ROADMAP.md. Versand bleibt aus.

## Wetter und Instagram 12.10.2026

Current:20261012-clubora-35, Ruecksprung34 erhalten. Migration000033 auf
eigener gemeinsamer ClubUnio-DB erfolgreich, vier additive Wetterparameter.
Keine bestehenden Instagram-Aktivflags veraendert, keine externen Writes.
Neuer Feed-Test9/43 und bestehende gezielte Regression28/289 erfolgreich,
gesamt37 Tests/332 Assertions. Kein Gesamttest aller Testsuiten.
Wetter live lokal/Pi und Desktop/Mobil lokal geprueft. Angemeldete Pi-Anzeige OK.
Login/CSS200, Signage ohne Anmeldung302; FPM/Scheduler aktiv.
184 verschluesselte Einstellungen und42 Vorlagen geprueft, Versand weiterhin aus.
Instagram noch deaktiviert, nur automatisiert getestet. Details/Grenzen:
CLUBUNIO-SIGNAGE-FEEDS.md, CLUBUNIO-ROADMAP.md, CLUBUNIO-FACILITIES.md.

## Vereinsstamm und Signage-Logos 12.10.2026

Current:20261012-clubora-34, Ruecksprung33 erhalten. Migration000032 und Einmalimport
auf eigener gemeinsamer ClubUnio-DB erfolgreich:1447 Vereine,3 Konfliktnummern
ohne geratenen Namen. Bestehende Wettkaempfe/Garderoben/Quellkennungen unveraendert.
Neue Maske football-clubs, Vereinslookup, Zuordnungs-Prueffilter und Signage-Logos.
Gezielte Regression:28 Tests/289 Assertions erfolgreich, inklusive bestehender
SFV-, Anlagen-, Signage- und gespeicherter Ansichten. Kein neuer Gesamttestlauf.
Desktop/Mobil-Vereinsformular, Wettkampf-Lookups und echte Signage-Logos lokal
visuell geprueft, ohne bestehende Vereine oder Wettkaempfe zu veraendern.
Keine Parameter/Aenderungen an Abhaengigkeiten; Versand weiterhin aus.
Deployment erfolgreich: Login/CSS200, Vereinsstamm/Signage ohne Anmeldung302.
Vereinsliste auch angemeldet auf dem Pi geprueft. PHP-FPM/Scheduler aktiv.
180 verschluesselte Einstellungen und42 Vorlagen geprueft, Versand aus.
Details: CLUBUNIO-FOOTBALL-CLUBS.md, CLUBUNIO-FACILITIES.md, CLUBUNIO-ROADMAP.md.
Ruecksprung33 kann die additive Tabelle behalten. Kein PHPMaker DEV/PROD-Deployment.

## Benutzerverwaltung und SFV-Sammelpruefung 12.10.2026

Current: 20261012-clubora-33, Ruecksprung32 erhalten. Migration000031 auf eigener
gemeinsamer ClubUnio-DB ausgefuehrt: Konten-/Zuordnungsrevisionen, Zuordnungs-ID,
account_roles-Lookup/FK. Keine bestehenden Konten/Rollen/Aktivflags geaendert.
Admin-only Benutzerverwaltung und SFV-Zeitraumpruefung mit atomarer Bestaetigung,
Ausblendvorschlaegen bei Absage/Wegverlegung und unveraenderten Garderoben.
Keine neue Automation, keine neuen Ini-Werte, kein Versand/externes Schreiben.
Desktop/Mobil-Kontenformular und echte SFV-Sammelvorschau lokal geprueft,
ohne echte Benutzerrechte oder Wettkaempfe zu veraendern.
Deployment abgeschlossen: Login/CSS HTTPS200, geschuetzte Konten-/SFV-Seiten302
ohne Anmeldung. PHP-FPM und bestehender Scheduler aktiv.180 verschluesselte
Einstellungen und42 Vorlagen geprueft; Versand weiterhin deaktiviert.
Breite Regression ClubUnioTest/TeamPlanningTest:151 Tests/1487 Assertions.
Anschliessender gezielter Nachtest der letzten Ergaenzungen:8 Tests/62 Assertions.
PHP-Syntax, Pint und Blade-Cache erfolgreich. Kein Gesamttest aller Testsuiten.
Ruecksprung32 kann additive Migration31 behalten. Details: CLUBUNIO-ACCOUNTS.md,
CLUBUNIO-FACILITIES.md und CLUBUNIO-ROADMAP.md. API/SaaS/Betrieb ausgeklammert.

## SFV-Wettkaempfe 12.10.2026

Current: 20261012-clubora-32, Ruecksprung31 erhalten. Migration000030 auf gemeinsamer eigener
ClubUnio-DB ausgefuehrt: zwei nullable Teamnamen-Spalten im SFV-Lesebestand.
Keine Aenderung an PHPMaker DEV/PROD oder legacy_prod. Keine neuen Parameter.
Manueller SFV-Abruf, Vorschau und bestaetigte Einzeluebernahme; Garderoben und
andere lokale Felder bleiben erhalten. Bestehende Spiele nie blind duplizieren.
16 gezielte Tests/139 Assertions erfolgreich (inklusive vorhandener SFV- und
Anlagen-/Signage-Regression). Isolierte Testdaten mit Rollback. Kein Gesamttest.
Blade-Cache und Formatpruefung erfolgreich; Desktop/Mobil lokal geprueft.
Echter SFV-Abruf und Vorschau erfolgreich, ohne bestehende Wettkaempfe zu aendern.
Ruecksprung31 kann additive Spalten behalten, keine Schema-Rueckmigration.
Vollstaendige Benutzer-/Rollenverwaltung noch offen, Zuordnungen vorhanden.
Fachdetails/Grenzen CLUBUNIO-FACILITIES.md; API/SaaS/Betrieb ausgenommen.
Deployment erfolgreich:180 verschluesselte Einstellungen und42 Vorlagen geprueft,
Versand weiterhin aus. HTTPS-Login/CSS200, SFV-Seite ohne Anmeldung302;
PHP-FPM und bestehender Scheduler aktiv. Kein neuer Cronjob eingerichtet.

## Fachlisten-Exporte 12.10.2026

Current: 20261012-clubora-31, vorher30 fuer Ruecksprung erhalten.
Spieler-/Staff-/Familien-/Bankkontenlisten mit gefiltertem CSV-Download,
Admin/Office- und Mandantenschutz, maskierter IBAN, festen Fachspalten,
Formelneutralisierung,5000-Zeilen-Limit und export_prepared-Audit.
Keine neue Migration, Parameter oder Abhaengigkeiten. Datenbestand unveraendert.
Gezielte Tests10/135 erfolgreich inklusive bestehender Finanzexport-Regression,
isolierte Testvereine und Rollback; kein neuer Gesamttestlauf.
Format-/Bladepruefung erfolgreich, Schaltflaeche lokal angemeldet Desktop/Mobil
geprueft. Kein echter Mitgliederexport bei der visuellen Pruefung erzeugt.
Deployment:180 verschluesselte Einstellungen,42 Vorlagen geprueft, Versand aus.
HTTPS-Login/CSS200, alle vier Listen ohne Anmeldung302, FPM/Scheduler aktiv.
API/SaaS/Betrieb und PHPMaker DEV/PROD unveraendert. Fachdetails/Grenzen:
CLUBUNIO-REPORTS.md. Weitere Speziallisten und Benutzerabnahme bleiben offen.

## Bus-Umbuchung und Kalender 12.10.2026

Current: 20261012-clubora-30. Release29 als Ruecksprung behalten.
Umbuchungen mit Revision/Konflikt/Audit, interne Aenderungs-/Stornonachrichten,
optionale Busanzeige/ICS im Kalender, Keybox-Zeitfenstervorschau ohne Codeausgabe.
Keine neue Schemamigration. clubunio:seed-message-templates ausgefuehrt:
42 Vorlagen, bestehende Texte unveraendert. 180 Einstellungen entschluesselbar;
outbound_enabled=false. Bei Rollback29 bleiben Daten/Vorlagen erhalten;
dessen alter Vorlagen-Pruefer kennt die zwei neuen Eventkeys noch nicht.

Gezielte Regression erfolgreich: Bus11/80 und Kalender/Vorlagen8/174,
zusammen19 Tests/254 Assertions. Kein neuer Gesamttestlauf dieser Version.
Blade-Cache/Formatpruefung erfolgreich. Desktop-/Mobilpruefung lokal angemeldet,
ohne reale Reservation zu aendern. HTTPS-Login/CSS200, geschuetzte Umbuchung
und Kalender302 ohne Anmeldung. FPM und Scheduler aktiv. Importierte18
Reservationen samt Workflowhistorie unveraendert,14 Zuordnungen offen.
API/SaaS/Betrieb und PHPMaker DEV/PROD nicht veraendert.
Details und verbleibende Keybox-/Fachabnahme: CLUBUNIO-BUS-RESERVATIONS.md.

## Busreservierungen 12.10.2026

Current: 20261012-clubora-29, vorherige Version28 als Ruecksprung erhalten.
Migration000029 nur gemeinsame ClubUnio-DB: neue Bus-/Status-/Reservationstabellen,
1 Bus und18 Reservationen aus bestehendem legacy_prod-Abzug importiert.
14 historische Reservationen mit fehlender Team-/Antragstellerzuordnung; keine
geratenen Datenkorrekturen. Workflow-Originaldaten fuer18 Eintraege erhalten.
Details, Regeln und offene Punkte: CLUBUNIO-BUS-RESERVATIONS.md.

Webmodul /bus/sv-sissach, Verwaltungseintrag Anlagen und Spielbetrieb.
Anfragen/Entscheidungen/Storno, Konflikt- und Revisionsschutz, interne Nachrichten,
Bus-/Statuspflege. API, SaaS, Cutover und echter Versand bleiben ausgenommen.
Keine PHPMaker-DEV/PROD-Aenderung. Kein neuer externer Scheduler/FCM-Versand.

Gesamtregression175 Tests/1661 Assertions bestanden; vorher fokussiert17/120.
Desktop/Mobil lokal angemeldet visuell geprueft, keine echten Formulare gespeichert.
Nach Deployment: Login200, neue Busseiten ohne Anmeldung302, CSS200;
php8.3-fpm und clubunio-scheduler.timer aktiv, current-Symlink29 bestaetigt.
180 verschluesselte Settings/40 Nachrichtenvorlagen erfolgreich geprueft,
outbound_enabled=false. Angemeldete Fachabnahme auf Pi bleibt beim Benutzer.
Ruecksprung auf28 kann additive Bus-Tabellen behalten; nicht automatisch loeschen.

## KI-Trainingsplanung 12.10.2026

Release26 deployed, Migration27 auf gemeinsamer ClubUnio-DB ausgefuehrt.
Webmodul unter /planning/sv-sissach/training-plans; Erstellen/Bearbeiten,
Teamfreigabe, bestaetigte KI-Review und PDF mit sicheren Positionsgrafiken.
32 Tests/305 Assertions erfolgreich, neutraler echter OpenAI-Test erfolgreich.
Rollback25 kann additive Tabellen behalten. Keine PHPMaker- oder App-Aenderung,
kein externer Bexio-Schreibbetrieb/Versand. Details CLUBUNIO-TRAINING-PLANS.md.
KI-Provider wartet maximal85s: ClubUnio-FastCGI-Limit120s. Am vorgeschalteten
Nginx Proxy Manager fuer Host dev-my2.svsissach.ch muss ebenfalls
`proxy_read_timeout 120s;` konfiguriert werden; Benutzer dazu angefragt.

## Bexio OpenID und SFV-Navigation 12.10.2026

Release25 erfolgreich deployed, Ruecksprung24 mit untenstehender DB-Einschraenkung.
OpenID-Verbindung nur Admin, eigener Tokenbestand, automatische Rotation und
ein PDF-Retry. Jumbojett1.0.2 plus phpseclib3.0.57 via Composer-Lock.
Fokussierte Tests9/96 erfolgreich; SFV-Pagination1/19 erfolgreich.
Migration26 bereits auf gemeinsamer ClubUnio-DB ausgefuehrt:5 alte Bexio-
Parameter entfernt,179 Settings verbleiben. Verschluesselte Sicherung nur
lokal unter storage/app/private/migration-backups; nicht oeffentlich deployen.
Account19 auf ausdruecklichen Wunsch admin statt office, auditierte Aenderung.
Alte Releases lesen entfernten BEXIO_TOKEN: bei Rollback bleibt ihr Bexio-PDF
deaktiviert, bis Codefix oder gepruefter Restore; keine PROD-Refresh-Tokens
reaktivieren. Fachliche Daten unveraendert, neue Verbindung erst nach Consent.
Details/Migrationspfad: CLUBUNIO-BEXIO-OIDC.md und CLUBUNIO-ROADMAP.md.
PHPMaker DEV/PROD und API/SaaS bleiben unberuehrt.
Regression115/1165 erfolgreich, fokussierte OIDC/PDF/Settings9/96 erfolgreich.
Consent abgeschlossen; BEXIO_OIDC_CONNECTION angelegt, jetzt180 Settings.
Echter PDF-Abruf Rechnung6790 lokal und Pi HTTP200,1893246 Bytes, PDF-Signatur
geprueft. Eigener Tokenrefresh auf Pi erfolgreich, anschliessender PDF-Abruf
ebenfalls200. Kein Legacy-Refresh-Token verwendet. Login200, OIDC-Seiten ohne
Anmeldung302, CSS200, FPM/Scheduler aktiv. Settings180/Templates40 verifiziert.

## Kalender und Terminansicht 12.10.2026

Release24 erfolgreich deployed, Ruecksprung23. Keine Datenbankmigration erforderlich.
Monat/Woche/Mannschaften, erhaltene Filter, sichtbarkeitsgeschuetzte
Ueberschneidungen und authentifizierter manueller ICS-Export.
Sabre VObject4.6.1 wird ueber composer.lock im Release installiert.
Teamplanung17 Tests/162 Assertions; abschliessend Kalender3/46 erfolgreich.
Desktop-Matrix und mobile Wochenansicht390px visuell geprueft.
Details/Migrationsgrenzen: CLUBUNIO-CALENDAR.md und CLUBUNIO-ROADMAP.md.
API/SaaS und PHPMaker DEV/PROD bleiben unveraendert.
Config/Routes/Views erneuert, Settings184/Templates40 verifiziert.
HTTPS Login200, Kalender/Export ohne Anmeldung302, CSS200.
FPM und Scheduler aktiv; bestehende Erinnerungen weiterhin alle5 Minuten.

## SFV, Gastspieler und Serien 12.10.2026

Release23 / rollback22. Additive Migration24/25 auf derselben separaten ClubUnio-DB
wie lokal ausgefuehrt. Keine neue PROD-Kopie, kein PHPMaker-Deployment.
SFV-Liveabruf lesend, explizite Uebernahme/Aktualisierung; Gaeste/Einladungen;
Serienvorschau mit atomarer Bestaetigung, Frist- und Revisionsschutz.
17 bestehende Serientermine,7 SFV-Verknuepfungen,20 aktive historische
Einzeleinladungen; Snapshot-Gaeste0. SFV-Lesetest63 Spiele erfolgreich.
Nur7 SFV-Leseparameter aktiviert, sonstige Integrationsschalter unveraendert.
Konfiguration/Secrets liegen weiter verschluesselt in tenant_settings und
privaten shared-Dateien, nicht im Deploymentarchiv. Kein FCM/echter Mailversand.
Browserpruefung: SFV-Uebernahme, Termin/Serie und mobile390px-Ansicht; keine
echten Termine/Teilnahmen durch Browserpruefung gespeichert.
Details, Tests und Migrationsgrenzen: CLUBUNIO-TEAM-PLANNING.md / ROADMAP.md.
Regression128/1270 plus zusaetzlicher Serien-Fristtest1/6 erfolgreich.
Deployment23 erfolgreich: Config/Routes/Views erneuert, Settings184/Templates40
verifiziert; HTTPS Login200, Portal200, geschuetzte SFV/Serienseiten302, CSS200.
FPM und Scheduler-Timer aktiv,5-Minuten-Erinnerungsjob weiterhin eingetragen.
SFV-Live-Lesetest direkt als www-data auf Pi erfolgreich:63 Spiele.
Abschliessender Teamplanungs-Testlauf14/116 erfolgreich.
API/SaaS bleiben geparkt. Vor Cutover Einladungen/Serien/SFV-IDs und saisonale
Team-Zuordnung erneut abgleichen; niemals Migration:fresh/erneuten Snapshot
ueber den bereits benutzten ClubUnio-Bestand laufen lassen.

## Teamplanung und Absenzen 12.10.2026

Release: 20261012-clubora-22; Teamplanung-Erstdeployment21, kompatibler Ruecksprung20.
Release22 ergaenzt die gepruefte Gastweiterleitung zur Spieler-/Eltern-Portal-Anmeldung.
Migration22/23 auf gemeinsamer neuer ClubUnio-DB und Snapshotimport ausgefuehrt.
26 aktive Termine, 451 Teilnahmen, 2 Absenzen. Keine Live-PROD-Datenmigration.
Webmodul und Portalzugriff, Fristschutz, Absenzen mit Wiederherstellung,
Teilnahme/Anwesenheit, Auswahlwerte/Farben, woechentliche Einzeltermine und
interne Erinnerungen. Bestehender Scheduler laeuft weiter, keine externe Push-
oder E-Mail-Zustellung durch Teamplanung. API/SaaS nicht erweitert.
Tests zusammen131/1264 erfolgreich, Browser Liste/Detail/Formular und mobile
Breite geprueft. Details/Migrationsgrenzen: CLUBUNIO-TEAM-PLANNING.md.
Deployment21: HTTPS Login200, geschuetzte Planung302, FPM/Config/Routes/Views
erneuert, Settings184/Templates40 geprueft. Scheduler erkennt den 5-Minuten-Job,
manueller Lauf und idempotenter Import erfolgreich. Kein PHPMaker-Deployment.
Release22 aktiv verifiziert: Planung302 zur Portal-Anmeldung, Portal200.
Zusaetzlicher Login-Weiterleitungstest1/9 erfolgreich. Systemd bestaetigt den
planmaessigen Erinnerungsjob am12.10.2026 um15:50CEST als erfolgreich.

## Invoice workflow and PDF 12.10.2026

Current release: 20261012-clubora-20; rollback: release19 (additive DB remains).
Migration000021 applied/verified on shared NEW ClubUnio DB; workflow lookup,
invoice revision and separate local processing state. No source migration.
Draft header/position editing, internal release/cancellation, 25-member season
preview batches and confirmed drafts, right-side PDF/download. Dompdf3.1.6.
Bexio document GET ignores synchronization activation; no external writes.
Full suite120 tests/1176 assertions passed; final expanded finance rerun13 tests/
148 assertions passed. Includes two added form/batch-bound tests not in full run.
Local fixture PDF rendered and visually inspected. Authenticated invoice list,
positions and right-side error panel visually checked. Real Bexio request401:
valid independent ClubUnio credential still required, no refresh of PROD token.
Deployment/config/routes/views/FPM successful; settings184/templates40 verified.
HTTPS login200; invoices/PDF302 to login; migration status21 verified on Pi.
Outbound remains disabled, no real draft created by browser checks. PHPMaker
DEV/PROD unchanged. Scope/remaining finance work: CLUBUNIO-INVOICES.md.

## Contribution preview and drafts 12.10.2026

Previous release: 20261012-clubora-19; rollback: release18. No DB migration.
Admin/office contribution preview with family discount/exemption, exact cents,
rule ambiguity checks, confirmed individual pending drafts and audit trail.
Existing seasonal contributions prevent duplicates. No release or Bexio writes.
Full regression: 116 tests / 1129 assertions passed. Final focused rerun after
UI adjustments: four tests / 31 assertions passed. Authenticated local browser
preview checked, including existing-invoice protection; no real invoice created.
Release switched, config/routes/views rebuilt, FPM reloaded. Settings184 and
templates40 verified; outbound remains disabled. HTTPS login200, protected
preview302 to login. Details: CLUBUNIO-CONTRIBUTIONS-TRAINING.md.
Finance and remaining business packages are not complete; API/SaaS deferred.
PHPMaker DEV/PROD and legacy source snapshot unchanged.

## Family and bank reports 12.10.2026

Previous release: 20261012-clubora-18; rollback: release17. No DB migration.
Two admin/office-only read-only reports with named/default saved views and
combined search/filters. IBAN masked; optional staff-season EXISTS filter.
Exact legacy semantics and remaining fields/exports: CLUBUNIO-REPORTS.md.
Full regression: 112 tests / 1098 assertions passed; Blade cache build passed.
Release switched successfully, config/routes/views rebuilt, FPM reloaded.
Encrypted settings184/templates40 verified; outbound remains disabled.
HTTPS login200, new family/bank report routes302 to login when unauthenticated.
Browser remains at login; authenticated visual acceptance still pending.
PHPMaker DEV/PROD and source snapshot unchanged. API/SaaS remain deferred.

## Facilities, display colors and dashboards 12.10.2026

Previous release: 20261012-clubora-17; rollback: release16 (new tables/columns remain).
Migration000018-20 applied to NEW shared ClubUnio DB only. Snapshot imports:
165 competitions, eight notices, three fields; 47 initial configurable colors.
Original member recorded dates preserved separately from import timestamps.
Backoffice CRUD, source-ID protection, saved date filters, internal Signage
preview and five dashboard widgets. Scope/gaps: CLUBUNIO-FACILITIES.md.
Full suite: 107 tests / 1019 assertions passed. Final date-filter hardening:
one additional focused rerun / 23 assertions passed. Blade cache build passed.
Release installed successfully, config/routes/views rebuilt, FPM reloaded.
Encrypted settings184 / templates40 verified, outbound disabled.
HTTPS login and stylesheet200; competitions/signage-preview/status-styles302
without login. Local browser reached login, authenticated visual check pending.
No real recipient delivery, no source refresh, no PHPMaker DEV/PROD changes.
Overall migration remains incomplete; API and SaaS explicitly deferred.

## Membership reports package 12.10.2026

Previous release: 20261012-clubora-16; compatible rollback: release15.
Migration000017 applied to NEW shared DB: configurable member-type reporting
flags; legacy meanings seeded only for imported SV Sissach types. Two read-only
lists with saved views and tenant/role/season scope. Details CLUBUNIO-REPORTS.md.
Full regression passed: 97 tests / 922 assertions. Release switched successfully,
config/routes/views rebuilt, FPM reloaded. Settings184/templates40 verified.
HTTPS login200, both new protected lists302 checked after deployment.
Browser reaches login; authenticated visual acceptance requires a fresh login.
No PHPMaker or external integration changes, no source refresh.

## Reports/dashboard package 12.10.2026

Previous release: 20261012-clubora-15; compatible rollback: release14.
Four read-only reports (open invoices, players, staff, payment-ready expenses),
saved views, open invoice KPIs, member age chart and expense season totals.
No migration or source refresh. See CLUBUNIO-REPORTS.md for exact semantics
and outstanding work. Three focused tests / 27 assertions passed.
Full regression: 93 tests / 884 assertions passed. Deployed successfully with
config/routes/views rebuilt and FPM reloaded. Encrypted settings184/templates40
verified, HTTPS login200 and protected open-invoices302 after release switch.
Local browser check interrupted by expired session and browser control timeout;
authenticated synthetic Livewire tests cover rendering and combined filters.

## Personal saved views release 11.10.2026

Previous release: 20261011-clubora-14; compatible rollback: release13.
Additive migration000016 already applied to NEW shared DB. Six admin/office
lists support private named/default views (CLUBUNIO-SAVED-VIEWS.md).
Full suite passed: 90 tests / 857 assertions. Local browser save dialog checked
without persisting real data. No PHPMaker/DEV/PROD or external service changes.
Deployed successfully, config/routes/views rebuilt, FPM reloaded. Encrypted
settings184 and template rendering40 verified. HTTPS login200 and protected
members302 checked after switching the release.

## Dashboard/list foundation release 11.10.2026

Previous release: 20261011-clubora-13; rollback: release12. No DB migration.
Two status distribution charts, first member flag reports, status tabs with
tenant-wide totals for registrations/expenses, central status/lookup colors in
Filament. Long-term parallel-build decision and remaining dashboard/list parity
documented in CLUBUNIO-ROADMAP.md. API/SaaS deferred, no PHPMaker changes.
86 tests / 799 assertions passed; final report count optimization additionally
tested (1 test / 9 assertions). Local browser charts and report tabs verified.
Config/routes/views rebuilt, FPM reloaded; encrypted settings184/templates40
verified. HTTPS login200 and protected member-reports302 verified.
Personal saved/default filters, full legacy dashboard/list parity and editable
database color configuration remain pending. No external writes or new mail.

## Contributions/training/dashboard release 11.10.2026

Previous release: 20261011-clubora-12; compatible rollback: release11.
Migration 000015 and marked import on NEW shared DB: 9 contribution prices,
117 rules, 21 training lookup values, 648 training rows. No legacy DB changes.
Admin/office maintenance, team-season training subtable and initial dashboard.
See CLUBUNIO-CONTRIBUTIONS-TRAINING.md for limits, especially pending Signage
and automatic contribution billing. Full suite: 84 tests / 782 assertions.
Snapshot verified unchanged: 84 tables / 49,946 rows. Browser checked dashboard,
training list and contribution prices locally. Config/routes/views rebuilt,
FPM reloaded. HTTPS login200, protected training/prices302; encrypted settings184
and template rendering40 verified on Pi. External writes remain disabled.

## Invoice portal release 11.10.2026

Previous release: 20261011-clubora-11. Adds member-derived invoice visibility,
combined status/season/search/member filters and active position details in
the restricted portal. No additional migration or Bexio request. See
CLUBUNIO-INVOICES.md. Compatible rollback: release10.
80 tests / 737 assertions passed, including combined filters, foreign invoice
IDs, inactive positions, pending/revoked/adult parent links and trainer/TL scope.
Deployment rebuilt caches/reloaded FPM. HTTPS login200 and protected invoice
portal302 verified; encrypted settings184/template rendering40 verified on Pi.

## Expense workflow release 11.10.2026

Previous release: 20261011-clubora-10. Role-scoped expense transitions,
position editing, laundry defaults, review indicator, own bank account management,
private attachment reads and editable finance catalogs. See CLUBUNIO-EXPENSES.md.
Migrations 000012..000014 applied to the NEW shared ClubUnio DB only.
Email events use the fixed-admin test outbox; Bexio writes and FCM remain off.
Rollback target is release09; migrations are additive and retained on rollback.
Source snapshot verification: 84 tables / 49,946 rows unchanged.
Full suite: 79 tests / 708 assertions passed. Local browser verified laundry
prefill and expense-type configuration without changing real records.
Deployment rebuilt configuration/routes/views and reloaded FPM. HTTPS login200,
protected expenses302. Private Azure expense sample read on Pi (image/png,
291240 bytes). Both registration and expense outboxes appear in schedule:list.
Encrypted settings184 and template rendering40 verified before the switch.

## Expanded registrations release 11.10.2026

Previous release: 20261011-clubora-09. Encrypted applicant details, atomic member
acceptance/reactivation, separate identity references, private Azure reads,
restricted TL portal, persistent notifications and fixed-admin mail outbox.
See CLUBUNIO-REGISTRATIONS.md. Migrations 000010/000011 applied to NEW ClubUnio DB;
69 details imported, 40 editable templates. PHPMaker DEV/PROD untouched.
Full suite: 70 tests/627 assertions passed. Browser new applicant form
checked without submitting real data. HTTPS login200/protected portal302.
Pi scheduler service/timer installed independently of legacy cron jobs; first
minute run succeeded. Emails only admin@svsissach.ch, external FCM/writes off.
Settings decryption184/template rendering40 verified on Pi. Rollback release08.

## Registration review release 11.10.2026

Previous release: 20261011-clubora-08. Backoffice review of registration status,
team and TL assignment, revision protection, audit and held events. See
CLUBUNIO-REGISTRATIONS.md for scope. Acceptance/member transfer, TL portal and
automatic notification delivery remain pending. Migration 000009 applied once
on the shared new ClubUnio DB. No PHPMaker DEV/PROD changes.

62 tests / 560 assertions passed. Browser list and review slide-over checked;
no real registration changed. Source verification: 84 tables / 49,946 rows.
Deployment rebuilt config/routes/views and reloaded PHP. Public login200,
unauthenticated registrations302; encrypted settings/template checks passed.
Release07 is the compatible rollback. No real mail or push sent by this slice.

## Identity and restricted portal release 11.10.2026

Previous release 20261011-clubora-07: see CLUBUNIO-ACCESS.md. Admin/office console stays
/clubora; restricted read-only member area uses /portal/login with a separate
guard. Verwaltung / Benutzerzuordnungen supports audited reviews/revocation.
Migration 000008 and snapshot import applied once: 66 self-links, 25 pending
parent proposals, zero unresolved non-empty source member IDs. Re-running import
is a no-op. Unknown technical roles remain disabled for the portal.

Full suite: 58 tests / 531 assertions passed, including 18th-birthday approval
with timezone-safe date comparison. Local administrative list
and desktop/mobile portal login checked. Authenticated portal tests use synthetic
rolled-back users, not real-user browser credentials. Source snapshot unchanged.
Release06 remains a compatible rollback. PHPMaker DEV/PROD remain untouched;
test email guard and all external write/push restrictions remain in force.
Post-deploy HTTPS: portal login/CSS200, unauthenticated member list302 to portal
login, account links302 to administrative login. Server caches rebuilt, encrypted
settings/template checks passed and link counts verified. No real-user portal
browser login was performed; role/login/session tests ran with synthetic users.

## Test mail and Azure document release 11.10.2026

Current deployed release 20261011-clubora-06: fixed admin test mail and authenticated member
attachment preview/download. See CLUBUNIO-COMMUNICATION.md / CLUBUNIO-DOCUMENTS.md.
Migration 000007 applied once to the shared new DB. 51 tests / 479 assertions
passed; actual local SMTP sample accepted, two Azure samples read successfully.
Local browser PDF preview verified. Previous release05 remains rollback-compatible.
Post-deploy: public HTTPS login200, protected document302 to login; PDF/JPEG
samples also read on Pi as www-data. A second sample email from Pi was accepted
by SMTP, exclusively to admin@svsissach.ch. Total: two actual test emails.
Snapshot hash/count check: 84 tables / 49,946 rows unchanged.

Private integration config: shared/.env.clubora-integrations.local, mode640,
admin:www-data, symlinked into each release before config caching. Never include
it in Git or deployment archives. TEST_MAIL_ENABLED=0 blocks all Laravel mail;
rebuild config cache after a config change. SMTP encryption is required.
Only fixed admin sample delivery and private Azure reads are enabled. No FCM,
Bexio writes or Azure writes. Previous deployment sections below are historical.

## Communication and note editing release 11.10.2026

Current deployed release: 20261011-clubora-05. Editable tenant email/push templates,
version history/restore, language variants and safe preview. Expense recipient
planning only (no delivery), plus audited member-note editing/deactivation.
39 starter templates / 34 events. Migration 000006 and seeding already applied
once to the shared ClubUnio DB. Prior release04 is rollback-compatible.
45 tests / 433 assertions passed; Blade cache compilation passed. Original
snapshot checked: 84 tables / 49,946 rows unchanged. Desktop editor and preview
checked; mobile DOM widths fit. No production PHPMaker changes or outbound calls.
See CLUBUNIO-COMMUNICATION.md. Older release sections below are historical.

Post-deploy: login HTTP 200, templates and expense details without authentication
302 to ClubUnio login. Server-side rendering check passed for all 39 templates;
184 encrypted settings and 155 encrypted bank accounts verified. Dossier counts
and financial totals unchanged. No authenticated browser session on Pi was used;
interactive editor/save tests ran locally against the same new DB.

## Member dossier release 11.10.2026

Current release: 20261011-clubora-04. All member child tables from the PMP,
finance read views with nested positions, and family/team/season master pages.
See CLUBUNIO-MASTER-DETAIL.md for scope, dependencies and limitations.
Migration 000005 and one-time import applied to the shared new ClubUnio DB;
no live PROD/DEV source writes or integration calls. Code rollback to release03
is compatible with these additive tables and columns.

Verification: 36 tests / 288 assertions, all passed. Source/target counts and
decimal financial totals match, no unresolved player/invoice-line references,
155 encrypted bank records decrypt correctly. Local authenticated browser:
member dossier tabs and child lists checked. Shared snapshot unchanged.
The release sections below are historical.

After release04: public login 200; members, invoices and expenses without
authentication 302 to ClubUnio login; /admin still redirects to ClubUnio.
Server-side dossier verification passed, including all 155 encrypted IBANs.
Original snapshot independently reverified: 84 tables / 49,946 rows unchanged.

## Pilot removed 11.10.2026

Current release: 20261011-clubora-03. Only the ClubUnio panel remains.
Old pilot panel, SQL Server models/configuration, invoice routes/views and
pilot-only tests removed from the active codebase. No legacy DB connection
is registered. GET /admin and its subpaths, plus /, redirect to /clubora.
Old /invoices/* PDF endpoints no longer exist. Shared data and previous
offline releases are retained; PHPMaker DEV/PROD are untouched.
Sections below describe historical releases.

Verified: 29 tests / 212 assertions passed after removing pilot-only tests.
Live / and /admin (including login/invoices subpaths) return 302 to /clubora;
old invoice PDF returns 404; ClubUnio login 200; members without login redirect
to ClubUnio login. All 184 encrypted settings verified; outbound disabled.

## Extended modules release 11.10.2026

Active: /var/www/svs-laravel/releases/20261011-clubora-02.
Entry: https://dev-my2.svsissach.ch/clubunio/login.
Scope/remaining work: [CLUBUNIO-MIGRATION.md](CLUBUNIO-MIGRATION.md).
Includes extended member creation/editing, families, player/staff/TL assignments,
lookup catalogs and audit viewer. Document metadata only, no Azure file access yet.
Local and Pi use the same ClubUnio database. Source PROD snapshot unchanged.

Migrations 000003/000004 and one-time imports already applied to ClubUnio locally;
server deployment did not rerun them. PHP dependencies reused from previous
release and checked against lock; assets/config/routes/views rebuilt. Private
config and APP_KEY unchanged; PHP-FPM reloaded after atomic symlink switch.
Previous 20261011-clubora-01 retained for rollback (schema additions compatible).

Verification: 35 tests, 236 assertions, two legacy DEV-read tests skipped. Synthetic
fixtures rolled back. All 84 snapshot tables / 49,946 rows independently verified.
Both imports rerun safely as no-ops. Pi counts match local; 184 encrypted settings
decrypt successfully. HTTPS login/CSS 200; members/families/players/audit redirect
302 without login; /.env 403. Authenticated browser acceptance remains with user.
No SmarterASP deployment, external notifications, Bexio or Azure writes.

Releases can now use release-clubunio-pi.sh with an explicitly named, reviewed
archive in shared. It refuses an existing release and keeps a rollback target.
Do not include .env, runtime cache/logs, uploads or local vendor in the archive.

## ClubUnio release 11.10.2026

Current release: /var/www/svs-laravel/releases/20261011-clubora-01.
Entry: https://dev-my2.svsissach.ch/clubunio/login (old /admin still available).
Includes members with edit slide-over, catalog management, team seasons,
registrations and encrypted settings. Uses the existing new ClubUnio PostgreSQL
database, not the old DEV SQL database. No migrations/imports rerun.

Installed php8.3-pgsql. Separate shared/.env.clubora and
shared/.env.postgresql.local, admin:www-data 640. ClubUnio APP_KEY preserved from
local for existing encrypted settings; former staging key retained as previous
key. Different session cookie; log in again for ClubUnio. Old shared/.env retained
unchanged for rollback. PostgreSQL TLS verify-full uses Linux system CA bundle.

Composer locked production dependencies installed, assets published, configuration,
routes and views cached, current symlink switched and FPM reloaded. No container
or proxy changes. Outbound integrations remain disabled. Login/CSS HTTP 200,
unauthenticated members redirect 302, /.env denied 403. Authenticated editing
needs user acceptance; local automated suite documented in CLUBUNIO-FOUNDATION.md.

Rollback: point current back to releases/20261010-01 and reload PHP-FPM.
No database rollback necessary. Private transport JSON removed on both machines.
Scripts install-clubunio-pi.sh / install-clubunio-env.php describe this initial
deployment and deliberately refuse overwriting an existing release/configuration.
Future deployments must reuse private configuration and storage, not regenerate keys.

## Original pilot installation

Deployment 10.10.2026, SSH admin@svs-pi-server. Separate Laravel pilot;
existing SmarterASP DEV/PROD unchanged. DEV SQL connection is read-only in code.

## Reverse proxy

- Public hostname: dev-my2.svsissach.ch (actual proxy/DNS hostname).
- Backend: http://192.168.3.157:8092
- Proxy address: 192.168.2.105 (existing UFW rules confirmed this address).
- TLS certificate and HTTP-to-HTTPS redirect at the proxy.
- Preserve Host; overwrite X-Forwarded-Proto with https on TLS requests.
- Pass X-Forwarded-For normally. Only 192.168.2.105 is trusted by Laravel.
- No WebSocket requirement. Proxy timeout >= 60 seconds recommended.
- Do not cache authenticated pages or PDF routes.
- Secure session cookies are enabled; browser login requires HTTPS.

DNS/proxy/TLS configured by user, HTTPS login verified 200 on 10.10.2026.
Original planned my-dev2 hostname has no DNS record. Use dev-my2 instead.
Pi global real-IP handling rewrites remote_addr to visitor IP. Site access uses
realip_remote_addr to verify the actual proxy peer; PHP receives that peer for
Laravel trusted-proxy handling. No blanket public access was enabled.

## Installation

Release: /var/www/svs-laravel/releases/20261010-01
Current symlink: /var/www/svs-laravel/current
Private config: /var/www/svs-laravel/shared/.env (640, admin:www-data)
Persistent runtime: /var/www/svs-laravel/shared/storage
Web root: current/public (never the project root)
FPM pool: /etc/php/8.3/fpm/pool.d/svs-laravel.conf
Nginx: /etc/nginx/sites-available/svs-laravel
Only proxy IP and localhost allowed; UFW allows proxy IP on port 8092.
php8.3-bcmath installed; pdo_sqlsrv already available.

Composer installed locked dependencies on Linux ARM64 without development
packages. Assets published, config/routes/views cached. No migrations or seeds.
No local user sessions or logs uploaded. A new APP_KEY was generated.
Only database credentials were copied, not the complete PHPMaker secret file.
Temporary environment transfer file removed after installing shared/.env.

Checks: nginx and FPM config valid; HTTP login 200; /.env 403;
unauthenticated invoice list 302. DEV connectivity successful.
Public HTTPS, stylesheet, secure cookies and HTTP-to-HTTPS redirect verified.
Real-user login still needs verification.

For later releases retain shared/.env and storage, install dependencies and
publish assets before switching current. Back up modified configuration first;
never run migrate:fresh or seed against the shared DEV database.
# Release27: Bexio reconciliation (12.10.2026)

Deployed 20261012-clubora-27; previous release26 retained for rollback.
Migration28 applied to ClubUnio PostgreSQL only. Web preview and confirmed
local status application for invoices/lines and paid expenses; no Bexio writes.
36 related tests / 341 assertions passed. Desktop/mobile rendering checked.
HTTPS login 200, protected reconciliation route 302 without authentication;
FPM and scheduler active. Real server-side Bexio preview: unchanged, status 8.
Outbound remains disabled. Details: CLUBUNIO-BEXIO-RECONCILIATION.md.

# Release28: Finanzeditoren, Exporte, Signage (12.10.2026)

Aktiv: 20261012-clubora-28, Ruecksprung27 beibehalten. Keine Migration.
Bexio-/Positionsstatus-Editoren, auditierte Archivierung manueller eigener
Entwurfspositionen, gefilterte Finanz-CSV und Signage-Aktualisierung/Tageswechsel.
Gesamtregression ClubUnio/Bexio127 Tests /1248 Assertions bestanden. Nachlauf
der finalen Web-Aktionen und Tageswechsel4 Tests /62 Assertions bestanden.
Lokale Status-Editormaske und Signage visuell kontrolliert; kein Bestandsdatensatz
geaendert. HTTPS login200, geschuetzter Editor302, CSS200; FPM/Scheduler aktiv.
Outbound deaktiviert,180 verschluesselte Settings und40 Vorlagen verifiziert.
Kein PHPMaker DEV/PROD-Eingriff, keine API/SaaS oder Produktivumschaltung.
Details: CLUBUNIO-FINANCE-EDITORS-EXPORTS.md, CLUBUNIO-FACILITIES.md und ROADMAP.

# ClubUnio Release44 (14.10.2026)

Aktiv: 20261014-clubora-44; Ruecksprung43 erhalten. Migration42 auf gemeinsamer
ClubUnio-DB angewendet; nicht nochmals auf Pi notwendig. Sandbox-Fixture-Service
und PDF-Zuordnungsschutz deployed. Rechnung7640 / Remote1, drei synthetische
Mitglieder. Seed wiederholt lokal und Pi ohne Duplikate; PDF-Service lokal/Pi
HTTP200,105930 Bytes. Login200, geschuetzte Rechnung302 anonym; FPM/Scheduler aktiv.
24 Tests/181 Assertions (Sandbox,OpenID,Abgleich) erfolgreich. Kein angemeldeter
visueller Browsertest. 189 verschluesselte Settings/42 Vorlagen geprueft.
Outbound bleibt aus, Statusabgleich im Sandbox-Mandanten absichtlich gesperrt.
Details CLUBUNIO-BEXIO-SANDBOX.md. Kein Live-Web-Schreibadapter/kein Zahlungstest.
API/SaaS/Betrieb/PHPMaker DEV/PROD unveraendert. Remote-Schreib-Fixture-CLI bewusst
nicht im neuen Releasearchiv enthalten. Ruecksprung43 hebt Sandbox-Schutz auf:
deshalb keine Bexio-PDFs/Abgleiche unter43 bei aktiver Sandbox verwenden.

# ClubUnio Branding (14.10.2026)

Aktiv: 20261014-clubunio-brand; Ruecksprung: 20261014-clubora-47.
Neuer Login /clubunio/login, alte /clubunio-GET-Links leiten weiter.
Nur Branding-Delta, keine neue Migration/Fachfunktion. HTTP200, Caches neu,
189 Einstellungen und42 Vorlagen geprueft. Details CLUBUNIO-REBRAND.md.

