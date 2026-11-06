# ClubUnio: fortlaufender Aufbau- und Migrationsplan

## Modellabnahme mit synthetischen Daten (06.11.2026)

72 gewertete Backoffice-Aufgaben ueber die echte OpenAI-API verglichen:
mini/Sol nach inhaltlicher Nachpruefung je36/36, keine beobachteten
Sicherheitsverstoesse. Mini schneller; kein nachgewiesener fachlicher Sol-Vorteil
in diesem kleinen Sample. Cacheeffekte und abweichende Heuristikwerte separat
dokumentiert. Kein Modellwechsel, Deployment oder Freischalten echter Daten.
`CLUBUNIO-ASSISTANT-BENCHMARK.md`; schwierige Langkontextfaelle und Live-/Race-
Abnahme bleiben eigenstaendige Folgeschritte.

## Backoffice-Assistent (06.11.2026)

Umsetzung der drei Pakete Tagesbericht/Fachabfragen, bestaetigte Fachaktionen
und begrenzte mehrstufige Vorbereitung. Normale ClubUnio-Weboberflaeche DE/EN,
mandanten- und benutzerbezogene Historie, Budgetreservierung und Fehlernachweis.
Externe KI bleibt gesperrt, bis Anbietervereinbarung, Verarbeitung und
Aufbewahrung geprueft sind. Keine autonomen Zahlungen oder Rechteaenderungen.
Live-KI-Abnahme und zusaetzliche Fachaktionen sind getrennte Folgeschritte;
Stand und Abnahmeprotokoll in `CLUBUNIO-ASSISTANT.md`.

Auf ClubUnio ausgerollt; 173 Tests/3155 Assertions und lesender Servergate gruen.
Offen: angemeldete visuelle Abnahme, Mehrprozess-Racetest und ausdrueckliche
Datenschutz-/Providerfreigabe. Keine automatische Aktivierung externer KI.

## Drittel und Viertel auf Sportplaetzen (05.11.2026)

Fuer die drei aktiven SVS-Plaetze sind Drittel A-C und Viertel A-D in DE/EN
ergaenzt. Bestehende Halbplaetze/Belegungen bleiben erhalten. Web und App
uebernehmen die dynamische Auswahl, geometrische Konfliktpruefung getestet.
20 Tests/312 Assertions und lesender Servercheck erfolgreich; Webauswahl
angemeldet geprueft. Physischer App-Test offen. CLUBUNIO-PITCH-FRACTIONS.md.

## Reservierungsanfragen im Dashboard (05.11.2026)

Bus-/Platzanfragen mit Gesamtzahl und direktem Genehmigungslink fuer
Geschaeftsstelle/Admin. Mandantentrennung, Rechte, Konflikt-/Revisionspruefungen
bleiben erhalten; keine Nachrichten beim Lesen. Begrenzte Listen mit Blaettern,
DE/EN und Tests. Kein Schema-/Legacy-Eingriff. CLUBUNIO-DASHBOARD-RESERVATIONS.md.

## Anfragebox unabhaengig vom Kalendertag (05.11.2026)

Eigene aktuelle Trainer-/TL-Anfragen und vollstaendige Admin-/Backoffice-Liste
mit Statusfilter. Eigener paginierter API-Leseweg, keine Kalenderbereichsgrenze,
keine wiederholten Konfliktberechnungen je Datum.72 Fluttertests/Analyse und
Sprachpruefung erfolgreich; physischer Test mit aktualisiertem Build bleibt offen.
Release-/Backendabnahme: CLUBUNIO-PITCH-REQUEST-INBOX-RELEASE.md.

## App-Anlagenstatus zuerst (05.11.2026)

Bestehende Offen/Gesperrt-Ansicht als Standard im App-Modul, persoenlicher
Trainer-Lesezugriff korrigiert, Zeitachse beim Scrollen fixiert. Team-/Pushziele
bleiben im datierten Kalender.56 Fluttertests und Analyse erfolgreich;
installiertes natives Testbuild weiterhin noetig. Keine neue Rechtezuordnung,
kein PHPMaker-Eingriff. CLUBUNIO-FACILITIES-STATUS-RELEASE.md.

## Firebase-Zugang vorbereitet (05.11.2026)

Serviceaccount privat auf ClubUnio installiert, iOS-Registry hinterlegt und
echte Google-OAuth-Anmeldung erfolgreich. Versandflags bleiben false;
eigenes Konto hat noch keine iOS-Geraetebindung. Kein FCM-Versand oder
Geraetenachweis. Naechster Schritt: eingegrenzte persoenliche Geraeteabnahme.
CLUBUNIO-FIREBASE-CREDENTIALS.md; PHPMaker DEV/PROD unveraendert.

## Platzplanung: Bedienungsnachlauf (05.11.2026)

Saisonbezogene Teamoptionen, kompakte parallele Tagesplantafel und mobile
Sprachkopfzeile umgesetzt.120 gezielte Tests/1047 Assertions erfolgreich;
DE/EN und angemeldete Websicht geprueft. Native Geraete-/Pushabnahme weiter
offen, keine echten Nachrichten oder Terminmutationen. Bestehende Rechte und
PHPMaker DEV/PROD unveraendert. CLUBUNIO-PITCH-USABILITY.md.

## Native Platzplanung und Sichtpruefung (05.11.2026)

App-Freigabeschalter auf ClubUnio aktiviert; neue App bietet die Platzplanung,
alte Clients behalten Anlagenstatus. Bestehende Rechte und PHPMaker unveraendert.
Angemeldete DE/EN-Desktop-/Mobil-Websicht nachgeholt; historische Mannschafts-
auswahl, breite Tagesplantafel und enge mobile Kopfzeile als Befunde offen.
Native Geraeteabnahme, FCM/APNs und Testmail weiterhin offen; kein echter Versand.
[Freigabe, Tests und Rueckweg](CLUBUNIO-NATIVE-PITCH-ACTIVATION.md).

## Halbplatzstandard (05.11.2026)

Training standardmaessig halber Platz, automatische A/B-Verteilung.95 bestehende
SV-Sissach-Trainings nach privater Sicherung zugeordnet;553 ohne Platz unveraendert.
ClubUnio-Release aktiviert; Konflikte/Sperren bleiben sichtbar und wirksam.
Keine automatische Platzfreigabe, keine PHPMaker-/Rechteaenderung.
[Nachweis und Migrationspfad](CLUBUNIO-TRAINING-HALVES.md).

## Neue Anforderung: Webchat (04.11.2026)

Chat mit fachlicher App-Paritaet in der normalen ClubUnio-Shell, gemeinsamer
Datenbasis und Pushweg; komfortabler Admin-/Backofficeversand mit Vorschau.
Gezielte Kommunikationsrechte statt globaler Adminfreigabe oder Einblick in
fremde Direktchats. Webpaket auf ClubUnio aktiviert und technisch geprueft; keine
automatische Profilzuordnung oder Aktivierung externer Dienste.
Funktionsmatrix: [Webchat](CLUBUNIO-WEB-CHAT.md),
[aktueller Abnahme-/Releasestand](CLUBUNIO-WEB-CHAT-RELEASE.md).

## Aufgenommene Pendenzen Platzplanung (04.11.2026)

- [x] Angemeldete DE/EN-Desktop-/Mobil-Websicht05.11.2026 nachgeholt; Befunde siehe Freigabeprotokoll.
- [x] Native Platzplanungsfreigabe05.11.2026 auf ClubUnio aktiviert.
- [ ] Echte native App-/Geraeteabnahme durchfuehren.
- [ ] Firebase/FCM/APNs konfigurieren, eigene Geraetebindung und isolierten Push testen.
- [ ] Testmail fuer neue Anfragen pruefen; keine E-Mail bei blossen Status-/Platzwechseln.
- [ ] Sieben mehrdeutige SFV-Zuordnungen pruefen; erst neue Vorschau bestaetigen, kein blindes Apply.
- [ ] Teilflaechen/Trainingszuordnungen, bestehende Sperren und Konflikte fachlich pruefen.
- [ ] Saisonbezogene Mannschaftsauswahl und Bezeichnungen in der Plantafel optimieren.
- [ ] Einmaligen Datenbankfehler im Plattform-Releasegate bei Wiederholung mit sicheren Diagnosen untersuchen.

Webrelease aktiv; Status, Nachweise und Migrationsgrenzen:
[Platzplanungs-Nachlauf](CLUBUNIO-PITCH-FOLLOWUP.md). Keine automatische
Aenderung von Bestandszuordnungen, Benutzerrechten oder PHPMaker DEV/PROD.

## DE/EN Fachoberflaechen (04.11.2026)

514 Bestandslabels, Fachdialoge, Lookup-Anzeigevarianten und Empfaengersprachen
nachgezogen. Katalogpruefung ohne historische Ausnahmen; kundeneigene Freitexte
bleiben original. Migration, Abnahme und Release: CLUBUNIO-LANGUAGE-COMPLETION.md.

## Saisonreferenzen gegen Migrationsbestand geprueft (03.11.2026)

23 Saisonfelder,17 Importzuordnungen/26746 Vergleiche und451 Planungsteilnahmen
ohne Mappingfehler.37 Saisonlabels und aktuelle Parameteraufloesung korrekt.
14 urspruengliche Beleg-/Teamsaison-Widersprueche nach Benutzerfreigabe nur in
ClubUnio korrigiert,191 Positionen unveraendert. Verbindliche Schritte fuer
spaetere Uebernahmen: CLUBUNIO-MIGRATION.md. Audit und Korrekturprotokoll:
CLUBUNIO-SEASON-REFERENCE-AUDIT.md und CLUBUNIO-EXPENSE-SEASON-CORRECTION.md.

## App-API auf aktuelle Saison begrenzen (03.11.2026)

Zentraler SQL-Scope fuer Teamplanung und Finanzen ueber general/SaisonID,
nicht ueber Kalenderdatum. Spesen-/Rechnungslisten und zugehoerige Lookups;
Rechte/Webhistorie erhalten, keine Datenmigration. CLUBUNIO-MOBILE-FINANCE-SEASON.md.

## Dokumentvorschau mit Zoom (03.11.2026)

PDFs/Bilder: Vergroessern, Verkleinern, Prozentwahl, Breiten-/Hoehenanpassung.
Gemeinsame Vorschau fuer Rechnungen, Dokumente und opt-in Anmeldeanhaenge.
Keine Daten-/Rechtemigration; CLUBUNIO-PREVIEW-ZOOM.md.

## Persoenlicher Benutzerzugriff im Mitgliederdossier (03.11.2026)

Eigener Tab fuer explizite Selbst-/Elternzuordnungen, Wirksamkeitsanzeige und
vorhandene Pruef-/Benutzereditoren. Keine Rollenauflistung statt Elternzugriff;
Trainer als Elternteil bleibt sichtbar. Bestehende Rechte/Services unveraendert,
keine DB-Migration. CLUBUNIO-MEMBER-PERSONAL-ACCESS.md.

## Kompakte Navigation und Dashboard (02.11.2026)

Suchfeld nur ueber Lupe, Desktop-Menuebreite per ziehbarem Rand mit bestehender
Benutzerspeicherung. Dashboard nur persoenliche Begruessung statt Profilkarte;
Abmeldung im Profilmenue. Keine DB-/Daten-/Rechte-Migration. Details:
CLUBUNIO-COMPACT-SHELL.md.

## Aufklappbare Fachnavigation (02.11.2026)

Saisons/Rechnungen mit unabhaengigen Pfeilbuttons und weiterhin direkten
Seitenlinks. Finanzkorrekturen/Beitragsberechnung unter Rechnungen;
Altplanuebernahme in Einstellungen. Quittungen und Adressliste fuer Massenversand
statt technischer Altbezeichnungen. Keine Daten-/Rechtemigration oder Import.
Details und Abnahme: CLUBUNIO-NAVIGATION-CLEANUP.md.

