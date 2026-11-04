# DE/EN: Fachoberflaechen und Sprachvarianten

04.11.2026. Nur ClubUnio; bestehendes PHPMaker DEV/PROD bleibt unveraendert.

## Umfang

- Die historische Liste mit 514 PHP-Beschriftungen ist vollstaendig im gemeinsamen
  DE-/EN-Katalog enthalten. Zusaetzlich Fachdialoge, Fehlertexte, HTML/PDF-Ansichten,
  Portaltexte, Barrierefreiheitslabels und JavaScript-Anzeigen.
- `lang/parts/*.en.json` sind die thematischen Quellkataloge. Zusammenfuehren mit
  `php tools/merge-translations.php`; widerspruechliche Formulierungen stoppen den
  Vorgang. Deutsche Quelltexte bilden den DE-Katalog.
- `php tools/check-translations.php` prueft jetzt auch Blade-Aufrufe und lehnt alle
  fehlenden Schluessel ab. Die historische Bestandsliste ist keine Ausnahme mehr.
- Freitexte, Vereins-/Personennamen, Providerdaten, technische Schluessel, Codes,
  IDs, Rechte und bestehende Buchungen werden nicht pauschal uebersetzt.

## Lookupwerte

`config/clubunio-lookups.php` begrenzt die uebersetzbaren Wertetabellen explizit.
Ausgabe und Auswahllisten nutzen `LookupTranslations`, nicht einen veraenderten
Model-Accessor. Statusvergleiche und Farben behalten dadurch ihre Originalwerte.
Teams, Saisons, Familien, Banken und Benutzertitel sind keine Uebersetzungsquelle.

Neue Tabelle `clubora.lookup_translations`: mandantenbezogene Sprachvarianten mit
Quelltabellenkennung, Quell-ID, Quelltext, Sprache, Anzeigetext und Revision.
Unter Einstellungen / Zugaenge & System gibt es den Admin-Editor
Lookup-Uebersetzungen. Aenderungen brauchen eine Begruendung, werden protokolliert
und gegen veraltete Revisionen und fremde Mandanten abgesichert. Keine neuen
Rechte fuer bestehende Benutzer; Nur-Lesen-Simulation darf nicht schreiben.

Fallback: passende vereinseigene Variante, ISO-Laendername, englisches
Standardwoerterbuch, unveraenderter Quellwert. Nach Aenderung des Quelltexts wird
eine dazu nicht mehr passende Variante nicht automatisch weiterverwendet.
Neue frei erfundene vereinseigene Werte muessen bewusst uebersetzt werden;
unbekannte Werte bleiben sichtbar statt geraten oder automatisch ersetzt zu werden.

## Nachrichten

Standardvorlagen erhalten DE-/EN-Varianten. Migration 001400 ergaenzt ausschliesslich
fehlende Varianten und deren erste Revision; vorhandene individuelle Texte und
Historien bleiben erhalten. Eine bestehende Deaktivierung wird uebernommen.

Die Empfaengersprache folgt dem persoenlichen Konto innerhalb des Vereins,
danach der Vereinssprache und Deutsch. Sie wird nie von der gerade ausgewaehlten
Admin-Oberflaechensprache abgeleitet. Fehlende Varianten fallen auf Deutsch
zurueck; ausdruecklich deaktivierte Varianten bleiben deaktiviert. Nur aktivierte
Sprachen koennen neu angelegt werden; vorhandene andere Varianten bleiben erhalten.
Ereignisse, Empfaengerauswahl, Idempotenz und Testversand-Schutz bleiben bestehen.
Individuelle Nachrichtentexte und Nutzereingaben werden nicht maschinell ersetzt.

## Migration und Deployment

1. Nur den geprueften eigenen Git-Delta in einen neuen ClubUnio-Release uebernehmen.
   Env, Composer-Abhaengigkeiten und Shared-Storage erhalten.
2. `deploy/prepare-language-completion.php --export` erzeugt eine verschluesselte
   Sicherung der Nachrichtenvorlagen und Revisionen ausserhalb des Webroots.
