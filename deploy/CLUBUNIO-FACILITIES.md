# ClubUnio: Anlagen, Wettkaempfe und Signage

Stand 12.10.2026, Erweiterung Release35. Parallelaufbau ausschliesslich in ClubUnio.
PHPMaker DEV/PROD und der unveraenderte legacy_prod-Snapshot bleiben unberuehrt.

## Wetter und Instagram (Release35)

Signage-Vorschau mit getrennt nachgeladenen, gecachten externen Feeds.
Aktuelles Wetter plus drei Tage, Instagram-Bilder mit Originalverlinkung.
Ausfaelle zeigen letzten Stand mit Zeitstempel statt den Spielplan zu blockieren.
Vier Wetterparameter per Migration33; Instagram bleibt bewusst deaktiviert.
Details und verbleibende Livefreischaltung: CLUBUNIO-SIGNAGE-FEEDS.md.

## Vereinsstamm und Logos (Release34)

Vereine und Logos als neue Verwaltungsmaske, Gastgeber/Gast-Vereinslookup in
Wettkaempfen, gespeicherter Prueffilter fuer fehlende/inaktive Zuordnungen.
Signage zeigt SFV-Logos anhand bestehender Vereinsnummern; kein Namensraten,
kein Ueberschreiben der Garderoben oder technischen SFV-Verknuepfung.
Migration32 und Einmalimport1447 Vereine,3 Konfliktnummern zur Fachklaerung.
Details/Grenzen/Migrationsschritte: CLUBUNIO-FOOTBALL-CLUBS.md.
Die folgenden Releaseabschnitte beschreiben jeweils den damaligen Stand.

## Sammelpruefung und Ausblendung (Release33)

- Zeitraum pruefen: maximal100 SFV-Spiele, getrennte Anzeige von Aenderungen,
  unveraenderten und nicht uebernommenen Spielen. Einzelne Aenderungen aufklappen.
  Manuelle Zuordnungskonflikte werden nicht automatisch aufgeloest.
- Bestaetigte Sammeluebernahme erfolgt atomar. Jede Vorschau wird erneut geprueft;
  ein zwischenzeitlicher Konflikt rollt die gesamte Sammlung zurueck. Keine
  partiellen Erfolge, keine automatische periodische Uebernahme/kein Cronjob.
- Eindeutig verknuepfte aktive kuenftige Spiele koennen nach SFV-Absage oder
  neuem Spielort ausserhalb der eindeutigen Tannenbrunn-Zuordnung zur Ausblendung
  vorgeschlagen werden. Sichtbarkeit erst nach expliziter Web-Bestaetigung false.
  Garderoben und restliche Wettkampfdaten bleiben gespeichert, active bleibt true.
- SFV-Status fuer Ausblendung: abgesagt, annulliert, abgebrochen, verschoben.
  Sonderstatus wie Null zu Null - Null Punkte bleiben manuelle Prueffaelle.
  Wegverlegung nur bei normalem angesetzten Status
  und nichtleerem Ort. Unbekannte Status oder nicht eindeutige Verknuepfungen
  bleiben Fehler zur manuellen Pruefung, keine geratenen Korrekturen.
- Neuansetzung schaltet Sichtbarkeit NIE automatisch wieder ein. Bereits
  unsichtbare Spiele bleiben unsichtbar. Auditaction sfv_withdrawal samt Grund.
- Feed-Abwesenheit, unvollstaendige Termine ohne Datum, archivierte Spiele und
  mehrfache historische Verknuepfungen bleiben manuell zu pruefen. Die Sammelliste
  umfasst nur im Zeitraum liegende datierte SFV-Zeilen. Keine Loeschung per Feed.
- Keine weitere Migration neben dem Kontenpaket31. Logo/Instagram/Wetter und
  oeffentlicher Displaybetrieb weiterhin offen.

## SFV-Uebernahme in Wettkaempfe (Release32)

- Anlagen und Spielbetrieb -> SFV-Wettkaempfe, auch als Schaltflaeche in der
  Wettkampfliste. Zeitraum waehlen, SFV abrufen, Spiel waehlen, Uebernahme pruefen,
  Vorschau bestaetigen. Nur Admin/Geschaeftsstelle; keine neue App-API/Automation.
- SfvPlanningService aktualisiert den gemeinsamen SFV-Lesebestand. Migration30
  ergaenzt host_name/guest_name aus teamNameA/teamNameB. Alte Cachezeilen vor der
  ersten Uebernahme neu abrufen; keine geratenen Teamnamen aus Titel-Splitting.
- Nur heutige/kuenftige Spiele, Datum/Zeit Europe/Zurich. Tannenbrunn - 1, Sissach
  bzw. Tannenbrunn, Sissach - 1 -> aktiver Lookup H; 2 -> K; 3 -> T.
  Heimkennzeichen alleine reicht nicht. Auch Drittvereins-Spiele auf der Anlage
  koennen aus einem gelieferten SFV-Datensatz uebernommen werden. Der Club-Feed
  liefert jedoch nicht zwingend alle fremden Spiele: manuelle Pflege bleibt.
