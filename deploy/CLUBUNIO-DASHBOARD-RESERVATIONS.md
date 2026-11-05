# Offene Bus- und Platzanfragen im Dashboard

05.11.2026. Nur ClubUnio; bestehendes PHPMaker DEV/PROD unveraendert.

## Bedienung und Rechte

- Neuer Dashboardbereich fuer Geschaeftsstelle/Admin: Busreservierungen und
  Sportplatzbelegung, jeweils Gesamtzahl offener Anfragen und fuenf Eintraege
  pro Seite. Unabhaengige Vor-/Zurueckpfeile, aelteste Termine zuerst.
- Bus: nur aktive Reservierungen mit technischem Status ANGEFRAGT.
  Platz: Status pending. Auch vergangene, noch nicht entschiedene Anfragen
  bleiben sichtbar; keine stille Verwerfung durch Datums-/Saisonfilter.
- Eintrag oeffnet die bestehende Busdetailseite beziehungsweise den passenden
  Tag und den Anfrage-/Genehmigungsdialog in der Sportplatzbelegung.
- Frischer Admin-/Office-Zugang im aktuellen Mandanten und bestehendes
  Modul-Lese-/Genehmigungsrecht erforderlich. Trainer/TL erhalten dadurch keine
  Geschaeftsstellenansicht. Auch bei Livewire-Nachladen neu autorisieren.
- Linkparameter sind keine Berechtigung: Platzdialog muss weiterhin in der
  mandantenbezogenen Kalenderprojektion existieren, andernfalls404. Entscheidungen
  nutzen unveraendert Revision, Konfliktpruefung und bestehende Fachservices.
- Darstellung DE/EN, Freitexte unveraendert und HTML-escaped. Kein Polling,
  kein Provideraufruf, keine Nachrichten oder Schreibvorgaenge beim Lesen.

## Migration und Rueckweg

Keine Schema-/Datenmigration, kein Import, keine Status-/Rechtezuordnung und
kein neuer Parameter. Bestehende Tabellen bus_reservations und pitch_requests
werden ausschliesslich lesend verwendet. Bei zukuenftigen Migrationen beide
Anfragetypen mitsamt Status/Mandant erhalten; kein kuenstliches Neu-Anlegen.

Gezieltes Delta auf Basis des zum Deployment aktiven ClubUnio-Releases;
private Konfiguration, Firebase-Registry, Composer, Uploads und Fremdaenderungen
erhalten. Rueckweg: vorheriges Release aktivieren und PHP-FPM neu laden; keine
Daten zuruecksetzen.

Aktiv:20261105-clubunio-dashboard-reservations-v2 auf Basis des parallel
veroeffentlichten20261105-clubunio-pitch-request-inbox. Acht Dateihashes sowie
normalisierte Baselines der vier bestehenden Runtime-Dateien geprueft. Private
Konfiguration einschliesslich Firebase unveraendert; Cacheaufbau und FPM-Reload
erfolgreich. HTTPS Health/Login200, Dashboard und Platzdialog anonym302.
Rueckweg:20261105-clubunio-pitch-request-inbox. Ein erster inaktiver Kandidat
mit unvollstaendigem CLI-Authentifizierungskontext wurde nicht aktiviert.

## Nachweise

DashboardReservationRequestsTest: Mandantentrennung, aktive/offene Status,
alte Anfragen, begrenztes Blaettern, entzogene Rolle, Leerzustand, fehlerhafte
Seitennummer, DE/EN-Render und escaped Links. PitchPlanningPageTest ergaenzt
das Oeffnen per Dashboardlink sowie404 ausserhalb der erlaubten Projektion.
Ein optionaler bestehender Export von visuellen Testfragmenten bleibt ausgelassen.
30 gezielte Tests/136 Assertions, ein optionaler Fragmentexport ausgelassen;
2673 DE/EN-Schluessel erfolgreich geprueft. Einschliesslich deaktiviertem Konto
und Nur-Lesen-Supportmodus. Dies ist eine automatisierte Renderpruefung, keine
behauptete angemeldete manuelle Browserabnahme.

Zusaetzlich14 bestehende PostgreSQL-Bustests erfolgreich. Die sechs bestehenden
Dashboard-/Detailtests (30 Assertions) sind nach Korrektur einer alten Fixture
ebenfalls erfolgreich: Fremdvereins-Gutschrift und -Rueckerstattung erhalten
eigene gueltige Elternbelege/Status statt absichtlich ungueltiger mandanten-
uebergreifender Fremdschluessel. Die DB-Schutzregeln werden nicht abgeschaltet.
Alle synthetischen PostgreSQL-Daten transaktional zurueckgerollt.

Servergate check-dashboard-reservation-requests.php liest in einer expliziten
READ-ONLY-Transaktion: Registrierung, aktuelle Zaehler, DE/EN, Link und Trainer403.
Keine echten Anfragen wurden genehmigt/abgelehnt oder fuer Tests angelegt.