3. `--apply <Sicherung>` prueft Sicherung, Quellhashes und Ausgangsbestand und fuehrt
   gezielt 001300 (Lookupvarianten) und 001400 (fehlende Nachrichtensprachen) aus.
   Beide Schritte samt Migrationsjournal laufen transaktional. Bestehende
   Vorlagen/Revisionen muessen danach unveraendert sein, sonst Rollback.
4. Uebersetzungen, Rollen-/Mandantentests, Views und Servergates pruefen, Caches
   erstellen, Release atomar aktivieren und HTTP-Abnahme durchfuehren.

Lokale Instanz und Pi teilen dieselbe ClubUnio-Datenbank. Migrationen nur einmal
ausfuehren, niemals Daten neu importieren oder DEV/PROD auffrischen. Beim Code-
Rueckweg additive Tabellen und Vorlagen belassen; keine Sprachdaten hart loeschen.

## Abnahme

Lokale Abnahme vom 04.11.2026:

- 2487 DE-/EN-Schluessel; keine Ausnahme fuer die514 historischen Labels.
- 79 Unit-Tests /52648 Assertions erfolgreich; PHP-Syntax aller App-Quellen,
  Katalog-/Platzhalterparitaet und Sprachvarianten eingeschlossen.
- 8 JavaScript-Tests erfolgreich, Blade-Cache erstellt, anonymer HTTP-Wechsel
  EN/DE auf localhost erfolgreich.
- Rollen-/UI-Regressionslauf19 Tests /463 Assertions erfolgreich.
- Lookup-Rechte separat mit synthetischen PostgreSQL-Daten geprueft:
  Mandantentrennung, Revisionen, API-only und Nur-Lesen-Simulation; Rollback.
- Breiter PostgreSQL-Regressionslauf:81 Tests vor dem ersten veralteten
  MyTeams-Test erfolgreich (davon1 umgebungsabhaengig uebersprungen).
  Dessen Erwartung stammte vor dem Zugriffspaket056fc93: Mannschaftslisten sind
  kein persoenliches Kinderdossier, Trainerzugriff benoetigt eine Teamfunktion.
  Nur Test korrigiert, keine Rechte/Produktionslogik geaendert. Nachlauf:
  korrigierter Test44 Assertions, letzte3 Tests73 Assertions sowie4 separate
  Mannschaftszugriffstests106 Assertions erfolgreich. Testdaten zurueckgerollt.

Automatische Katalogabdeckung beweist nicht, dass beliebige kundeneigene Texte
uebersetzt wurden. Fachliche Daten und bestehende Dokumentinhalte bleiben original.

## Release und Nachkontrolle

Aktiv auf `https://dev.clubunio.ch`: `20261104-clubunio-languages-complete`,
gezieltes Delta `ae7feb3` auf `20261104-clubunio-languages`. Alle370 ausgelieferten
Dateien per Git-Objekthash mit dem Commit verglichen, ohne Unterschiede. Kein
Env-/Composer-/Storage-Austausch, keine Aenderung an PHPMaker DEV/PROD.

Migrationen001300/001400 nach verschluesseltem Export gemeinsam transaktional
ausgefuehrt. Vorhandene Vorlagen und Revisionen anschliessend bytegleich im
JSON-Snapshot,368 Vorlagen erfolgreich gerendert. Sicherung0600 im privaten
Serverordner `shared/language-backups`, nicht im Git oder Webroot.
Config-/Route-/View-Cache erstellt, PHP-FPM neu geladen. Grundpruefung,
Rollenpruefung, Mobile-Saisonfilter und Simulationsschutz erfolgreich.

Anonymer HTTPS-Sprachwechsel EN/DE erfolgreich. Englische Loginseite visuell auf
Desktop1440x1000 und Mobil390x844 geprueft, keine horizontale Ueberbreite.
Sprachauswahl und Viewport danach zurueckgesetzt. Lokale Nachweise ausserhalb Git:
`storage/app/language-completion-login-en-desktop.png` und
`storage/app/language-completion-login-en-mobile.png`.

Angemeldete manuelle Sichtpruefung der Fachmasken noch offen: vorhandene
Browsersitzung war abgelaufen, erneute Anmeldung angefragt. Die synthetischen
Dialog-/Rollen-/Lookup-/Nachrichtentests sind davon unabhaengig erfolgreich.
Keine echten Nachrichten, Zahlungen oder Datenauffrischung ausgelöst.