Mobile Sichtabnahme390px/320px abgeschlossen: Pfeile, Suche, Zielseiten und
Umbruch bestaetigt. Keine Code-/DB-Korrektur notwendig; nur Doku-Nachlauf.

## Saison-Untermenues (02.11.2026)

Saisonplanung, Mannschaften pro Saison, Spieler-, Staff- und TL-Zuordnungen sowie
Trainingszeiten unter Saisons gebuendelt. Bestehende direkte URLs, berechtigte
Unterbereiche ohne Saisonrecht und Menuesuche bleiben erhalten. Operative
Planung/Wettkaempfe/Trainingsplaene bleiben eigenstaendig. Keine DB-Migration,
Rechteerweiterung oder Datenuebernahme. CLUBUNIO-SEASON-NAVIGATION.md.

## Absenzen durch Mannschaftsverantwortliche (02.11.2026)

Trainer und technische Leiter duerfen Absenzen der aktiven Mitglieder ihrer
zugeordneten Teams erfassen/bearbeiten. Admin/Geschaeftsstelle auch ohne eigene
Mitgliedsverknuepfung fuer alle aktiven Vereinsmitglieder. Native Seitendialoge,
begrenzte Mitgliedersuche und frische serverseitige Pruefung beim Speichern.
Keine erweiterten Loeschrechte; Fristsperren, Revision, Audit und Datenschutz
erhalten. Keine DB-Migration oder Profil-/Datenuebernahme. Gezielter ClubUnio-
Release, PHPMaker DEV/PROD unveraendert. Details: CLUBUNIO-ABSENCE-MANAGEMENT.md.

## App-Kommunikation (02.11.2026, Entwicklungsstand)

Persoenlicher Login, saisongebundene Chats, Ankuendigungen, Umfragen,
Planungsadapter, Bexio-bestaetigte private Rechnungshinweise und KI-Adapter
lokal implementiert. Folgepaket: Bearbeiten/Loeschen, Gruppenmoderation,
Direktkontaktsperren, Mute, Empfaengervorschau, Aenderungscursor, private
Bild-/PDF-Quarantaene mit zwingendem Scanner und persistente Sprachwahl.
Migrationen82/83 vorbereitet, nicht angewendet; Modul/KI/Uploads aus.
36 Tests/343 Assertions,13 neue und10 bestehende Transportchecks bestanden.
Noch kein Abschluss P0-P6: Push/WebSocket, fachlicher Mitgliedschaftsabgleich,
Retention und Geraeteabnahme offen. DEV-Loginpfad live404; Deployment fehlt.
Details: CLUBUNIO-COMMUNICATION-API.md, CLUBUNIO-COMMUNICATION-AI.md und
CLUBUNIO-COMMUNICATION-PACKAGE2.md; App-Abnahme im mobilen Repository.

## Benutzersimulation (26.10.2026)

Additive Migration81 und persoenlich freizuschaltender Nur-Lesen-Supportmodus.
Kein bestehendes Konto automatisch freigeschaltet, keine Rechteveraenderung.
Web-Allowlist, feste Vereinsgrenze, eigenes Passwort, Supportgrund,15-Minuten-
Limit und Originaladmin-Protokoll. App/API-Simulation und interaktive Livewire-
Bedienung noch nicht enthalten. Details/Abnahme: CLUBUNIO-IMPERSONATION.md.

## API-Fachaktionen Bexio, SFV und KI (26.10.2026)

Durable verschluesselte API-Auftraege/Ergebnisse mit additiver Migration79.
Lokales Einreichen mit Idempotenz, explizite Ausfuehrung ausserhalb SQL-Transaktion,
keine automatische Wiederholung unklarer Provider-/KI-Aufrufe. Bexio bleibt
Admin-only mit objektbezogener Web-Freigabe und beiden Identitaeten im Journal.
SFV-Quelllesezugriff, Abruf, gepruefte Wettkampf-/Teamplanung-Uebernahme sowie
KI-Erstellung/Pruefung und privates Trainings-PDF. Bestehende Scopes, Fachrechte,
Fristen, Qualitaetsgates und Garderoben bleiben erhalten. Keine Profilvergabe,
Bankzahlung, API-Clientumstellung oder Aenderung an PHPMaker DEV/PROD.
Details und Abnahme: CLUBUNIO-API-PROVIDERS.md.
Deployment erfolgreich,214 Regressionstests plus70 ueberlappende gezielte
Abschlusspruefungen gruen. HTTPS-Einreichen/Wiederholung/Status mit isoliertem
QA-Verein geprueft, Testzugriffe deaktiviert. Swagger1.1.0 und Verwaltungsansicht
angemeldet geprueft; keine echten Provider-Schreibtests oder Zahlungen.

## Eigene API-Plattform (25.10.2026)

API-Aufbau ausdruecklich freigegeben; neuer unabhaengiger v1-Vertrag mit
technischen Integrationen, bestehenden Rechteprofilen und eingeschraenkten
Scopes. Admin-only Tokenverwaltung/Swagger/OpenAPI, lesende Webseite-DTOs,
explizite Fachadapter, private Dateizugriffe und bestehende Upload-Wiederaufnahme.
Migration76 additiv nur ClubUnio, keine bestehenden Rechte erweitert oder
Client-Tokens automatisch angelegt. FK78 fuer die Profil-/Vereinsgrenze gezielt
angewendet. Finale API-Gruppe119 Tests/26,554 Assertions gruen; reale HTTPS-
Abnahme mit isoliertem QA-Verein und angemeldete Swagger-/Verwaltungspruefung
erfolgreich. Release20261026-clubunio-api-v1-final ergaenzt responsive Scope-
Auswahl und no-store fuer Tokenantworten. Abdeckungsmatrix benennt bewusst
offene Spezialadapter, keine pauschale Vollabnahme aller Webfunktionen.
App-Neubau folgt separat; SaaS/Betrieb und PHPMaker DEV/PROD
bleiben unveraendert. Siehe CLUBUNIO-API.md.

## Gezielte Bexio-Gutschriftzuordnung (25.10.2026)

Offizielle Doku und Sandbox-Oberflaeche bestaetigen unter Verkauf > Mehr >
Zahlungen erfassen (Manuell) ein Betragsfeld plus Zielrechnungs-Auswahl
pro Gutschrift. Abschluss26.10.2026: GS-00007 mit10 auf RE-00015 (Payment36)
und20 auf RE-00016 (Payment37) verrechnet; ClubUnio-Salden10/5,
Kundenguthaben110 Rest0, Korrektur427 confirmed. Nachweise in
CLUBUNIO-FINANCE-ACCEPTANCE.md. Kein automatischer API-Schreibweg daraus
ableiten. Keine Runtime-/Schema-/Datenmigration und kein Deployment noetig;
bestehende Buchungen sowie PHPMaker DEV/PROD unveraendert.

## Sponsorenlauf in CHF (25.10.2026)

Betragseingaben und Anzeigen durchgehend CHF: Verwaltung, Mitgliederportal und
oeffentliche Webzusage. Serveradapter rechnet exakt in interne Ganzzahl-Rappen,
Punkt/Komma und maximal zwei Nachkommastellen, optionale Obergrenze bleibt null.
Keine Umrechnung bestehender DB-Werte, keine Schema-/Rechte-/App-API-Aenderung,
kein Bexio-Versand oder Bankzahlung. CLUBUNIO-SPONSOR-RUN.md. Release
20261025-clubunio-sponsor-chf, Rueckweg sponsor-visual; Legacy DEV/PROD unveraendert.

## Sichtabnahme Dokumentenserien/Sponsorenlauf (25.10.2026)

Angemeldete Chrome-Pruefung ueber ClubUnio/Pi-Proxy abgeschlossen: Historie,
Vorlageneditor ohne Speichern, private Serie41, Anlass/Teilnehmer134 und
Abrechnung42 am Desktop sowie390px. Layoutluecken/Checkboxbreite korrigiert,
breite Tabellen innen scrollbar und Statusbeschriftungen nicht abgeschnitten.
Reines Blade-Delta deployed, keine Schema-/Rechte-/Fachaenderung oder externer
Schreibaufruf. Testdaten unveraendert; PDF vom Benutzer selbst geprueft, weil
dessen Viewer-Erweiterung die Browsersteuerung blockierte. Genaue Testgrenzen:
CLUBUNIO-SPONSOR-DOCUMENT-ACCEPTANCE.md. API/SaaS/Betrieb/Legacy unveraendert.

## Azure-/Bexio-Live-Abnahme (25.10.2026)

Dokumentserie41 mit neuem privatem Azure-PDF erfolgreich, identische SHA256 beim
direkten Ruecklesen und geschuetzten Webabruf, anonym404, keine Duplikate.
Sponsorenlauf134 mit synthetischen Daten, fuenf Runden und CHF3.00-Obergrenze;
Bexio RE-00017 angelegt/ausgestellt, Testversand an Admin bestaetigt, Status
Versendet zurueckgelesen. Keine echte Zahlung. Laenderfeld im Adapter korrigiert
(`iso_3166_alpha2`), Testdouble nach realem Vertrag nachgezogen. Vorherige
Versandkonfiguration wiederhergestellt, QA-Referenzsaison/Teamzuordnungen inaktiv.
Originalbestand unveraendert; immutable QA-Evidenz erhalten. Drei Live-Webkernel-
Tests/27 Assertions sowie14 Provider-/Billing-Tests/73 Assertions bestanden.
Manuelle angemeldete Desktop-/Mobilpruefung anschliessend durchgefuehrt, siehe oben.
CLUBUNIO-SPONSOR-DOCUMENT-ACCEPTANCE.md. Kein API-/SaaS-/Betriebs- oder Legacy-Delta.

## Dokumentenserien und Sponsorenlauf (25.10.2026)

Zwei Legacy-Erweiterungen als native ClubUnio-Webmodule umgesetzt. Serien mit
unveraenderlichen Vorlagenversionen/Empfaengersnapshots, frischer Vorschau,
wiederaufnehmbarer privater PDF-Ablage und getrennten geprueften Rueckgaben.
Sponsorenlauf mit Saison-/Teambezug, Teilnehmerbestand, Helferrechten, Runden-
Freigabe, geschuetzten Zusagen und widerrufbaren Aliaslinks. Oeffentliche Zusagen
bleiben bis bewusster Admin-Aktivierung gesperrt; Testmail nur an Admin.
Einzelabrechnung mit expliziten Buchhaltungszuordnungen, fixer Sandbox-Pruefung,
durabler Ausfuehrungsabsicht und Sync-Auftraegen ohne blinde Schreibwiederholung.
Migrationen73/74/75 ausschliesslich ClubUnio; keine Legacy-Datenuebernahme,
kein Refresh und keine neue Rechtevorlage. Vorlagen/Belege bei Rollback erhalten.
Details, Abnahmegrenzen und offene Live-Pruefungen in CLUBUNIO-DOCUMENT-SERIES.md,
CLUBUNIO-SPONSOR-RUN.md und CLUBUNIO-SPONSOR-BILLING.md.
App-API, SaaS, Betrieb und bestehendes PHPMaker DEV/PROD unveraendert.

## Saisonplanung fachlich-technisch abgenommen (23.10.2026)

Gesamtablauf einschliesslich verbindlichem Browserabschluss nur mit isolierten
Testdaten durchgefuehrt: drei Teams, drei Preise/Regeln, elf Zuordnungen, drei
Trainingszeiten; alle25 Ergebnisverknuepfungen korrekt. Quellsaetze und echter
Vereinsbestand unveraendert; temporaere QA-Daten/Zuordnung entfernt. Staff-/TL-
Drag nun visuell bestaetigt. Staff-Funktionsuebergabe beim Drag und Mitglied-ID-
Suche korrigiert; Abschluss mit expliziter Seitenbestaetigung. ClubUnio deployed,
keine Migration oder Rechteerweiterung. Technische Restabnahme des Wizards erledigt;
Freigabe eines echten Vereinsplans bleibt Aufgabe der Verantwortlichen.
CLUBUNIO-SEASON-ACCEPTANCE.md. API/SaaS/Betrieb/PHPMaker DEV/PROD unveraendert.

