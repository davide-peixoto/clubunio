# Persoenlicher Trainingsplaner (03.11.2026)

Lokale native Migration der bestehenden SVS-Trainingsplaner-Masken, kein
Ersatzscreen. `MobileTrainingRoutes` wird ausschliesslich unter der bestehenden
persoenlichen `clubs/{tenant}`-Gruppe registriert. Kein v1-Integrationstoken,
kein PHPMaker-Aufruf und kein automatischer Altbestandsimport.

## Fachvertrag

Elf Routen unter `training-plans`: Liste, options, share-teams, Detail,
PATCH, share, PDF, generate, review, review/apply und GET work/{uuid}.
`MobileTrainingPlanService` ergaenzt den vorhandenen Trainingsdienst nur fuer
persoenliche Zugriffe: konfigurierte general/SaisonID, aktive aktuelle
Mannschaften, vorhandene Trainingsfunktionsrechte und echte Trainerzuordnung.
Eltern-/Mitgliedsrollen gewinnen dadurch keine Trainingsrechte. Die bestehende
persoenliche Loginpolicy laesst reine Backoffice-Profile weiterhin geschlossen;
der native Caphelper wahrt auch dort getrennte Funktionen. Web/v1 verwenden weiterhin ihre bestehende
Policy; insbesondere bleibt deren bisherige Teamfreigabe nur lesend.

Native Freigabe ist eine ausdrueckliche Entscheidung des Planeigentuemers:
ausgewaehltes berechtigtes aktuelles Trainerteam darf gemeinsam bearbeiten und
bei gemeinsam vorhandener approve-/update-Berechtigung pruefen und uebernehmen.
Es wird kein kostenpflichtiger Vorschlag fuer einen Nutzer reserviert, der
ihn aufgrund fehlenden update-Rechts nicht uebernehmen koennte. Mitbearbeiter duerfen nicht
weiterfreigeben. Migration **100** speichert genau ein Ziel pro Plan mit
mandantenbezogenen Fremdschluesseln. Aeltere shared-Flags behalten ihre
urspruengliche Mannschaft als Ziel; explizite native Ziele ersetzen dies.
Widerruf, Zielwechsel und Inhaltsaenderung erhoehen die Planrevision. Jeder
Write nutzt die angezeigte Revision, Tenant-Sperre, Session-Sperre und frische
persoenliche Rechte. Parallel geaenderte/revozierte Entwuerfe werden abgewiesen.

Manuelle Aenderungen erhalten Coaching/Varianten und kanonische Daten.
Spielerzahl wird im gespeicherten Input angepasst. Geaenderter Aufbau/Ablauf
oder Spielerzahl markiert vorhandene Skizzen als veraltet; App und PDF blenden
diese bis zur explizit uebernommenen neuen Qualitaetspruefung aus. SVGs kommen
aus dem vorhandenen lokalen Marker-Renderer, niemals aus Provider-HTML/URLs.

Private PDF-Ausgabe nutzt den bestehenden Dompdf-Dienst mit persoenlicher
Policy, angezeigter Revision, 20MiB-Grenze, Content-Length, X-Content-SHA256,
X-Training-Revision, application/pdf und private/no-store. Keine oeffentliche
Datei-/Provider-URL. Die App legt zufaellige private Cacheverzeichnisse an und
raeumt sie beim Schliessen der Vorschau auf. Extern exportierte Kopien liegen
anschliessend im Verantwortungsbereich der gewaehlten Zielanwendung.

## KI und Wiederaufnahme

Bestehende TrainingPlanService-/TrainingPlanAi-/Content-Pruefungen bleiben
gemeinsam; der neue reviewDraft-Einstieg erlaubt die Pruefung ungespeicherter
Entwuerfe und Spielerzahl ohne Aenderung des Originals. Erst review/apply
speichert nach ausdruecklicher Bestaetigung, frischer Revision und Rechten.
Vorschlag 30Minuten gueltig, persoenlich gebunden, einmal uebernehmbar.

Jobs verwenden die vorhandene **mobile_ai_work82**, keine neue Jobtabelle.
Anfrage/Entwurf/Sessionreferenz/Ergebnis sind verschluesselt; ein UUID und
Fingerprint binden Benutzer, Mandant, Operation, Saison, Revision und
Providerkonfiguration. Gleiche Entwuerfe verwenden auch nach Verlust des
lokalen UUID denselben vorhandenen Auftrag. Kein erneuter bezahlter Aufruf
durch Polling, Timeout, Prozessneustart oder einen unklaren Fehler.
Nach einem vollstaendig erhaltenen Generate-Ergebnis kann ein weiterer
bewusster Erstellen-Knopfdruck mit previous_work=zuletzt_erhaltene_UUID einen
neuen Plan mit gleichen Parametern anfordern. Der Server verlangt den letzten
eigenen abgeschlossenen Generate-Auftrag; laufende/unklare Arbeit wird dadurch
nicht umgangen. So bleibt die urspruengliche Neuerstellen-Funktion erhalten.

Reservierung erfolgt atomar unter derselben Tenant-Sperre wie Chat-/OCR-Kosten
und summiert die gesamten Monatskosten aus mobile_ai_work. Die gepruefte
Reservierung bleibt konservativ nach begonnenem Anbieteraufruf bestehen; keine
geratenen Tokenpreise/automatische Rueckerstattung unklarer Aufrufe. Nur eine
nachweislich vor Aufruf der Anbietermethode abgewiesene Arbeit erhaelt
state=rejected und actual_microusd=0. Die App zeigt den Fehler; ein neuer
bewusster Knopfdruck kann eine neue Anfrage reservieren. Kein automatischer
Neuversuch. Vorhandene Trainingsquoten gelten
zusaetzlich. Fehlende Konfiguration oder nicht explizit gepruefte Limits
schliessen die KI mit503. Mindestens Modell-Allowlist, Mandanten-Allowlist,
Reservierungsbetrag, Monatslimit, maximale Eingabebytes und Ausgabetokens
muessen freigegeben sein. Keine kostenpflichtige Abnahme durch Tests.

