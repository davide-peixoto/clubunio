# ClubUnio Web: Listen, Signage, Bus und KI-Training

Stand 14.10.2026, Release42. Diese Uebersicht ersetzt historische Offen-Vermerke
nur fuer die unten genannten Funktionen. API, SaaS, Betrieb/Cutover und reale
Benachrichtigungen bleiben ausgeschlossen. Bexio unveraendert bis Sandbox.

## Listen und Bedienung

Vorhanden: kombinierte Suche/Filter/Sortierung, persoenliche Ansichten mit
Standardwahl, Status-/Berichtstabs, Dossierverknuepfungen und rollenbegrenzte
CSV-Exporte mit Formelschutz. Exportiert wird die gefilterte Gesamtauswahl,
nicht nur die aktuelle Seite; 5000-Zeilen-Limit ohne stille Kuerzung.

| Alte Listen | ClubUnio |
| --- | --- |
| Ehren-/Frei-/Supporter-/Sponsor-/Spots-Mitglieder | Mitgliederlisten, jeweiliger Berichtstab, nun auch CSV |
| Passivmitglieder / Mitglieder ohne Team | Eigene Listen, Saison-/Mitgliedschaftsregeln, CSV |
| Spieler / Saisonspielerliste / Spieler ohne Beitrag | Spielerlisten, Saison/Mannschaft/Ohne Beitragsposition, CSV |
| Spieler mit Eltern | Spielerlisten: optionale Elternkontaktspalten und separater bestaetigter Kontakt-CSV |
| TeamStaff Saison | Stafflisten, Saison/Mannschaft/Funktion, CSV |
| Familien mit Kindern | Familienliste mit Zuordnungen, Adresse/Rabatt, CSV |
| Bankkonten Staff | Bankkontenliste, optional Staff-Saison, maskierte IBAN, vollstaendige Bankadresse/CSV |
| Offene Beitraege / Zur Bezahlung | Offene Rechnungen / Spesen zur Bezahlung, gefilterte CSV |

Bankfelder aus Institut: Domizil, Postadresse, PLZ, Landcode, BCNr, Postkonto.
Einmalige Ergaenzung nur aus dem vorhandenen legacy_prod-Snapshot, passend zur
legacy_id des importierten Vereins. Keine Neuabfrage der PHPMaker-PROD-DB.
Bankstamm unter Stammdaten bearbeitbar. Bankpostkonto ist NICHT die persoenliche
IBAN; persoenliche IBAN bleibt auch im Export maskiert.
Elternkontakte erscheinen NICHT automatisch im normalen Spielerexport.
Mitgliederlisten-CSV enthaelt Nummer,Name,Art,Status, keine sensiblen Ausweisdaten.

Release43 schliesst den technischen Altlisten-Abgleich: zentrale Schluesselliste
und Spots-Versandliste samt Exporten. Auswahlregeln/Unterschiede zum Altbestand
stehen in CLUBUNIO-LEGACY-REPORTS.md. Fachliche Benutzerabnahme bleibt erforderlich.

## Signage

Vorhanden: Spielplan mit Garderoben/Logos, Trainingszeiten mit Platzvarianten,
Anlagenstatus, Meldungen, Wetter/Instagram mit Fehlerpause und begrenztem
Altbestand, Heute-Modus und Tageswechsel. SFV-Sammelpruefung bewahrt manuelle
Garderoben und behandelt Absage/Verlegung durch bestaetigte Ausblendung.
Neu: Vollbildschalter fuer die angemeldete Vorschau, responsives Raster,
Rueckkehr ueber denselben Schalter oder Escape. Keine oeffentlichen URLs/Tokens.
Unter Einstellungen kann nur ein Admin den vorhandenen Instagram-Token fuer
den Feed aktivieren/deaktivieren. Zustand sichtbar, Aenderung auditiert,
konkurrierende Aenderung abgewiesen. Kein Token im Browser und kein Login-
oder Refresh-Workflow neu eingefuehrt. Echte Einstellung unveraendert gelassen.