## Wizard-Interaktionen abgeschlossen (23.10.2026)

Echtes Drag-and-drop aus Pool und zwischen Mannschaften geprueft. Voll befuellte
Trainingszeit nach Neuladen erhalten; schneller Autosave ueberschreibt keine
laufenden Eingaben mehr. Neue Planung/Verwerfen mit Seitenbestaetigung, Verwerfen
im Browser erfolgreich. QA136 verworfen, Entwurf112 unveraendert, keine Saison
verbindlich angelegt und keine Fachtabellen befuellt. Desktop/390px geprueft.
Korrektur auf ClubUnio deployed; keine Migration oder Rechteaenderung.
Automatisierte Tests decken zusaetzlich Spieler/Staff/TL-Verschieben und alle
Trainingszeitfelder ab. Vollstaendige Fachabnahme bleibt davon getrennt.
API/SaaS/Betrieb und PHPMaker DEV/PROD bleiben ausgeschlossen.
CLUBUNIO-SEASON-PLANNING.md.

## Wizard-Sichtpruefung und Anzeige (23.10.2026)

Browser-Plugin erlaubt angemeldete Desktop-/390px-Sichtpruefung. Kein verbindlicher
Abschluss. Korrekturen: Navigation unter Sport, Firmen-/ID-Fallback statt leerer
Mitgliederzeilen samt Suche, begrenzter Pool-Scrollbereich fuer schmale Ansichten.
Keine Rechte-/Schema-/Fachregel-Aenderung. Korrekturen deployed und nach Verbindungs-
abbruch in frischem angemeldetem Tab visuell bestaetigt, Desktop/390px ohne
horizontalen Ueberlauf. Gefuellte Trainingszeiten und echtes Drag-and-drop noch
offen. CLUBUNIO-SEASON-PLANNING.md. PHPMaker DEV/PROD unveraendert.

## Profil-Seed-Regression und Wizard-Sichtpruefung (22.10.2026)

Seed71-Test prueft seine eingefrorene Rechtevorlage statt heutiger Modulvorlage.
Zusaetzlicher Test schuetzt vorhandene Profile und Benutzerzuordnungen vor
Ueberschreiben/Rechteerweiterung. Nur Tests/Doku; keine Migration, Rechteaenderung
oder neuer Runtime-Release. Wizard-Sichtpruefung erneut durch Browsersteuerung
wegen nicht zuverlaessig feststellbarer URL blockiert und weiterhin offen.
Alle30 Profil-/Wizard-Tests mit220 Assertions erfolgreich, ohne Testausschluss.
Details: CLUBUNIO-SEASON-PLANNING.md. API/SaaS/Betrieb/PHPMaker DEV/PROD unveraendert.

## Saisonplanungs-Wizard (21.10.2026)

Sieben Webschritte mit verschluesselten, versionierten Entwuerfen; Saisonvorlage,
Mannschaften, neue Beitraege/Regeln, Spielerpool mit Vorschlaegen/Drag-and-drop,
Staff/TL, Trainingszeiten und frischer Abschlusspruefung. Erst bestaetigter
Abschluss erstellt alle Objekte gemeinsam ueber bestehende Fachservices.
Rollback bei Fehlern und idempotenter Wiederholungsabschluss; keine Rechnungen,
Nachrichten oder Umstellung der aktuellen Saison. Migration72 nur in ClubUnio:
season_plans/season_plan_results. Kein Bestandsimport/PHPMaker-Eingriff.
Angemeldete visuelle/fachliche Gesamtplan-Abnahme bleibt offen; Computersteuerung
hat Browserzugriff sicherheitsbedingt beendet. CLUBUNIO-SEASON-PLANNING.md.
API, SaaS und Betrieb weiterhin ausgeschlossen.

## Zwei-Zielrechnungen-Sandbox vorbereitet (19.10.2026)

Neue synthetische Rechnungen RE-00014/15/16 und lokale Korrektur427,
ohne alte Belege zu veraendern, E-Mails oder Zahlungen auszuloesen.
Manuell eine neue GS30 erstellen, zuerst10 auf RE-00015, nach Pruefung20
auf RE-00016. Provider- und lokaler Zwei-Schritt-Nachweis bleiben offen.
Details: CLUBUNIO-FINANCE-ACCEPTANCE.md. Keine Schema-/Runtimeaenderung;
Fixtures nicht produktiv migrieren. PHPMaker DEV/PROD unveraendert.
Zwischenpruefung: gemeldete CHF10-Verrechnung auf RE-00015 im direkten
Providerabruf noch nicht sichtbar (Rest20, keine Verrechnungsnachweise).
GS-Nummer/Firma klaeren, zweite Teilverrechnung noch nicht freigegeben.

## Finanz-Abnahmeabgleich (19.10.2026)

GS-00004/RE-00009 und GS-00005/RE-00013 erneut lesend live bestaetigt;
veraltete Offen-Vermerke berichtigt. Naechste Fachabnahme: ein Guthaben auf
zwei Zielrechnungen verteilen. Keine erneute Anlage bestehender Gutschriften,
keine Schema-/Runtimeaenderung. CLUBUNIO-FINANCE-ACCEPTANCE.md.

## Performance-Gesamtmessung (19.10.2026)

Zwoelf Web-Lifecycle-Szenarien je dreimal gemessen; synthetisches Speichern,
Trainings-PDF/Kopie und grosse Historie separat mit Rollback. Wiederholbare
CLI-Suite mit Median/Spannweite, Abfragen/DB-Zeit, ohne Identitaetsausgabe.
Kein weiterer belegter Fachcode-Engpass; keine Rechte-/Cache-/Schemaaenderung.
Browser-Ende-zu-Ende-Abnahme bleibt offen, Steuerung aus Sicherheitsgruenden
beendet. Messwerte/Grenzen: CLUBUNIO-PERFORMANCE-ACCEPTANCE.md.

## Platzwart-Einschraenkung (19.10.2026)

Eigenes Modul fuer Platzverfuegbarkeit/Bemerkungen, keine Status-Wertetabelle.
Bestehende breite Anlagenrechte anderer Profile erhalten. Profilupdate ohne
Benutzerzuordnung oder Schemamigration. CLUBUNIO-CARETAKER-SCOPE.md.

## Synchronisations-/Protokollperformance (18.10.2026)

Uebersicht ohne vorab geladene Verlaufseintraege; pro geoeffnetem Auftrag
20 Eintraege, aeltere ueber separate Pagination vollstaendig erreichbar.
Listenprojektion ohne technische Tokens und ohne ungenutzte Payloads.
Frische Admin-/Vereinspruefung beim Oeffnen und Blaettern. Keine Historie
geloescht, keine Aenderung der Sync-Ausfuehrung oder Rechte. Keine Migration.
CLUBUNIO-SYNC-VIEW-PERFORMANCE.md.

## Vorbereitete SVS-Profile (18.10.2026)

Sieben Verwaltungsprofile inklusive Platzwart angelegt, ohne Benutzerzuordnung.
Bestehende Rechte unveraendert; keine Schema- oder Anwendungsmigration.
Definition, Pruefung und Migrationshinweise: CLUBUNIO-SVS-ACCESS-PROFILES.md.

## Trainingsplan-Performance (18.10.2026)

Planliste nur mit sichtbaren Metadaten statt vollstaendigen Plan-JSONs;
Teamlabels einmal indizieren. Unbeschraenkte Fachleserechte brauchen keine
ungenutzte Teamliste beim Detail-/PDF-Zugriff. Frische Rechte und Trainer-
Teamscopes bleiben erhalten. KI-/Kopierlimits unter gleichem Lock gemeinsam
zaehlen. Keine KI-Vertrags-/Qualitaets- oder Datenbankaenderung.
CLUBUNIO-TRAINING-PERFORMANCE.md.

## Dossierdialoge (18.10.2026)

Drei Browser-Abnahmebefunde korrigiert: verschachtelte Rechnungs-Audits,
explizite Tabellenaktualisierung beim Positionsdialogschluss und Ladezustand
der Relation-Tabs; deutsche Positions-/Verlaufsbeschriftungen. Keine Migration
oder Benutzerrechteaenderung. CLUBUNIO-DOSSIER-UI-FIXES.md.

## Serien-/Absenzperformance (18.10.2026)

Kapazitaet im SQL zaehlen statt Teilnehmerdaten zu laden. Absenzperioden
innerhalb eines gesperrten Schreibvorgangs einmal lesen; Terminabgleich nur
fuer das betroffene Mitglied. Serienvorschau verwendet unveraenderliche
Datumswerte erneut. Rechte, Fristsperren, Revisionen und Audit erhalten.
Keine Migration. CLUBUNIO-SERIES-ABSENCE-PERFORMANCE.md.

## Beitrags-/Entwurfsperformance (17.10.2026)

25er-Stapelvorschau mit gebuendelter Saison-/Regel-/Preis-/Familien-/Positions-
Grundlage und gemeinsamer Ergebnisberechnung. Einzelentwurf weiterhin frisch
unter bestehenden Locks, Spieler-Verknuepfungen einmal lesen. Rechte, Rappen,
Fingerprints und Audit erhalten. Kein Bexio-Versand, keine Migration.
CLUBUNIO-CONTRIBUTION-PERFORMANCE.md.

## Profilabnahme (17.10.2026)

Geschaeftsstelle nur lesen und Finanzen bearbeiten automatisiert abgenommen:
6 Tests/564 Assertions inklusive Ressourcen-/Untertabellenpruefung. Testdaten
zurueckgerollt, bestehende Rechte per Fingerprint unveraendert. Keine Migration,
kein Deployment, keine Benutzerumstellung. Details: CLUBUNIO-PROFILE-ACCEPTANCE.md.

Manueller Browserdurchlauf auf dev.clubunio.ch mit zwei separaten Testkonten
ebenfalls erfolgt: Rechtegrenzen, Rechnungs-/Positions-/Spesenbearbeitung und
PDF bestaetigt, Testdaten anschliessend entfernt. Offen aus der UI-Abnahme:
Rechnungsverlauf entpackt after_value.invoice nicht, Elternzeile/Tab nach
verschachtelten Dialogen nicht frisch, Positionsaktion "Edit line" unuebersetzt.
Keine pauschale Vollabnahme aller Dialoge; Details und Bildbelege im Testbericht.

## Saison-/Mehrfachaktionsperformance (17.10.2026)

Saisonvorschau mit gruppierten Zuordnungen, gemeinsam geladenen Mitgliedern
und passenden Zielteams. Auswahl-Select nur ID/Label; Snapshot-Lock-Reads
fuer Mehrfachaktionen gebuendelt. Frische Schreibrechte, Versionen, HMAC und
Audit unveraendert. Keine Migration. CLUBUNIO-SEASON-BULK-PERFORMANCE.md.

## Rundnachrichten-Performance (16.10.2026)

Benutzer-/Mannschaftsauswahl mit Serversuche statt voller Listen,50 Treffer
und separate Labels fuer bis500 gewaehlte IDs. Statuskatalog je Tabellenaufbau
statt je Zeile; frische Empfaenger-/Versandpruefung und atomare Zustellung
unveraendert. Keine Migration. CLUBUNIO-ANNOUNCEMENT-PERFORMANCE.md.

## Formular-/Lookupperformance (16.10.2026)

Mitgliederauswahl mit begrenzten Anzeigespalten; Spesen-Bankkontoauswahl ohne
Leerabfrage und mit schmaler Projektion. Lookuppruefung beim Mitgliedersave
pro Tabelle gebuendelt, Status-/Empfaengerdaten im selben Save wiederverwendet.
Frische Rechte, Feldabhaengigkeiten, Version/Audit erhalten; keine Migration.
Details und Testgrenzen: CLUBUNIO-FORM-PERFORMANCE.md.

## PDF-/Vorschauperformance (16.10.2026)

