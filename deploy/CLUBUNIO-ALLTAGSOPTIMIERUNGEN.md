# ClubUnio: übersichtliche Alltagsabläufe

Stand: 24.11.2026. Vom Benutzer in den nächsten Arbeitsumfang aufgenommen.
Status: auf DEV umgesetzt; Browser-Dateiupload und echte Kamera noch offen.
Release, Fachverhalten und Abnahme: [Alltagsabläufe v1](CLUBUNIO-DAILY-WORKFLOWS.md).
Basis ist der abgenommene Work Hub v3. Die sechs Pakete erweitern vorhandene
Fachabläufe und werden nicht als parallele Datenhaltung aufgebaut.

## Gemeinsame Bedienungsregeln

- Eine klare Hauptaktion pro Ablauf, verständliche Ergebnisse statt technischer
  Zwischenstände. Details und selten benötigte Optionen bleiben aufklappbar.
- Sofort erkennbar: Was ist offen, was wurde gespeichert, was braucht eine Prüfung?
- Direktlinks führen zum betreffenden Objekt, Tag und nötigen Filter.
- Bestätigungen dort, wo sie fachlich nötig sind: Konflikte, Folgen für andere
  Termine, Freigaben und verworfene Eingaben. Kein zusätzlicher Klick für jeden
  gewöhnlichen, rückgängig machbaren Aufgabenwechsel.
- Nach erfolgreichem Speichern oder Abgleich werden betroffene Übersichten
  aktualisiert. Laufende Eingaben und Drag-Vorgänge werden dabei nicht ersetzt.
- Mandantenfarben, zentraler React-Parameter, bisheriges Design, DE/EN,
  Tastaturbedienung und reduzierte Bewegung gehören zu jedem Paket.
- Berechtigungen gelten auch für Zähler, Zusammenfassungen, Vorschläge und
  Direktlinks; keine fremden oder inzwischen entzogenen Objekte anzeigen.

## 1. SFV: vom Abruf bis zur sichtbaren Planung

### Ziel aus Benutzersicht

Eine Hauptaktion **„SFV abgleichen“** für den gewählten Zeitraum. Das Ergebnis
nennt getrennt: neue übernommene Spiele, aktualisierte Spiele, unveränderte
Spiele und Fälle zur Prüfung. „Abgerufen“ darf nicht als „eingeplant“ erscheinen.
Die Liste **„Prüfung nötig“** erklärt jeden offenen Fall und bietet die nächste
passende Aktion. Erneutes Abgleichen legt keine Dubletten an.

### Fachliche Übernahme

- Neue zukünftige Spiele nur bei eindeutigem Spiel, aktivem zugeordnetem Platz,
  eindeutigem Mannschafts-/Saisonbezug, passenden Rechten und konfliktfreier
  Belegung automatisch übernehmen. Auswärtsspiele benötigen keine lokale
  Platzreservierung, können aber bei eindeutiger Zuordnung Teamtermine ergeben.
- Bereits verbundene Spiele gemeinsam betrachten: SFV-Quelle, Wettkampf,
  Platzbelegung und verknüpfte Mannschaftstermine dürfen nicht auseinanderlaufen.
- Die bestehenden Vorschau-, Revisions- und Folgenprüfungen weiterverwenden.
  Mehrdeutige Mannschaften/Plätze, unvollständige Zeiten, Dubletten, belegte
  Flächen und widersprüchliche lokale Datensätze bleiben zur Prüfung stehen.
- Geschützte lokale Ausnahmen und die vorhandene SFV-Spielverschiebungsregel
  erhalten. Bestehende Termin-/Platzänderungen mit prüfpflichtigen Folgen nicht
  ungefragt übernehmen; auch Absagen und fehlende Rechte nachvollziehbar behandeln.