Abnahmegrenze: echter Instagram-Feed mit gueltigem Token; Dauerdisplay,
Token-Erneuerung und unbeaufsichtigter Kioskbetrieb sind nicht freigegeben.

## Bus

Vorhanden: Antrag/Genehmigung/Ablehnung/Storno/Umbuchung, TeamSaison-/Mitglieder-
bindung, Konflikt-/Revisionsschutz, Kalender/ICS, interne Nachrichten und
Bus-/Statuspflege. Keine echte Pushzustellung im Testbetrieb.
Neu: Historische Zuordnung im Detail importierter unvollstaendiger Reservationen.
Nur aktive Admin/Office-Konten; bestehende Zuordnungen bleiben unveraendert,
fehlende Mannschaft/Antragsteller koennen auch fuer historische Saisons/Mitglieder
mit Begruendung ergaenzt werden. Fremdmandanten und alte Revisionen abgewiesen.
Keine Aenderung von Zeitraum/Genehmigung, keine Nachricht/Schluesselcodeausgabe.
14 Altreservationen benoetigen die fachliche Auswahl; keine automatische Vermutung.

Physische Igloohome-Ausgabe und Widerruf bei Umbuchung/Storno bleiben technisch
und fachlich ein gesonderter Integrationspunkt. Das Buspaket einschliesslich
Schluesselhardware ist daher nicht vollstaendig abgeschlossen.

## KI-Training

Vorhanden: KI-Erstellung mit File Search/Qualitaetsregeln, Bearbeitung, sichere
Grafiken/PDF, private und lesende Teamfreigabe, bestaetigte KI-Review-Uebernahme,
Revisions-/Mandanten-/Rollen-/Ratelimitschutz.
Neu: Archiv/Aktuell-Filter, Archivieren/Wiederherstellen durch Besitzer oder
Verwaltung. Archiv sperrt Bearbeitung/Review, Lesen/PDF bleiben nach vorhandener
Berechtigung moeglich. Private Kopie eines sichtbaren Plans ohne KI-Aufruf,
eigener Besitzer, keine uebernommene Freigabe, Revision1, Request-UUID gegen
Doppelanlage. Kopie bleibt bei derselben Mannschaft/Saison. Archivierung ist
keine automatische Aenderung der bestehenden Lesefreigabe.
Kopien haben getrennte Limits (10/Minute,100/Tag je Benutzer,500/Tag je Verein)
und belasten die KI-Quoten nicht.

Historischer TrainingPlannerAuditLog-Snapshot hat41 Zeilen, darunter moegliche
Fehlversuche. Kein ungepruefter Import als41 fertige Plaene. Altplan-Konvertierung,
Besitzer-/Teamzuordnung und alte Grafikformate bleiben im Migrationsplan separat.
KI-Fachabnahme durch Trainer bleibt noetig; das ist keine automatische Aussage
ueber Trainingsqualitaet. Externe KI-Aufrufe in dieser Regression nur gemockt.

## Migration und Ruecksprung

000039 auf eigener ClubUnio-DB einmal anwenden: training_plans.archived_at sowie
sechs Bankfelder und deren Snapshot-Ergaenzung. Lokal/Pi teilen die Datenbank.
Keine Loeschungen oder Aenderung von PHPMaker DEV/PROD. APP_KEY beibehalten.
Vor Rollback auf41 archivierte Trainings beachten: alte Listen kennen das Feld
nicht und zeigen sie wieder als normal; vorherige Version nicht fuer deren
Bearbeitung einsetzen. Zusatzfelder behalten; kein automatisches down().

Aktueller Test-/Deploymentnachweis steht in PI.md. Offene Integrations- und
Migrationspunkte oben verhindern die pauschale Aussage "alles komplett fertig".
