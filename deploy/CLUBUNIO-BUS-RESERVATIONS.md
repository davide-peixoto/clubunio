# ClubUnio Busreservierungen

Release42: Historische Zuordnung im Detail fuer importierte unvollstaendige
Reservationen, nur Admin/Office. Begruendung/Revisionsschutz/Audit, keine
Ueberschreibung bestehender Links oder Benachrichtigung.14 Altfaelle bleiben
zur fachlichen Auswahl. Details/Restpunkte: CLUBUNIO-WEB-COMPLETION.md.

Stand 12.10.2026. Web-Fachpaket, keine Mobile-API und kein Produktiv-Cutover.

Release30 lokal/Pi aktiv: Umbuchung, Kalender und Schluesselbox-Zeitfenster.
Gezielte Regression19/254 erfolgreich, Desktop/Mobil lokal geprueft.
HTTPS-Login/CSS200, Zugriffsschutz302, FPM/Scheduler aktiv. Ruecksprung29.
Deployment-/Teststand siehe PI.md. Vorheriger Stand Release29.

## Erweiterung 30

- Umbuchen vor Beginn: Verwaltung fuer genehmigte/offene Reservationen,
  Antragsteller nur fuer eigene offene Anfragen. Team, Antragsteller und Status
  bleiben unveraendert. Bus, Zeitraum, Ziel und Bemerkung sind aenderbar.
- Pflicht-Aenderungsgrund, Revisionsschutz, erneute Konflikt-/Aktivitaetspruefung,
  atomisches Audit und interne Nachricht. Unveraenderte Formulare erzeugen keine
  neue Revision oder Nachricht. Vergangene/laufende Reservationen gesperrt.
- Kalender: Busreservierungen optional zuschalten; Monats-/Wochentagesfelder
  plus Zeitraumliste in allen Ansichten, inklusive nicht zugeordneter Altimporte.
  Datum, Team, Saison und Suche gelten auch fuer Busse. Terminart/Terminstatus
  gelten nur fuer Teamtermine. Busstatus: angefragt/genehmigt. Maximal1000.
- ICS-Export mit separaten Bus-UIDs, UTC-Zeiten und Revisionsnummer. Keine
  Antragstellerdaten, internen Bemerkungen oder Zugangscodes im Kalenderexport.
  Sichtbarkeit entspricht dem Busmodul; keine Erweiterung der Leserechte.
- Schluesselbox: berechtigte genehmigte Reservationen zeigen Start minus4h bis
  Ende plus4h. Ausgabe echter PINs fest deaktiviert, keinerlei Igloohome-Aufruf.
  Bestehende physische Codes werden weder importiert, verlaengert noch widerrufen.

Keine Schemaaenderung/Migration30 erforderlich. Nach erfolgreicher Pruefung
`php artisan clubunio:seed-message-templates` nur gegen ClubUnio ausfuehren:
ergaenzt bus.changed/bus.cancelled, ueberschreibt keine vorhandenen Texte.
Kein neuer Parameter, Cronjob oder externer Versand.

## Umfang

- Verwaltung: Anlagen und Spielbetrieb > Busreservierungen. Portal: Bus.
- Webpfad `/bus/{tenant}`: Liste, kombinierte Suche/Datums-/Team-/Busfilter,
  Statusfilter mit Anzahlen, paginierte Ergebnisse und Detailansicht.
- Erfassen mit Bus, TeamSaison, Antragsteller, Zeitraum, Ziel und Bemerkung.
  Zeiten werden als Europe/Zurich interpretiert und mit Offset gespeichert.
- Trainer/TL: nur verantwortete Mannschaften, eigener aktiver Mitgliederbezug;
  fremde Antragsteller werden serverseitig abgewiesen. Status immer ANGEFRAGT,
  auch bei manipuliertem Genehmigt-Payload. Elternvertretung allein reicht nicht.
- Admin/Geschaeftsstelle: alle Reservationen, aktive Antragsteller aus Lookup,
  wahlweise direkt genehmigen. Beendete/inaktive Saisons sind fuer neue
  Reservationen und Genehmigungen gesperrt. Historische Listen bleiben lesbar.
- Genehmigen/Ablehnen/Stornieren nur Verwaltung; eigene offene Anfrage kann
  durch den Antragsteller storniert werden. Genehmigte Reservationen koennen
  nur durch die Verwaltung storniert werden. Abgeschlossene Zeitraeume und
  terminale Status bleiben unveraendert. Kein physisches Loeschen.
- Busse und Status: Bus erfassen, Bezeichnung/Aktivierung bearbeiten;
  Statusbezeichnungen/Aktivierung bearbeiten. Technische Statuscodes sind
  unveraenderlich, neue Workflowzustaende brauchen eine fachliche Erweiterung.
