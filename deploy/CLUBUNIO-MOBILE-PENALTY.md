# Persoenliche Original-Vereinschallenge (04.11.2026)

Aktueller DEV-Nachweis04.11.2026: Identity-/Push-/Challenge-/Keybox-Unterbau ist
auf DEV,400/500/600/700 atomar nach gepruefter verschluesselter Sicherung
angewendet.14 leere neue Tabellen/30 FKs, Elternzeilen unveraendert; nur
zusaetzliche Session-Unique wie geplant. Runtime-/Mail-/Zustellungs-/PINflags
bleiben geschlossen; externe Konfiguration/Abnahme und weitere Integration
bleiben offen. Details: PI.md und CLUBUNIO-MOBILE-IDENTITY-PUSH-SCHEMA.md.
Fruehere Vorbereitungsangaben unten sind historisch, keine aktuelle Aussage
ueber den inzwischen bestaetigten Schema-/DEV-Stand.


Audit G1 betrifft einen echten fehlenden Originalablauf. Der bestehende
PenaltyGameScreen, sein Spiel/Pausieren/Fortsetzen/Ueben, Schuetzen-/Torhueter-
Ranglisten und expliziter Adminreset werden jetzt persoenlich angebunden.
Neue600 ist nur Quellstand, keine permanente Anwendung/DEV-Freigabe.

## Echte Scope- und Betriebsgrenzen

MobilePenaltyAccess verlangt persoenlichen aktuellen Mandantenzutritt,
`native_penalty_enabled` (CLUBUNIO_MOBILE_NATIVE_PENALTY_ENABLED, defaultfalse)
und eine echte aktive Penalty-Option in tenantgebundenem mobile_module_options.
Ohne explizite Option keine Teilnahme, keine automatische Mandantenaktivierung.
MobileModuleSettings kennt den neuen Schluessel, mobile Profilfilter steuern die
Menuesicht. Der Menueclient benoetigt zusaetzlich penalty-v1; Marker verleihen
keine Rechte. Reset ausschliesslich durch echte Rollen admin/office.

Die bisherigen versteckten Originaleinstiege (Mitglieder-Langdruck und lange
Ladeanzeigen) verwenden eine injizierte PersonalPenaltyScope. Jede Oeffnung
laedt echte Optionen frisch. Ohne persoenlichen Scope und ohne Legacyprovider
schliesst der Launcher klar, ohne ProviderNotFound. Root bindet den Scope und
den direkten typisierten Push-/Menueaufruf in der Shell ein. Die isCurrent-
Funktion muss die tatsaechliche eingefrorene Account-/Mandantengeneration
pruefen, nicht nur eine Widget-Mounted-Eigenschaft.

## Vertrag und Originalregeln

Unter clubs/{tenant}/penalty: GET options/leaderboard, POST sessions,
POST sessions/{48lowerhex}/finish und POST leaderboard/reset. Start braucht
mode shoot/save und command_key UUID fuer expliziten Retry nach unklarer
Antwort. Finish akzeptiert nur genauzehn echte Integerentscheidungen -1..2,
niemals Score, Namen, Account oder fremde Gegner vom Client. Servergegner sind
verschluesselt gespeichert; Score wird nach Originalregel neu berechnet:
100+20 je laufender Erfolgsserie, Fehler setzt Serie zurueck, maximal2100.
Mindestens8 Sekunden,30 Minuten Ablauf, maximal30 Starts pro Konto/10 Minuten,
100000 Rundeneintraege je Mandant und begrenzte Bereinigung alter Runden.
Identischer Finishreplay bestaetigt denselben Score, andere Entscheidungen409.
Startreplay bindet Modus/Konto/Mandant/Rundenstand. Bestleistung wird nur bei
Verbesserung ersetzt, Ranggleichheit und zuerst erreichte Bestleistung bleiben.
Angezeigter Name kommt aus echter eigener Mitgliedszuordnung und reduziert den
Nachnamen auf einen Initialbuchstaben; keine freie Benutzernamenuebernahme.

Wie im Original werden die zehn Gegner bei Start an den Client gegeben. Die
Pruefung verhindert frei eingespeiste Scores und ungueltige/fremde/zu schnelle
Runden; sie beweist keine unveraenderte App oder tatsaechliche menschliche
Reaktionen. Keine darueber hinausgehende Cheat-/Zertifizierungsaussage.

Reset bestaetigt die angezeigte Challengegeneration und die editierbaren
Titel-/Nachrichtfelder. Tenantlock, Loeschen nur eigener Ranglisten/Runden,
Generation+1 und dauerhafter verschluesselter mobile_penalty_resets-Auftrag
sind atomar. Derselbe alte Reset wird409, nie automatisch wiederholt. Nach
Commit nimmt MobilePushSources::challenge die echte Quelle fuer frische
Empfaenger und Geraeteauslieferung auf, sofern500 freigegeben ist; kein Provider
im Reset. Sourcefehler bleiben ehrlich queued/mit Versandwarnung, niemals sent.
Ein spaeterer expliziter Worker kann die dauerhafte Quelle erneut vormerken.
Keine privaten Titel/Texte in FCM; nur typisierte IDs/generischer Hinweis.

600 erzeugt vier neue Tabellen mit CompositeFKs und Groessen-/Score-/Moduschecks:
mobile_penalty_challenges/sessions/best/resets. Vorhandene PHPMaker-Daten werden
nicht importiert oder veraendert. Persoenliche Ansicht nutzt echten Vereinsnamen
und neutrale Vereinsgrafik statt festem SVS-Logo; Originalspielfeldfarben bleiben
Spielgrafik. Kein ClubUnio-Logo wird als Vereinslogo ausgegeben.

## Nachweise und noch notwendige Integration

Zwei Regel-Unitfaelle/13 Assertions gruen. Vier echte HTTP-/PostgreSQLfaelle mit
128 Assertions gruen; anschliessend gezielt erweiterter Menue-/Readinessfall
separat1/16 gruen. TEMP600 hat ausschliesslich temporaere FK-Parents, alle Daten
zurueckgerollt, kein permanentes DDL. Tests umfassen Score/replay/best, Zeit,
30-Startlimit, strikte Typen/Manipulation, Rollen/Revocation, CrossTenant,
CompositeFK, atomaren verschluesselten Nachrichtenauftrag und alte/neue Menues.
Drei SDK/FakeTransport- und drei Launcherfaelle pluszehn Originalspieltests gruen
(insgesamt16 Flutterfaelle, getrennte Laeufe). Lints/Analyse gezielt geprueft.

Freigabegate check-mobile-penalty.php --foundation prueft fuenf geschuetzte
Routen und geschlossene Readiness; --enabled verlangt echte600 und Ledger.
Root integriert App-Scope/Marker, prueft600/500 und deployt separat. Echte
Geraete-/Push-/Coldstartabnahme bleibt offen. G1 ist damit als Quellpaket
vorbereitet, keine Behauptung vollstaendiger ausgelieferter Gesamtmigration.
