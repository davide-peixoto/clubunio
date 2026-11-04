# Persönliche lokale Diagnose (G12, 04.11.2026)

Der tatsächlich vorhandene Originalablauf verwendet sieben Tipps auf die
Profilkarte innerhalb von drei Sekunden. Dieser Einstieg, das Diagnosesheet,
Export und Logs leeren sind jetzt auch im persönlichen Profil vorhanden.
Kein Legacy-AppState, ApiClient oder bestehendes AppDiagnostics-Log wird im
persönlichen Zweig gelesen. Das Sheet scrollt auch bei kleiner verfügbarer Höhe.

PersonalDiagnostics hält maximal100 Einträge ausschliesslich im Arbeitsspeicher.
Ein echter persönlicher SDK-Request erfasst UTC-Zeit, feste Routenkategorie,
Erfolg/Fehler, HTTP-Status und begrenzte Laufzeit. Die geschlossene Kategorie
entsteht vor Speicherung; keine konkreten Pfade oder IDs verbleiben im Puffer.
Gespeichert werden niemals Token/Proof/Passwörter, Header, Cookies, freie Texte,
Request-/Responsebodies, Namen/Emailadressen, Konto-/Mandanten-/Objekt-IDs,
Hosts, URLs/Querystrings, Dateiinhalte oder Verzeichnis-/Cachemanifeste.
Auch Fehlerobjekte werden nicht übernommen. Unbekannte Routen heissen other.

Tokenwechsel, Logout und Mandantenwahl löschen den Puffer und erhöhen seine
Generation. Der Export ist an die beim Öffnen gebundene Sitzungsgeneration und
Mandantenwahl sowie die Puffergeneration gebunden. Nach asynchroner nativer
Buildinfoabfrage und direkt vor Übergabe an die Teilen-Funktion wird erneut
geprüft. Ein zwischenzeitlicher Kontowechsel oder Löschen verhindert den Export.
Ein bereits vom Benutzer an die Betriebssystem-Teilen-Funktion übergebener
Anhang lässt sich nachträglich nicht zurückrufen.

Der persönliche Export enthält JSON statt des unsicheren alten Dateisammel-ZIPs:
Formatversion, den öffentlichen ClubUnio-/Custom-Buildnamen aus AppBrand,
geprüfte Versions-/Buildnummer, Exportzeit und den erlaubten Puffer.
Er nutzt XFile.fromData; keine Diagnosedatei oder fremde Datei wird gesucht,
eingelesen oder dauerhaft von diesem Dienst gespeichert. Der Benutzer wählt
das Ziel im vorhandenen ShareSheet. In Werkzeugen/Tests findet keine Zustellung
statt. Betriebssystem-Sharecache/echte Android-/iOS-Exportabnahme bleibt separat.

Nachweis: sieben neue Tests für Ringgrenze, geschlossene Felder, gefährliche
Metadaten, echte SDK-Erfolgs-/Fehlererfassung ohne Inhalte, Custom-Buildname,
Löschen, Konto-/Logoutwechsel während Buildinfo, tatsächliche Shellintegration
und Originalsheet ohne Legacyprovider. Analyse sauber. Der gezielte gemeinsame
Konto-/Push-/Communication-/Native-Menü-/Challenge-Nachlauf besteht28 Fälle.
Die bisherige Communicationannahme einer Today-Teamabfrage wurde an den
tatsächlichen G9-Kalendervertrag angepasst; Planungsfehler-/Chat-/Profilprüfung
bleibt erhalten. Keine Migration, kein Serverflag und kein Provider nötig.

Gesamtlauf-Nachtrag: Die Attendance-Testfixture hatte noch die alte
sendSquadPush-Signatur ohne optionale Pushrevision/Empfängerfingerprint.
Nur diese Fixture wurde dem echten Repositoryvertrag angepasst. Der bestehende
Bestätigungs-/Doppelsendeschutztest prüft zusätzlich, dass ein nach Öffnen der
Bestätigung geänderter Hintergrundstand den angezeigten Snapshot nicht ersetzt.
Gezielter team_planning_attendance_test.dart-Nachlauf23/23 grün; keine
Produktabsicherung geändert und kein breiter Gesamtlauf durch diesen Agenten gestartet.
