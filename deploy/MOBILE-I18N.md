# Mobile App-Sprache: lokale Sprachdateien

04.11.2026. Die Profilwahl ist die **App-Sprache**; ihr bestehender persönlicher
Preferences-Vertrag bleibt unverändert. Die frühere Teilübersetzung der Shell
wird durch zentrale ARB-Kataloge für Deutsch, Englisch, Französisch, Italienisch
und Portugiesisch (Portugal) ersetzt. Die bestehenden SVS-Oberflächen bleiben
Grundlage der App; keine zusätzliche Appkopie.

Die App übersetzt ihre Oberfläche nicht per API. Texte werden mit dem Build
gebündelt und lokal aufgelöst. Kein KI-Auftrag beim Öffnen einer Ansicht oder
beim Wechsel der Sprache. Die getrennte Zielsprachenwahl und Einwilligung für
ausgewählte Chatnachrichten bleiben bestehen; OpenAI-/Datenschutzhinweise werden
nun ebenfalls aus den lokalen Katalogen angezeigt. Kein Provider freigeschaltet.

Navigation, Login, Profil, Chat, Teamplanung, My Teams, Trainingsplaner, Spesen,
Mitglieder, Anmeldungen, Dokumente, Bus/Anlagen, Sponsorenlauf und Challenge
verwenden die gemeinsamen Übersetzungen. Zählformen über ICU, benannte Parameter
für ganze Sätze, localeabhängige Kalender-/Datumsanzeigen. Anzeigeübersetzungen
ändern keine API-/Enumwerte oder Rollen-/Mandantenrechte. Eigene Namen, Titel,
Nachrichten, Dokumente, Datenwerte und redaktionelle Vereinsinhalte bleiben Daten.

Pflegequelle im separaten Apprepository: `l10n_fragments/*/app_*.arb`.
Deterministischer Merge, Flutter-Generator und `tools/check_i18n.py` prüfen
Vollständigkeit und feste UI-Literale. Eigene Appdelegates sind an material_ui
angepasst. Kein global veränderlicher Repository-Sprachzustand. Wartungsanleitung:
App `docs/I18N.md`, Moduldetails `docs/I18N-PLANNING.md` und
`l10n_fragments/expenses/README.md`. App-AGENTS enthält die verbindlichen Schritte.

Reine Appänderung. Keine Backend-Runtime-/Schema-/ENV-Änderung und kein erneutes
DEV-Deployment nötig. Das Apppaket löst kein Backend-Deployment aus. Während der Umsetzung wurde
lesend bereits das parallel bereitgestellte DEV-Release
`20261104-clubunio-languages` festgestellt; dessen Betriebs-/Codeänderungen
werden durch dieses Apppaket nicht überschrieben oder mit abgenommen. Native iOS-Kamera-/Fotobibliothek-/
Face-ID-Texte sind in fünf Ressourcen registriert; nativer Neubau und Sichtprüfung
auf macOS/iPhone sind damit nicht ersetzt.


Abnahme des Apppakets:597/597 Fluttertests im abschließenden vollständigen Lauf,
Analyse ohne Befund;1915 Schlüssel vollständig in allen fünf lokalen Katalogen,
AST-Prüfung fester UI-Texte bestanden.28 neue Locale-/Navigations-/Modulfälle.
Keine echten Nachrichten, KI-Aufträge, Bankzahlungen oder Provideraktionen.

Synchronisierter App-Commit: `0befab0` in `clubunio_mobile`, Hauptzweig.
