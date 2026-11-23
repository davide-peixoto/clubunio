<?php

// Public app instructions. No private account or club data.
return [
    'login' => [
        'title' => 'In der App anmelden und Biometrie nutzen',
        'intro' => 'Verwende dein persönliches ClubUnio-Konto. Vereins-App und ClubUnio-App verwenden denselben persönlichen Anmeldeablauf.',
        'steps' => [
            ['title' => 'Umgebung prüfen', 'body' => 'Prüfe vor dem Login die ausgewählte Umgebung. Das Zahnrad öffnet die angebotene Auswahl. DEV und Stage sind Testumgebungen mit getrennten Sitzungen von PROD.'],
            ['title' => 'Anmelden', 'body' => 'Tippe auf Anmelden, schließe die Anmeldung im Keycloak-Fenster ab und kehre zur App zurück. Prüfe danach Konto und Vereinsname im Kopf.'],
            ['title' => 'Biometrie aktivieren', 'body' => 'Öffne Profil und aktiviere Biometrische Anmeldung, wenn du die gespeicherte persönliche Sitzung mit der Gerätebiometrie öffnen möchtest. Bestätige die Systemabfrage und prüfe den Schalterstatus.'],
            ['title' => 'Fehler nachvollziehen', 'body' => 'Bei einem Fehler notiere Umgebung, Zeitpunkt und sichtbaren Diagnosecode. Versuche nach Prüfung der Verbindung die Anmeldung erneut oder öffne den angebotenen Support.'],
        ],
        'notes' => ['Biometrie ersetzt keine gültige Serversitzung. Eine abgelaufene oder widerrufene Sitzung erfordert eine neue Anmeldung.', 'Teile keine Passwörter oder Wiederherstellungscodes.'],
    ],
    'expense' => [
        'title' => 'Spesenbeleg erfassen und einreichen',
        'intro' => 'Erfasse den Beleg im ausgewählten Verein und ergänze seine Positionen und Nachweise.',
        'steps' => [
            ['title' => 'Spesen öffnen', 'body' => 'Öffne Spesen über die untere Navigation oder das Modulmenü. Prüfe Saison und vorhandene Belege, bevor du einen neuen Beleg anlegst.'],
            ['title' => 'Beleg anlegen', 'body' => 'Starte die Neuerfassung, fülle die angezeigten Pflichtfelder aus und wähle die passenden verfügbaren Zuordnungen. Speichere den Beleg.'],
            ['title' => 'Position und Nachweis ergänzen', 'body' => 'Öffne den Beleg und füge eine Position hinzu. Erfasse Betrag und verlangte Angaben. Wähle den Nachweis über die angebotene Kamera- oder Dateiauswahl und kontrolliere die Vorschau.'],
            ['title' => 'Übertragung und Einreichung prüfen', 'body' => 'Kontrolliere Positionen und Übertragungsstatus der Anhänge. Nutze die angebotene Statusaktion zum Einreichen und ergänze eine verlangte Begründung. Prüfe anschließend den bestätigten Status im Detail.'],
        ],
        'notes' => ['Ein gespeicherter Entwurf ist noch keine Freigabe oder Auszahlung. Aktionen hängen von Rolle, Belegstatus und Vereinsfreigabe ab.', 'Bei unklarem Speicherergebnis zuerst den vorhandenen Beleg öffnen, statt denselben Beleg nochmals neu anzulegen.'],
    ],
    'profile' => [
        'title' => 'Profil, App-Sprache und gespeicherte Logins verwalten',
        'intro' => 'Im Profil verwaltest du persönliche Einstellungen und Gerätesitzungen. Die App-Sprache ist von der Nachrichtenübersetzung getrennt.',
        'steps' => [
            ['title' => 'Profil öffnen', 'body' => 'Tippe auf das Profilsymbol im oberen Menü. Kontrolliere das angezeigte Konto vor einer persönlichen Änderung.'],
            ['title' => 'App-Sprache wählen', 'body' => 'Öffne App-Sprache und wähle eine vom Backend freigegebene Sprache. Die Auswahl stellt die Oberfläche um; Chatnachrichten werden dadurch nicht automatisch übersetzt.'],
            ['title' => 'Weiteren Login verwalten', 'body' => 'Unter Profile / Logins kannst du einen Login hinzufügen oder zu einem gespeicherten Konto wechseln. Prüfe nach dem Wechsel Konto und Verein.'],
            ['title' => 'Gerätesitzungen prüfen', 'body' => 'Prüfe Angemeldete Geräte. Widerrufe eine nicht mehr benötigte andere Gerätesitzung bewusst. Für die Abmeldung auf diesem Gerät verwende die Abmeldeaktion im Kopfmenü.'],
        ],
        'notes' => ['Kontowechsel und Vereinswechsel sind unterschiedliche Aktionen.', 'Blockierte Chatkontakte verwaltet private Kontaktblockierungen und vergibt keine Team- oder Mitgliederrechte.'],
    ],
    'tenants' => [
        'title' => 'Den richtigen Verein auswählen',
        'intro' => 'Die ClubUnio-App unterstützt mehrere berechtigte Vereine. Vereins-Apps bleiben an ihren eigenen Verein gebunden.',
        'steps' => [
            ['title' => 'Aktiven Verein erkennen', 'body' => 'Lies den Vereinsnamen im Kopf, besonders vor Änderungen an Spesen, Mitgliedern oder Reservierungen.'],
            ['title' => 'Vereinsauswahl öffnen', 'body' => 'Tippe in der ClubUnio-App auf die Vereinsauswahl im Kopf, wenn mehrere Vereine verfügbar sind. Angeboten werden nur deine freigegebenen Vereine.'],
            ['title' => 'Zielverein wählen', 'body' => 'Wähle den Zielverein und warte auf dessen geladenen Kontext. Öffne das gewünschte Modul neu und kontrolliere die Datenzuordnung.'],
            ['title' => 'Benachrichtigungsziel kontrollieren', 'body' => 'Beim Öffnen einer Benachrichtigung werden Zielverein und Rechte frisch geprüft. Bestätige einen angebotenen Wechsel bewusst und prüfe den Vereinsnamen im geöffneten Objekt.'],
        ],
        'notes' => ['Custom Builds bieten keinen Wechsel in andere Vereine. Nutze dafür die ClubUnio-App mit entsprechender Berechtigung.', 'DEV-, Stage- oder PROD-Auswahl wechselt die Serverumgebung und ist kein Vereinswechsel.'],
    ],
    'chat' => [
        'title' => 'Nachrichten, Bilder, Termine und Reaktionen verwenden',
        'intro' => 'Der mittlere Chatbutton öffnet berechtigte Unterhaltungen. Gruppenchats und persönliche Nachrichten sind getrennt.',
        'steps' => [
            ['title' => 'Unterhaltung finden', 'body' => 'Öffne Chats und wähle Gruppenchats oder Nachrichten. Suche nach einem geladenen Chatnamen. Vereinsankündigungen bleiben oben angeheftet; die Listensuche durchsucht keine Nachrichtentexte.'],
            ['title' => 'Nachricht vorbereiten', 'body' => 'Öffne eine Unterhaltung oder starte über den Stift eine Direktnachricht und suche einen erlaubten Kontakt. Schreibe den Text. Über Plus neben dem Eingabefeld kannst du angebotene Bilder, Dateien oder einen Teamtermin ergänzen.'],
            ['title' => 'Senden und Status beachten', 'body' => 'Prüfe Text und vorbereitete Anhänge und tippe auf Senden. Du kannst weiterschreiben, während die vorherige Nachricht gespeichert wird. Achte auf den angezeigten Speicher- oder Fehlerstatus.'],
            ['title' => 'Antworten und reagieren', 'body' => 'Wische auf einer Nachricht, um sie als Antwortbezug auszuwählen. Halte eine gespeicherte Nachricht länger gedrückt, um verfügbare Aktionen und Reaktionen zu öffnen. Tippe auf vorhandene Reaktionen, um ihre Urheber zu sehen.'],
            ['title' => 'Lesestand prüfen', 'body' => 'Halte eine eigene gespeicherte Nachricht länger gedrückt und wähle Gelesen von, wenn angeboten. Diese Liste wird erst auf Abruf geladen.'],
        ],
        'notes' => ['Direktkontakte richten sich nach Rolle und Mannschaft: Eltern erhalten insbesondere Staffkontakte, Spieler berechtigte Teamkontakte; Trainer und Verwaltung erhalten ihre jeweils weitergehenden freigegebenen Kontakte.', 'Gelesen von zeigt den Lesestand der Unterhaltung, keinen Beweis des tatsächlichen Betrachtens. Bei Timeout zuerst den bestehenden Sendestatus prüfen statt denselben Text neu zu senden.'],
    ],
    'translation' => [
        'title' => 'Chatnachrichten gezielt übersetzen',
        'intro' => 'Nachrichtenübersetzung ist optional und von der App-Sprache getrennt. Aktivierung übersetzt keine Nachricht automatisch.',
        'steps' => [
            ['title' => 'Chat-Einstellungen öffnen', 'body' => 'Öffne die Einstellungen der Chatübersicht und suche Nachrichten übersetzen. Lies den Datenschutzhinweis zur Verarbeitung durch OpenAI.'],
            ['title' => 'Zielsprache festlegen', 'body' => 'Aktiviere die Funktion bewusst und wähle eine angebotene Zielsprache. Die zentral freigegebenen Ziele können sich von den App-Sprachen unterscheiden. Speichere die Einstellung.'],
            ['title' => 'Einzelne Nachricht übersetzen', 'body' => 'Öffne die Unterhaltung und wähle bei der gewünschten Nachricht Übersetzen. Prüfe die Bestätigung zur externen Verarbeitung und bestätige nur, wenn du einverstanden bist.'],
            ['title' => 'Mit Original vergleichen', 'body' => 'Lies die Übersetzung und behalte das Original als maßgebliche Nachricht. Deaktiviere die Funktion in den Chat-Einstellungen, wenn du sie nicht mehr verwenden möchtest.'],
        ],
        'notes' => ['Übersetzungen können Fehler enthalten. Prüfe vertrauliche Angaben bewusst vor externer Verarbeitung.', 'Deaktivierte Übersetzung, gesperrte Sprache oder ausgeschöpftes Budget können die Aktion verhindern. Die Oberfläche verwendet lokale Sprachkataloge, nicht diese Funktion.'],
    ],
    'planning' => [
        'title' => 'Teamtermine finden und Teilnahme melden',
        'intro' => 'Die Teamplanung zeigt freigegebene Termine und Rückmeldungen. Bei Familienkonten wählst du bewusst die richtige Person.',
        'steps' => [
            ['title' => 'Mannschaft und Zeitraum wählen', 'body' => 'Öffne Termine beziehungsweise Teamplanung. Wähle die gewünschte Mannschaft oder die angebotene Gesamtansicht und nutze Kalender oder Liste.'],
            ['title' => 'Termin prüfen', 'body' => 'Öffne den Termin und kontrolliere Datum, Beginn, Mannschaft und Ort. Ältere Termine oder weitere Wochen lädst du über die angebotenen Aktionen.'],
            ['title' => 'Rückmeldung erfassen', 'body' => 'Wähle den eigenen oder berechtigt vertretenen Spieler und nutze die angebotene Zu- oder Absage. Kontrolliere anschließend die gespeicherte Rückmeldung.'],
            ['title' => 'Traineraktionen verwenden', 'body' => 'Berechtigte Trainer finden Bearbeiten, Spieler / Gast hinzufügen sowie Absage- oder Löschaktionen. Prüfe vor einer Änderung deren Umfang und bestätige bewusst.'],
        ],
        'notes' => ['Eine Chatabstimmung ersetzt keine Teilnahmemeldung. Anwesenheit kann erst nach den geltenden Terminregeln bestätigt werden.', 'Bei fehlenden Terminen zuerst Mannschaft, Zeitraum, Verein und Berechtigung prüfen.'],
    ],
    'teams' => [
        'title' => 'My Teams und berechtigte Teamdetails nutzen',
        'intro' => 'My Teams bündelt deine Mannschaften, Spieler, Staff und gültigen Trainingszeiten.',
        'steps' => [
            ['title' => 'Mannschaft öffnen', 'body' => 'Öffne My Teams aus dem Modulmenü und wähle die Mannschaft. Prüfe Saison und Teamname in den Details.'],
            ['title' => 'Spieler und Staff prüfen', 'body' => 'Lies die verfügbaren Einträge. Eltern sehen bei anderen Spielern nur Namen und beim Staff die freigegebenen Kontaktdaten.'],
            ['title' => 'Änderung als Trainer beantragen', 'body' => 'Öffne als Trainer deiner Mannschaft ein berechtigtes Mitglied und wähle Änderung beantragen, wenn angeboten. Korrigiere zulässige Angaben und sende den Antrag zur Prüfung.'],
            ['title' => 'Trainingszeiten und Anmeldungen ansehen', 'body' => 'Prüfe die aktuell gültigen Trainingszeiten und öffne verfügbare Probetrainingsanfragen. Akzeptierte Anmeldungen werden in diesen Teamdetails nicht nochmals aufgeführt.'],
        ],
        'notes' => ['Trainerrechte gelten für freigegebene eigene Teams, nicht pauschal für alle Mitglieder oder Familienrechnungen.', 'Das Ausblenden akzeptierter Probetrainingsanfragen verändert das getrennte Modul Anmeldungen nicht.'],
    ],
    'members' => [
        'title' => 'Eigene Mitgliederdaten und Kinder öffnen',
        'intro' => 'Mitglieder zeigt persönliche, vertretene oder administrativ freigegebene Datensätze. Jedes Dossier wird erneut autorisiert.',
        'steps' => [
            ['title' => 'Mitglied öffnen', 'body' => 'Tippe unten auf Mitglieder, suche den Eintrag und öffne seine Karte direkt.'],
            ['title' => 'Angaben und Aktionen prüfen', 'body' => 'Prüfe Stammdaten, Adresse und Kommunikation. Nutze angebotene Aktionen für Dokumente, Rechnungen, Mitgliederausweis oder Änderungen.'],
            ['title' => 'Kinderzugriff kontrollieren', 'body' => 'Prüfe, ob deine verifizierte Login-E-Mail als Elternadresse beim Kind hinterlegt ist. Unter 18-Jährige können damit automatisch freigegeben werden, sofern keine manuelle Sperre besteht.'],
            ['title' => 'Erwachsene Elternfreigabe verwalten', 'body' => 'Volljährige Mitglieder benötigen eine ausdrückliche Freigabe. Öffne als berechtigtes Mitglied im Profil Elternfreigabe / Elternlogins und bestätige oder widerrufe den Zugang. Bei unklarer Zuordnung hilft die Geschäftsstelle.'],
        ],
        'notes' => ['Eine gemeinsame E-Mail-Adresse allein gibt keinen Zugriff auf beliebige Dossiers. Ab dem 18. Geburtstag gelten die Erwachsenenregeln.', 'Trainer öffnen Teammitglieder über My Teams. Eltern erhalten dadurch keine fremden Spielerdetails.'],
    ],
    'membership' => [
        'title' => 'Mitgliedschaft oder weiteres Kind anmelden',
        'intro' => 'Eine Mitgliedschaftsanfrage erfasst Angaben und Unterlagen; sie bedeutet noch keine Aufnahme in den Verein.',
        'steps' => [
            ['title' => 'Passenden Einstieg wählen', 'body' => 'Ohne Login bietet eine freigeschaltete Vereins-App Neue Mitgliedschaft auf dem Startbildschirm. Die ClubUnio-Core-App bietet diesen anonymen Einstieg nicht. Mit Elternkonto öffnest du nach dem Login Mitglieder und die angebotene neue Mitgliedschaft.'],
            ['title' => 'Antrag vollständig ausfüllen', 'body' => 'Erfasse Person, Adresse und Kontakte. Wähle den Rechnungsempfänger: Die zugehörige E-Mail muss beim Mitglied, bei der Mutter oder beim Vater ausgefüllt sein. Beachte weitere angezeigte Pflichtfelder.'],
            ['title' => 'Anhänge hinzufügen', 'body' => 'Wähle verlangtes Passfoto, Ausweis und weitere Unterlagen. Prüfe Vorschau und bestätigte Übertragung. Ein deaktivierter Upload darf nicht durch ungesicherten Versand umgangen werden.'],
            ['title' => 'Bestätigen und Status verfolgen', 'body' => 'Schließe die angebotene Konto- und E-Mail-Bestätigung ab. Prüfe die Zusammenfassung und sende den Antrag. Bewahre die bestätigte Referenz auf und verfolge den Status über den angebotenen persönlichen Zugang.'],
        ],
        'notes' => ['Mit bestehendem Elternkonto kein zweites Konto vorsorglich anlegen; nutze den Ablauf für eine weitere Mitgliedschaft.', 'Pflichtfelder, Dateiformate und Bearbeitung können je Verein variieren. Bei fehlender Uploadfreigabe die Geschäftsstelle kontaktieren.'],
    ],
    'wallet' => [
        'title' => 'Mitgliederausweis zeigen und in Wallet speichern',
        'intro' => 'Der Ausweis verwendet Vereinslogo und Vereinsfarben. Sein QR-Code ermöglicht eine aktuelle Gültigkeitsprüfung.',
        'steps' => [
            ['title' => 'Ausweis öffnen', 'body' => 'Öffne unter Mitglieder dich oder ein berechtigt vertretenes Kind und wähle Mitgliederausweis.'],
            ['title' => 'Karte kontrollieren', 'body' => 'Prüfe Verein, Mitglied, Status und Zeitpunkt der letzten Prüfung. Eine alte Bildschirmaufnahme ist kein aktueller Gültigkeitsnachweis.'],
            ['title' => 'Wallet hinzufügen', 'body' => 'Wähle im Exportbereich auf iPhone Zu Apple Wallet hinzufügen oder auf Android Zu Google Wallet hinzufügen, sofern eingerichtet. Schließe den angebotenen Dialog ab und prüfe die gespeicherte Karte.'],
            ['title' => 'Beim Partner prüfen lassen', 'body' => 'Zeige den QR-Code in App oder Wallet. Der Partner kann ihn mit einer Handykamera scannen und die aktuelle Gültigkeit auf der Webseite prüfen. Nutze Export oder Teilen bewusst.'],
        ],
        'notes' => ['Ein geöffneter Wallet-Dialog bestätigt keine Speicherung. Ohne vollständige Einrichtung kann die Aktion deaktiviert sein.', 'Eine gültige Mitgliedschaft garantiert keinen Rabatt. Es gelten die Angebotsbedingungen des Partners; alte Exporte ersetzen die Liveprüfung nicht.'],
    ],
    'events' => [
        'title' => 'Event-QR scannen und Anwesenheit bestätigen',
        'intro' => 'Der Event-QR öffnet den persönlichen Check-in. Scannen allein meldet keine Anwesenheit.',
        'steps' => [
            ['title' => 'QR am Eingang scannen', 'body' => 'Scanne den Event-QR des Vereins mit der Systemkamera und öffne den erkannten Link.'],
            ['title' => 'Konto und Event prüfen', 'body' => 'Melde dich bei Bedarf an. Prüfe Verein, Event, Ort und Zeitfenster. Eine Vereins-App akzeptiert keine fremden Vereinsziele.'],
            ['title' => 'Person bewusst wählen', 'body' => 'Wähle eine angebotene eigene oder berechtigt vertretene Mitgliedsidentität. Prüfe, für wen die Anwesenheit gilt.'],
            ['title' => 'Teilnahme bestätigen', 'body' => 'Tippe ausdrücklich auf Teilnahme bestätigen und warte auf die bestätigte Anzeige. Bei unklarem Netzwerkstatus prüfe die neu geladene Ansicht vor weiteren Aktionen.'],
        ],
        'notes' => ['Ein gesperrter oder abgelaufener Check-in und ein geschlossenes Zeitfenster erlauben keine Bestätigung.', 'Direktes App-Öffnen hängt von Geräte- und Buildverknüpfungen ab. Öffnet der Browser, nutze seinen angebotenen Anmeldeweg.'],
    ],
    'reservations' => [
        'title' => 'Bus oder Spielfeld reservieren',
        'intro' => 'Reservierungen gehören zum ausgewählten Verein. Eine Anfrage und eine bestätigte Belegung sind unterschiedliche Zustände.',
        'steps' => [
            ['title' => 'Modul und Ressource wählen', 'body' => 'Öffne Bus für eine Busreservation oder Platzbelegung für eine Spielfeldanfrage. Wähle Datum und Ressource.'],
            ['title' => 'Zeitraum auswählen', 'body' => 'Wähle einen angebotenen freien Kalenderbereich oder die Neuerfassung. Im unterstützten Belegungskalender ziehst du mehrere Slots für Beginn und Dauer auf.'],
            ['title' => 'Anfrage prüfen und senden', 'body' => 'Prüfe Datum, Beginn, Dauer, Mannschaft, Bus oder Spielfeld und weitere Pflichtangaben. Sende und kontrolliere das bestätigte Speicherergebnis.'],
            ['title' => 'Status verfolgen', 'body' => 'Öffne die Anfrage erneut und prüfe den aktuellen Status. Bearbeiten, Absagen oder Freigeben ist nur bei angebotener Aktion möglich. Beim Buszugang gelten zusätzlich Reservierungs- und Zugangszeitfenster.'],
        ],
        'notes' => ['Eine Anfrage ist nicht automatisch genehmigt. Kollisionen, Sperren und aktuelle Rechte prüft der Server.', 'Bei unklarem Status erneut laden, statt eine zweite Anfrage anzulegen.'],
    ],
    'documents' => [
        'title' => 'Dokumente und Rechnungen lesen',
        'intro' => 'Dokumente werden anhand aktueller Rechte geladen. Persönliche Unterlagen und Familienrechnungen sind nicht für alle Vereinskonten sichtbar.',
        'steps' => [
            ['title' => 'Einstieg wählen', 'body' => 'Öffne Dokumente im Modulmenü oder ein berechtigtes Mitglied mit dessen Dokument- beziehungsweise Rechnungsaktion.'],
            ['title' => 'Datei auswählen', 'body' => 'Prüfe Bezeichnung und Bezug des Eintrags. Öffne die Datei oder die angebotene PDF-Ansicht einer Rechnung.'],
            ['title' => 'Anzeige kontrollieren', 'body' => 'Warte auf die Vorschau und prüfe das Dokument. Verwende angebotene Export- oder Teilenaktionen nur bewusst.'],
            ['title' => 'Ladefehler behandeln', 'body' => 'Prüfe bei Fehlern Verbindung, Konto und Verein und tippe auf Erneut laden. Besteht der Fehler weiter, melde Zeitpunkt und Dokumentart mit den angebotenen Diagnosedaten.'],
        ],
        'notes' => ['Trainerzugriff auf Kontakte gibt keine pauschalen Rechnungs- oder Ausweisrechte.', 'Vertrauliche Unterlagen nicht ungeprüft an externe Kontakte oder Supportnachrichten anhängen.'],
    ],
    'keyboxes' => [
        'title' => 'Schlüsselboxen und Notfallablauf verwenden',
        'intro' => 'Garderobe, Ballhaus und Bus benötigen persönliche Freigaben und vollständige Einrichtung durch den Verein.',
        'steps' => [
            ['title' => 'Berechtigte Box öffnen', 'body' => 'Öffne den angebotenen Schlüsselbox-Einstieg im Profil oder die Box deiner genehmigten Busreservation. Wähle die persönlich freigegebene Box.'],
            ['title' => 'Zugang anfordern', 'body' => 'Prüfe Box und Kontext und starte die angebotene Zugangsaktion bewusst. Warte auf das eindeutige Ergebnis; der Auftrag kann im Hintergrund geprüft werden.'],
            ['title' => 'Code geschützt verwenden', 'body' => 'Verwende einen erfolgreich ausgegebenen Code nur für den vorgesehenen Zugang. Teile ihn nicht im Chat und verlasse die Ansicht nach Nutzung.'],
            ['title' => 'Störung behandeln', 'body' => 'Nach bestätigter Nicht-Erzeugung kann ein neuer bewusster Versuch angeboten werden. Nur nach drei bestätigten Providerausfällen und vorhandener Notfallkonfiguration kann kurz ein Notfallcode erscheinen. Bei ungewissem Ergebnis die Geschäftsstelle kontaktieren.'],
        ],
        'notes' => ['Nach ungewissem PIN-Ergebnis nicht blind wiederholt anfordern. Fehlende Rechte nicht durch andere Boxen umgehen.', 'Ausblenden macht einen bekannten physischen Offlinecode nicht ungültig. Ein Notfallcode ist kein normaler dauerhaft speicherbarer Zugang.'],
    ],
    'notifications' => [
        'title' => 'Posteingang und verknüpfte Objekte öffnen',
        'intro' => 'Die Glocke im oberen Menü öffnet deine Hinweise. Verknüpfte Objekte benötigen weiterhin aktuelle Berechtigungen.',
        'steps' => [
            ['title' => 'Posteingang öffnen', 'body' => 'Tippe auf die Glocke im oberen Menü und prüfe Liste und angezeigten Verein.'],
            ['title' => 'Mitteilung auswählen', 'body' => 'Tippe auf den Hinweis. Die App prüft Ziel und Rechte frisch und öffnet das Objekt. Bestätige einen notwendigen Vereinswechsel bewusst.'],
            ['title' => 'Gelesen-Status verwalten', 'body' => 'Nutze Alle gelesen, um verfügbare Hinweise gemeinsam als gelesen zu markieren. Prüfe danach Liste und Ungelesenzähler.'],
            ['title' => 'Nicht erreichbare Ziele prüfen', 'body' => 'Bei nicht erreichbarem Objekt kontrolliere Verein und Konto und lade erneut. Geänderte Rechte oder ein entferntes Objekt können den Zugriff verhindern.'],
        ],
        'notes' => ['Eine ungelesene Mitteilung gewährt keine zusätzlichen Objektrechte. Vereins-Apps wechseln nicht in fremde Vereine.', 'Posteingang und System-Push sind getrennt: Ein Hinweis kann ohne Pushanzeige vorhanden sein.'],
    ],
    'push' => [
        'title' => 'Push-Mitteilungen einschalten und prüfen',
        'intro' => 'Push benötigt Profilfreigabe, Betriebssystemberechtigung und vollständig eingerichtete App- und Serverumgebung.',
        'steps' => [
            ['title' => 'Profil-Schalter prüfen', 'body' => 'Öffne Profil und suche Mitteilungen einstellen. Beachte Hinweise auf fehlende Verfügbarkeit dieses Builds oder dieser Umgebung.'],
            ['title' => 'Mitteilungen aktivieren', 'body' => 'Aktiviere bewusst den Schalter und bestätige die Systemabfrage. Abgelehnte Systemrechte müssen gegebenenfalls in den Geräteeinstellungen geändert werden.'],
            ['title' => 'Geräteeinstellungen prüfen', 'body' => 'Prüfe in den Betriebssystemeinstellungen für die App Mitteilungsfreigabe, Anzeigeoptionen sowie Fokus- oder Nicht-stören-Modus.'],
            ['title' => 'Mit Posteingang abgleichen', 'body' => 'Prüfe bei fehlendem Push zuerst den Posteingang und das aktive Konto. Melde wiederholte Probleme mit Umgebung, Zeitpunkt und Gerätetyp über Support.'],
        ],
        'notes' => ['Verbindung, Energiesparregeln und Fokusmodi beeinflussen die Systemzustellung; eine bestimmte Zustellzeit ist nicht garantiert.', 'Erneuter Login richtet fehlende Providerkonfiguration nicht automatisch ein.'],
    ],
    'diagnostics' => [
        'title' => 'Hilfe finden und Diagnosekontakt vorbereiten',
        'intro' => 'Die Wissensbasis erklärt die Bedienung. Technische Fehler meldest du nachvollziehbar mit einem geprüften Diagnoseanhang.',
        'steps' => [
            ['title' => 'Anleitung suchen', 'body' => 'Öffne Hilfe & Support im Modulmenü und suche nach Aufgabe oder Modul. Lies die zur App passende Anleitung und ihre Hinweise.'],
            ['title' => 'Fehler beschreiben', 'body' => 'Notiere Aktion, erwartetes und tatsächliches Ergebnis, Umgebung, Verein und Zeitpunkt. Erkläre, ob der Fehler wiederholbar ist.'],
            ['title' => 'Diagnosekontakt vorbereiten', 'body' => 'Nutze das Mail-/Diagnosesymbol im Support. Prüfe die angezeigten Konto-, Vereins- und Geräteinformationen, bereinigten technischen Ereignisse und den vorbereiteten Anhang.'],
            ['title' => 'Bewusst senden', 'body' => 'Prüfe Empfänger, Nachricht und Anhänge im Mail- oder Teilenablauf und sende selbst ab. Ein geöffneter Entwurf ist kein bestätigter Versand. Alternativ nutze ein freigeschaltetes Supportticket.'],
        ],
        'notes' => ['Keine Passwörter, Anmeldecodes, Schlüsselbox-PINs oder ungeprüften Ausweisfotos in die Problembeschreibung übernehmen.', 'Eine Supportanfrage ändert keine Vereinsdaten, vergibt keine Berechtigung und löst keine Zahlung aus.'],
    ],
];