Nach Commit wird genau ein Terminierungscallback registriert; er beansprucht
queued->running atomar vor dem Anbieteraufruf. Ausgeloggte/deaktivierte
Benutzer, widerrufene Sessions, Saison-/Rechte-/Konfigurationswechsel werden
vor und nach dem Aufruf geprueft.
Der unmittelbar vor Versand gepruefte Schluessel-/Modell-/Vector-Snapshot
wird direkt an den bestehenden Anbieterclient gegeben; dieser liest fuer
native Arbeit kein zweites abweichendes Modell zwischen Kostenpruefung und
HTTP-Aufruf ein. Geheimnisse werden weder im Auftrag noch in Logs abgelegt.
Ein abgebrochener running-Auftrag bleibt
unklar/attention und wird nie automatisch neu gestartet. GET startet keine
Arbeit. Ein nach Prozessabbruch noch queued gebliebener Auftrag kann durch
einen berechtigten Betreiber gezielt ueber MobileTrainingWork::execute(uuid)
fortgesetzt werden; running/attention werden dort nicht erneut ausgefuehrt.
Nach zehn Minuten zeigt Polling offene Arbeit als attention. Keine pauschale
Wiederaufnahme ueber das ganze Ledger. Abgelaufene unveraenderte Review-
Entwuerfe bleiben geschlossen; fuer eine neue Pruefung muss der Entwurf
bewusst geaendert oder der Fall betrieblich beurteilt werden.

Native Responses-Anfragen verwenden store=false, max_tool_calls=1 und den
expliziten max_output_tokens-Wert. Letzterer umfasst auch Reasoningtokens;
er ist kein alleiniger Preisdeckel, daher ist die gepruefte Reservierung
einschliesslich Retrieval notwendig. Primaerdokumentation:
[Responses Create](https://developers.openai.com/api/reference/cli/resources/responses/methods/create),
[Tokenzaehlung](https://developers.openai.com/api/docs/guides/token-counting).

## Freigabe und Nachweise

`native_training_enabled=false` sowie `training_ai.enabled=false` und
cost_reviewed=false bleiben Standard. Migration100 ist nur lokal vorhanden,
nicht dauerhaft angewendet. Ausschliesslich dieser exakt benannte Pfad darf
nach frischem Ledgerabgleich/Backup gezielt angewendet werden; fremde90/91
bleiben unberuehrt. Kein gesamtes Migrationsverzeichnis ausfuehren.
Root koordiniert Commit/Sync und DEV-Release. `check-mobile-training.php
--foundation` prueft elf geschuetzte Routen, deaktivierte Flags und vorhandene
Grundtabellen; `--training-enabled` verlangt die gezielt angewendete100.
Beide pruefen ohne Daten-/Anbieteraenderung; echte KI-Kostenfreigabe separat.

`MobileTrainingTest` verwendet synthetische Rollbackfixtures, die DDL100 nur
auf TEMP-Tabellen mit TEMP-FK-Eltern und einen privaten Grammaradapter.
Keine shared-DDL-/FK-Sperren gegen produktive Eltern. application_name ist
clubunio-mobile-training-test. Eigene PG-Suiten nacheinander ausfuehren.
Http::fake und Mail::fake verhindern echte Provider-/Zustellaktionen.
Nachweis lokal: **5 PG-Tests/110 Assertions bestanden**, einschliesslich
erfolgreicher privater Neuerstellung, gemeinsamer OCR-/Trainingskosten und
Widerruf waehrend des simulierten Anbieteraufrufs. **7 neue Fluttertests
bestanden**, gezielte Dartanalyse ohne Befund, PHP-Syntax und Foundationgate
bestanden. Die bestehenden acht Collaboration-/Farbtests bestehen ebenfalls;
Root hat die veraltete Drei-Farben-Erwartung auf die vier tatsaechlichen
Statusfarben mit Kontrastpruefung korrigiert. Auch der getrennte
approve-/update-Caphelper und unveraendert geschlossene persoenliche
Backoffice-Profilanmeldung sind im finalen nativen Lauf geprueft.
Weitere Nachweise: TrainingPlanTest17/152 und LegacyTrainingMigrationTest3/26
bestanden. SfvTrainingApiActionsTest27/327 hatte genau eine fehlgeschlagene
Signaturassertion, alle Fachpfade bestanden. review behaelt jetzt wieder
exakt seinen urspruenglichen Vertrag mit authorization als letztem nullable
Closure-Parameter; reviewDraft delegiert auf dieselbe private Umsetzung.
PHP-Syntax und Reflection pruefen dies lokal erfolgreich. **Training bleibt
bis zum gezielten Contract-/native5-Nachlauf offen**. PG-Fenster wird mit
Root/Spesen/Directory seriell koordiniert, keine parallele eigene PG-Suite.
Die Fluttertests erhalten Originalbearbeitung/-Vorschau und pruefen konkrete
Mannschaften, angezeigte Revisionen, gleiche Anfrageschluessel und Fehler bei
unvollstaendigen Daten.

Offen: permanente100/DEV-Integration, echte bewusst freigegebene Anbieter-
und Kostenabnahme, native Geraete-/Druck-/externe PDF-Abnahme. Bestehende
LegacyTrainingPlanSource/-Importer-Freigaben werden nicht automatisiert.