Bexio-PDF ohne unbenutzte lokale Relationen, PDF.js direkt im Vorschaufenster
statt zusaetzlicher Viewer-HTML-Anfrage. Worker-/Canvas-Cleanup bei Schliessen
und Dokumentwechsel. Frische Dokument-/Sandboxpruefung und Downloads erhalten,
kein Dokumentcache, keine Migration. Details: CLUBUNIO-PDF-PERFORMANCE.md.

## Signage-/Anlagen-/Busperformance (16.10.2026)

Signage-Lookups innerhalb eines Requests gesammelt, Design-/Periodenwerte
pro Aufbau wiederverwendet. Bus-Neuanlage ohne doppelte Teamliste und mit
minimalen Mitgliederauswahlfeldern; Labels per ID statt wiederholter Suche.
Frische Aktionsrechte, oeffentliche Freigaben und historische Garderoben
erhalten. Keine Migration. Details: CLUBUNIO-SIGNAGE-BUS-PERFORMANCE.md.

## Dynamische Rollen (16.10.2026)

Modul-/Funktionsprofile mit Nur-Lesen-Vorlage, Admin-Editor, Benutzerzuordnung,
Revision und Audit. Arbeitsbereichszugang getrennt von konkreten Fachrechten.
Explizite Pruefungen in Fachservices, Policies, Webaufrufen, Untertabellen,
Livewire-Aktionen, Downloads, Exporten und Versand. Bestehende Portalfilter
bleiben erhalten; Profile erhalten keine Portalrolle und keine impliziten
Office-Rechte. Ein Rechteentzug gilt auch fuer bereits geoeffnete Dialoge.
Migrationen69/70 plus Aktivierung71; keine bestehenden Konten umstellen.
Gesamtpruefung bestanden und auf ClubUnio bereitgestellt; Abschlussstand in PI.md. Details und
Zugriffsgrenzen: CLUBUNIO-DYNAMIC-ROLES.md. PHPMaker DEV/PROD unveraendert.

## Dossier-Auswahlaktionen (16.10.2026)

Mehrfachstatus fuer Funktionen/Sponsoring/Veranstaltungen im Mitgliederdossier;
gezielte Mannschaftsauswahl bei Saisonuebernahme. Gleiche Fachservices,
Mandantenbindung, Revisionen, Audit und atomare Konfliktbehandlung. Keine
Schema-/Datenmigration; PHPMaker DEV/PROD unveraendert. Details in
CLUBUNIO-DOSSIER-COMFORT.md.

## Listen-/Exportperformance (16.10.2026)

Vereinsrelation fuer acht Auswertungsressourcen gesammelt laden; CSV-Exporte
ohne reine Anzeige-Relationen und mit begrenzter Feldauswahl. Finanz-CSV vor
Header-Versand aufbereitet. Rechte, Filter/Sortierung, 5000er-Grenze, Maskierung
und Audit erhalten; keine Migration. Details: CLUBUNIO-REPORT-PERFORMANCE.md.

## Dossier-Komfortfunktionen (16.10.2026)

Atomare Mehrfachaktionen fuer Zuordnungen, lesender Aenderungsverlauf und
Saisonuebernahme mit signierter Vorschau/Dublettenpruefung. Keine DB-Migration;
vorhandene Objekt-IDs/Auditdaten bleiben bei spaeterer Migration erhalten.
Details und Grenzen: CLUBUNIO-DOSSIER-COMFORT.md. PHPMaker DEV/PROD unveraendert.

## Spesen-/Dokumentperformance (16.10.2026)

Anmeldeliste ohne Mandantenabfrage pro Zeile, Spesenpositionsbuttons mit
begrenztem Render-Lesecheck und Anhangvorschau erst auf Abruf. Fachaktionen,
Azure-Sicherheitsgrenzen und Fehlerreferenzen unveraendert. Keine Migration
und kein PHPMaker DEV/PROD-Eingriff. Details:
[Spesen und Dokumente](CLUBUNIO-EXPENSE-DOCUMENT-PERFORMANCE.md).

## Verbleibende DB-Leseabfragen (16.10.2026)

Menue-/Seitensichtbarkeit und bestaetigter Backoffice-Leseeinstieg nutzen den
vorhandenen Requestkontext. Admin/Office und Mandanten weiterhin getrennt;
Schreibaktionen und Portalrollen bleiben frisch. Team-/Einladungsfilter als
SQL-Unterabfragen statt vorab geladener ID-Listen. Keine DB-Migration.
Tests und Messungen: CLUBUNIO-DB-READ-PERFORMANCE.md.
PHPMaker DEV/PROD, API und SaaS unveraendert.

## Untertabellen: restliche Aktionen (16.10.2026)

Wiederherstellen fuer Zuordnungen, direkte Aktivstatusaktionen, sichere
Positionskopien und Schluesselrueckgabe. Bestehende Fachservices und Sperren
erhalten; keine DB-Migration. Details: CLUBUNIO-RELATION-ACTIONS.md.
PHPMaker DEV/PROD unveraendert; API/SaaS/Betrieb nicht erweitert.

## Teamplanung- und Kalenderperformance (16.10.2026)

Wiederholte Datumsumwandlungen fuer Konflikte/Tageszellen gebuendelt,
bereits autorisierte Teamlabels wiederverwendet. Sichtbarkeits-, Rollen-,
Einladungs- und Fristsperren unveraendert, kein Rechtecache. Referenztests
einschliesslich Mikrosekunden und Zeitumstellung sowie Portal-Entzugstest.
Keine Datenmigration. Details: CLUBUNIO-PLANNING-PERFORMANCE.md.
PHPMaker DEV/PROD, API und SaaS unveraendert.

## Dashboard- und Detailperformance (16.10.2026)

Kennzahlen, Statusdiagramme und Rechnungsdetail-Summen mit gebuendelten
Abfragen; fachlich unpassende Bexio-Aktionen kurzgeschlossen, ohne Rechte
zu cachen. Mitglieder-/Spesendetails und Dossier-Untertabellen mitgeprueft.
Frische Rollen-/Schreibpruefungen, Betrags- und Austrittsregeln erhalten.
Keine Datenmigration. Details: CLUBUNIO-DASHBOARD-DETAIL-PERFORMANCE.md.
PHPMaker DEV/PROD, API und SaaS unveraendert.

## Dossier-Kopien und Aktivstatus (16.10.2026)

Funktionen, Sponsoring und Veranstaltungszuordnungen: direkte Kopier-,
Deaktivierungs- und Wiederherstellungsaktionen. Versionsschutz und Audit
ueber bestehenden Fachservice. Keine DB-Migration. Details:
CLUBUNIO-DOSSIER-LIFECYCLE.md. PHPMaker DEV/PROD unveraendert.

## Filter-/Suchperformance mit frischen Aktionsrechten (16.10.2026)

Livewire-Darstellung buendelt wiederholte Mandantenpruefungen nur waehrend
des Renderns. Eingaben, Aktionen und verschachtelte Mounts bleiben frisch;
kein Rechtecache zwischen Anfragen. Filter plus Suche, Mandantentrennung,
Rollenentzug und Ausnahme-Cleanup getestet. Keine Datenmigration; bestehende
Import-/Fachregeln bleiben unveraendert. Details: CLUBUNIO-PERFORMANCE.md.
Nur ClubUnio; PHPMaker DEV/PROD, API und SaaS unveraendert.

## Weitere Dossierdialoge (16.10.2026)

Familienzuordnungen in beide Richtungen, Anmeldungspruefung im Dossier und
eingebettete Guthaben-/Gutschrift-/Rueckerstattungsseiten ergaenzt.
Bestehende Fachservices, Rechte und Nachweisregeln bleiben massgeblich.
Keine Schema-/Datenmigration. Details: CLUBUNIO-DOSSIER-DIALOGS.md.
PHPMaker DEV/PROD unveraendert; API, SaaS und Betrieb ausgeschlossen.

## Mitglieder-/Mannschaftsdossiers: Finanzdialoge (16.10.2026)

Rechnungen und Spesen direkt bearbeiten, Positionen im rechten Dialog.
Neue Belege im Mitgliederdossier; neue Spesen auch bei Mannschaften.
Mannschaftsrechnungen nur ueber explizite Beitragspositionszuordnung.
Keine DB-Migration; Zuordnungen beim spaeteren Import erhalten.
Details: CLUBUNIO-DOSSIER-FINANCE.md. PHPMaker DEV/PROD unveraendert.

## Saison: Rechnungen und Spesen direkt bearbeiten (16.10.2026)

Belegkopf/Neuanlage und wiederverwendete Positionsmanager im Saison-Dialog;
interne Rechnungsfreigabe/Storno/PDF sowie Spesenstatus ohne Menuewechsel.
Serverseitige Saisonbindung und unveraenderte Fach-/Revisionssperren.
Keine DB-Migration. Details und Grenzen: CLUBUNIO-SEASON-FINANCE.md.
PHPMaker DEV/PROD, API, SaaS und Betrieb unveraendert.

## Performance73: Abfragen statt mehr RAM (16.10.2026)

Messungen zeigen wiederholte Mandanten-/Rollenpruefungen als Hauptanteil der
Seitenwartezeit. Rechtekontext nur waehrend eines GET, erneute Pruefung bei
jedem Request/Write, Tenant-Beziehungen gebuendelt. Keine Ergebnis-/Rechtecaches
ueber Requests hinweg und keine DB-Migration. ClubUnio-FPM-Pool mit zwei warmen
Prozessen, maximal acht parallelen Anfragen statt vier; Speicher pro Request
bleibt256M. Pruefungen, Vergleichswerte und Ruecknahme: CLUBUNIO-PERFORMANCE.md.
PHPMaker DEV/PROD, API und SaaS bleiben unveraendert.

## Navigation: angemeldete Sichtpruefung (16.10.2026)

Desktop/Mobile-Stichprobe abgeschlossen, Suchtreffer in eingeklappten
Menuegruppen korrigiert. Details: CLUBUNIO-NAVIGATION.md.
Reines JS/CSS/View-Delta, keine Datenmigration oder Berechtigungsaenderung.
PHPMaker DEV/PROD, API, SaaS und Betrieb unveraendert.

## Gezielter Betriebsschutz Nginx (16.10.2026)

Auf ausdruecklichen Benutzerauftrag nach dem Adress-/Startausfall:
Systemd-Wiederanlaufregel fuer bestehenden Pi-Nginx, zehn Sekunden Pause,
keine dauerhafte Startversuchssperre. Keine neue Netzwerk-/Proxyfreigabe und
keine DB-/Appmigration. Isolierter Nginx-Test statt echter Netzunterbrechung.
Versionierte Quelle, Installer, Tests und Ruecknahme:
CLUBUNIO-NGINX-RECOVERY.md. Andere Betriebs-/API-/SaaS-Pakete bleiben geparkt;
PHPMaker DEV/PROD und aktueller ClubUnio-Anwendungsrelease unveraendert.

## Eigener lokaler Projektordner (16.10.2026)

ClubUnio liegt jetzt physisch unter C:\ClubUnio mit unveraendertem eigenem
Git-Repository. Alter SVS-Pfad bleibt Junction, PHPMaker-Quellpfade fuer reine
CLI-Migration explizit getrennt. Kein DB-/Servermigrationsschritt, kein neues
Deployment. Details: CLUBUNIO-LOCAL-WORKSPACE.md. Neue Arbeiten nur im eigenen
Arbeitsordner, keine weiteren Projektkopien erstellen.

## Fachpaket72: Geschuetzte Dokumentuploads

