# KI-Trainingsplanung im Web

Release42: Planarchiv/Wiederherstellung und private Kopie ohne KI-Aufruf.
Migration39 ergaenzt archived_at, Details/Ruecksprung/Abnahme in
CLUBUNIO-WEB-COMPLETION.md. Archivierte Plaene nicht bearbeiten oder KI-pruefen;
bisherige Leserechte/PDF bleiben. Keine automatische Legacy-Planmigration.

Stand 12.10.2026. ClubUnio, keine Aenderung an PHPMaker DEV/PROD oder App-API.

## Umfang

- `/planning/{tenant}/training-plans`: Suche, Mannschaftsfilter, Pagination.
- Neuer Plan mit Saison-/Mannschaftslookup, Dauer, Spielerzahl, Schwerpunkt,
  Platzgroesse und Material. Saison standardmaessig neueste vorhandene Saison.
- Bearbeitung der einzelnen Uebungen, Prinzipien und Qualitaetspruefung.
- Private Plaene; ausdrueckliche lesende Freigabe an zustaendige
  Teamverantwortliche. Spieler/Eltern erhalten keinen Zugriff.
- KI-Pruefung des gespeicherten Plans als Vorschau. Uebernahme ausschliesslich
  per bestaetigtem POST, an Benutzer, Mandant, Planrevision und30min gebunden.
- PDF mit eingebetteten schematischen Spielfeldgrafiken. Grafikdaten bestehen
  aus maximal50 streng begrenzten Positionsmarkern; kein Modell-SVG/HTML.
  Manuelle Textaenderungen verschieben die Marker nicht automatisch; eine
  erneute KI-Pruefung erzeugt dazu einen bestaetigbaren vollstaendigen Vorschlag.

## Architektur und Schutz

TrainingPlanController koordiniert Web-Requests; TrainingPlanService uebernimmt
Sichtbarkeit, Revisionen, Speicherung, Freigabe und Audit; TrainingPlanAi den
Provider; TrainingPlanContent validiert und erzeugt sichere Grafiken.

Bestehende verschluesselte Settings OPENAI_API_KEY,
OPENAI_TRAINING_VECTOR_STORE_ID und OPENAI_TRAINING_MODEL werden verwendet.
Keine neuen Schluessel oder Vector Stores, kein Modellwechsel.
Manuell ausgeloeste KI-Anfragen sind erlaubt, obwohl der Schutz gegen
produktive Bexio-Schreibvorgaenge/echte Mitgliedernachrichten aktiv bleibt.

Responses API mit verpflichtendem file_search und strengem JSON-Schema;
store=false, festes HTTPS-Ziel, keine Weiterleitungen,5s Verbindung/85s gesamt.
Abgeschlossener Suchaufruf und gueltiger Plan erforderlich. SV Sissach/SFV
muessen in der Qualitaetspruefung sichtbar sein. J+S-Phasenfolge und exakte
Gesamtdauer werden zusaetzlich serverseitig geprueft.
Keine Mitgliederlisten, medizinischen Angaben oder internen Mitglieder-/Team-IDs
werden aus der DB an den Provider uebergeben. Freitexte muessen ohne private
Personenangaben erfasst werden. Logs enthalten keine Prompts, Schluessel oder
Provider-Antworten, sondern Referenz, technische Benutzer-/Mandanten-ID und Status.

Maximal1 Anfrage/Minute und20/24h je Benutzer sowie100/24h je Verein, einschliesslich
fehlgeschlagener Versuche. DB-Lock schuetzt Reservierung auch ueber lokale/Pi-
Prozesse hinweg. Request-UUID verhindert doppelte Erstellung durch Wiederholung.
Kein HTTP-Aufruf unter dem Reservierungs-Lock. Rechte werden vor Speicherung
nochmals geprueft. Keine automatischen Wiederholungen kostenpflichtiger Anfragen.

## Migration und Ruecksprung

Migration `2026_10_12_000027_training_plans.php` nur auf separater ClubUnio-DB:
training_plans und training_plan_requests mit mandantengebundenen Fremdschluesseln.
Lokal und Pi teilen diese DB; Migration nur einmal ausfuehren. Keine Aenderung
am legacy_prod-Snapshot oder an den alten Produktiv-/DEV-Datenbanken.
Code-Ruecksprung auf Release25 moeglich; additive Tabellen behalten.
Release26 auf Pi deployed. FastCGI120s; vorgelagerter Proxy braucht ebenfalls
120s Lesetimeout (Benutzer zur NPM-Einstellung angefragt).
Keine destruktive down-Migration. Bereits erzeugte Plaene bleiben erhalten.

Der Import historischer PHPMaker-Trainingsplaene bleibt ein eigener gepruefter
Migrationsschritt (Benutzerzuordnung, Freigaben, altes Diagrammformat). Er wurde
nicht implizit ausgefuehrt. Grafiken sind schematisch, kein interaktiver Taktikeditor.

## Pruefung

TrainingPlanTest:7/52; mit Teamplanung, Bexio und Pagination32/305 bestanden.
Web/PDF, Rechte, Mandantentrennung, Revision, Idempotenz, Rate-Limit,
bestaetigte Review-Uebernahme, Inhaltsvalidierung und Providerfehler.
Externe Aufrufe in Tests ausschliesslich gemockt; isolierte DB-Transaktionen.
Separater echter Test mit neutralem B-Junioren-Passspiel: vier Uebungen erfolgreich
erzeugt und validiert; keine Mitgliederdaten gesendet, kein Plan gespeichert.
`tools/clubunio-training-check.php` zeigt nur Konfigurationsvorhandensein.
Optional `--live-test` fuehrt genau einen kostenpflichtigen neutralen Test aus.
Erfassungsformular angemeldet auf Desktop und390px visuell geprueft.
Fachliche Benutzerabnahme und PDF-Layoutabnahme mit einem gespeicherten Plan offen.

Quellen: https://developers.openai.com/api/docs/guides/tools-file-search
und https://developers.openai.com/api/docs/guides/structured-outputs

## Weitere beauftragte Fachpakete

Noch nicht durch dieses Paket erledigt: Bexio-Fachprozesse, Finanzen, Signage,
Bus/Anlagen sowie weitere Listen/Bedienung. Sie bleiben im Migrationsplan offen.
API und SaaS weiterhin ausgenommen; kein Cutover.
