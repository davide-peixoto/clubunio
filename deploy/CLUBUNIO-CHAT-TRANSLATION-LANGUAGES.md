# Getrennte Sprachen fuer Chatuebersetzung und App-Oberflaeche

Stand: 23.11.2026.

## Freigaben

Die bisherige DE/EN-Grenze entstand durch die gemeinsam verwendete
MobileLanguages-Policy. Sie war keine OpenAI-Textuebersetzungsgrenze. OpenAI
beschreibt seine Textmodelle als mehrsprachig, veroeffentlicht damit aber keine
fuer alle Modelle gleichwertig garantierte vollstaendige Sprachmatrix.
Quelle: https://help.openai.com/en/articles/6742369-how-can-i-use-the-openai-api-with-text-in-different-languages
Die Uebersetzungsqualitaet kann je Sprache und Text variieren.

App-/Web-Oberflaeche: unveraendert config/clubunio-languages.php, derzeit DE/EN.
MobileLanguagePolicy intersectiert weiter nur echte lokale UI-Kataloge und diese
zentrale Freigabe. Keine Oberflaechenuebersetzung ueber eine KI-API.

Chatuebersetzung: eigener zentraler Freigabekatalog in
config/clubunio-chat-translation.php und ChatTranslationLanguages. Freigegeben
fuer OpenAI/AzureOpenAI sind zunaechst24Ziele: Deutsch, Englisch, Franzoesisch,
Italienisch, Portugiesisch (Portugal), Spanisch, Albanisch, Bosnisch, Kroatisch,
Serbisch, Tuerkisch, Arabisch, Ukrainisch, Russisch, Polnisch, Rumaenisch,
Niederlaendisch, Griechisch, Ungarisch, Bulgarisch, Tschechisch, Slowakisch,
Tamil und Persisch. Dies ist ein bewusst gewaehlter Produktkatalog, keine
Behauptung ueber alle moeglichen OpenAI-Sprachen oder garantierte Qualitaet.

Der gesonderte Azure-Translator-Adapter bleibt bei seinen bisherigen fuenf
geprueften Zielen; dessen API-Codes und Faehigkeiten muessen vor einer Erweiterung
separat geprueft werden. Unbekannte Provider geben keine Ziele frei.

## API und Zustimmung

chat-preferences verwendet weiter language_policy.enabled/default und liefert
zusaetzlich names: lokalisierte Anzeigenamen in DE/EN. Sprachcodes bleiben
sprachneutral; pt wird wie bisher zu pt-PT normalisiert. Der Client verwendet
fuer Chatziele einen getrennten Policytyp ohne UI-Katalogschnittmenge und
uebernimmt nur die serverseitig freigegebenen, formal gueltigen Codes.
Alte Antworten ohne names bleiben mit den vorhandenen lokalen Namen lesbar;
fuer neue unbekannte Namen dient der Code als dokumentierter Fallback.

Es gibt keine automatische Aktivierung. Die Chat-Einstellung, ausdrueckliche
Bestaetigung fuer jede Nachricht, OpenAI-/Datenschutzhinweis, Kostenbudget und
Cache bleiben erhalten. Auswaehlen/Speichern einer Zielsprache erzeugt keinen
kostenpflichtigen Providerauftrag. App-Sprache und Schreibassistenz verwenden
weiter ihre eigene UI-Sprachpolicy. Keine bestehende Benutzereinstellung wurde
im Rahmen des Releases pauschal geaendert.

Gespeicherte gesperrte Ziele werden als fallback markiert und aktivieren keine
Uebersetzung in der Ersatzsprache. Vor Cachezugriff, innerhalb des Tenantlocks
und nach dem Provideraufruf prueft MobileAiService die aktuelle Zielsprachfreigabe
und die persoenliche Zustimmung erneut. Nicht freigegebene Codes werden vor
Provider-/Budgetarbeit abgewiesen. Die App zeigt serverseitige Sprachnamen auch
in der Bestaetigung und richtet arabische/persische Uebersetzungen rechts nach
links aus, ohne die App-Oberflaeche zu spiegeln.

## Datenbank und Deployment

Die bisherige CHECK-Constraint erlaubte nur de/en/fr/it/pt-PT. Additive Migration
2026_11_23_000170_chat_translation_languages ersetzt diese Enumkopplung durch
eine begrenzte Sprachcode-Formatpruefung und erweitert varchar10 auf20. Die
konkrete Freigabeliste bleibt ausschliesslich im Backendkatalog. Migration wurde
vor den neuen Servicedateien auf DEV/Stage/PROD ausgefuehrt. Private gezielte
Sicherungen der Preferences-/Migrationstabellen sowie Quelldateien liegen auf
den Servern; keine Umgebungskonfiguration oder Secrets wurden eingecheckt.

Alte Clients begrenzen ihre Darstellung weiterhin auf die ihnen bekannten
Katalogsprachen. Fuer die vollstaendige24erAuswahl ist der aktuelle App-Neubau
noetig; Custom Builds und Core verwenden denselben zentralen Vertrag.

Rueckweg: Chatkatalog gezielt verkleinern und Konfigurationscache erneuern.
Die erweiterte Formatconstraint und neue gespeicherte Codes erhalten; kein
pauschaler Datenbankrestore oder automatischer Reset persoenlicher Zustimmung.
Ein Quellrollback braucht eine gepruefte Behandlung inzwischen gespeicherter
neuer Zielcodes. Kein Schema-Downgrade mit stiller Datenverwerfung.

## Nachweis

10 gezielte Backendtests/132Assertions, einschliesslich Sprachtrennung,
Providerintersektion, ungueltigen Codes und Entzug vor Cache/waehrend des Locks/
nach einer simulierten Providerantwort. DE/EN-Kataloge:3075Schluessel konsistent.
Mobile:41fokussierte Tests inkl Chatfunktionen, bestehender Sprachpolicy und
schmaler Dropdowns; Analyse von5Dateien und2172Texte/fuenfKataloge inklAST sauber.

deploy/check-chat-translation-languages.php --synthetic-rollback
clubunio_dev|clubunio_stage|clubunio_prod verwendet die echte PostgreSQL-Struktur,
speichert alle24Ziele mit ausgeschalteter Zustimmung und prueft die aktuellen
Serviceregeln. Alle Daten werden zurueckgerollt, Providerantworten simuliert.
Es werden keine privaten Vereinsnachrichten an OpenAI uebertragen und keine
kostenpflichtigen Testanfragen ausgeloest. Sprachqualitaetsabnahme durch Sprecher
der jeweiligen Sprache und der neue native iPhone-Build bleiben eigenstaendige
Pruefschritte.

Release abgeschlossen: DEV, Stage und PROD bestanden jeweils47synthetische
PG-Pruefungen mitRollback. App-Commit c7f9eb6; vollstaendigeAuswahl nachNeubau.