Additive eigene Migration68: verschluesselte Binaer-/Metadaten, SHA256 und
unveraenderlicher Azure-Zielpfad in document_uploads; echte NULL-Akteure fuer
oeffentliche Anmeldungen im Syncjournal. Mitglieder-/Anmelde-/Spesenanhaenge
erst nach bestaetigter Uebertragung publizieren, Timeout mit gleichem Pfad
lesend aufloesen; kein blindes Neuhochladen. Zentrale manuelle Azure-Pruefqueue,
frische Quellrechte, Speicherlimits, Freigabesperre bei offenen Dateien.
Keine Altdateimigration/-loeschung. Ruecknahme behaelt Staging und APP_KEY.
Details/Migrationsschritte: CLUBUNIO-DOCUMENT-UPLOADS.md. API, SaaS, Betrieb
und PHPMaker DEV/PROD unveraendert. GS-00005 mit RE-00013 auf Kontakt3 im
Providerweb verrechnet und in ClubUnio bestaetigt:10.00 Guthaben verbraucht,
Rechnung20.00/offen10.00. OriginalRE-00006 unveraendert. Keine Bankzahlung.

## Aufgabenorientiertes Menue (16.10.2026)

Dashboard vor Mitglieder/Sport/Finanzen/Anlagen & Kommunikation/Auswertungen.
Stammdaten und Konfigurationen hinter einer gegliederten, durchsuchbaren
Einstellungen-Uebersicht statt Einzelpunkten in der Hauptnavigation.
Nur bereits autorisierte Links werden umgeordnet, alle Direktlinks bleiben.
Kein DB-Migrationsschritt. Details und Pruefungen: CLUBUNIO-NAVIGATION.md.

## Navigation und Dossierbearbeitung (16.10.2026)

Menuesuche, benutzerbezogene Desktopbreite, Fachicons und gegliedertes
Mitgliederdossier. Saison-/Mannschafts-/Mitgliederzuordnungen, Beitragsregeln
und Trainingszeiten direkt im Dossier pflegen; passende Hauptlisten mit
Kopieren/Deaktivieren. Historie und Fachvalidierung bleiben erhalten.
Keine DB-Migration. Finanzbearbeitung aus Saison ist inzwischen separat
ergänzt: CLUBUNIO-SEASON-FINANCE.md. Umfang: CLUBUNIO-WORKSPACE-EDITING.md.
PHPMaker DEV/PROD und API/SaaS bleiben unveraendert.

## Fachpaket71: Synchronisation und normale Bexio-Bedienung

Eigene additive Migration67 speichert mandantengebundene Syncauftraege und
unveraenderliche Ereignisse. Bexio-Uebertragung/-Aktionen/-Status/Katalog und
SFV-Abruf werden vor externem Zugriff erfasst; KI-Fehler werden zur Pruefung
gesichert. Bestehende Fehlerjournale koennen ohne externe Writes uebernommen
werden. Nur Adminwiederholung mit Lease/Revision und unveraenderter Verbindung;
unklare Bexio-Writes nur abgleichen, nie blind erneut senden. Manueller Abschluss
ist kein Synchronisationsnachweis. Normale Rechnungsaktionen am Objekt,
technische Testseiten nicht mehr in der Navigation. Azure-Upload-Staging und
weitere externe Versandadapter sind ein gesonderter Ausbau, kein stiller
Fallback. Kein neuer Cron/Worker. Details: CLUBUNIO-SYNCHRONISATION.md.
GS-00005 in Bexio mit10.00 inkl.0.75 MWST bestaetigt, noch unverrechnet.
PHPMaker DEV/PROD, API, SaaS und Betrieb unveraendert.

## Fachpaket69: Uebrige Verwaltungswege

Bexio-Verbindung in Filament-Layout, zentraler Mandant und stale-tab-geschuetzter
Verbindungsstart; Dokumentrouten ohne Vereinskennung. Kein DB-Schritt, keine
Neuverbindung. Oeffentliche Signage/Anmeldung und eingeschraenktes Portal bleiben
bewusst getrennt. PDF-Drawer bleibt rahmenlos. Details: CLUBUNIO-WORKSPACE.md.
Nur ClubUnio; bestehendes DEV/PROD und API/SaaS unveraendert.

## Fachpaket68: Einheitliche Bus- und Planungsverwaltung

Bus, Teamtermine, Absenzen, SFV und Trainingsplaene nutzen die gemeinsame
Filament-Navigation mit aktivem Sitzungsmandanten statt Vereinskennung in URLs.
Teamkalender bleibt in der Teamplanung; optionale Busbelegung dort bleibt.
Vorhandene Fachservices und eingeschraenkte Portalwege werden nicht ersetzt.
Formularnachweis mit Mandantenrevision, alte Verwaltungslinks nur bei passendem
Kontext, keine Weiterleitung alter Schreibaktionen. Auch Routencache und
Livewire-Folgeaufrufe erhalten den Kontext. Keine DB-Migration oder Datenkopie.
Spaetere Portal-/App-Migration gesondert planen, nicht mit dieser Umstellung
vermischen. Details und Rollback: CLUBUNIO-WORKSPACE.md.
PHPMaker DEV/PROD, API, SaaS und Betriebsautomatisierung bleiben unveraendert.

## Fachpaket67: Teilkorrekturen / Kundenguthaben / Web-Fachabgleich

Neue eigene Migration66: Kundenguthaben und unveraenderliches Verrechnungsjournal.
Adminregistrierung aus freigegebener Originalkorrektur mit manueller
Steuer-/Betragsattestation; providerbestaetigte Zuordnung auf mehrere Rechnungen
des gleichen verifizierten Rechnungsempfaengers. Rest und Nachweise im
Mitgliederdossier und auf Zielrechnungen; kein freies Geld-/Rueckerstattungskonto.
PMP-Matrix fortgeschrieben und Metadatencheck konsistent. Stammdatenpflege
prueft nun auch den Benutzer-Aktivstatus frisch. Keine Rueckmigration.
Live-Steuerfall18/RE-00006 bleibt bis manuell neu angelegter GS offen;
RE-00009/GS-00004 lesend bestaetigt. Details/Grenzen:CLUBUNIO-CUSTOMER-CREDITS.md.
API/SaaS/Betrieb und PHPMaker DEV/PROD bleiben unveraendert.

## Fachpaket66: Vereinsbezogenes Signage

Kompakte TV-Ansicht nach SVS-Referenz, gemeinsame Vorschau/oeffentliche Anzeige.
Vier mandantengebundene Designsettings, Admin-Dialog mit Audit/Versionsschutz.
Eigene additive Migration65; keine Fachdatenmigration. Standardperiode initial
erste aktive, explizite Pflege statt behaupteter automatischer Winterumschaltung.
Details: CLUBUNIO-SIGNAGE-DESIGN.md. API/SaaS/Betrieb und PHPMaker unveraendert.

## Fachpaket65: Oeffentliche Signage und Testanmeldung

Defekten Livewire-Alias der Signage-Vorschau explizit registriert. Neue Webseiten
/signage/sv-sissach und /join/sv-sissach, Links in Vorschau/Anmeldeliste. Nur
heutige freigegebene Spiele, keine Mitglieder-/Anmeldedaten im Display.
Anonyme Neuanmeldung mit Vereinslookups, privatem Azure-Upload, Einwilligung,
CSRF/Session/Throttle/Honeypot und UUID-Doppelschutz; kein oeffentlicher Editweg.
Additive eigene Migration64 plus explizite Publikationssettings. Outbound aus,
Testkennzeichnung bleibt; vor spaeterem Ersatz der bestehenden Anmeldung
Datenschutz/Dokumentpflicht/Versand fachlich abnehmen. Keine Altuebernahme.
Details: CLUBUNIO-PUBLIC-WEB.md. API/SaaS/Betrieb/PHPMaker DEV/PROD unveraendert.

## Fachpaket64: Direkte Rechnungsuebertragung in die Sandbox

Admin-Button bei intern freigegebenen neuen Rechnungen, explizite Bestaetigung,
separate generische Testkontakte fuer weitere Mitglieder. Importierte
Kontakt-IDs/Altbelege bleiben unveraendert. Nur SVS Api Test, Entwurf ohne
E-Mail/Bankzahlung. Additive eigene Migration63 fuer Empfaengerregistry und
Versandpruefungszuordnung; keine Bestandsmigration. Timeoutabgleich nur lesend,
keine blinde Wiederholung. Nachgewiesen mit synthetischer Rechnung9298 /
RE-00010 CHF1.00. Ruecksprung belaesst Nachweise im additiven Schema.
Details: CLUBUNIO-INVOICE-TRANSFER.md. API/SaaS/Betrieb/PHPMaker DEV/PROD ausgenommen.

## Fachpaket63: Bexio-Gutschriften eindeutig abgleichen

RE-00009/GS-00004 live lesend bestaetigt und lokal abgeglichen: CHF10.05
Gutschrift, keine Geldzahlung, Rest0. Lokaler Gutschriftsnachweis107; Wiederholung
ohne neue Belege/Revision. Gutschriftnummer, Provider-ID, Eintrag, Betrag und
urspruengliche Rechnung statt alleiniger Gesamtsaldo-Pruefung. Webauswahl der
verrechneten GS-Nummern und sichtbarer Nachweis, keine freie Referenzbehauptung.
Kein Schemaeingriff. Alter TestGS-00002 bleibt unveraendert; eigenstaendiges
Kundenguthaben ohne Verrechnung und Mehrrechnungsverteilung weiterhin separat.
Details: CLUBUNIO-CREDIT-ALLOCATION.md. Kein API/SaaS/Betrieb/PHPMaker-DEV/PROD.

## Fachpaket62: Materialuebergaben und historische Uebernahme

Web-Entwurf, Materialpositionen, Bereitstellung, Selbst-/Eltern-Empfangsbestaetigung,
geschuetztes PDF, Revision/Audit, unveraenderliche bestaetigte Nachweise.
Mitgliederdossier/Portal und eigener Statuslookup; keine qualifizierte Signatur.
Additive eigene Migration61/62; eine historische unterschriebene Quittung mit
beiden Azure-Originalen per Hash geprueft archiviert. Weitere18 Trainingsplaene
eindeutig zugeordnet und privat archiviert, elf Quellen weiter zu klaeren;
Qualitaetssperren bleiben. GS-00002 gemeldet, Bexio6 Rueckfluss noch0:
keine blinde Bestaetigung. Details: CLUBUNIO-MATERIAL-RECEIPTS.md.
Keine App-API, SaaS, Betriebsumstellung oder PHPMaker-DEV-/PROD-Aenderung.

## Fachpaket61: Ankuendigungen und Rundnachrichten

Webentwuerfe mit Benutzer-/Rollen-/Mannschafts-/Gesamtauswahl, Vorschau und
bestaetigte interne Veroeffentlichung. Berechtigte Eltern, Deduplizierung,
Empfaenger-/Textsnapshot, Revision/Audit, Rueckzug und Portalansichten.
Eigene additive Migration60 (Brandingrelease60 hatte keine Migration).
Keine Altnachrichten importiert, keine externen Rundmails/FCM, keine neuen Jobs.
Details: CLUBUNIO-ANNOUNCEMENTS.md. API/SaaS/Betrieb und PHPMaker DEV/PROD bleiben
ausgenommen; Quittungs-Fachvertrag sowie Hardware-/Sandbox-Fachabnahmen offen.

## Blaue Identitaet, Release60

15.10.2026: Verbundenes U als SVG, Verwaltung/Portal/Login/Favicon und
Markenaktionen blau. Semantische Statusfarben unveraendert. Keine Migration;
Ruecksprung59 moeglich. Details: CLUBUNIO-BLUE-BRAND.md.

## Fachpaket59: Mitgliederantraege und allgemeine Dokumentpflege

Eigener Web-Antrags-/Pruefweg, verschluesselte Snapshots, aktuelle Eltern-/
Identitaetsrechte, Konflikt-/Doppelantragsschutz, Revision/Audit. Allgemeine
Mitgliederdokumente auf privatem Azure mit UUIDs und expliziter Portal-Freigabe;
Upload/Aktivpflege in Liste und Mitgliederdossier. Interne Notifications,
kein echter Versand. Migration59 nur eigeneDB; keine Altantraege automatisch
importiert, keine Bestandsdateien verschoben oder oeffentlich freigegeben.
Details: CLUBUNIO-MEMBER-CHANGES-DOCUMENTS.md. Offen: redaktionelle Ankuendigungen,
Quittungs-Fachvertrag und bestehende manuelle Hardware-/Sandbox-Abnahmen.
API/SaaS/Betrieb und PHPMaker DEV/PROD bleiben ausgenommen.

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

