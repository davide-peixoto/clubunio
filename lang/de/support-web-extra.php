<?php

return [
    'access' => [
        'title' => 'Benutzer, Rollen und Modulprofile verwalten',
        'intro' => 'Kontozuordnung und Modulprofil bestimmen den Zugriff auf den Verein.',
        'steps' => [
            [
                'title' => 'Benutzer auswählen',
                'body' => 'Öffne Einstellungen und den freigegebenen Benutzerbereich. Suche das persönliche Konto und kontrolliere Verein und Kontostatus.',
            ],
            [
                'title' => 'Rolle und Profil prüfen',
                'body' => 'Prüfe die zugewiesene Vereinsrolle und gegebenenfalls das Modulprofil. Vergleiche Lesen, Bearbeiten, Freigeben und Herunterladen getrennt.',
            ],
            [
                'title' => 'Verknüpfung kontrollieren',
                'body' => 'Prüfe bei persönlichen Mitglieder- oder Elternzugängen zusätzlich die passende Kontoverknüpfung. Vergib nur die für die Aufgabe notwendigen Rechte.',
            ],
            [
                'title' => 'Zugriff prüfen',
                'body' => 'Speichere die autorisierte Änderung und lasse die Person den betroffenen Bereich erneut öffnen. Bei einem Rechtefehler Rolle und konkrete Aktion getrennt prüfen.',
            ],
        ],
        'notes' => [
            'API-Konten sind keine persönlichen Benutzerkonten. Plattformadministration und Vereinsverwaltung sind getrennt.',
            'Keine zusätzlichen Adminrechte vergeben, nur um einen einzelnen fehlenden Menüpunkt sichtbar zu machen.',
        ],
    ],
    'season' => [
        'title' => 'Eine Saison planen und abschliessen',
        'intro' => 'Der Saisonplanungs-Wizard trennt gespeicherte Entwürfe und verbindlichen Abschluss.',
        'steps' => [
            [
                'title' => 'Planungsentwurf öffnen',
                'body' => 'Öffne unter Sport den Saisonbereich und die Saisonplanung. Wähle einen vorhandenen Entwurf oder die angebotene neue Planung.',
            ],
            [
                'title' => 'Mannschaften und Beiträge prüfen',
                'body' => 'Bearbeite die vorgesehenen Schritte für Mannschaften und Beiträge. Prüfe Vorlage, Saison und Mannschaftsbezeichnungen vor der Übernahme.',
            ],
            [
                'title' => 'Personen und Trainingszeiten zuordnen',
                'body' => 'Ordne Spieler, Staff und technische Leitung zu. Ergänze Trainingszeiten mit Sommer- oder Winterplan, Anlage und Beginn beziehungsweise Ende.',
            ],
            [
                'title' => 'Abschluss kontrollieren',
                'body' => 'Prüfe alle Schritte und die angebotene Abschlussübersicht. Schliesse eine echte Vereinsplanung erst nach fachlicher Kontrolle verbindlich ab.',
            ],
        ],
        'notes' => [
            'Ein gespeicherter Entwurf ist noch keine abgeschlossene Saison. Verwerfen ist eine gesonderte Aktion.',
            'Die aktuelle Vereinssaison beeinflusst unter anderem die Sicht im Trainer-Webportal.',
        ],
    ],
    'training' => [
        'title' => 'Trainingspläne erstellen und anwenden',
        'intro' => 'Trainingsplanung ist von Kalenderterminen und Platzreservierungen getrennt.',
        'steps' => [
            [
                'title' => 'Planbereich öffnen',
                'body' => 'Öffne Training planen beziehungsweise den angebotenen Trainingsplanbereich und wähle die berechtigte Mannschaft.',
            ],
            [
                'title' => 'Plan vorbereiten',
                'body' => 'Nutze einen bestehenden Plan oder die angebotene Neuanlage. Ergänze die geforderten Trainingsangaben und kontrolliere Mannschaft und Zeitraum.',
            ],
            [
                'title' => 'Inhalte prüfen',
                'body' => 'Lies erzeugte oder bearbeitete Inhalte und passe Übungen an Alter, Fähigkeiten und verfügbare Mittel an. Prüfe Hinweise statt ungeprüft zu übernehmen.',
            ],
            [
                'title' => 'Speichern und anwenden',
                'body' => 'Speichere den Plan und verwende die angebotene Prüf-, Anwendungs- oder PDF-Aktion. Kontrolliere bei der Anwendung die Zielzuordnung.',
            ],
        ],
        'notes' => [
            'KI-Vorschläge ersetzen keine sportfachliche oder gesundheitliche Beurteilung.',
            'Eine Trainingsbeschreibung bucht keinen Platz und erteilt keine Reservierungsgenehmigung.',
        ],
    ],
    'contributions' => [
        'title' => 'Mitgliederbeiträge vor der Rechnung prüfen',
        'intro' => 'Beitragspreise und Regeln bilden die Grundlage für die Beitragsvorschau.',
        'steps' => [
            [
                'title' => 'Stammdaten prüfen',
                'body' => 'Öffne die erlaubten Beitragspreise und Beitragsregeln. Prüfe Saison, Gültigkeit und Zuordnung, bevor du eine Vorschau erstellst.',
            ],
            [
                'title' => 'Einzelvorschau laden',
                'body' => 'Öffne die Beitragsvorschau und wähle Mitglied und Saison. Vergleiche berechnete Positionen, Zuordnungen und Ausnahmen.',
            ],
            [
                'title' => 'Saisonlauf prüfen',
                'body' => 'Nutze bei Bedarf Saisonlauf prüfen und kontrolliere die Vorschau sowie Hinweise je Mitglied. Unklare Zuordnungen zuerst korrigieren.',
            ],
            [
                'title' => 'Ergebnis gezielt übernehmen',
                'body' => 'Verwende erst nach Prüfung die angebotene Aktion zur Entwurfserstellung oder weiteren Verarbeitung. Kontrolliere die entstandenen Rechnungsreferenzen.',
            ],
        ],
        'notes' => [
            'Eine Vorschau ist keine versandte Rechnung und löst keine Bankzahlung aus.',
            'Änderungen an Beitragsregeln können viele Mitglieder betreffen; vor einem Stapellauf die Wirkung prüfen.',
        ],
    ],
    'billing' => [
        'title' => 'Rechnungen, Gutschriften und Zahlungen unterscheiden',
        'intro' => 'Verwende den Finanzbereich mit der für deine Aufgabe freigegebenen Aktion.',
        'steps' => [
            [
                'title' => 'Rechnung finden',
                'body' => 'Öffne Rechnungen und suche nach Mitglied, Status oder Rechnungsreferenz. Kontrolliere Rechnungsempfänger und Saison.',
            ],
            [
                'title' => 'Positionen und Belege prüfen',
                'body' => 'Öffne den Datensatz und prüfe Positionen, Betrag und vorhandene PDF- beziehungsweise Providerreferenzen.',
            ],
            [
                'title' => 'Korrekturen passend bearbeiten',
                'body' => 'Nutze für Korrekturen die angebotenen Gutschrift- oder Guthabenabläufe statt erledigte Zahlungsinformationen ungeprüft zu überschreiben.',
            ],
            [
                'title' => 'Verarbeitungsstatus kontrollieren',
                'body' => 'Prüfe nach jeder erlaubten Verarbeitung den gespeicherten Status und gegebenenfalls den Rücklauf des eingerichteten Buchhaltungsdienstes.',
            ],
        ],
        'notes' => [
            'Rechnungserstellung, Versand, Zahlungseingang und Rückerstattung sind getrennte Vorgänge.',
            'Eine Anleitung ist keine Freigabe für echte Bankzahlungen oder einen produktiven Finanzlauf.',
        ],
    ],
    'announcements' => [
        'title' => 'Ankündigungen und Rundnachrichten vorbereiten',
        'intro' => 'Prüfe Inhalt und Empfängerkreis vor jeder Veröffentlichung oder Zustellung.',
        'steps' => [
            [
                'title' => 'Bereich öffnen',
                'body' => 'Öffne Ankündigungen und Rundnachrichten. Suche vorhandene Einträge oder verwende die angebotene Erfassungsaktion.',
            ],
            [
                'title' => 'Inhalt vorbereiten',
                'body' => 'Ergänze Betreff, Nachricht und die angebotenen zeitlichen Angaben. Verwende verständliche Inhalte ohne unnötige persönliche Daten.',
            ],
            [
                'title' => 'Empfänger prüfen',
                'body' => 'Kontrolliere den ausgewählten Kreis, Mannschaften und Rollen sowie eine angebotene Empfängervorschau. Prüfe die richtige Vereinssicht.',
            ],
            [
                'title' => 'Freigabe und Ergebnis prüfen',
                'body' => 'Führe die angebotene Freigabe oder Zustellung erst nach Kontrolle aus. Prüfe anschliessend gespeicherten Status und vorhandene Rückmeldungen.',
            ],
        ],
        'notes' => [
            'Ein Lesestatus ist von erfolgreicher Push- oder E-Mail-Zustellung zu unterscheiden.',
            'Nachträgliche Änderungen und erneute Zustellung können unterschiedliche Aktionen sein; nicht ungeprüft mehrfach senden.',
        ],
    ],
    'document_series' => [
        'title' => 'Dokumentenserien erstellen und Rückgaben prüfen',
        'intro' => 'Eine Serie verbindet Vorlage, Empfänger und persönliche Dokumente.',
        'steps' => [
            [
                'title' => 'Vorlage und Serie wählen',
                'body' => 'Öffne Dokumentenserien. Wähle die passende Vorlage und prüfe Zweck, Verein und Version.',
            ],
            [
                'title' => 'Empfänger kontrollieren',
                'body' => 'Prüfe die angebotenen Empfänger und persönlichen Zuordnungen. Kontrolliere die Vorschau vor der verbindlichen Erstellung.',
            ],
            [
                'title' => 'Originale bereitstellen',
                'body' => 'Verwende die vorgesehene Ausstellungs- oder Bereitstellungsaktion und prüfe die erzeugten Originale sowie ihren Status.',
            ],
            [
                'title' => 'Rückgaben zuordnen',
                'body' => 'Öffne die richtige Person und ordne eine geprüfte unterschriebene Rückgabe dem zugehörigen Original zu. Kontrolliere den dokumentierten Rückgabestatus.',
            ],
        ],
        'notes' => [
            'Private Originale und Rückgaben sind keine öffentlichen Dateien.',
            'Ein geänderter Vorlagenentwurf ersetzt kein bereits ausgestelltes Original; historische Nachweise bleiben erhalten.',
        ],
    ],
    'social' => [
        'title' => 'Social-Media-Grafiken und Beiträge vorbereiten',
        'intro' => 'Freigabe und externer Versand sind getrennte Schritte.',
        'steps' => [
            [
                'title' => 'Social Media öffnen',
                'body' => 'Öffne unter Anlagen und Kommunikation Social Media. Wähle Spielübersicht, Resultate oder einen freien Beitrag.',
            ],
            [
                'title' => 'Vorlage und Medien prüfen',
                'body' => 'Wähle ein Layout und bearbeite Texte, Felder und Bilder. Verwende eigene Bilder nur mit passender Veröffentlichungsfreigabe.',
            ],
            [
                'title' => 'Vorschau freigeben',
                'body' => 'Kontrolliere Grafik, Datum, Paarung und Ergebnis. Prüfe die JPEG-Vorschau und verwende erst danach die erlaubte Freigabeaktion.',
            ],
            [
                'title' => 'Versandziel prüfen',
                'body' => 'Bei eingerichteter Zustellung prüfe Konto, Kanal und angebotenen Versandstatus. Kontrolliere nach Änderungen erneut, ob der Beitrag wieder ein Entwurf ist.',
            ],
        ],
        'notes' => [
            'Ein freigegebener Entwurf ist noch kein veröffentlichter Beitrag. Testziele und produktive Kanäle dürfen nicht verwechselt werden.',
            'Private Spielerdaten, Aufgebote und interne Bemerkungen gehören nicht automatisch in öffentliche Grafiken.',
        ],
    ],
    'campaigns' => [
        'title' => 'Kampagnenvorschau und Rücklauf verstehen',
        'intro' => 'Der erweiterte Kampagnenversand kann auf freigegebenen Testbetrieb begrenzt sein.',
        'steps' => [
            [
                'title' => 'Entwurf öffnen',
                'body' => 'Öffne Kampagnen und den gewünschten Entwurf. Prüfe Typ, Verein und verfügbare Betriebsfreigabe.',
            ],
            [
                'title' => 'Vorlage und Sprache prüfen',
                'body' => 'Wähle die passende Vorlage und kontrolliere DE- beziehungsweise EN-Texte sowie die angebotene Ausgabesprache je Empfänger.',
            ],
            [
                'title' => 'Empfänger prüfen',
                'body' => 'Kontrolliere Vorschau und Empfängerliste vollständig. Verwende im ausgewiesenen Testbetrieb ausschliesslich die dafür erlaubten synthetischen Adressen.',
            ],
            [
                'title' => 'Rücklauf unterscheiden',
                'body' => 'Prüfe eingereiht, angenommen, Zustellmeldung, Rückläufer und ausdrückliche Bestätigung getrennt. Starte unklare Versuche nur über die begründete Wiederanlaufaktion neu.',
            ],
        ],
        'notes' => [
            'Im eingeschränkten Testbetrieb erfolgt keine echte SMTP-Zustellung; ein Teststatus ist kein Nachweis einer produktiven E-Mail.',
            'Linkaufruf, Druck und explizite Bestätigung sind unterschiedliche Ereignisse.',
        ],
    ],
    'integrations' => [
        'title' => 'Integrationen und API-Zugänge sicher verwalten',
        'intro' => 'Technische Zugänge sind getrennt von persönlichen Benutzerkonten zu pflegen.',
        'steps' => [
            [
                'title' => 'Bereich auswählen',
                'body' => 'Öffne Einstellungen und die erlaubten API-Integrationen beziehungsweise Parameter und Integrationen. Prüfe vor Änderungen Verein und Umgebung.',
            ],
            [
                'title' => 'Zweck und Rechte festlegen',
                'body' => 'Wähle den vorgesehenen Dienst und begrenze den Zugang auf die benötigten Funktionen. Prüfe getrennte Lese-, Schreib- und Exportrechte.',
            ],
            [
                'title' => 'Zugang sicher hinterlegen',
                'body' => 'Verwende nur die dafür vorgesehenen geschützten Eingabefelder. Übertrage Schlüssel nicht in Notizen, Chat, Screenshots oder Supportartikel.',
            ],
            [
                'title' => 'Ergebnis prüfen',
                'body' => 'Nutze die angebotene Verbindungs- oder Auftragsprüfung und kontrolliere Ergebnis und Referenz. Sperre nicht mehr benötigte Zugänge gezielt.',
            ],
        ],
        'notes' => [
            'Eine gespeicherte Konfiguration beweist noch keinen erfolgreichen Provideraufruf.',
            'Produktive Zustellung, Zahlungen und Providerwechsel benötigen die jeweils vorgesehenen fachlichen Freigaben.',
        ],
    ],
    'facilities' => [
        'title' => 'Anlagenstatus und Verfügbarkeit pflegen',
        'intro' => 'Anlagenstatus beschreibt die aktuelle Nutzungslage; Platzanfragen besitzen einen eigenen Genehmigungsablauf.',
        'steps' => [
            [
                'title' => 'Anlage finden',
                'body' => 'Öffne Anlagenstatus und suche die betroffene Anlage. Prüfe, ob dein Verein Spielfelder oder sportneutrale buchbare Anlagen verwendet.',
            ],
            [
                'title' => 'Status bearbeiten',
                'body' => 'Wähle den erlaubten Status und ergänze eine sachliche Bemerkung. Prüfe, ob der gewählte Status Training sperrt.',
            ],
            [
                'title' => 'Speicherung prüfen',
                'body' => 'Speichere und kontrolliere die angezeigte Anlage, den Status und die Aktivierung. Prüfe bei Bedarf die Auswirkungen in der Belegungsansicht.',
            ],
            [
                'title' => 'Planung getrennt prüfen',
                'body' => 'Beurteile zukünftige Anfragen in der Platzbelegung separat. Ein aktueller Sperrstatus allein beschreibt nicht die gesamte zukünftige Planung.',
            ],
        ],
        'notes' => [
            'Statusstammdaten und Status einer konkreten Anlage sind unterschiedliche Einträge.',
            'Nicht berechtigte Benutzer dürfen einen Sperrstatus nicht durch andere Stammdaten umgehen.',
        ],
    ],
    'material_returns' => [
        'title' => 'Materialausgabe und Rückgaben nachführen',
        'intro' => 'Materialquittungen und Rückgabebuchungen dokumentieren die physische Ausgabe und Rückgabe.',
        'steps' => [
            [
                'title' => 'Quittung öffnen',
                'body' => 'Öffne Materialquittungen oder die Materialrückgabe. Wähle das richtige Mitglied und eine ausgestellte beziehungsweise bestätigte Quittung.',
            ],
            [
                'title' => 'Position prüfen',
                'body' => 'Kontrolliere ausgegebene Menge, bereits zurückgebuchte Menge und offene Menge der konkreten Position.',
            ],
            [
                'title' => 'Rückgabe buchen',
                'body' => 'Erfasse nur tatsächlich zurückgegebenes Material mit Menge, Datum und Rückgabezustand. Prüfe vor dem Bestätigen die Position.',
            ],
            [
                'title' => 'Restmenge prüfen',
                'body' => 'Kontrolliere offene Menge und Rückgabeverlauf. Pflege bei Bedarf den angebotenen Solltermin und nutze den Filter für überfällige Rückgaben.',
            ],
        ],
        'notes' => [
            'Teilrückgaben sind möglich. Die Summe darf die ausgegebene Menge nicht überschreiten.',
            'Die Rückgabe verändert nicht stillschweigend die ursprüngliche Quittung und ist keine allgemeine Lagerbestandsbuchung.',
        ],
    ],
    'sponsor_runs' => [
        'title' => 'Sponsorenlauf organisieren',
        'intro' => 'Anlass, Teilnehmende, Zusagen und Abrechnung sind getrennte Arbeitsschritte.',
        'steps' => [
            [
                'title' => 'Anlass öffnen',
                'body' => 'Öffne Sponsorenlauf und wähle den richtigen Anlass. Kontrolliere Saison, Datum, Ort und Anmeldefrist.',
            ],
            [
                'title' => 'Teilnehmende prüfen',
                'body' => 'Prüfe Mannschafts- und Mitgliederzuordnungen. Öffne die Details der berechtigten Teilnehmenden und kontrolliere vorhandene Zusagen.',
            ],
            [
                'title' => 'Leistung nachführen',
                'body' => 'Erfasse die vorgesehenen Leistungs- oder Rundendaten erst nach fachlicher Kontrolle. Prüfe Beträge und Zusagen in der angebotenen Übersicht.',
            ],
            [
                'title' => 'Abrechnung vorbereiten',
                'body' => 'Nutze die freigegebene Abrechnungsvorschau und kontrolliere Rechnungsempfänger sowie Ergebnisse. Eine externe Rechnung nur über den autorisierten Ablauf weiterverarbeiten.',
            ],
        ],
        'notes' => [
            'Öffentliche Sponsorenlinks und interne Mitgliederdaten sind getrennt. Teile nur die dafür vorgesehenen öffentlichen Links.',
            'Eine Zusage oder Rechnungserstellung ist kein Zahlungseingang.',
        ],
    ],
    'veo' => [
        'title' => 'Veo-Aufnahmeplanung für eine Woche prüfen',
        'intro' => 'Der Plan verwendet SFV-Spiele und bestätigte Platzzuteilungen.',
        'steps' => [
            [
                'title' => 'Woche wählen',
                'body' => 'Öffne Veo-Aufnahmeplanung und wähle das Startdatum oder blättere zur gewünschten Woche.',
            ],
            [
                'title' => 'Kameras prüfen',
                'body' => 'Kontrolliere die Kamerabezeichnung pro Anlage. Pflege eine Änderung nur für die tatsächlich dort verfügbare Kamera.',
            ],
            [
                'title' => 'Mannschaften freigeben',
                'body' => 'Prüfe, welche Saisonmannschaften für die Aufnahmeplanung aktiviert sind. Ändere diese Auswahl nur mit entsprechender Zuständigkeit.',
            ],
            [
                'title' => 'Plan kontrollieren',
                'body' => 'Prüfe die vorgeschlagenen Aufnahmezeiten gegen Spiel und bestätigte Platzbelegung. Kläre fehlende oder widersprüchliche Daten vor der praktischen Aufnahme.',
            ],
        ],
        'notes' => [
            'Ein angezeigter Plan ist kein Nachweis einer tatsächlich gestarteten oder gespeicherten Kameraaufnahme.',
            'Die Planung ersetzt keine Information der betroffenen Personen oder erforderliche Aufnahmefreigaben.',
        ],
    ],
    'challenge' => [
        'title' => 'Vereinschallenge und Penalty-Spiel einordnen',
        'intro' => 'Das Penalty-Spiel ist eine Appfunktion mit vereinsbezogener Rangliste.',
        'steps' => [
            [
                'title' => 'Freigabe prüfen lassen',
                'body' => 'Fehlt die Vereinschallenge in der App, lasse Vereinsfreigabe und App-Modulprofil durch die Administration prüfen.',
            ],
            [
                'title' => 'In der App öffnen',
                'body' => 'Melde dich mit dem berechtigten Konto im richtigen Verein an und öffne die angebotene Vereinschallenge. Es gibt dafür keinen gleichwertigen eigenständigen Web-Spielbereich.',
            ],
            [
                'title' => 'Runde abschliessen',
                'body' => 'Wähle den angebotenen Modus und spiele die Runde in der App zu Ende. Prüfe das bestätigte Ergebnis und die aktuelle Rangliste.',
            ],
            [
                'title' => 'Rangliste bewusst verwalten',
                'body' => 'Ein Zurücksetzen ist eine besondere berechtigte Aktion. Verwende sie nur mit dem vorgesehenen Bestätigungsablauf und nach Klärung der Auswirkungen.',
            ],
        ],
        'notes' => [
            'Challenge-Punkte sind Spielstände und keine Spesen, Zahlungen oder disziplinarischen Strafen.',
            'Eine abgelaufene oder unvollständige Runde kann nicht regulär gewertet werden.',
        ],
    ],
    'staff_functions' => [
        'title' => 'Staff und Vereinsfunktionen zuordnen',
        'intro' => 'Personenstammdaten, Mannschaftsstaff und Vereinsfunktionen erfüllen unterschiedliche Zwecke.',
        'steps' => [
            [
                'title' => 'Aufgabe klären',
                'body' => 'Prüfe, ob eine Person als Mannschaftsstaff, technische Leitung oder mit einer allgemeinen Vereinsfunktion zugeordnet werden soll.',
            ],
            [
                'title' => 'Passenden Bereich öffnen',
                'body' => 'Öffne die Mannschaftszuordnung im Saisonbereich oder den berechtigten Mitgliedereintrag mit seinen Funktionen. Kontrolliere Saison und Person.',
            ],
            [
                'title' => 'Zuordnung erfassen',
                'body' => 'Wähle die vorgesehene Funktion und die angebotenen Gültigkeitsangaben. Vermeide doppelte oder historisch unpassende Zuordnungen.',
            ],
            [
                'title' => 'Wirkung prüfen',
                'body' => 'Kontrolliere die gespeicherte Zuordnung und kläre technische Zugriffsrechte separat im Benutzerbereich. Eine Funktion allein darf nicht mit uneingeschränkten Adminrechten verwechselt werden.',
            ],
        ],
        'notes' => [
            'Ein eigenständiger Helferschichtplan ist kein Bestandteil dieses beschriebenen Ablaufs.',
            'Vereinsfunktion und Benutzerrolle sind unterschiedliche Zuordnungen.',
        ],
    ],
    'website' => [
        'title' => 'Öffentliche Vereinsdaten für eine Website bereitstellen',
        'intro' => 'Der lesende Website-API-Zugang ist von persönlichem Fachzugriff getrennt.',
        'steps' => [
            [
                'title' => 'Veröffentlichungszweck klären',
                'body' => 'Bestimme, welche freigegebenen Vereinsinformationen die Website benötigt. Veröffentliche keine persönlichen Dossiers über allgemeine Website-Zugänge.',
            ],
            [
                'title' => 'API-Zugang prüfen',
                'body' => 'Lasse die zuständige Administration den dafür vorgesehenen lesenden Website-Zugang und den richtigen Verein kontrollieren.',
            ],
            [
                'title' => 'Ausgabe kontrollieren',
                'body' => 'Prüfe die auf der Website sichtbaren Inhalte gegen die Vereinsdaten und die Freigaben. Unterscheide öffentliche Angaben von internen Bemerkungen.',
            ],
            [
                'title' => 'Änderungen nachvollziehen',
                'body' => 'Pflege Daten in ihrem zuständigen ClubUnio-Modul und prüfe die Website nach deren Aktualisierung. Melde veraltete Ausgabe mit Quelle und Zeitpunkt.',
            ],
        ],
        'notes' => [
            'Öffentliche Website-Ausgabe und die Bearbeitung interner Vereinsdaten sind getrennte Abläufe.',
            'API-Schlüssel dürfen nicht in öffentliche Skripte, Bilder oder Supportartikel kopiert werden.',
        ],
    ],
    'reports' => [
        'title' => 'Auswertungen gezielt filtern und exportieren',
        'intro' => 'Berichte verwenden bestehende Fach- und Exportberechtigungen.',
        'steps' => [
            [
                'title' => 'Bericht wählen',
                'body' => 'Öffne Auswertungen und den passenden Bericht, etwa Mitglieder, Spieler, Staff, offene Rechnungen oder Bankkonten.',
            ],
            [
                'title' => 'Filter setzen',
                'body' => 'Stelle den angebotenen Verein, die Saison und fachliche Filter ein. Prüfe, ob die Auswahl dem beabsichtigten Zweck entspricht.',
            ],
            [
                'title' => 'Ergebnis prüfen',
                'body' => 'Kontrolliere Stichproben und Statusangaben. Eine leere Liste kann durch Filter oder fehlende Berechtigungen entstehen.',
            ],
            [
                'title' => 'Export bewusst nutzen',
                'body' => 'Verwende einen angebotenen Export nur bei Bedarf und mit passender Berechtigung. Teile die Datei ausschliesslich mit den vorgesehenen Empfängern.',
            ],
        ],
        'notes' => [
            'Ein Sichtrecht umfasst nicht automatisch ein Download- oder Exportrecht.',
            'Berichte können personenbezogene Daten enthalten. Vermeide öffentliche Ablagen und ungeprüfte Weitergabe.',
        ],
    ],
    'families' => [
        'title' => 'Familienzuordnungen kontrollieren',
        'intro' => 'Familiengruppierung und bestätigter Elternlogin sind getrennte Sachverhalte.',
        'steps' => [
            [
                'title' => 'Familie suchen',
                'body' => 'Öffne den erlaubten Familienbereich und suche die richtige Familiengruppe. Prüfe Name und Adressangaben.',
            ],
            [
                'title' => 'Mitglieder prüfen',
                'body' => 'Kontrolliere die zugeordneten Personen. Vergleiche die Zuordnung mit den tatsächlich gewünschten Familienbeziehungen.',
            ],
            [
                'title' => 'Zuordnung bearbeiten',
                'body' => 'Nutze die angebotenen Bearbeitungsaktionen und prüfe jede Person vor dem Speichern. Ändere persönliche Kontakte im zuständigen Mitgliedereintrag.',
            ],
            [
                'title' => 'Elternlogin separat prüfen',
                'body' => 'Wenn ein Elternkonto ein Kind nicht sieht, prüfe die bestätigte Kontoverknüpfung und Altersfreigabe im dafür vorgesehenen Bereich.',
            ],
        ],
        'notes' => [
            'Eine Familiengruppe gewährt allein keinen Elternzugriff auf geschützte Mitgliederdaten.',
            'Bei Volljährigen ist die ausdrückliche Freigabe unabhängig von gemeinsamer Adresse erforderlich.',
        ],
    ],
    'competitions' => [
        'title' => 'SFV-Wettkämpfe kontrolliert übernehmen',
        'intro' => 'Externe Spielinformationen und interne Teamtermine sind getrennte Datensätze.',
        'steps' => [
            [
                'title' => 'Zeitraum auswählen',
                'body' => 'Öffne SFV-Wettkämpfe oder den SFV-Bereich der Teamplanung. Wähle Zeitraum und gewünschtes Spiel.',
            ],
            [
                'title' => 'Zuordnung prüfen',
                'body' => 'Kontrolliere Paarung, Datum, Spielort und Zielmannschaft. Prüfe, ob bereits ein interner Termin oder eine Verknüpfung vorhanden ist.',
            ],
            [
                'title' => 'Vorschau kontrollieren',
                'body' => 'Nutze die angebotene Vorschau für einzelne oder mehrere Spiele. Kläre fehlende Mannschaften und Konflikte vor einer Übernahme.',
            ],
            [
                'title' => 'Ergebnis prüfen',
                'body' => 'Führe die erlaubte Übernahme aus und kontrolliere die entstandenen internen Termine. Prüfe spätere externe Änderungen getrennt.',
            ],
        ],
        'notes' => [
            'Externe Spielangaben sind kein Nachweis einer internen Platzgenehmigung.',
            'Vermeide doppelte Übernahmen desselben Spiels.',
        ],
    ],
    'signage' => [
        'title' => 'Anzeigen und Signage-Meldungen vorbereiten',
        'intro' => 'Signage-Inhalte sind für die vorgesehenen Anzeigeflächen bestimmt.',
        'steps' => [
            [
                'title' => 'Meldung erfassen',
                'body' => 'Öffne Signage-Meldungen und lege über die angebotene Aktion einen Eintrag an.',
            ],
            [
                'title' => 'Datum und Inhalt prüfen',
                'body' => 'Erfasse Anzeigen am, Betreff und Meldung. Prüfe Lesbarkeit und verzichte auf vertrauliche Mitgliederdaten.',
            ],
            [
                'title' => 'Aktivierung kontrollieren',
                'body' => 'Speichere den gewünschten Aktivstatus. Nutze Suche und Filter, um die Meldungen für den richtigen Zeitraum zu kontrollieren.',
            ],
            [
                'title' => 'Vorschau prüfen',
                'body' => 'Öffne die angebotene Signage-Vorschau und prüfe die Ausgabe. Die Anzeige auf einem echten Gerät zusätzlich vor Ort kontrollieren.',
            ],
        ],
        'notes' => [
            'Ein gespeicherter Eintrag beweist nicht, dass ein physischer Bildschirm online ist.',
            'Vergangene Meldungen können durch den Filter Ab heute ausgeblendet sein.',
        ],
    ],
    'sponsors' => [
        'title' => 'Sponsoring im Mitgliederdossier pflegen',
        'intro' => 'Sponsoringeinträge dokumentieren Vereinbarung, Betrag und Laufzeit.',
        'steps' => [
            [
                'title' => 'Dossier auswählen',
                'body' => 'Öffne den berechtigten Mitgliedereintrag und den Bereich Sponsoring. Prüfe, ob du die richtige Person oder Organisation bearbeitest.',
            ],
            [
                'title' => 'Eintrag erfassen',
                'body' => 'Nutze die angebotene Erfassungsaktion und ergänze Sponsor, Betrag in CHF, Laufzeit bis und erforderliche Bemerkungen.',
            ],
            [
                'title' => 'Angaben kontrollieren',
                'body' => 'Vergleiche Betrag und Laufzeit mit der freigegebenen Vereinbarung. Speichere nur sachlich notwendige Bemerkungen.',
            ],
            [
                'title' => 'Verlauf nachführen',
                'body' => 'Kontrolliere bestehende Einträge, bevor du einen weiteren erfasst. Pflege Änderungen über die vorhandene Bearbeitungsaktion.',
            ],
        ],
        'notes' => [
            'Ein Dossiereintrag ist keine automatisch versandte Sponsorenrechnung und kein Zahlungseingang.',
            'Sponsoring im Dossier und Zusagen bei einem Sponsorenlauf sind unterschiedliche Bereiche.',
        ],
    ],
    'synchronisation' => [
        'title' => 'Synchronisationsaufträge und Fehler prüfen',
        'intro' => 'Das Protokoll beschreibt einzelne Aufträge und ersetzt keine fachliche Kontrolle des Zielsystems.',
        'steps' => [
            [
                'title' => 'Aufträge filtern',
                'body' => 'Öffne Synchronisation und wähle Status beziehungsweise Provider. Suche den zum Vorfall gehörenden Auftrag.',
            ],
            [
                'title' => 'Details prüfen',
                'body' => 'Kontrolliere Objekt, Vorgang, Zeitpunkt und vorhandenen Verlauf. Unterscheide offen, erfolgreich und Aufmerksamkeit erforderlich.',
            ],
            [
                'title' => 'Ursache beheben',
                'body' => 'Prüfe die angezeigten Hinweise und zuständigen Fachdaten. Lasse fehlende Providerkonfiguration von der berechtigten Administration korrigieren.',
            ],
            [
                'title' => 'Wiederanlauf bewusst starten',
                'body' => 'Nutze nur die angebotene Wiederanlaufaktion mit erforderlicher Begründung. Prüfe danach Ergebnis und Zielobjekt statt mehrfach dieselbe Verarbeitung anzustossen.',
            ],
        ],
        'notes' => [
            'Bei unklarem externem Ergebnis vor einem Wiederanlauf prüfen, ob die Aktion im Zielsystem bereits erfolgt ist.',
            'Protokollinhalte können vertrauliche Referenzen enthalten; keine Zugangsdaten an Supporttexte anhängen.',
        ],
    ],
    'work_inbox' => [
        'title' => 'Pendenzen im Arbeitsvorrat bearbeiten',
        'intro' => 'Übersichtszähler verweisen auf Fachbereiche mit eigener Berechtigungsprüfung.',
        'steps' => [
            [
                'title' => 'Übersicht öffnen',
                'body' => 'Öffne Dashboard oder den angebotenen Arbeitsvorrat im richtigen Verein. Prüfe die sichtbaren Kategorien und Zähler.',
            ],
            [
                'title' => 'Fachobjekt öffnen',
                'body' => 'Wähle beispielsweise eine Spese zur Freigabe oder einen Auftrag mit Aufmerksamkeit. Öffne den zugrunde liegenden Datensatz.',
            ],
            [
                'title' => 'Inhalt prüfen',
                'body' => 'Kontrolliere Antrag, Belege, Status und Zuständigkeit im Fachmodul. Der Zähler allein ist keine Entscheidungsgrundlage.',
            ],
            [
                'title' => 'Ergebnis verfolgen',
                'body' => 'Führe die zulässige Aktion im Fachmodul aus und prüfe anschliessend gespeicherten Status und Übersicht.',
            ],
        ],
        'notes' => [
            'Eine Kategorie erscheint nur, wenn die erforderlichen Berechtigungen und Modulfreigaben vorhanden sind.',
            'Pendenzen, Posteingangsmitteilungen und technische Synchronisationsfehler sind unterschiedliche Aufgabenarten.',
        ],
    ],
    'catalogs' => [
        'title' => 'Stammdaten und Sprachvarianten pflegen',
        'intro' => 'Stammdaten definieren auswählbare Werte; Änderungen können mehrere Abläufe beeinflussen.',
        'steps' => [
            [
                'title' => 'Katalog wählen',
                'body' => 'Öffne Einstellungen und den zuständigen Bereich, etwa Mitglieder und Verein, Sport und Anlagen oder Finanzen.',
            ],
            [
                'title' => 'Eintrag prüfen',
                'body' => 'Suche den vorhandenen Wert. Prüfe Code, Bezeichnung, Sortierung und Aktivierung, bevor du einen ähnlichen Eintrag neu erstellst.',
            ],
            [
                'title' => 'Sprachvarianten pflegen',
                'body' => 'Verwende die angebotenen Lookup-Übersetzungen für freigegebene Sprachen. Interne Codes und bestehende Benutzereingaben bleiben unverändert.',
            ],
            [
                'title' => 'Wirkung prüfen',
                'body' => 'Kontrolliere die betroffenen Formulare nach dem Speichern. Deaktiviere alte Werte nur nach Prüfung bestehender Verwendungen.',
            ],
        ],
        'notes' => [
            'Eine Bezeichnung zu übersetzen ist keine Freigabe einer neuen Oberflächensprache. Sprachfreigaben werden zentral verwaltet.',
            'Ändere nicht Statuscodes, um einen fachlichen Workflow zu umgehen.',
        ],
    ],
    'message_templates' => [
        'title' => 'Nachrichtenvorlagen mit Platzhaltern pflegen',
        'intro' => 'Vorlagen werden je Ereignis, Kanal und Sprache verwaltet.',
        'steps' => [
            [
                'title' => 'Vorlage wählen',
                'body' => 'Öffne Nachrichtenvorlagen und prüfe Ereignis, E-Mail- oder Push-Kanal und Sprache. Wähle die vorhandene Vorlage oder eine erlaubte Neuanlage.',
            ],
            [
                'title' => 'Texte bearbeiten',
                'body' => 'Ergänze Betreff beziehungsweise Titel und Nachricht. Verwende nur die für dieses Ereignis angebotenen Platzhalter.',
            ],
            [
                'title' => 'Vorschau kontrollieren',
                'body' => 'Prüfe die Vorschau mit Beispieldaten und behebe ungültige Platzhalter oder unzulässige Formatierung. Pflege jede benötigte Sprachvariante separat.',
            ],
            [
                'title' => 'Aktivierung prüfen',
                'body' => 'Speichere die autorisierte Variante mit dem gewünschten Aktivstatus. Nutze eine angebotene Testfunktion nur mit dem dafür freigegebenen Empfänger.',
            ],
        ],
        'notes' => [
            'Eine gespeicherte Vorlage löst noch keine Rundnachricht aus.',
            'Beispieldaten in einer Vorschau ersetzen keine Kontrolle des tatsächlichen Empfängerkreises.',
        ],
    ],
];