- Auf Folgefehler vorbereitet sein: Ein erfolgreicher Quellabruf mit teilweise
  offener Übernahme wird ausdrücklich als Teilergebnis angezeigt. Wiederholung
  darf bereits erfolgreiche Übernahmen weder verdoppeln noch zurücksetzen.
- Geöffnete Plantafeln und Kalender erhalten nach Abschluss neue Daten oder
  einen sichtbaren Aktualisierungshinweis, ohne ungespeicherte Eingaben zu verlieren.

### Abnahme

Ein eindeutig zugeordnetes neues Heimspiel erscheint nach einem Abgleich auf
der Plantafel und im richtigen Mannschaftskalender. Auswärts- und Heimspiele
werden korrekt unterschieden. Wiederholung erzeugt keinen zweiten Eintrag.
Unklare Zuordnung und geschützte lokale Änderungen erscheinen mit Erklärung
unter „Prüfung nötig“. Datum, Anspielzeit, Platz, Absage und fehlende Rechte
werden einschließlich veralteter Vorschau und paralleler Änderungen getestet.
Ein bereits offener Kalender zeigt das abgeschlossene Ergebnis korrekt.

Technische Anschlüsse: `SfvPlanningService`, `SfvCompetitionService`,
`SfvRescheduleImpact`, `SfvLocalOverride`, `PitchPlanningService`,
`PlanningCalendarService` und der vorhandene Sync-Auftragsdienst.
Details zum Schutz lokaler Änderungen: [Arbeitskorb/SFV](CLUBUNIO-WORK-INBOX-SFV.md).

## 2. „Mein Verein“ als Tagescockpit

Eigene überfällige und heute fällige Aufgaben, offene berechtigte Freigaben
und aktuelle Planungskonflikte in wenigen kompakten Bereichen anzeigen.
Kurze Listen mit „Alle anzeigen“ führen in die vorhandenen Fachseiten.
Zeitraum und Bedeutung der Zahlen müssen erkennbar sein; erledigte oder
archivierte Aufgaben gehören nicht zu „offen“. Bei fehlenden Rechten entfällt
der Bereich. Favoriten, Verlauf und gespeicherte Ansichten bleiben erreichbar.

Abnahme: Zähler und angezeigte Einträge passen zusammen; ein bearbeitetes
Objekt verschwindet aus der offenen Liste. Handyansicht, leere Bereiche und
eingeschränkte Profile funktionieren ohne zusätzliche Einrichtung.

## 3. Aufgabenboard

Drei Spalten: **Offen → In Bearbeitung → Erledigt**. Zuständigkeit und
Fälligkeit bleiben sichtbar. Drag-and-drop ergänzt die vorhandene Liste;
ein gleichwertiger Statuswechsel per Tastatur und auf dem Handy bleibt erhalten.
Archiv und Wiederherstellung bleiben separate vorhandene Aktionen.

Abnahme: Verschieben speichert genau einmal über `WorkTaskService`, aktualisiert
Zähler und bestätigt den neuen Stand. Bei Fehler oder veralteter Revision bleibt
die Karte nicht fälschlich in der Zielspalte; neu laden/erneut prüfen wird angeboten.
Sichtbarkeit, `tasks.complete`, Zuweisungsrechte und Mandantentrennung gelten
unverändert. Drag-and-drop ist nicht die einzige Bedienmöglichkeit.

## 4. Planungsalternativen für Mannschaften und Busse

Aus dem vorhandenen Konflikthinweis direkt passende freie Zeitfenster anbieten.
Bei Bussen können geeignete freie Fahrzeuge hinzukommen; bei Mannschaften
Mannschaft, Zeit und erforderliche Anlagen gemeinsam berücksichtigen.
Zeitraum und Vorschlagsgrenzen sichtbar machen. Wenn nichts passt, zum
richtigen Fachplan verlinken, statt einen leeren Dialog stehen zu lassen.