## Mandantentrennung und Vereinsdomains (25.10.2026)

Web-/DB-Grenzpruefung,26 zusaetzliche Tenant-FKs (Migration77) auf ClubUnio,
exakte Domainzuordnung ohne Rechtevergabe und vereinsbezogener Login mit Logo/
Farbe vorbereitet. Keine realen Domains, DNS/TLS oder Code-Deployment geaendert.
Mehrvereinsauswahl bleibt zentral; Customdomain ist an einen Verein gebunden.
Parallel entstehende API76 separat abnehmen; keine pauschale Kunden-/SaaS-Freigabe.
Pruefmatrix, Wiederholung und Grenzen: CLUBUNIO-TENANT-BOUNDARIES.md.

## API-Mandantengrenzen (26.10.2026)

Separates Sicherheitsdelta fuer die parallel entwickelte API: Domain-/Tokenverein
vor jedem Controller abgleichen, unbekannte Hosts ablehnen; Migration78 fuer den
zusammengesetzten Profil-FK vorbereitet. API-Basisarbeitsstand bewusst nicht
mitcommitten. Neue Grenztests und vorhandene Fachregressionen gemeinsam pruefen.
Nachlauf26.10.: Migration78 bereits installiert (Batch77), Schemaaudit ohne
ungesicherte Tenant-FK-Paare. Kein Deployment in diesem Nachlauf.
119 API-Tests/26554 Assertions inklusive Swagger-/Admin-UI und aufgebautem
Routecache erfolgreich. Finale API-Integration bleibt separat; Wiederholung
bei weiteren parallelen Aenderungen erforderlich. Abos, Abrechnung
und bestehendes DEV/PROD unveraendert. CLUBUNIO-API-TENANT-BOUNDARIES.md.

## Gemeinsame Web-/API-Freigabe (26.10.2026)

Abgeschlossene API cb8d89a mit gepruefter Web-Mandantentrennung auf ClubUnio
zusammengefuehrt: Release20261026-clubunio-tenant-api, Quellrevision a09a197.
128 Tests/26755 Assertions isoliert erfolgreich; sieben Servergates, echtes
HTTPS-QA und1220 Dateihashes bestaetigt. Noch keine realen Vereinsdomains.
Weitere parallele Provider-API79 ausgeschlossen und separat abzunehmen.
Bestehendes DEV/PROD, App-Neubau, SaaS und Bankzahlungen unveraendert.
Migrations-/Rollbacknachweis: CLUBUNIO-TENANT-API-RELEASE.md.

## Plattformverwaltung (26.10.2026)

Separate zentrale Verwaltung unter /platform: Vereine, Domains, Vereinsdesign,
persoenliche Konten und Vereinszuordnungen, mit globaler separat freigegebener
Rolle statt einer Superadmin-Ausnahme in Fachdaten. Migration80 installiert;
initiales Konto nach ausdruecklicher Bestaetigung freigeschaltet. Normale
Vereinsadmins duerfen Plattformkonten auch nicht per Kennwortreset uebernehmen.
Domains erst nach Besitz-/DNS-/TLS-Pruefvermerken aktiv; keine echte Domain neu
aufgeschaltet. Release20261026-clubunio-platform auf impersonation, acht Gates
und21 Quellhashes geprueft.60 Grenztests plus41 ueberlappende finale Integrations-
tests gruen. Angemeldete Browser-Sichtpruefung nach erneuter Anmeldung offen.
Keine Abos/SaaS-Abrechnung, App-Neubau oder PHPMaker-DEV-/PROD-Aenderung.
Details und Grenzen: CLUBUNIO-PLATFORM-ADMINISTRATION.md.

## DEV-Kommunikation aktiviert (02.11.2026)

Release20261102-clubunio-mobile-communication aktiviert; additive Migrationen82/83
nach isoliertem PG18-Backup-Restore und DDL-Rollback erfolgreich angewendet.
Kommunikation/gescannte Uploads aktiv,421 TeamSaisons provisioniert; KI mangels
DEV-Providerkey weiterhin aus. Elf Gates und29 Tests/287 Assertions erfolgreich.
HTTPS context401/session422 statt404, Weblogin200. Echte persoenliche App-Abnahme
und KI-Realaufruf offen. Details/Rueckweg: CLUBUNIO-MOBILE-DEV-DEPLOYMENT.md.


## Mobile: bestehende SVS-Accountmasken (03.11.2026)

Bestehende Login-/Profil-/Passwortmasken und Modul-Drawer mit persoenlichen
ClubUnio-Adaptern verbunden. Neues eigenes Profil, Passwortwechsel mit
Sitzungswiderruf und berechtigungsabhaengiger Modulkatalog. Noch offene
Fachmodule im Menue deaktiviert, keine Behauptung einer Gesamtmigration.
Release20261103-clubunio-mobile-account-shell auf member-access ausgeliefert;
keine Migration, Env/Storage und parallele Aenderungen erhalten. Zwoelf Gates,
sechs Dateihashes,38 Tests/369 Assertions und synthetischer PG-Kernelablauf
mit Rollback erfolgreich. HTTPS profile/modules/password401, Weblogin200.
Details und offene Schritte: CLUBUNIO-MOBILE-ACCOUNT-SHELL.md.


## Alle Appmodule: gemessene API-Performance (04.11.2026)

DEV aktiv:20261104-clubunio-mobile-modules-performance-v1 ausacfda56.
17 committete Dateien,15 bekannte Git/SHA-Baselines,2 neue vorab abwesend;
alle Gates vor atomarem Wechsel/FPMreload bestanden. Env/Composer/Schema/
Providerflags unveraendert. Rollback:20261104-clubunio-mobile-performance-v1.
Gemeinsame Fakten nur in exakt einem authentifizierten GET, kein TTL-/Cross-
Requestcache, keine Wiederverwendung bei Writes/Locks/Reauth. Planung/MyTeams/
Dossier/Training/Anmeldungen/Dokumente/Sponsor/Notifications lesend gebuendelt;
Anlagen/Bus/Profil/Keybox/Challenge geprueft, bestehende Fachablaufe erhalten.

500 App-Tests gruen, Analyse ohne Befund; Shared47/744, ModulePermission13/80,
Planung22 unterschiedliche PG-Faelle, MyTeams3/54+Members6/125 und weitere
Module6/204 plus bestehende Fachregressionen gruen. Ausschliesslich synthetische
Rollbackfixtures; kein Import/Refresh, Provideraufruf oder echte Bankzahlung.

Vorher/nachher mit vorhandenen DEV-Daten und synthetischen Rollen, je2 warme
Kernelaufrufe: Planung20 14.6s→0.78s (608/609→29/30SQL), TL-Modulmenue5.9s→0.73s
(241→26SQL), TL-Spesen19 1.8s→0.63s (73→22SQL), Adminspesen61 0.82s→0.54s.
Datensatzanzahlen erhalten. Keine FPM-/Internet-/iPhone-Renderingzeit enthalten.
Numerischer Server-Timing-Anteil jetzt optional im privaten redigierten SDK-
Diagnosering; keine Headertexte/URLs/IDs/Token exportiert. HTTPSnachlauf200/
401/503 korrekt,17Hashes erneut bestaetigt, PHP-FPM aktiv. iOS-Simulator selbst
hier nicht gemessen. Details:PERFORMANCE-MODULES-RELEASE.md und
PERFORMANCE-API-MEASUREMENTS.md; App:mobile/docs/PERFORMANCE-ALL-MODULES.md.


## 04.11.2026: Mobile Funktionsparität, Rechte und Chat auf DEV

Aktiv: 20261104-clubunio-mobile-functional-v2 (Runtime9cf83de, Produktc7de5b4),
App546844e. 21 committete Runtime-/Gate-/Schema-Dateien mit exakten Git-/SHA-
Baselines und Nachprüfung, PHP-FPM aktiv. Alle DEV-Gates inklusive neuer Chat-
HTTP-Prüfung grün. V1-Staging blieb wegen MailFake-Prüfskriptfehler inaktiv;
1100 war bereits additiv angewendet. V2 prüfte das Schema ausschliesslich lesend
gegen die private verschlüsselte V1-Sicherung; keine erneute Anwendung.
Runtime-Rollback:20261104-clubunio-mobile-modules-performance-v1.

525 Fluttertests grün, Analyse ohne Befund; Kommunikation46/710;
PG Mitglieder/MyTeams12/254, Einstieg3/49, Bus2/57, Keybox6/122, Challenge4/134.
Trainerverzeichnis privat, frische Dossiers mit Ausweis/Rechnungen/Antrag,
verantwortliche Probetrainings, Busleerlisten und Profil-/Keyboxeinstiege
korrigiert. ChatPlus mit Bild/Datei/Termin/Poll, Emoji-Reaktionen und Opt-in-
Übersetzungseinstellungen; Posteingang oben, Chat fest mittig, MyTeams unten.

Genau zwei bestehende Elternvorschläge und SV-Sissach-Challenge nach getrennten
expliziten Benutzerbestätigungen auf DEV gesichert/auditiert freigegeben;
Trainerrolle, andere Konten und App-Modulprofile unverändert. Nachprüfung am
betroffenen Konto: exakt drei persönliche Mitglieder, drei Rechnungs-/Ausweis-
Endpunkte200 und frische Dossierfähigkeiten; Bus/MyTeams200, Challenge sichtbar,
Keybox-Einstieg vorhanden. Temporäre Prüfsitzung vollständig zurückgerollt.
HTTPS200/401/503 korrekt. Keine Provider-/PHPMaker-/PROD-/SaaS-/Storeumstellung.
Übersetzungsanbieter weiterhin aus; Keyboxen nicht eingerichtet, physische
Ausgabe geschlossen. Windowsprüfungen ersetzen keinen nativen iPhone-Lauf.
Details:CLUBUNIO-MOBILE-FUNCTIONAL-PARITY.md.


## 04.11.2026: priorisierte Mobile-Hauptnavigation

App3792e56:Spesen,Mitglieder,zentraler Chat,Termine,Mehr nach SVS-Vorbild.
Fachmodule nur bei frischem persönlichem Modulrecht; Chat bleibt auch bei
fehlenden Modulen exakt mittig. Spesen/Mitglieder im Hauptbereich mit sichtbarer
Leiste, weitere Funktionen und Profil über Mehr; Posteingang oben. Farben aus
AppBrand/Theme, keine feste Referenzfarbe. Bestehende Screens/Adapter und
Konto-/Mandantenwechsel weiterverwendet.535/535 Fluttertests bestanden,
Analyse ohne Befund; echte Modulwechsel/Rechteentzug und alle8 Kombinationen
bei320px sowie grosse Schrift320/390/1024 geprüft. Reine Appänderung,
keine Backend-/Schema-/DEV-Betriebsänderung. DEV weiterhinfunctional-v2.
Details:CLUBUNIO-MOBILE-PRIMARY-NAVIGATION.md; App:docs/PRIMARY-NAVIGATION.md.


## 04.11.2026: Teamgebundene Dossiers und einheitliche App-Navigation

Aktiv auf DEV:20261104-clubunio-mobile-team-access-v2, Runtime59cb059,
Produkt056fc93; Appbe6bec5.7 committete Dateien mit bekannten Git-/rohen SHA-
Baselines; nach Aktivierung alle7Hashes erneut bestätigt, PHP-FPM aktiv.
Rollback:20261104-clubunio-mobile-functional-v2. Schema,Umgebung,Composer,
Betriebs-/Providerflags und PHPMaker unverändert.