- Statusfarben verwenden dieselbe zentrale Zuordnung wie die anderen Module.

## Konsistenz und Nachrichten

Tenant-Zeilenlock serialisiert Antraege, Entscheidungen und Auswahlpflege.
Reservationen haben Revisionen; veraltete Entscheidungen werden abgewiesen.
Auswahlpflege prueft einen Fingerabdruck des angezeigten Wertes. Alle Writes
werden mit Vorher-/Nachherwert und Akteur in object_changes protokolliert.

ANGEFRAGT, GENEHMIGT und UNBEKANNT blockieren denselben Bus bei Ueberschneidung.
Direkt anschliessende Zeitraeume sind erlaubt. Konfliktpruefung bei Erstellung
und erneut bei Genehmigung. Stornierte/abgelehnte/inaktive Eintraege blockieren
nicht. Bestehende widerspruechliche Importe werden nicht automatisch geloescht.
Bus, Antragsteller, Mannschaft und Saison werden bei Genehmigung neu geprueft.

Interne ClubUnio-Benachrichtigungen innerhalb derselben DB-Transaktion:
- Neue Anfrage: aktive Geschaeftsstelle-Konten des Vereins.
- Genehmigung, direkte genehmigte Erfassung oder Ablehnung: berechtigt mit
  dem Antragsteller verknuepfte aktive Portal-Konten (self/approved).
- Wiederholte/veraltete Entscheidungen erzeugen keine doppelte Nachricht.
- Vorlagen bus.requested, bus.approved, bus.rejected aus bestehender Textpflege.
- Portal-Nachricht verlinkt die sichtbarkeitsgeschuetzte Reservation.

Kein realer FCM-, E-Mail- oder SMS-Versand. outbound_enabled bleibt false.
Umbuchungen: offene Anfrage an Geschaeftsstelle, genehmigte an Antragsteller.
Stornierungen: Geschaeftsstelle und berechtigter Antragsteller, dedupliziert.
Neue editierbare Vorlagen: bus.changed und bus.cancelled.

## Migration 000029

Nur `db_acdf8d_dclubora`. Neue Tabellen buses, bus_statuses, bus_reservations,
Tenant-Fremdschluessel, Datumsconstraint und Zeitraumindex.
Einmaliger Import aus dem bereits vorhandenen `legacy_prod`-Snapshot, nicht
aus der laufenden PHPMaker-PROD-Datenbank: 1 Bus und 18 Reservationen.
Quell-/Zielanzahl wird verglichen, Abweichung bricht die Migration ab.
Migration erfolgreich lokal gegen die gemeinsame ClubUnio-Datenbank ausgefuehrt.

Alle 18 Quellreservationen tragen GENEHMIGT. Bei 14 fehlt TeamSaison oder
Antragsteller; keine geratenen Zuordnungen. Fehlende Angaben bleiben NULL,
bei spaeterer Genehmigung verlangt die Logik eine vollstaendige Zuordnung.
Legacy-IDs, Status, Zeitraum, Busbemerkung, Ort,
Entscheidbemerkung und Aktivitaet bleiben erhalten. Originale Workflowzeitpunkte,
Bearbeiternamen, alte TeamID und Loeschdatum zusaetzlich in legacy_workflow.
Wenn kein Antragszeitpunkt vorhanden ist, created_at=Importzeit; nicht als
historisch gesichertes Antragsdatum interpretieren.

Kein Zurueckschreiben, kein periodischer Neuimport. Fuer einen spaeteren
Cutover muessen Snapshot-Aktualisierung, fehlende Zuordnungen und Konflikte
fachlich abgeglichen werden. Additive Tabellen bei Code-Rollback behalten;
down() verweigert destruktives automatisches Zurueckrollen.

## Pruefung und verbleibend

Tests mit isolierten Testvereinen und Transaktionsrollback, niemals echte
Reservationen/Benachrichtigungen erzeugen. Webmasken lokal angemeldet auf
Desktop und schmalem Bildschirm geprueft, ohne echte Reservation abzusenden.
Test-/Deployment-Abschluss in PI.md und CLUBUNIO-ROADMAP.md.

Offen: Fachabnahme, Nachpflege historischer Zuordnungen, geschuetzte
Schluesselboxausgabe inklusive Umgang mit bereits ausgestellten Codes bei
Umbuchung/Storno. Vor echtem Betrieb Zugriffsfenster und Provider gesondert testen.
Kein Abschluss des gesamten Bus-/Anlagenpakets oder der weiteren Fachmodule.