Abnahme: Vorher/nachher und betroffene Buchung sind vor der Bestätigung klar.
Bei Serien ist Einzeltermin versus Serie ausdrücklich erkennbar. Ein inzwischen
belegter Vorschlag wird unter Sperre erneut geprüft und nicht gespeichert.
Bestehende Freigabestände und verknüpfte Objekte bleiben fachlich konsistent;
keine automatische Umplanung anderer Personen oder geschützter SFV-Termine.

## 5. Benachrichtigungen: Zähler und Bündelung

Ein gut sichtbarer Ungelesen-Zähler führt zur bestehenden Zentrale. Mehrere
Meldungen zum selben zugänglichen Objekt werden verständlich zusammengefasst;
unterschiedliche Aufgaben oder Mandanten nicht miteinander vermischen.
Die neueste Änderung, Anzahl und Zeitpunkt bleiben erkennbar, ältere Details
sind aufklappbar. Der Direktlink öffnet das konkrete Objekt.

Abnahme: Badge, Bündel und Lesestatus verwenden dieselbe berechtigte Sicht.
„Als gelesen“ hat einen klar erkennbaren Umfang und betrifft nur eigene Meldungen.
Bei Rechteentzug verschwindet auch der Zähleranteil. Paginierung bleibt nach der
Bündelung nachvollziehbar; keine pauschale Erweiterung der Ladegrenzen oder
neue externe E-Mail-/Pushzustellung als Nebenwirkung.

## 6. Mobile Spesenerfassung im Backend

Ein Einstieg **„Beleg erfassen“**: Foto aufnehmen oder vorhandene Datei wählen,
Vorschau prüfen, notwendige Angaben ergänzen und als Entwurf speichern.
Der Beleg gehört danach zum richtigen Spesendossier; kein späteres Suchen
nach einer separat hochgeladenen Datei. Kameraablehnung oder nicht unterstützte
Kamera erhält eine normale Dateiauswahl als Alternative.

Abnahme: Vorschau, Dateiprüfung, Uploadfehler und Wiederholung funktionieren
auf kleinem Bildschirm. Doppeltippen erzeugt keinen zweiten Entwurf oder Beleg.
Ein fehlgeschlagener Upload wird nicht als vollständig gespeichert ausgegeben;
ein vorhandener Entwurf bleibt wiederauffindbar. Private Ablage, Scanner,
Datei-/Größenlimits und bestehende Spesenrechte weiterverwenden.
Keine Freigabe oder Zahlung durch diesen Ablauf. Automatische Beleganalyse ist
optional und gehört nur bei bereits freigeschalteter Anbieter-/Kostenkonfiguration
in den Ablauf; die manuelle Erfassung bleibt vollständig bedienbar.

Anschlüsse: geführte Erfassung, `ExpenseService`, `ExpenseDocumentService`
und die bestehenden Belegdienste. Vorhandene App-Verträge nicht duplizieren.
[Spesen](CLUBUNIO-EXPENSES.md), [optionale Analyse](CLUBUNIO-MOBILE-EXPENSE-OCR.md).

## Reihenfolge und Freigabekriterien

1. SFV-Abgleich und sichtbare Kalenderaktualisierung.
2. Tagescockpit und Benachrichtigungszähler/-bündelung.
3. Aufgabenboard.
4. Erweiterte Planungsalternativen.
5. Mobile Belegerfassung.

Jedes Paket erhält gezielte Fachtests, Prüfung von DE/EN und Mandantenrechten,
Chrome-Abnahme auf Desktop und in responsiver Handyansicht sowie dokumentierte
Prüfgrenzen für Kamera und echte Geräte. Rückweg und gegebenenfalls additive
Migrationen vor der DEV-Aktivierung festlegen. Nach Releases Dokumentation und
Migrationsplan nachführen, Commit & Sync. Umsetzung auf DEV am 24.11.2026;
Stage, PROD, Store-Veröffentlichung und Betriebsumstellungen gehören nicht dazu.