Trainer/Assistenztrainer benötigen echte aktive Funktion im konkreten aktuellen
Team; Helfer/Coach/fehlende Funktionen erteilen keine Trainerdossiers oder
Anträge. Eltern sehen im Team nur Spielernamen und Staffkontakte, persönliche
Kinderdossiers separat vollständig. team_id separat validierte Query, Antrag
statt Direktänderung, erneute Rechteprüfung bei Verwaltungsfreigabe. Kein neues
Finanz-/Dokument-/Ausweis-/Prüfrecht aus der Trainerrolle. Mitglieder öffnen
direkt ohne zusätzliche Aufklapp-/Linkstufe. Einheitliches Menü/Profil; obere
Leiste Heute,Profil,Posteingang,Menü,Abmelden, genau eine eigene Abmeldung.

550/550 App-Tests und Analyse grün; Kommunikation47/721, PG-Team-/Member-
Regression16/360 plus Common-Änderungsabläufe3/22; Roster23/23SQL konstant.
Alle bisherigen DEV-Gates plus neue60 HTTP-Prüfungen bestanden und Fixtures
zurückgerollt. V1-Staging blieb wegen eines Testdatenfehlers (insertGetId auf
Composite-Key-Modulkatalog) inaktiv/erhalten; V2 enthält die korrigierte Probe.

Lesender Nachlauf am betroffenen Konto:3 eigene/vertretbare Mitglieder,2 volle
Kinderdossiers, echte Trainerdetails/Optionen;2 Elternteams mit24 namensreinen
Spielern und2 Staffkontakt-GETs, genau eine aktuelle Sitzung. Keine echten
Änderungsanträge angelegt, temporäre Sitzung zurückgerollt. HTTPS200/401,
keine Provideraufrufe/Importe/Bankzahlungen. Native iPhone-Abnahme nicht behauptet;
Mac-App muss den synchronisierten Stand neu bauen.
Details:MOBILE-TEAM-MEMBER-ACCESS.md; App:docs/MEMBERS-PROFILE-RELEASE.md.


## 04.11.2026: Chatentwurf, Kamera und Reaktionspersonen

DEV aktiv: `/var/www/svs-laravel/releases/20261104-clubunio-chat-composer-v1`,
Backendruntime `e894d6b`, App `32ecc4f`. Rückfall: vorheriges
`20261104-clubunio-mobile-team-access-v2`. Nur drei Runtimequellen und ein
neues Gate bereitgestellt; keine Schema-, ENV-, Composer- oder Provideränderung.
Alle bisherigen Gates sowie das neue Gate mit29 HTTP-/Privatheitsprüfungen
ausgeführt; synthetische Daten zurückgerollt. Rohdatei-SHAs vor/nach Aktivierung,
HTTPS200/401 und aktive FPM-Verknüpfung geprüft.

Termin wird vor dem Senden im Entwurf angezeigt und mit dem bearbeitbaren Text
als eine Nachricht gesendet. Kamerabutton mit kontrollierter Bildübernahme und
bestehendem Upload-/Scanablauf. Blasen bis80% verfügbarer Chatbreite, eigene und
fremde Nachrichten verschieden ausgerichtet/eingefärbt. Langes Drücken öffnet
Aktionen/Emoji-Auswahl; vorhandene Reaktion zeigt Namen statt sie umzuschalten.
Namenliste frisch autorisiert und paginiert, nur bei Bedarf, keine neuen
Namensabfragen im laufenden Polling.569/569 Fluttertests, Analyse ohne Befund;
Backend Kommunikation49/768, PG-Probe1/3 plus29 Kernelprüfungen grün.
Keine echten Vereinsnachrichten oder Provideraktionen für die Abnahme.
Mac nach Pull neu bauen; echte iPhone-Kameraaufnahme bleibt separat zu prüfen.
Details: deploy/MOBILE-CHAT-COMPOSER.md, App docs/CHAT-FEATURES.md und CHAT-CAMERA.md.


## 04.11.2026: zentrale Sprachfreigabe der App

Aktiv auf DEV:20261104-clubunio-language-policy-v1; Runtime6ee1afa,
Paket-/Basisprüfung2f35e8c. Rollback:20261104-clubunio-language-autoswitch.
App81b097e synchronisiert. Einzige Freigabequelle bleibt
config/clubunio-languages.php über LanguagePreferences::enabled(), unverändert
DE/EN. Fünf lokale Appkataloge sind Fähigkeiten, keine eigenständige Freigabe.
Context/Profil/Chat-Präferenzen liefern language_policy+wirksame Sprache.
Fehlende/ungültige/leere Policy sperrt Auswahl/Save. Gesperrte Altwerte nur
wirksam aufgelöst, keine GET-Mutation; keine Chat-Zustimmung für Ersatzsprache.
API validiert Freigabe frisch beim Schreiben und vor KI-/Providerarbeit.

617/617 App-Tests grün, Analyse und1917/5-Katalog-/AST-Prüfung sauber.
Backend58/820 plus28 echte PostgreSQL-/Kernelprüfungen; alle DEV-Gates bestanden,
Fixtures zurückgerollt, Rohdatei-SHAs und PHP-FPM/HTTPS200/401 bestätigt.
Sieben gezielte Dateien aus Gitblobs bereitgestellt, parallele Webänderungen
über aktuelle Basiskopie erhalten. Keine neue Sprach-/Providerfreigabe,
Schema-/ENV-/Composeränderung, echten Nachrichten oder Bankzahlungen.
Mac-App vollständig neu bauen; Doku:MOBILE-LANGUAGE-POLICY.md und App docs/I18N.md.


## 04.11.2026: Sofortiger Direktchateinstieg und rollenabhängige Kontaktsuche

Spieler eigene Mannschaften; Eltern ausschließlich Kinderteamstaff; Trainer
kanonische eigene Trainer-/Assistenztrainerteams samt bestätigten Spielereltern,
zusätzlich vereinsweite Teamstaffs und eigener Kinderstaff. TL/Admin/Office alle
aktiven persönlichen Vereinschatkonten. Gerichtet suchen, bestehende berechtigte
Zweierchats beantworten; Rechtewiderruf/Blockierung bleiben frisch.
DEV direct-contacts-v1 aus956af46 aktiv,272 Serverchecks und640 App-Tests grün.
Keine Schema-/Provider-/PHPMaker-Umstellung. MOBILE-DIRECT-CONTACTS.md.


## 04.11.2026: RAMchatcache und echtes Delta im normalen Aktualisierungstakt

App zeigt gelesene Listen/Nachrichten aus begrenztem sitzungsgebundenem RAMstand;
frische persönliche Delta-Metadaten statt wiederholter50er-Nachrichtenprojektion.
Rejoin, Cursorreset und verspätete Mutationen abgesichert; keine Dateipersistenz.
DEV chat-delta-v1 aus7442f67 aktiv, Delta62+alle Bestandsgates bestanden.
App660f0d9 mit653 Tests grün. MOBILE-CHAT-DELTA.md.


## 05.11.2026: Explizite Admin-Genehmigung trotz aktuellem Platzstatus

Aktiv DEV20261105-clubunio-pitch-admin-status-override, Rueckweg dashboard-reservations-v2.
Runtime b416136, lesender Gatefix5a04213; App b9e38dd. Nur ausdruecklicher Admin
mit Update-Recht darf aktuellen Anlagenstatus fuer genau eine Genehmigung
uebergehen; Belegungs-/Sperrzeitkonflikte und Revision bleiben geprueft.
Auditflag, kein Status-/Schema-/Providerwechsel, keine echte Anfrage genehmigt.
53 Backendtests/824 Assertions und64 App-Tests erfolgreich; Code-/i18n-Pruefung
sauber. Vier Dateihashes und Konfigurationsgleichheit geprueft, FPM aktiv,
HTTPSup200/context401. CLUBUNIO-PITCH-ADMIN-STATUS-OVERRIDE.md.


## 05.11.2026: Chatbilder vor Upload und Speicherung komprimiert

DEV aktiv20261105-clubunio-chat-image-compression, Basis pitch-admin-status-override.
Runtime bd6a414, App5157a59. Lange Seite1600px, Ziel350KiB, hart512KiB;
Alpha/Ausrichtung erhalten, Metadaten entfernt, PDFs unverändert.
42 App-Tests und69 Backendtests/1052Assertions grün; PG-Fixtures zurückgerollt,
Analyse und1997/5-i18n-Prüfung sauber. Drei Rohdateihashes/Baseline und vollständige
Konfiguration geprüft; synthetisches Servergate118819->30690Bytes/Alphaerhalt,
FPM aktiv, HTTPSup200/context401. Keine Migration/Provider-/PHPMakeränderung.
Bestehende Bilder nicht verändert. Rückbau nach neuen Uploads benötigt weiterhin
den neuen Dateinamensleser/Replaypfad. CLUBUNIO-CHAT-IMAGE-COMPRESSION.md.


## 05.11.2026: Persönliches iOS-Push auf DEV freigeschaltet

Auf ausdrücklichen Auftrag beide Pushflags true; vorhandene iOS-Core-Registry
und übrige Konfiguration byte-/arraygeprüft erhalten. www-data-Capability true,
unsupported Clients weiter false. Neuer Minutentimer/oneshotwww-data-Worker,
max10 Einträge und kein Parallellauf. Vorher0 Geräte/Bindungen/Outbox, erster
Lauf erfolgreich0 verarbeitet, kein künstlicher Testversand. FPM aktiv,
HTTPSup200/context401. Kein Runtime-/DB-/PHPMakerwechsel, noch keine physische
FCM-/APNs-Zustellungsabnahme. CLUBUNIO-DEV-PUSH-ACTIVATION.md.


## 05.11.2026: Teamplanungsliste trotz Push wieder schnell

DEV aktiv20261105-clubunio-planning-push-list aus938d386, Basis chat-image-compression.
Eager Aufgebotsempfänger-/Pushfingerprints aus Listen/Heute entfernt, Detail-/
Versandprüfung unverändert. Gleiche2Termine25.328s/906SQL→1.322s/46SQL;
Servergate Kandidat1326ms/47SQL und vor Aktivierung1180ms/47SQL,0Snapshots.
Pushflags/Registry und kompletter Konfigurationsstand erhalten, Versandtimer/FPM
aktiv, Health200/context401.52 Backendtests/963Assertions und25 Fluttertests
bestanden. Keine Migration, echten Nachrichten oder Appbuildänderung.
MOBILE-PLANNING-PUSH-LOADING.md.


## 05.11.2026: Geteilte Teamtermine schnell öffnen

DEV aktiv20261105-clubunio-planning-detail-loading, Runtime64d01be.
Optionaler leichter Detailabruf ohne vorgezogene Push-Empfängerprojektion;
frische Rechte/Roster bleiben, bisherige Clients erhalten weiter volle Details.
Flutter öffnet Terminroute sofort, 1Detailabruf, explizite Pushvorbereitung mit
Aufgebotsvergleich/Bestätigung. Vergleich12.973s→1.155s; Releasegate679ms/26SQL,
22Personen/0Snapshots. Pushflags/Registry unverändert, Timer/FPMaktiv, Health200.
52Backendtests/990Assertions und45Flutterregressionen grün, extra UI-Fall ergänzt.
Keine Migration. MOBILE-PLANNING-DETAIL-LOADING.md; neuer Flutterstand nötig.


## 05.11.2026: Interaktive Erste Schritte und Support-Mail

Rollen-/modulabhängige aufklappbare Checkliste, direkte Aktionen und ausdrücklich
markierter Fortschritt. Lokale Speicherung jeOrigin/Benutzer/Mandant, begrenztes
Warten auf Gerätespeicher, Fehler sichtbar, keine automatischen Fachdatenänderungen.
Supportformular bereitet Mail an info@clubunio.ch mit Benutzer-/Vereins-/Geräteinfos
und begrenztem bereinigtem Diagnoseanhang vor; Benutzer sendet im Mailprogramm.
Fehlende native Anhangsunterstützung bietet Share-Fallback. Allgemeinen
Aktualisieren-Menüeintrag entfernt; gezieltes Refresh bleibt erhalten.

