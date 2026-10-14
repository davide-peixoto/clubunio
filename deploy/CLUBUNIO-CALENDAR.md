# ClubUnio: Kalender und Terminexport

Stand12.10.2026, Webpaket Release24. Nur ClubUnio, keine PHPMaker-Aenderungen,
keine mobile API und keine SaaS-Erweiterung. Keine Datenbankmigration.

Pruefung: Teamplanung17 Tests/162 Assertions erfolgreich, abschliessender
Kalenderlauf3/46 ebenfalls. Desktop-Matrix und mobile Wochenansicht390px
visuell geprueft. Import in echte Outlook/Apple/Google-Kalender bleibt
Benutzerabnahme; der ICS-Roundtrip ist automatisiert geprueft.

## Bedienung

Teamplanung > Kalender: Monat, Woche oder Mannschaften (Wochenmatrix).
Datum, Saison, Mannschaft, Terminart, Status und Suchtext sind kombinierbar.
Ansichtswechsel, Heute und Vor/Zurueck behalten Filter. Monatsraster zeigt
vollstaendige Wochen einschliesslich Randtagen; Export umfasst genau denselben
sichtbaren Zeitraum und dieselben Filter. Mehrtagestermine erscheinen an jedem
betroffenen Tag, im Export nur einmal. Zeiten: Europe/Zurich.

Termine verlinken direkt auf bestehende Teilnehmer-/Gastspielerdetails.
Absenzen sind weiterhin ueber die Teamplanungsnavigation erreichbar.
Statusfarben benutzen dieselbe zentrale Farblogik wie die anderen Listen.
Mobil wird die Woche als Tagesliste gezeigt; Monats-/Mannschaftsraster sind
horizontal scrollbar. Keine Drag-and-drop-Aenderung ohne Fachvalidierung.

## Rechte und Ueberschneidungen

Der Kalender und Export pruefen aktive Konten, Vereinszugriff und dieselben
Team-/Einzeleinladungsrechte wie die Teamplanung. Eine Einzeleinladung oeffnet
nicht die anderen Termine einer Mannschaft. Optionen werden nur aus sichtbaren
Teams oder erlaubten Einzelterminen ermittelt. Keine oeffentliche Kalender-URL.

Ueberschneidung = zwei aktive Termine derselben TeamSaison mit streng
ueberlappenden Zeitintervallen. Direkt aufeinanderfolgende oder abgesagte
Termine zaehlen nicht. Hinweise beachten auch durch Suche/Terminart ausgeblendete
Termine, aber ausschliesslich solche, fuer die Leserechte bestehen. Keine
personenbezogene Verfuegbarkeitsanalyse, keine Platz-/Busreservierungspruefung.
Warnungen sind informativ und verhindern keine autorisierte Terminbearbeitung.
Maximal1000 Termine und5000 Vergleichstermine; sonst eingrenzbare Validierung
statt stiller Teilresultate. Keine Gesundheits-/Absenzgruende in Kalenderdaten.

## Kalenderdatei

Download ueber geschuetzte Webroute /planning/{tenant}/calendar/export.
ICS-Erzeugung durch sabre/vobject4.6.1 (Composer-Lock), nicht eigene
iCalendar-Serialisierung. Referenz: https://sabre.io/vobject/icalendar/
Datum/Zeit als UTC, damit Sommer-/Winterzeit in Outlook/Google/Apple korrekt
interpretiert wird. Stabile UID pro Verein/Termin, Revision als SEQUENCE,
LAST-MODIFIED/DTSTAMP, CANCELLED fuer abgesagte Termine. Enthalten sind Titel,
Ort, Mannschaft und geschuetzter Detail-Link; keine Teilnehmer, Kommentare,
Absenzgruende oder Kontaktdaten. Private/no-store und Attachment-Header.

Die Datei ist ein manueller Export zum Importieren, KEIN automatisch
aktualisiertes Kalenderabo. Aktualisierung/Dublettenbehandlung nach erneutem
Import haengt vom Kalenderprogramm ab. Gefilterte/entfernte Termine werden
nicht als Loeschauftrag verschickt. Kein Versand von Kalendereinladungen.

## Betrieb und Migration

Neue Serviceklasse PlanningCalendarService, Webaktionen/Views und CSS;
Composer neu sabre/vobject, sabre/xml und sabre/uri. Auf Pi installierte
Lock-Abhaengigkeiten benoetigen PHP-XML/mbstring (Deployment prueft Plattform).
Keine neue Tabelle, keine neuen Parameter, keine Cronjobs, keine Migration
von Daten. Voriges Release23 bleibt als Code-Rollback verfuegbar.
Spaeterer Cutover muss Termin-/Vereins-IDs stabil halten oder Kalender-UIDs
abbilden; Datenbankkopien duerfen nicht unkontrolliert bestehende UID neu vergeben.

Tests: alle Ansichten, Filter-/Navigationslinks, eingeschraenkte Suche,
Einzeleinladung ohne fremde Konfliktdetails, Intervallgrenzen, Absagen,
Mehrtagestermine und DST, ICS-Roundtrip/Validierung/Umlaut-Escaping.
Browserpruefung lokal mit bestehender Anmeldung; keine echten Termine geaendert.
Fachliche Benutzerabnahme und realer Import in die jeweiligen Kalenderclients
bleiben vor produktiver Abloesung erforderlich. KI-Trainingsplanung bleibt das
naechste Fachpaket, API/SaaS bleiben geparkt.