- Zulaessige Quellstatus: angesetzt, neu angesetzt, noch nicht ausgetragen.
  Andere Status/unklare Orte/archivierte oder mehrfache Verknuepfungen erfordern
  manuelle Pruefung. Keine automatische Loeschung oder Wiederaktivierung.
- SFV darf nur Datum, Zeit, Gastgeber, Gast, Spielfeld und technische Quelle/ID
  aktualisieren. Garderoben, Seiten, Kategorien, Vereinsnummern, Bemerkungen und
  manuelle Signage-Sichtbarkeit werden NIE vom Sync geschrieben.
- Bestehende VerbandMatchID wird eindeutig verwendet. Unverknuepfte Spiele am
  gleichen Tag blockieren eine blinde Neuanlage: vorhandenen Eintrag auswaehlen
  oder nach fachlicher Pruefung explizit als eigenstaendiges Spiel bestaetigen.
  Kein automatisches Zusammenlegen anhand unscharfer Vereinsnamen.
- Vorschau ist Livewire-Locked; Source-/Zielrevision und Fingerprint werden in
  einer Transaktion unter Mandantensperre erneut geprueft. Gleichzeitige manuelle
  Aenderungen werden nicht ueberschrieben. Identischer Abruf ist ein No-op;
  echte Aenderungen werden in object_changes protokolliert.
- Neue Wettkaempfe sind aktiv und auf Signage sichtbar. Vereinsnummern bleiben
  bei Neuanlage leer (Team-ID ist KEINE Vereinsnummer); Logo-Mapping bleibt offen.
- Keine zusaetzlichen Ini-Parameter, keine PHPMaker-Regeneration. Migration30 ist
  additiv, nur ClubUnio-DB. Ruecksprung auf31 kann die beiden Spalten behalten.
  Abgesagte/wegverlegte/aus dem Feed verschwundene Spiele muessen weiterhin
  manuell auf Unsichtbar/Inaktiv gesetzt werden. Automatischer Statusabgleich,
  Massenuebernahme, Logo/Instagram/Wetter und oeffentlicher Displaybetrieb offen.

## Daten und Migration

- Migration000018: competitions, signage_notices, field_availabilities und
  facility_statuses. Fremdschluessel enthalten Mandant und Ziel-ID.
- clubunio:import-facilities liest einmalig den bestehenden Snapshot:
  165 Wettkaempfe, acht Meldungen und drei Platzstatus. Wiederholung ueberschreibt
  keine ClubUnio-Aenderungen. Unbekannte Lookups brechen den Import atomar ab.
- Spielfeld/Garderobe/Seite verwenden die bereits aus PMP importierten
  training_options. Hauptplatz/Hauptfeld -> H, Kunstrasen -> K,
  Trainingsplatz/Trainingsfeld -> T. Vereinsnummern bleiben erhalten.
- Aktive VerbandMatchID ist je Mandant eindeutig. Historische geloeschte Zeilen
  werden inaktiv uebernommen. Konflikte werden NICHT automatisch zusammengelegt.
- Quelle, VerbandMatchID und Import-/Sync-Zeitstempel sind technisch geschuetzt.
  Formulare koennen diese weder leeren noch durch zusaetzliche Felder veraendern.

## Bedienung

- Navigation Anlagen und Spielbetrieb: Wettkaempfe, Anlagenstatus,
  Signage-Meldungen, Signage-Vorschau.
- Wettkaempfe standardmaessig ab heute (Europe/Zurich), aktive Eintraege.
  Ab-heute-Filter kann fuer historische Daten entfernt werden. Datumsbereich,
  Spielfeld, Signage-Sichtbarkeit und Suche kombinierbar.
- Garderoben/Seiten getrennt fuer Gastgeber und Gast. Spiele anderer Vereine
  sind moeglich; kein impliziter Gastgeber=SV-Sissach-Filter.
- Wettkaempfe und Meldungen unterstuetzen persoenliche gespeicherte Ansichten.
  Checkbox-/Datumsfilter werden beim Speichern/Laden explizit validiert.
- Anlagenstatus-Werte kommen aus einer editierbaren Tabelle; blocks_training
  steuert die bestehende Trainingsvariante, nicht die Bezeichnung.
- Aenderungen erfordern Admin/Geschaeftsstelle, Mandantenpruefung und Revision.
  Veraltete Formulare werden abgewiesen; object_changes protokolliert Aenderungen.
  Loeschen erfolgt nicht physisch; die Datensaetze werden deaktiviert.

## Interne Signage-Vorschau