53 gezielte Fluttertests grün (20Guide/33Support-Navigation-Layout), Codeanalyse
sauber,2020Texte in5lokalenSprachkatalogen/i18n-Prüfung erfolgreich. Neue native
Mail-/Geräteplugins: vollständiger Appneubau erforderlich, physischer Mailversand
offen. Kein Backend-/DB-Vertrag geändert, kein DEV-Release notwendig.
CLUBUNIO-MOBILE-GUIDANCE-SUPPORT.md; Appdoku docs/GETTING-STARTED-SUPPORT.md.


## 05.11.2026: Posteingang akzeptiert Platzbelegungsziele

Appfehler «Unbekanntes Benachrichtigungsziel» für bestehende pitch_planning-
Mitteilungen reproduziert/behoben. Posteingang und Push verwenden dieselbe
strikte Zielzuordnung; Mandanten-/Objektprüfungen bleiben.30Flutterfälle grün,
Analyse sauber. Kein Backend-/DB-/DEV-Wechsel; aktueller Appbuild erforderlich.
CLUBUNIO-PITCH-NOTIFICATIONS.md; App docs/PERSONAL-NOTIFICATIONS.md.


## 06.11.2026: Chatversand ohne Push-Timeout

DEV aktiv20261106-clubunio-chat-send-push-candidates, Runtimefae451d,
Basisassistant-v1 unverändert erhalten. Sendeversuch499 bei bereits gespeicherter
Nachricht: Pushfanout prüfte36Konten/2024SQL/55.199s auch ohne Gerät. Jetzt aktive
Bindingkandidaten zuerst, danach frische Objekt-/Mute-/Leave-/Revoke-Rechte.
Lesendes Gate31ms/1SQL; Teiloperation, keine neueNachricht/Pushqueue für Abnahme.
55Backendtests/1019Assertions und27Fluttertests grün,2806DE/EN-Schlüssel geprüft.
KompletteConfig/Assistant-/Pushflags/Registry/ENV/Composer erhalten, FPM/Timeraktiv,
Health200/context401. Keine Migration/Appcodeänderung/neuerBuild erforderlich.
MOBILE-CHAT-SEND-PERFORMANCE.md.


## 06.11.2026: Chat mit dauerhafter Pushqueue und Timeout-Abgleich

DEV aktiv20261106-clubunio-chat-async-locks, Runtimefd2e181; vorherasync-send
31e25fd mit genauadditiverMigration200. Nachricht+Pushintentatomar, Workerfanout
undProviderhintergründig. OwnclientUUIDGET striktlesend, keineWiederbeitritte.
SharedSend-/Providerlocks kompatibel, UUIDadvisory+Chatsperre gegenDoppelversand,
Widerrufeweiterexklusiv. PG2-Verbindungscheck22msShared, Exclusiveblockiert,
UUIDserialisiert; keineFachnachricht/Push erzeugt.77Backendtests/1416Assertions
und830Appfällegrün, Analyse/I18n2029/5App und2806DE/ENBackend sauber.
KompletteConfig/Registry/Flags/ENV/Composererhalten, FPM/Timeraktiv, Health200.
AppzeigtsofortPending, sichereverschlüsselteWiederaufnahme/Timeoutlookup,
keinAutoReplaybei404, alterBody/UUIDbeiRetry. NeuerAppstandfürUIerforderlich.
MOBILE-CHAT-ASYNC-SEND.md / App CHAT-RELIABLE-SEND.md.

## 06.11.2026: Chat ohne blockierten Composer und mit Wischantworten

DEV aktiv: 20261106-clubunio-chat-interaction, Runtime d887662, Basis async-locks.
Genau die additive Replymigration000300 atomar mit Ledger angewendet; nullable
Referenz, Tenant-FK/Ordnungscheck/Index, frische Chat-/Joinfloorprüfung und gebündelte
Livezitate. Edit/Delete aktualisiert Antwortzitate auch im Delta. 80 Backendtests
mit1453Assertions grün. Beide lesenden DEV-Gates erfolgreich: 81SQL,2224 beziehungsweise
2201ms CLI-Lookup; keine Sendezeitmessung. Vollständige Konfiguration einschließlich
Registry, Push-/Assistantflags, ENV und Composer erhalten. FPM und Pushtimer aktiv,
Worker erfolgreich, Health200/context401. Keine Testnachricht erzeugt.
App: getrennte Sendeversuche, sofort freies Feld, optimistische Reaktionen mit Status,
Antwort per Wischgeste. Abschließende Appprüfungen in MOBILE-CHAT-INTERACTION.md.

App-Gesamtsuite mit 855 Fällen und anschließend 46 gezielte Regressionen nach der
letzten Sitzungsablaufkorrektur grün. Neun Dateien ohne Analysebefund; 2042 Texte
in fünf Appkatalogen und 2806 Backendschlüssel DE/EN geprüft. Aktueller Appstand nötig.

## 06.11.2026: Persönliche Chat-Lesemarkierungen

DEV aktiv: 20261106-clubunio-chat-readers-i18n, Runtime67ee58e, Basisreaders/be81c6c.
Autorengebundener rein lesender GET mit frischen Leserrechten und Joinfloor;
minimale Namenliste, begrenztes Paging und bestehende Cursor statt Lesezeit.
App-Langdruckaktion nur für eigene gespeicherte Nachrichten, Abruf auf Nachfrage.
84 Backendtests/2382Assertions, abschließend vier Leserfälle/929Assertions und
40 Appregressionen grün. Analyse sauber, 2046Texte/5Appkataloge und2806DE/EN geprüft.
Beide Rollouts ohne Migration, komplette Config/Registry/Flags/ENV/Composer erhalten.
Lesende Gates erfolgreich, final149SQL/3741 beziehungsweise3624msCLI, keine
Testnachricht/Push erzeugt. FPM/Timer aktiv, Worker erfolgreich, Health200/context401.
CHAT-READ-RECEIPTS.md; aktueller Appstand erforderlich.

## 06.11.2026: Posteingang schneller als gelesen markieren

DEV aktiv: 20261106-clubunio-notifications-read-all, Runtime05ad543, Basisreaders-i18n.
Historische Nachprüfung entfernt, Ziele einmal je gesperrter Rechteprojektion
geprüft und Marker gebündelt. Frame endet vor Writes; übrige POSTs ohne Rechtecache.
Stichtag, Sourcebindung und frische Session-/Mandantenrechte bleiben erhalten.
Baseline: bereits24 von26 Prüfungen40.160s/1489SQL. Voller korrigierter Controller:
26 Marker und20 beobachtete ACKs,130SQL/3572ms beziehungsweise3558ms in beiden Gates.
Sämtliche Testmarker und synthetische Sitzung nachweislich zurückgerollt.
93 Backendtests/2521Assertions, drei PG-Fälle/44Assertions und33 Appfälle grün.
Analyse und2052/5App- sowie2806DE/EN-Katalogprüfung sauber. Keine Migration,
vollständige Config/Registry/Flags/ENV/Composer erhalten. FPM/Timer aktiv,
Worker erfolgreich, Health200/context401. Doku MOBILE-NOTIFICATIONS-READ-ALL.md.
App übernimmt ACK ohne Folgeabrufe; Netzwerkfehler erhalten die Liste. Neuer Appstand nötig.

## 06.11.2026: Performance-Abgleich häufiger Appabläufe

DEV aktiv20261106-clubunio-mobile-hotpaths aus85378af, Basisnotifications-read-all.
Planningrechte der Inbox gebündelt, akzeptierte Direktchatnamen gemeinsam geladen;
Chat-GETs mit Provider-Sharedlock kompatibel und Rechte nach Sperrerwerb frisch.
Echte Zweiverbindungsprüfung: Liste/Detail laden, exklusive Rollenwrites blockiert.
Gleiche Inbox26/Page20 von3926ms/138SQL auf766ms/26SQL, Read-all26 von3558ms/130SQL
auf1185ms/40SQL. Chatliste7/Direct0 von749ms/23SQL auf759ms/26SQL; zusätzliche
frische Prüfung bewusst, kein erfundener warmer Geschwindigkeitsgewinn.
Alle Gate-Sitzungen/Marker-/Metadatenwrites zurückgerollt, keine Provideraktionen.
97 Backendtests/2555Assertions, fünf PG-Fälle/103Assertions und gesamte894Appfälle
grün. Zwölf Dartdateien analysefrei,2052/5App- und2806DE/EN-Katalogprüfung sauber.
Keine Migration, komplette Config/Registry/Flags/ENV/Composer erhalten; Diensteaktiv,
Worker erfolgreich, Health200/context401. PERFORMANCE-HOTPATH-AUDIT.md und
App PERFORMANCE-UX-AUDIT.md. Neuer Appstand erforderlich, native Messgrenzen dokumentiert.


## 06.11.2026: Chatpush mit Vorschau und kurzem Versandintervall

DEV aktiv: 20261106-clubunio-push-preview, Basis mobile-hotpaths. Bisher zwei
Chatpushs mit 35,5s Mittel/48s Maximum bis Providerannahme, keine Gerätezeit.
Timerwartezeit von 60s auf 2s nach Abschluss, Genauigkeit 1s; Journal bestätigt
nun etwa 3s für leere Zyklen. Bis 100 Outboxeinträge, Versand unmittelbar je
Fanout. Kurzvorschau Absender80/Text180 erst nach frischen Quell-/Empfängerrechten
und Joinfloor; Routing bleibt IDs, andere Pusharten generisch. DE/EN-Systemtexte,
Freitext unverändert. Firebase/APNs erhalten den Kurztext; OS-Vorschau wirkt.
87 lokale Regressionen/2367 Assertions plus 18 PG-Pushfälle/219 Assertions grün;
Sprachprüfung2806 DE/EN plus PHP-Gruppen. PG-Fixtures zurückgerollt, Provider gefälscht.
Keine Migration/Appänderung, gesamte Config/Registry/Flags/ENV/Composer erhalten.
FPM/Timer aktiv, Worker success/Exit0, Health200/context401. Physische Ankunft noch
nicht neu gemessen. MOBILE-PUSH-PREVIEW-LATENCY.md; Manifest
1f56877364ae8196a1efbeff4aead1947145ffb71639e364a6be7e91d9d8b7dd.


## 07.11.2026: Verbleibende iPhone-Pushwartezeit reduziert

DEV aktiv: 20261107-clubunio-push-worker, Basis push-preview. Benutzer misst
10–12s bis iPhone; letzte zwei Pushs zuvor bereits 8/9s bis Firebase-Annahme.
Dauerhafter einzelner Worker mit 250ms Ruhepause, maximal 300s je Prozesslauf,
systemd-Neustart; Timer deaktiviert und als Rollbackunit erhalten. Neue Request-
kontexte je Zyklus, keine übernommenen Saison-/Rechtefakten. Fachsammlung weiter
alle zwei Sekunden nach Abschluss; Chat zuerst. Quell-/Empfängerprüfung jeweils
lokaler frischer Leserahmen, keine allgemeine Rechtecachefreigabe. Ziel65SQL/1702ms
auf47SQL/1255ms, Quelle37SQL/901ms auf12SQL/329ms im inaktiven Lesegate.
98 lokale Fälle/2481 Assertions plus18 PG-Pushfälle/219 Assertions grün;
Sprachprüfung2806 DE/EN plus PHP-Gruppen erfolgreich. Keine Migration/Appänderung.
Komplette Config/Registry/Flags/ENV/Composer erhalten. FPM und Worker aktiv,
Worker enabled/running ohne Restartschleife, Timer disabled/inactive,
Health200/context401. Keine Testnachricht/Providerzustellung erzeugt. Neue
physische iPhone-Ankunft noch nicht gemessen. MOBILE-PUSH-RESIDENT-WORKER.md.
Manifest cbbe1480e8b7217efaf5bbb622b44490720c4ee3ca7a8488cca1df83d3fb471d.
