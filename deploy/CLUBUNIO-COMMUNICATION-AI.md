# Kommunikation: KI- und Uebersetzungsbetrieb

Stand02.11.2026. Implementierte Adapter, standardmaessig deaktiviert.
Konfiguration `config/clubunio-mobile.php`; keine Schluessel in der App.

`POST /api/mobile/v1/clubs/{t}/ai`: request_key(UUID), operation
translate/correct/friendly/shorten/check, language(de/en/fr/it/pt-PT/sq/sr/tr),
consent=true und text(max4000) oder message_id. Nachrichten nur uebersetzen;
aktuelle Chatberechtigung, Eintrittsgrenze und Revision vor/nach Aufruf pruefen.
Antwort: text, language, cached. Kein automatisches Senden oder Fachwerkzeug.
Die App zeigt Original und ausdruecklich anzunehmenden/verwerfbaren Vorschlag.

OpenAI Responses: store=false, keine Tools/Dateien/Konversationen, nur ausgewaehlter
Text mit fester Aufgabenanweisung. Azure OpenAI nutzt denselben Textvertrag mit
Deployment unter HTTPS *.openai.azure.com bzw. *.cognitiveservices.azure.com.
Azure Translator nur translate; Schreiboperationen sind gesperrt. Redirects und
automatische HTTP-Wiederholungen ausgeschaltet. Payloads und Schluessel nicht loggen.
Die ausdrueckliche Aktion in der App erfragt Uebermittlung; rechtliche/regionale
und Aufbewahrungspruefung bleibt vor realer Aktivierung erforderlich.

Journal bleibt in ClubUnio: Anbieter, Modell, Operation, Fingerprint/Revision,
reservierte Kosten, Tokenusage und verschluesseltes Ergebnis. Gleiche Request-ID
mit anderem Inhalt409. Unklarer/fehlgeschlagener bezahlter Aufruf bleibt attention,
behielt Kostenreserve und wird unter derselben ID nicht erneut ausgefuehrt.
Keine automatische Wiederaufnahme unbekannter Auftraege. Manuelle neue Aktion
mit neuer ID kann erneut kosten; im Betrieb zuerst Journal pruefen.

Gemeinsamer Cache nur fuer aktuell berechtigte Nachrichtenuebersetzungen,
niemals fuer fremde private Entwuerfe. Providerunabhaengiger Fingerprint erhaelt
passende bestehende Uebersetzungen beim Wechsel. Kein Export von Chatverlaeufen
zu OpenAI/Azure und kein Verlust der eigenen IDs, Stimmen oder Planung.

Monatslimit20 USD je Verein in Micro-USD, vor Provideraufruf unter Vereinssperre
reserviert. OpenAI nur konfiguriertes gpt-5.4-mini zugelassen,25'000 Micro-USD
Reserve pro Anfrage, Usage mit0.75/4.50 Micro-USD pro Ein-/Ausgabetoken bewertet.
Das ist eine lokale Kostenkontrolle, keine Abrechnung/Faktura. Preise vor
Aktivierung anhand https://openai.com/api/pricing/ erneut bestaetigen.
Azure braucht zuerst explizit gepruefte Worst-Case-Reserve
`azure_reservation_microusd`; null blockiert neue Azure-Aufrufe. Diese Reserve
wird mangels einheitlichem Modell-/Translatorpreis voll belastet. Preis-/Modell-
aenderung verlangt angepasste Reserve; kein automatisch garantierter Fremdpreis.

Wechsel: laufende Auftraege abwarten oder attention klaeren, Endpoint/Deployment,
Key/Region und Reserve konfigurieren, Fake-Vertrag und synthetischen kleinen
Realaufruf pruefen, translation_provider/writing_provider aendern und Cache
neu laden. Keine DB-/Chatmigration erforderlich. Rueckweg dieselben Felder auf
openai; bestehende Ergebnisse bleiben. Globaler Modul- oder KI-Schalter kann
die Funktion jederzeit sperren. Providerzugang/Realaufruf nicht durchgefuehrt.

Vier Provider-Unitests verifizieren Requestvertrag, Azure-Ziel/Deployment,
Translator-Grenzen und Ablehnung beliebiger Azure-Origins. Featuretests belegen
Cache nach Anbieterwechsel, Budget und keine blinde Wiederholung. Noch offen:
Sprachqualitaet mit realen Fachbeispielen, Prompt-Angriffsauswertung, parallele
PG-Budgetrennen, Termin-/Optionsuebersetzung und persistente Sprachpraeferenz.
