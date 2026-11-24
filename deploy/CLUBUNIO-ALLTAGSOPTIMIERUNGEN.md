# ClubUnio: übersichtliche Alltagsabläufe

Stand: 24.11.2026. Vom Benutzer in den nächsten Arbeitsumfang aufgenommen.
Status: auf DEV umgesetzt; Browser-Dateiupload und echte Kamera noch offen.
Release, Fachverhalten und Abnahme: [Alltagsabläufe v2](CLUBUNIO-DAILY-WORKFLOWS.md).
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

## 1. SFV: automatische Übernahme und Verschiebungen

Benutzerpräzisierung vom 24.11.2026: SFV-Wettkämpfe automatisch übernehmen,
SFV-Verschiebungen ebenfalls automatisch nachführen. Garderoben dürfen dabei
nicht verloren gehen. Auf DEV mit `20261124-clubunio-daily-v2` umgesetzt.

- Die Hauptaktion „SFV abgleichen“ ruft ab und übernimmt eindeutig zugeordnete
  zukünftige Heimspiele in Wettkämpfe/Plantafel und Mannschaftskalender.
  Auswärtsspiele erhalten Mannschaftstermine ohne lokale Platzreservierung.
- Datum, Uhrzeit und zugeordnetes Spielfeld sind vom SFV maßgebend. Die bisherige
  Einzelprüfung einer Verschiebung entfällt im automatischen Backend-Abgleich.
  Auch lokale Überlappungen verhindern die Nachführung nicht; sie bleiben als
  Konflikt auf der Plantafel erkennbar. Andere Buchungen werden nicht umgeplant.
- Bestehendes Spiel aktualisieren, niemals für eine Verschiebung löschen und
  neu anlegen: Wettkampf-ID, Heim-/Gastgarderoben, Garderobenseiten, Bemerkungen
  und lokale Sichtbarkeit bleiben erhalten. Dauer und Frist des verknüpften
  Teamtermins verschieben sich gemeinsam; Teilnahme und Beschreibung bleiben.
- Wiederholung erzeugt keine Dubletten. Wettkampf und Teamtermin werden pro
  Spiel atomar gespeichert. Ein Fehler rollt beide Änderungen zurück.
- Unklare Mannschaft-/Saison-/Platzzuordnung, fehlende Daten/Rechte, Dubletten,
  archivierte Verknüpfungen und Absagen bleiben nachvollziehbare Prüffälle.
- Gespeicherte Sync-Aufträge funktionieren ohne Browser-Sitzung. Bestehende API-
  Berechtigungsgrenzen und historische Social-Ergebnisabrufe bleiben erhalten;
  kein neuer periodischer Abruf wird eingerichtet.
- Geöffnete Kalender erhalten den vorhandenen Aktualisierungshinweis ohne
  Überschreiben laufender Eingaben. Ergebnis nennt neu, aktualisiert, unverändert
  und Prüfung nötig getrennt vom Quellabrufzähler.

Abnahme: 42 lokale Tests / 561 Assertions und 17 native DEV-Prüfungen bestanden.
Beide Garderoben, Seiten, Notizen, Identität und Wiederholung sowie Rollback bei
Kalenderfehler ausdrücklich geprüft. Details: [Releaseprotokoll](CLUBUNIO-DAILY-WORKFLOWS.md).

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
