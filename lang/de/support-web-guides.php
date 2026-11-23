<?php

return [
    'login' => [
        'title' => 'Im Browser anmelden und Zugang prüfen',
        'intro' => 'Verwende dein persönliches Vereinskonto. Anmeldung und Vereinsberechtigung sind getrennt.',
        'steps' => [
            [
                'title' => 'Umgebung prüfen',
                'body' => 'Öffne die vom Verein mitgeteilte Webadresse. Prüfe, ob du DEV, Stage oder die produktive Anwendung benötigst.',
            ],
            [
                'title' => 'Anmelden',
                'body' => 'Starte Anmelden und schliesse den angebotenen Keycloak-Ablauf einschliesslich einer eingerichteten zweiten Bestätigung ab.',
            ],
            [
                'title' => 'Verein kontrollieren',
                'body' => 'Prüfe nach der Rückkehr den Vereinsnamen und den Bereich. Mitgliederportal und Verwaltung bieten je nach Rolle unterschiedliche Menüs.',
            ],
            [
                'title' => 'Fehler eingrenzen',
                'body' => 'Notiere Zeitpunkt, Umgebung und ob der Fehler vor oder nach Keycloak erscheint. Lasse fehlende Vereinsrechte von der Geschäftsstelle prüfen.',
            ],
        ],
        'notes' => [
            'HTTP 403 kann fehlende Vereins- oder Bereichsrechte bedeuten. Wiederholtes Anmelden vergibt keine Rechte.',
            'Passwörter, Einmalcodes und Wiederherstellungscodes niemals in eine Supportanfrage kopieren.',
        ],
    ],
    'expense' => [
        'title' => 'Spesen im Web erfassen und prüfen',
        'intro' => 'Erfasse Beleg und Positionen und reiche die Abrechnung nach der Kontrolle ein.',
        'steps' => [
            [
                'title' => 'Spesen öffnen',
                'body' => 'Öffne Spesenbelege im Portal oder den freigegebenen Verwaltungsbereich. Suche und Statusfilter helfen beim Finden bestehender Belege.',
            ],
            [
                'title' => 'Beleg erfassen',
                'body' => 'Lege einen Beleg an. Ergänze die angebotenen Angaben wie Mannschaft, Abrechnungsrunde und Bankkonto. Füge Positionen mit Datum, Art und Betrag hinzu.',
            ],
            [
                'title' => 'Belege kontrollieren',
                'body' => 'Ergänze geforderte Nachweise im Positionsformular. Öffne die gespeicherten Dateien und prüfe Lesbarkeit, Betrag und Zuordnung.',
            ],
            [
                'title' => 'Status ändern',
                'body' => 'Wähle bei einem bearbeitbaren Beleg den zulässigen neuen Status und ergänze den erforderlichen Prüfvermerk. Kontrolliere danach den gespeicherten Status.',
            ],
        ],
        'notes' => [
            'Statuswechsel hängen von Rolle, Modulprofil und Belegstatus ab. Eine Freigabe ist nur mit passender Berechtigung möglich.',
            'Eine freigegebene Abrechnung ist kein Nachweis einer ausgeführten Bankzahlung.',
        ],
    ],
    'profile' => [
        'title' => 'Persönliches Profil und Kontosicherheit',
        'intro' => 'Persönliches Konto, Mitgliederdaten und Gerätefunktionen haben getrennte Einstellungen.',
        'steps' => [
            [
                'title' => 'Profil öffnen',
                'body' => 'Öffne dein persönliches Profil und kontrolliere Anzeigename und Benutzername. Prüfe, ob du mit dem beabsichtigten Konto arbeitest.',
            ],
            [
                'title' => 'Sicherheit verwalten',
                'body' => 'Öffne den angebotenen Link zur Keycloak-Kontoverwaltung. Dort verwaltest du Passwort, Anmeldemethoden und Gerätesitzungen in einem separaten Tab.',
            ],
            [
                'title' => 'Mitgliederdaten ändern',
                'body' => 'Für Adresse oder Kontaktinformationen öffne den berechtigten Mitgliedereintrag und nutze den vorgesehenen Änderungsantrag.',
            ],
            [
                'title' => 'Sitzungen prüfen',
                'body' => 'Melde nicht mehr verwendete Sitzungen in der Kontoverwaltung ab. Melde dich nach der Arbeit an gemeinsam genutzten Browsern ab.',
            ],
        ],
        'notes' => [
            'Face ID, gespeicherte App-Profile und der Push-Schalter gehören zur App. Das Webprofil aktiviert diese Funktionen nicht auf einem Telefon.',
        ],
    ],
    'tenants' => [
        'title' => 'Im richtigen Verein arbeiten',
        'intro' => 'Prüfe den aktiven Verein vor jeder fachlichen Änderung.',
        'steps' => [
            [
                'title' => 'Verein prüfen',
                'body' => 'Kontrolliere Vereinsname und Webadresse im Kopfbereich oder in der Navigation. Prüfe bei Testumgebungen auch DEV beziehungsweise Stage.',
            ],
            [
                'title' => 'Verein wechseln',
                'body' => 'Ist dein Konto mehreren Vereinen zugeordnet und eine Vereinswahl vorhanden, wähle dort den gewünschten Verein.',
            ],
            [
                'title' => 'Modul neu öffnen',
                'body' => 'Öffne nach dem Wechsel das Modul neu und prüfe Mannschaft, Saison und Datensatz. Verwende keine alten Formulare eines anderen Vereins weiter.',
            ],
            [
                'title' => 'Fehlenden Verein melden',
                'body' => 'Fehlt eine erwartete Zuordnung, bitte die zuständige Geschäftsstelle um Prüfung deines persönlichen Zugangs.',
            ],
        ],
        'notes' => [
            'Eine kundeneigene Vereinsdomain kann auf ihren Verein begrenzt sein. Dort muss keine freie Vereinswahl angeboten werden.',
            'Menüsichtbarkeit ersetzt keine serverseitige Berechtigungsprüfung.',
        ],
    ],
    'chat' => [
        'title' => 'Nachrichten im Web verwenden',
        'intro' => 'Der Webchat verwendet dieselben Konversationen und Kontaktregeln wie die App.',
        'steps' => [
            [
                'title' => 'Konversation wählen',
                'body' => 'Öffne Chat in der Webverwaltung. Wähle eine vorhandene Konversation oder nutze die angebotene Suche und Kontaktwahl.',
            ],
            [
                'title' => 'Empfänger kontrollieren',
                'body' => 'Prüfe Verein und Empfänger. Die Kontaktwahl bietet nur Personen an, denen dein Konto nach den Vereins- und Mannschaftsregeln schreiben darf.',
            ],
            [
                'title' => 'Nachricht zusammenstellen',
                'body' => 'Schreibe den Text und ergänze über die angebotenen Aktionen beispielsweise einen Anhang, eine Abstimmung oder einen freigegebenen Teamtermin. Prüfe alles vor dem Senden.',
            ],
            [
                'title' => 'Verlauf prüfen',
                'body' => 'Nutze die vorhandenen Nachrichtenaktionen für Antworten, Reaktionen und erlaubte Änderungen. Prüfe nach einem Netzwerkfehler zuerst den Verlauf, bevor du erneut sendest.',
            ],
        ],
        'notes' => [
            'Eltern-, Spieler- und Trainerkonten haben unterschiedliche Kontaktmöglichkeiten. Eine Hilfeanleitung erweitert diese Rechte nicht.',
            'Prüfe, ob Bilder und Dateien für alle Empfänger bestimmt sind.',
        ],
    ],
    'translation' => [
        'title' => 'Nachrichtenübersetzung im Web einstellen',
        'intro' => 'Oberflächensprache und Übersetzung einzelner Nachrichten sind getrennt.',
        'steps' => [
            [
                'title' => 'Chat-Einstellungen öffnen',
                'body' => 'Öffne die Einstellungen des Webchats und suche den Schalter zur Nachrichtenübersetzung.',
            ],
            [
                'title' => 'Zielsprache wählen',
                'body' => 'Aktiviere die Funktion bei Bedarf und wähle eine vom Backend freigegebene Zielsprache. Die Liste kann mehr Sprachen enthalten als die Oberfläche.',
            ],
            [
                'title' => 'Datenschutz bestätigen',
                'body' => 'Lies vor einer Übersetzungsanfrage den angezeigten Datenschutzhinweis. Bestätige die Verarbeitung nur, wenn du die Übersetzung anfordern möchtest.',
            ],
            [
                'title' => 'Original vergleichen',
                'body' => 'Vergleiche wichtige Inhalte mit dem Original. Schalte die Funktion in den Chat-Einstellungen wieder aus, wenn du sie nicht verwenden möchtest.',
            ],
        ],
        'notes' => [
            'Chatübersetzungen werden durch OpenAI verarbeitet. Die Oberfläche verwendet gepflegte lokale Texte und wird nicht bei jedem Aufruf per KI übersetzt.',
            'Eine Übersetzung kann Fehler enthalten und verändert die ursprüngliche Nachricht nicht.',
        ],
    ],
    'planning' => [
        'title' => 'Teamtermine und Rückmeldungen im Web',
        'intro' => 'Verwende Teamplanung für Termine, Kalender und Rückmeldungen.',
        'steps' => [
            [
                'title' => 'Mannschaft und Zeitraum wählen',
                'body' => 'Öffne Teamplanung und stelle die passende Mannschaft sowie die angebotenen Datums- und Statusfilter ein.',
            ],
            [
                'title' => 'Termin öffnen oder erfassen',
                'body' => 'Öffne einen bestehenden Termin oder die angebotene Neuanlage. Kontrolliere Datum, Beginn, Dauer, Ort und Mannschaft.',
            ],
            [
                'title' => 'Teilnehmende kontrollieren',
                'body' => 'Prüfe in der Terminansicht Rückmeldungen, Gäste und zusätzliche Einladungen. Bearbeite nur Personen innerhalb deiner Zuständigkeit.',
            ],
            [
                'title' => 'Serienänderungen prüfen',
                'body' => 'Verwende für wiederkehrende Termine den Serienablauf und kontrolliere dessen Vorschau, bevor du mehrere Termine gemeinsam änderst.',
            ],
        ],
        'notes' => [
            'Im Trainer-Webportal begrenzt die aktuelle Vereinssaison die sichtbaren Mannschaften und Termine. Bei leeren Listen zuerst Saison und Filter prüfen.',
            'Ein Kalendertermin ist kein Nachweis einer genehmigten Platzreservierung.',
        ],
    ],
    'teams' => [
        'title' => 'Mannschaften, Staff und Trainingszeiten verwalten',
        'intro' => 'Mannschaftsansichten richten sich nach Rolle, Saison und Zuordnung.',
        'steps' => [
            [
                'title' => 'Mannschaft öffnen',
                'body' => 'Öffne den Mannschaftsbereich und wähle die passende Saisonmannschaft. Kontrolliere Saison und Bezeichnung.',
            ],
            [
                'title' => 'Spieler und Staff prüfen',
                'body' => 'Kontrolliere die sichtbaren Zuordnungen. Öffne Mitgliederdetails nur innerhalb deiner Verwaltungs- oder Trainerrechte.',
            ],
            [
                'title' => 'Trainingszeiten prüfen',
                'body' => 'Prüfe Trainingstag, Beginn, Ende und Anlage sowie die Zuordnung zum Sommer- oder Winterplan.',
            ],
            [
                'title' => 'Änderungen erfassen',
                'body' => 'Nutze die angebotenen Zuordnungsaktionen. Änderungen an persönlichen Mitgliederdaten laufen über den vorgesehenen Änderungsantrag.',
            ],
        ],
        'notes' => [
            'Die App bietet dafür My Teams. Elternzugriff auf das Team eines Kindes erlaubt keine Einsicht in persönliche Daten anderer Spieler.',
            'Das Trainer-Webportal schliesst historische oder zukünftige Zuordnungen ausserhalb der aktuellen Vereinssaison aus.',
        ],
    ],
    'members' => [
        'title' => 'Mitgliederdaten und Änderungsanträge im Web',
        'intro' => 'Eigene Daten, Kinder und administrative Mitgliedersichten haben unterschiedliche Berechtigungen.',
        'steps' => [
            [
                'title' => 'Mitglied suchen',
                'body' => 'Öffne Mitglieder im erlaubten Portal- oder Verwaltungsbereich. Suche den gewünschten Eintrag und prüfe die richtige Person.',
            ],
            [
                'title' => 'Details prüfen',
                'body' => 'Kontrolliere Stammdaten, Adresse und Kommunikation. Rechnungen, Dokumente und Ausweise benötigen zusätzliche eigene Freigaben.',
            ],
            [
                'title' => 'Änderung beantragen',
                'body' => 'Nutze die angebotene Änderungsfunktion, vervollständige die Angaben und reiche den Antrag ein. Verfolge seinen Status statt denselben Antrag mehrfach anzulegen.',
            ],
            [
                'title' => 'Elternzugriff klären',
                'body' => 'Fehlt ein Kind, lasse die hinterlegte Elternadresse und die bestätigte Kontoverknüpfung prüfen. Volljährige benötigen eine ausdrückliche Freigabe.',
            ],
        ],
        'notes' => [
            'Trainer erhalten Details nur innerhalb ihrer berechtigten Mannschaften. Eltern sehen keine persönlichen Daten anderer Spieler aus dem Kinderteam.',
            'Eine passende E-Mail allein hebt eine manuelle Sperre der Elternverknüpfung nicht auf.',
        ],
    ],
    'membership' => [
        'title' => 'Mitgliedschaftsanmeldungen prüfen',
        'intro' => 'Öffentliche Vereinsanmeldung und administrative Bearbeitung sind getrennte Abläufe.',
        'steps' => [
            [
                'title' => 'Vereinsformular verwenden',
                'body' => 'Nutze für eine Anmeldung den veröffentlichten Link des richtigen Vereins. Prüfe den Vereinsnamen vor der Eingabe persönlicher Daten.',
            ],
            [
                'title' => 'Pflichtangaben ergänzen',
                'body' => 'Erfasse Adresse, Kontakte und Rechnungsangaben sowie geforderte Anhänge. Die Pflicht-E-Mail hängt auch vom gewählten Rechnungsempfänger ab.',
            ],
            [
                'title' => 'Anmeldung prüfen',
                'body' => 'Berechtigte Personen öffnen Anmeldungen und die Anfrage. Kontrolliere Unterlagen, Status und Mannschaftszuordnung.',
            ],
            [
                'title' => 'Entscheidung speichern',
                'body' => 'Wähle den zulässigen Status und ergänze die geforderte Begründung. Prüfe anschliessend Entscheidung und gegebenenfalls entstandene Mitgliedszuordnung.',
            ],
        ],
        'notes' => [
            'Eine Anfrage ist noch keine Aufnahme. Fotos und Ausweise gehören in die geschützten Uploadfelder, nicht in den Supportfreitext.',
            'Akzeptierte Probetrainingsanmeldungen können aus App-Teamdetails verschwinden und bleiben unter Anmeldungen nachvollziehbar.',
        ],
    ],
    'wallet' => [
        'title' => 'Mitgliederausweis und Wallet prüfen',
        'intro' => 'Der Ausweis verwendet Vereinslogo und Vereinsfarben. Die QR-Prüfung zeigt den aktuellen Status.',
        'steps' => [
            [
                'title' => 'Ausweis öffnen',
                'body' => 'Öffne den freigegebenen Mitgliederausweis bei den berechtigten Mitgliederdaten. Prüfe Name, Verein und Mitgliedsstatus.',
            ],
            [
                'title' => 'QR-Code prüfen',
                'body' => 'Scanne den Ausweiscode mit einem anderen Gerät. Prüfe auf der geöffneten Webseite Verein und aktuelle Gültigkeit.',
            ],
            [
                'title' => 'Wallet in der App nutzen',
                'body' => 'Zum Speichern auf dem Telefon öffne den Ausweis in der ClubUnio-App. Nutze die angebotene Apple- oder Google-Wallet-Aktion, sofern sie eingerichtet ist.',
            ],
            [
                'title' => 'Abweichungen melden',
                'body' => 'Melde falschen Status oder fehlende Vereinsgestaltung mit Mitgliedsreferenz und Umgebung an die Geschäftsstelle. Veröffentliche keine ungeschützten Ausweiskopien.',
            ],
        ],
        'notes' => [
            'Ein gespeicherter Wallet-Pass ersetzt keine Liveprüfung. Rabatte richten sich zusätzlich nach den Bedingungen des Partners.',
            'Fehlende Wallet-Aktionen können eine unvollständige Provider-, Zertifikats- oder Umgebungseinrichtung bedeuten.',
        ],
    ],
    'events' => [
        'title' => 'Event-QR-Check-in vorbereiten und auswerten',
        'intro' => 'Berechtigte Verantwortliche können Zeitfenster und QR-Plakat eines Events bereitstellen.',
        'steps' => [
            [
                'title' => 'Veranstaltung öffnen',
                'body' => 'Öffne Veranstaltungen und den gewünschten Event. Prüfe Saison, Ort und gegebenenfalls Mannschaft.',
            ],
            [
                'title' => 'Check-in konfigurieren',
                'body' => 'Wenn freigegeben, öffne Check-in konfigurieren. Aktiviere die Funktion und lege Öffnungs- und Schliesszeit fest.',
            ],
            [
                'title' => 'QR-Plakat drucken',
                'body' => 'Drucke das angebotene QR-Plakat für den Eingang. Teste den Link mit einem berechtigten Testkonto, bevor die Veranstaltung beginnt.',
            ],
            [
                'title' => 'Anwesenheit prüfen',
                'body' => 'Mitglieder scannen und bestätigen mit ihrem Konto. Prüfe die Check-in-Liste in den Veranstaltungsdetails und schliesse den Check-in bei Bedarf über die vorgesehene Aktion.',
            ],
        ],
        'notes' => [
            'Scannen allein bestätigt keine Anwesenheit: Anmeldung, Berechtigung, Zeitfenster und Bestätigung werden geprüft.',
            'Ein ersetzter QR-Link macht alte Plakate unbrauchbar. Drucke nach Erneuerung das aktuelle Plakat.',
        ],
    ],
    'reservations' => [
        'title' => 'Busreservierungen und Platzanfragen bearbeiten',
        'intro' => 'Prüfe Zeitraum, Ressource und Status vor jeder Entscheidung.',
        'steps' => [
            [
                'title' => 'Kalender öffnen',
                'body' => 'Öffne Busreservierungen oder Platzbelegung. Wähle Tag beziehungsweise Zeitraum und kontrolliere Fahrzeug oder Spielfeld.',
            ],
            [
                'title' => 'Anfrage erfassen',
                'body' => 'Nutze die angebotene Neuanlage oder markiere im Platzkalender einen Zeitbereich. Prüfe vorgelegtes Datum, Beginn, Dauer und Ressource vor dem Absenden.',
            ],
            [
                'title' => 'Entscheidung prüfen',
                'body' => 'Öffne als zuständige Person die Anfrage. Kontrolliere Konflikte und bestehende Belegung, bevor du die angebotene Genehmigung oder Ablehnung mit Begründung ausführst.',
            ],
            [
                'title' => 'Status kontrollieren',
                'body' => 'Prüfe nach dem Speichern den Status. Nach Änderungen an Zeitraum oder Zuteilung kontrolliere erneut, ob eine Genehmigung erforderlich ist.',
            ],
        ],
        'notes' => [
            'Eine eingereichte Anfrage ist erst nach der erforderlichen Entscheidung bestätigt.',
            'Aktuelle Platzsperre und Genehmigung einer zukünftigen Anfrage sind getrennte Fachentscheidungen. Verwende nur die angebotenen berechtigten Aktionen.',
        ],
    ],
    'documents' => [
        'title' => 'Dokumente und Rechnungen sicher öffnen',
        'intro' => 'Dokumentrechte werden zusätzlich zum Zugriff auf einen Mitgliedereintrag geprüft.',
        'steps' => [
            [
                'title' => 'Bereich wählen',
                'body' => 'Öffne Dokumente oder den Dokument- beziehungsweise Rechnungsbereich des berechtigten Mitglieds.',
            ],
            [
                'title' => 'Zuordnung kontrollieren',
                'body' => 'Prüfe Dokumentart, Mitglied, Datum und Status. Öffne nur Dateien, für die dein Konto eine Downloadfreigabe besitzt.',
            ],
            [
                'title' => 'Datei öffnen',
                'body' => 'Verwende Vorschau oder Download. Ein externes Rechnungs-PDF benötigt zusätzlich die Erreichbarkeit des eingerichteten Rechnungsdienstes.',
            ],
            [
                'title' => 'Ladefehler melden',
                'body' => 'Prüfe die Verbindung und wiederhole den Aufruf einmal. Melde wiederkehrende Fehler mit Uhrzeit, Dokumentart und Referenz statt vertrauliche Anhänge ungeprüft weiterzuleiten.',
            ],
        ],
        'notes' => [
            'Ein Link ist keine öffentliche Freigabe. Private Dokumente benötigen beim erneuten Öffnen weiterhin eine berechtigte Sitzung.',
            'Zurückgezogene oder veraltete Dokumente können trotz sichtbarer Referenz nicht mehr abrufbar sein.',
        ],
    ],
    'keyboxes' => [
        'title' => 'Persönliche Schlüsselboxen verwalten',
        'intro' => 'Echte Igloohome-Zugänge und Schlüsselbox-Testzuordnungen sind getrennte Bereiche.',
        'steps' => [
            [
                'title' => 'Box auswählen',
                'body' => 'Öffne als berechtigte Administration Persönliche Schlüsselboxen. Prüfe Verein und Box; unterscheide Garderobe, Ballhaus und eine gegebenenfalls zugeordnete Busbox.',
            ],
            [
                'title' => 'Zuordnung pflegen',
                'body' => 'Prüfe Name und optionale Buszuordnung. Pflege Provider-Gerätezuordnung ausschliesslich im geschützten Feld und ergänze die erforderliche Begründung.',
            ],
            [
                'title' => 'Freigabe kontrollieren',
                'body' => 'Prüfe persönliche Zuordnungen, Aktivierung und vollständige Provider-Einrichtung. Eine gespeicherte Box allein erlaubt noch keine erfolgreiche Codeausgabe.',
            ],
            [
                'title' => 'Notfall und Widerruf prüfen',
                'body' => 'Pflege Notfallzugang nur über die angebotenen geschützten Felder. Prüfe bei Codeänderungen auch notwendige physische Massnahmen; unklare Ausgabe nicht blind wiederholen.',
            ],
        ],
        'notes' => [
            'Notfallzugang benötigt separate Einrichtung und Ausfallbedingungen. Eine Schlüsselbox-Testzuordnung simuliert nur; sie öffnet keine physische Box.',
            'Providerdaten und Notfall-PINs nie in Screenshots, Videos oder Supporttexte übernehmen. Ausblenden widerruft keinen bekannten physischen Code.',
        ],
    ],
    'notifications' => [
        'title' => 'Posteingang im Browser verwenden',
        'intro' => 'Mitteilungen verweisen auf Fachobjekte; deren Zugriff wird beim Öffnen erneut geprüft.',
        'steps' => [
            [
                'title' => 'Posteingang öffnen',
                'body' => 'Öffne die angebotene Glocke oder den Posteingangsbereich des Portals.',
            ],
            [
                'title' => 'Mitteilung auswählen',
                'body' => 'Prüfe Betreff und Datum und öffne die gewünschte Mitteilung. Kontrolliere beim Zielobjekt Verein und aktuellen Status.',
            ],
            [
                'title' => 'Gelesenstatus ändern',
                'body' => 'Verwende bei Bedarf Alle gelesen. Warte auf die Bestätigung und kontrolliere den Zähler statt mehrfach zu klicken.',
            ],
            [
                'title' => 'Fehlendes Ziel klären',
                'body' => 'Prüfe, ob das Objekt noch besteht und deine Rolle weiterhin Zugriff erlaubt. Melde wiederkehrende Fehler mit Mitteilungsreferenz und Zeitpunkt.',
            ],
        ],
        'notes' => [
            'Eine Mitteilung vergibt keine zusätzlichen Rechte auf Mitgliederdaten, Rechnungen oder Mannschaften.',
            'Lesen im Browser und Push-Zustellung auf einem Telefon sind getrennte Vorgänge.',
        ],
    ],
    'push' => [
        'title' => 'Push-Probleme im Vereinsbetrieb eingrenzen',
        'intro' => 'Push betrifft die mobile App. Eine Websitzung registriert kein Telefon für App-Push.',
        'steps' => [
            [
                'title' => 'Gerät und Build klären',
                'body' => 'Erfrage, ob ClubUnio oder ein Vereinsbuild betroffen ist, welche App-Version verwendet wird und welche Umgebung ausgewählt ist.',
            ],
            [
                'title' => 'Gerätefreigabe prüfen',
                'body' => 'Bitte die Person, den Mitteilungsschalter im App-Profil sowie die Benachrichtigungsfreigabe in den Systemeinstellungen des Telefons zu prüfen.',
            ],
            [
                'title' => 'Einrichtungsfehler abgrenzen',
                'body' => 'Bei mehreren betroffenen Geräten lasse die Administration die Push-Einrichtung von Umgebung und Build kontrollieren. Eine gesendete Nachricht bestätigt keine Push-Zustellung.',
            ],
            [
                'title' => 'Verzögerung dokumentieren',
                'body' => 'Notiere Versand- und Empfangszeit, Umgebung und App-Version. Melde wiederholte Probleme ohne private Nachrichteninhalte oder Gerätetokens.',
            ],
        ],
        'notes' => [
            'Fokusmodus, Verbindung und Betriebssystem beeinflussen die Anzeige.',
            'Push benötigt eingerichtete Provider, passenden Build und gültige Gerätesitzung.',
        ],
    ],
    'diagnostics' => [
        'title' => 'Eine hilfreiche Supportanfrage erstellen',
        'intro' => 'Beschreibe das Problem nachvollziehbar. Support ersetzt keine fachliche Genehmigung.',
        'steps' => [
            [
                'title' => 'Fehler beschreiben',
                'body' => 'Notiere Verein, Modul, Zeitpunkt und letzte Aktion. Trenne erwartetes Verhalten von der tatsächlichen Meldung.',
            ],
            [
                'title' => 'Hilfe durchsuchen',
                'body' => 'Suche in Hilfe und Support nach Aufgabe oder Fehlermeldung. Prüfe, ob die Anleitung für App oder Web geschrieben ist.',
            ],
            [
                'title' => 'Ticket eröffnen',
                'body' => 'Erstelle bei weiterhin bestehendem Problem eine Anfrage mit aussagekräftigem Betreff und nachvollziehbaren Schritten. Prüfe erforderliche Angaben und Anhänge vor dem Freigeben.',
            ],
            [
                'title' => 'Im Ticket nachverfolgen',
                'body' => 'Nutze dasselbe Ticket für Antworten und Ergänzungen statt mehrere Anfragen für einen Vorfall anzulegen. Schliesse es nach geprüfter Lösung.',
            ],
        ],
        'notes' => [
            'Keine Passwörter, Einmalcodes, PINs, Bankzugänge oder ungeprüften Ausweiskopien übermitteln.',
            'Die App bietet zusätzlich einen ausdrücklich vorbereiteten Diagnose-Mailentwurf. Dieser Geräteanhang entsteht nicht automatisch aus einer Websitzung.',
        ],
    ],
];
