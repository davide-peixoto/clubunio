# Mobile Spesen: optionale Beleganalyse

Stand: 03.11.2026. Separater Anschluss an die persoenliche Spesenmigration88.
Keine weitere Migration: Auftrag, Kostenreservierung und verschluesselte Ergebnisse
verwenden `clubora.mobile_ai_work` aus Migration82. Migration88 muss fuer die
persoenlichen, sauberen Belegentwuerfe vorhanden sein.

## Vertrag und Sicherheit

`POST /api/mobile/v1/clubs/{club}/expenses/receipts/{receipt}/suggest` mit
`request_key` (UUID), `draft_revision` und `consent: true`. Der bereits vorhandene
Pfad muss auf `MobileExpenseOcrController::suggest` zeigen. Gemeinsame persoenliche
Session-/Mandantenmiddleware und `MobileExpenseReady` bleiben erforderlich.

200 liefert `state: succeeded`, `cached`, `suggestion`, `confidence`, `warnings`
und `meta`. 202 liefert `state: pending` und `retry_after`; eine spaetere explizite
Aktion mit derselben UUID fragt denselben Providerauftrag ab. Es gibt keine
automatische Wiederholung und keinen zweiten Analyse-POST bei ungewissem Ergebnis.
409 sperrt fremde/veraltete/geaenderte Belege und nicht passende Wiederholungen;
429 begrenzt Frequenz, Tageskontingent und Mandantenbudget; 503 bedeutet bewusst
nicht freigeschaltet. Providerfehler enthalten keine Providerantworten/Schluessel.

Vor und nach Providerzugriff: frische persoenliche Session, Mandantenzugang,
Spesenberechtigung, eigener ungebundener sauberer Beleg, Revision, Ablauf,
Scannerstand, SHA256 und begrenzte verschluesselte Bytes. Maximal4MiB werden an
den Anbieter geschickt. PDFs/JPEG/PNG werden ausschliesslich als `base64Source`
uebergeben, nie als vom Nutzer vorgegebene URL. Die feste Azure-Ressourcenadresse
und validierte Auftrags-UUID verhindern beliebige Rueckrufadressen/Weiterleitungen.
Ergebnisstream maximal2MiB; Timeout und Lease begrenzen parallele Abfragen.

Kostenreservierung erfolgt vor dem ersten POST unter Mandantensperre. Standard:
20 Anfragen/Minute, 10 kostenpflichtige Analysen/Account/Tag; Monatsbudget ist
das kleinere aus OCR-Limit und gemeinsamem Mobile-KI-Limit. Alle KI-Auftraege
des Mandanten zaehlen mit. Ohne echte Kostenabrechnung bleibt die konfigurierte
Worst-Case-Reservierung stehen. Ein bereits erfolgreich analysierter identischer
eigener Belegstand kann ohne weitere Providerkosten wiedergegeben werden.
Die Auftragstabelle enthaelt keine Belegbytes oder unverschluesselten OCR-Texte.

## Anbieter und Konfiguration

Azure AI Document Intelligence `prebuilt-receipt`, API `2024-11-30`, fest
`pages=1`, ohne Zusatzfunktionen. Nur zuverlaessige CHF-Gesamtbetraege sowie
Haendler/Datum werden vorgeschlagen. Spesentyp, Spieltyp und Gegner werden nicht
geraten. Fremdwaehrung, unsichere Felder und mehrdeutige Ergebnisse werden nicht
als Finanzwerte uebernommen. Die erste Seite und die manuelle Pruefung werden
vor Aufruf und im Ergebnis erklaert.

`config/clubunio-expense-ocr.php` ist standardmaessig geschlossen. Erst nach
gepruefter Azure-Ressource, Datenschutz-/Kostenkonfiguration und ausdruecklicher
Freischaltung setzen:

- `CLUBUNIO_MOBILE_EXPENSE_OCR_ENABLED=true`
- `CLUBUNIO_MOBILE_EXPENSE_OCR_TENANTS`: explizite Mandantenslugs
- `AZURE_DOCUMENT_INTELLIGENCE_ENDPOINT`: HTTPS-Ressource unter cognitiveservices.azure.com
- `AZURE_DOCUMENT_INTELLIGENCE_KEY`: nur Serverkonfiguration, nie App/Repository
- `CLUBUNIO_EXPENSE_OCR_COST_REVIEWED=true`
- `CLUBUNIO_EXPENSE_OCR_RESERVATION_MICROUSD`: gepruefter Worst-Case-Preis einer Seite
- `CLUBUNIO_EXPENSE_OCR_MONTHLY_LIMIT_MICROUSD`: OCR-Mandantenlimit

Es wird kein Preis erfunden. Fehlende DEV-Konfiguration bleibt geschlossen.
`clubunio:expire-mobile-expense-ocr` loescht verschluesselte OCR-Ergebnisse und
Providerzeiger nach24Stunden, behaelt aber die Kostenreservierung. Zusammen mit
Belegbereinigung stuendlich mit `withoutOverlapping` einplanen. Belegablauf oder
Loeschung sperren den persoenlichen Ergebnisabruf bereits sofort.

Primaerquellen:
[Receipt-Modell](https://learn.microsoft.com/azure/ai-services/document-intelligence/prebuilt/receipt?preserve-view=true&view=doc-intel-4.0.0),
[Analyze](https://learn.microsoft.com/en-us/rest/api/aiservices/document-models/analyze-document?view=rest-aiservices-v4.0+(2024-11-30)),
[Get Analyze Result](https://learn.microsoft.com/en-us/rest/api/aiservices/document-models/get-analyze-result?view=rest-aiservices-v4.0+(2024-11-30)).

## Integration und Nachweis

Backend: Spesenroute zeigt auf den neuen Controller; `capabilities.ai` verlangt
den Clientmarker `expense-ocr-v1` und `MobileExpenseOcr::available($tenant)`.
Die persoenliche Shell injiziert den OCR-Adapter; der bestehende Editor startet
nach Upload keine Analyse, sondern bietet bei Serverfreigabe einen Analysebutton.

Flutter: `PersonalExpenseOcrRepository` statt reinem `PersonalExpenseRepository`
injizieren. Fuer `ExplicitExpenseOcr` nach Upload keine automatische Analyse
starten. Stattdessen einen Button bei vorhandenem Belegentwurf anbieten und
`requestExpenseOcrSuggestion` aufrufen. Erst dessen nicht-null Rueckgabe in die
bestehenden Formularfelder uebernehmen. Der Helper zeigt getrennt Einwilligung
und Ergebnispruefung; Speichern bleibt eine weitere Aktion. Derselbe Adapter
behaelt pro Beleg dieselbe Request-UUID bei ungewissem Ergebnis.

Geprueft:8 echte PostgreSQL-Rollbacktests /90 Assertions mit `Http::fake`, darunter
Mandanten-/Actor-/Revisionsgrenzen, ungewisser POST, paralleler Auftrag, Budget,
Tageslimit, Providerantwortgroesse und Rechteentzug waehrend der Abfrage.
5 Fluttertests pruefen Einwilligung, unveraenderte UUID, Fehlerdaten und optionale
Ergebnisuebernahme einschliesslich echtem Originaleditor mit Dateiauswahl; zusammen
mit7Spesentests12Tests gruen. Zusatztest5Assertions prueft Clientmarker und
konfigurierte Freigabe. Gezielte Dart-Analyse ohne Befund. Keine bezahlten Aufrufe,
keine neuen Schemaaenderungen und keine echte Azure-Analyse ausgefuehrt.