- Nur angemeldetes Backoffice, keine oeffentliche Anzeige oder neue App-API.
- Spielplan und Meldungen am gewaehlten Tag; nur aktive sichtbare Wettkaempfe.
- Trainingsperiode bewusst aus Tabellen-Lookup waehlen. Team, TeamSaison und
  Saison muessen aktiv sein, Datum muss in der Saison liegen.
- PMP-Wochentage: Sonntag=1, Montag=2, ... Samstag=7, unabhaengig von DB-Sprache.
- Alte Varianten erhalten: H+T gesperrt -> synthetic_only; nur H gesperrt ->
  without_main; sonst all_fields. Unbekannter Status wird nicht als offen gelesen.
  Kunstrasen-Sperre hatte bisher keine eigene Trainingsvariante. Diese fachliche
  Grenze wird sichtbar beibehalten und nicht als neue automatische Absage behandelt.
- Texte werden escaped ausgegeben. HTML-Altmeldungen werden als Text importiert.
- Vereinsnummern sind gespeichert; automatische Logo-Aufloesung, Instagram,
  Wetter, Display-Token/Kioskmodus und automatischer SFV-Sync sind NOCH OFFEN.
  Manueller SFV-Liveabruf mit bestaetigter Uebernahme seit Release32 vorhanden.
- Keine Push-/Emailzustellung durch diese neuen Masken. Ereignisanbindung fuer
  Platzstatus und Busworkflow bleiben im Migrationsplan offen.

## Zentrale Farben

- Migration000019: status_styles; ersetzt die kurzzeitig separate Farbe der
  neuen Anlagenstatus-Tabelle. Stammdaten: Status- und Auswahlfarben.
- clubunio:seed-status-styles ergaenzt fehlende Bezeichnungen aus Status-/Lookup-
  Tabellen ohne vorhandene Anpassungen zu ueberschreiben; initial 47 Farben.
- Gleiche normalisierte Bezeichnung (Gross-/Kleinschreibung/Leerraum) verwendet
  in Filament-Listen, Details, Status-Tabs, Diagrammen und Signage dieselbe Farbe.
  Neue unbekannte Werte haben weiterhin deterministische Standardfarben.
- Farben sind pro Mandant, als sechsstellige Hexwerte validiert, revisions- und
  auditgesichert. Deaktivierung faellt auf die Standardfarbe zurueck.
- Requestgebundener Cache vermeidet eine DB-Abfrage pro Tabellenzelle; keine
  mandantenuebergreifende globale statische Speicherung. Nach Bearbeitung geleert.
- Portal-Farbabgleich bleibt offen, neue API/SaaS weiterhin ausgenommen.

## Signage-Aktualisierung (12.10.2026)

Die angemeldete Web-Vorschau aktualisiert sich alle60 Sekunden. Heute-Modus
folgt dem Tageswechsel Europe/Zurich. Manuelle Datumauswahl schaltet diesen
Modus aus; Vorschauen springen nicht ungewollt zurueck. Trainingsperiode bleibt
unveraendert, Zugriff bleibt Admin/Office. Kein oeffentlicher Displayzugang,
keine API und keine Integration von Instagram/Wetter in diesem Teilschritt.
Automatisierter Test deckt Mitternacht, manuelles Datum und Rueckkehr zu Heute ab.

## Dashboard

- Migration000020: recorded_at aus dem urspruenglichen Mitglieder.ErfasstAm.
  Unbekannte Quelldaten bleiben unbekannt, Importzeit ist KEIN Eintrittsdatum.
  Neue ClubUnio-Mitglieder bekommen ein technisches Erfassungsdatum in Zurich.
- Neue Mitglieder nach Erfassungsmonat, letzte zwoelf Kalendermonate inkl. aktuell.
- Rechnungen nach Positionen: keine Position -> Ohne Position, mindestens O ->
  Offen, alle B -> Bezahlt, sonst Teilweise. Inaktive Positionen/Belege ausgeschlossen.
- Rechnungsbetrag je Saison: Gesamt und offen, maximal zehn Saisons.
  Bisherige Null-Netto/Brutto-Fallbackregel bleibt unveraendert (Fachabnahme offen).
- Tabellen offene Rechnungen und Spesen F/Z mit Direktlinks, Pagination.

## Pruefung / Betrieb

Automatisiert: Source-ID-Erhalt, Revision/Audit, Rollen/Mandanten, Lookups,
Filter+Suche+Ansichten, Formular-Anlage/Bearbeitung, XSS-Escaping, Datumsgrenzen,
Trainingsvarianten, zentrale Farben und Rechnungsaggregation.
Gesamttest 107 Tests/1019 Assertions; letzter Filtertest 1 Test/23 Assertions.
Release17 lokal und Pi bereitgestellt, HTTP und Cacheaufbau erfolgreich.
Angemeldete visuelle Abnahme ausstehend. Details siehe PI.md.
Kein echter externer Schreibzugriff, kein Cutover und keine neue Nachrichtenzustellung.
