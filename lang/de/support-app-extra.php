<?php

// Public instructions for additional app modules.
return [
    'training' => [
        'title' => 'Ein Training planen und mit dem Trainerteam teilen',
        'intro' => 'Training planen unterstützt die berechtigte Trainingsvorbereitung, gespeicherte Pläne und ausdrücklich übernommene Vorschläge.',
        'steps' => [
            [
                'title' => 'Planer öffnen',
                'body' => 'Öffne Training planen im Modulmenü und wähle eine angebotene Mannschaft. Prüfe vorhandene gespeicherte Trainings, bevor du einen neuen Plan erzeugst.',
            ],
            [
                'title' => 'Vorgaben festlegen',
                'body' => 'Erfasse die angezeigten Trainingsparameter, insbesondere Spielerzahl, Ziel und Rahmenbedingungen. Kontrolliere die Angaben und starte die verfügbare Erstellungsaktion bewusst.',
            ],
            [
                'title' => 'Plan prüfen und bearbeiten',
                'body' => 'Warte auf den geladenen Plan und prüfe Aufbau und Ablauf fachlich. Nutze angebotene Bearbeitung oder Vergleich. Einen Vorschlag übernimmst du nur mit der ausdrücklichen Übernahmeaktion.',
            ],
            [
                'title' => 'Freigabe und PDF verwenden',
                'body' => 'Öffne die angebotene Zusammenarbeit für berechtigte Trainer oder die PDF-Vorschau. Prüfe den aktuellen Planstand vor dem Teilen oder Exportieren.',
            ],
        ],
        'notes' => [
            'KI-Funktionen benötigen eine Vereinsfreigabe und eingerichtete Provider. Ein Vorschlag ist keine automatisch verlässliche Trainingsanweisung.',
            'Nach unklarem Erstellungsstatus zuerst den bestehenden Auftrag prüfen; nicht mehrfach neue kostenpflichtige Anfragen starten.',
        ],
    ],
    'facilities' => [
        'title' => 'Anlagenstatus und Sperren prüfen',
        'intro' => 'Status Anlagen zeigt die im Verein eingerichteten Plätze und ihren aktuellen Status.',
        'steps' => [
            [
                'title' => 'Anlagenstatus öffnen',
                'body' => 'Öffne Status Anlagen aus dem Modulmenü oder die angebotene Statusansicht der Platzbelegung. Prüfe den ausgewählten Verein.',
            ],
            [
                'title' => 'Platzkarte lesen',
                'body' => 'Lies die Karte der betroffenen Anlage und ihre aktuellen Statusangaben und Hinweise. Eine andere Anlage kann einen anderen Status besitzen.',
            ],
            [
                'title' => 'Status berechtigt ändern',
                'body' => 'Wenn dir eine Statusaktion angeboten wird, wähle den passenden eingerichteten Status und prüfe weitere Angaben. Bestätige die Änderung bewusst und kontrolliere den neu geladenen Zustand.',
            ],
            [
                'title' => 'Bei Widersprüchen nachfragen',
                'body' => 'Bei abweichenden Angaben lade die Ansicht erneut. Kläre eine unverständliche Sperre mit der zuständigen Vereinsstelle, statt sie durch eine andere Buchung zu umgehen.',
            ],
        ],
        'notes' => [
            'Statusänderungen benötigen eigene Rechte. Ein sichtbarer Platz ist keine Berechtigung, ihn freizugeben.',
            'Anlagenstatus und eine bestätigte Reservierung sind getrennte Informationen. Prüfe die Vorgaben des Vereins für die tatsächliche Nutzung.',
        ],
    ],
    'challenge' => [
        'title' => 'Vereinschallenge spielen und Rangliste ansehen',
        'intro' => 'Die Vereinschallenge bietet Schießen und Halten. Die Verfügbarkeit richtet sich nach der Freigabe des Vereins.',
        'steps' => [
            [
                'title' => 'Challenge öffnen',
                'body' => 'Öffne Vereinschallenge im Modulmenü, wenn der Einstieg freigegeben ist. Prüfe den angezeigten Verein und die Spieloptionen.',
            ],
            [
                'title' => 'Disziplin und Runde wählen',
                'body' => 'Wähle Penalty schiessen oder Penalty halten. Starte eine Vereinsrunde für eine mögliche Wertung oder Training ohne Wertung.',
            ],
            [
                'title' => 'Zehn Versuche spielen',
                'body' => 'Beim Schießen wählst du die Ecke passend zum Kraftbalken. Beim Halten beobachtest du die Flugrichtung und reagierst rechtzeitig. Folge den eingeblendeten Hinweisen bis zum Rundenende.',
            ],
            [
                'title' => 'Ergebnis prüfen',
                'body' => 'Lies das Rundenergebnis und die Bestätigung einer Ranglistenübertragung. Öffne die Rangliste und wähle die angebotene Disziplin. Bei fehlgeschlagener Übertragung nutze nur die angebotene Ergebnis-Wiederholung.',
            ],
        ],
        'notes' => [
            'Eine lokale Trainingsrunde ist kein automatisch gespeicherter Vereinsranglistenbeitrag. Der angezeigte Speicherstatus bleibt maßgebend.',
            'Eine neue Challenge beziehungsweise ein Ranglisten-Reset ist eine gesonderte Verwaltungsaktion mit weitreichenden Folgen.',
        ],
    ],
    'sponsor_run' => [
        'title' => 'Am Sponsorenlauf teilnehmen und Zusagen verfolgen',
        'intro' => 'Der Sponsorenlauf bündelt freigegebene Anlässe, Teilnehmende und Sponsorzusagen.',
        'steps' => [
            [
                'title' => 'Anlass auswählen',
                'body' => 'Öffne Sponsorenlauf und wähle den freigegebenen Anlass. Prüfe Saison, Datum und die angebotene Ansicht für dich, Mannschaft oder Helfer.',
            ],
            [
                'title' => 'Teilnehmende prüfen',
                'body' => 'Öffne die eigene oder berechtigt vertretene Person. Prüfe Aufgaben und vorhandene Sponsorzusagen. Eltern wählen das richtige Kind bewusst.',
            ],
            [
                'title' => 'Sponsorlink bewusst erzeugen',
                'body' => 'Öffne die angebotene QR-/Teilenansicht. Lies die Namenseinwilligung und erzeuge den Link nur nach ausdrücklicher Bestätigung. Prüfe den Link vor dem Weitergeben.',
            ],
            [
                'title' => 'Runden und Ergebnis kontrollieren',
                'body' => 'Berechtigte Helfer erfassen über die angebotenen Aktionen Runden oder Check-in. Prüfe Entwurf und Vorschau vor einer verbindlichen Freigabe. Rechnungsstatus kann nach Berechtigung eingesehen werden.',
            ],
        ],
        'notes' => [
            'Die Sichtbarkeit eines Anlasses gewährt keine Helfer-, Freigabe- oder Verwaltungsrechte.',
            'Ein öffentlicher Sponsorlink gibt freigegebene Angaben weiter. Private Rechnungs- und Zahlungsaktionen werden dadurch nicht freigeschaltet.',
        ],
    ],
    'registrations' => [
        'title' => 'Mitgliederanmeldungen als berechtigte Stelle prüfen',
        'intro' => 'Anmeldungen ist die berechtigte Bearbeitungsansicht für eingegangene Mitgliedschafts- oder Probetrainingsanfragen.',
        'steps' => [
            [
                'title' => 'Anmeldungen öffnen',
                'body' => 'Öffne Anmeldungen im Modulmenü, wenn verfügbar. Suche den Antrag und prüfe Person, Status und Mannschaft.',
            ],
            [
                'title' => 'Angaben und Anhänge prüfen',
                'body' => 'Öffne die Detailansicht und kontrolliere die freigegebenen Angaben und vorhandenen Anhänge. Lade nur Dokumente, die du für deine Aufgabe benötigst.',
            ],
            [
                'title' => 'Zuweisung oder Statusaktion wählen',
                'body' => 'Nutze die angebotene Bearbeitung, Mannschaftszuweisung oder Statusaktion. Prüfe den angezeigten Umfang und vervollständige die verlangten Angaben vor der Bestätigung.',
            ],
            [
                'title' => 'Neuen Stand kontrollieren',
                'body' => 'Lade den bestätigten Antrag erneut und kontrolliere Status und Zuordnung. Bei einem Konflikt oder unklarem Netzwerkfehler zuerst den aktuellen Stand prüfen.',
            ],
        ],
        'notes' => [
            'Trainer bearbeiten nur freigegebene teambezogene Anmeldungen; allgemeine Aufnahme- und Finanzrechte entstehen daraus nicht.',
            'Akzeptierte Anmeldungen können im Modul Anmeldungen weiter nachvollziehbar bleiben, auch wenn sie unter My Teams nicht mehr erscheinen.',
        ],
    ],
    'approvals' => [
        'title' => 'Pendenzen und Freigaben in der App bearbeiten',
        'intro' => 'Berechtigte Verwaltungsrollen sehen auf Heute die vom Server freigegebenen Pendenzenkacheln.',
        'steps' => [
            [
                'title' => 'Pendenzen auf Heute prüfen',
                'body' => 'Öffne Heute und prüfe die angebotenen Kacheln und Zähler für Änderungsanträge, Anmeldungen, Platzanfragen, Spesen, Bus und Dokumente.',
            ],
            [
                'title' => 'Kategorie öffnen',
                'body' => 'Tippe auf die benötigte Kategorie. Prüfe Liste und Filter, da der Zähler eine Übersicht ist und keinen einzelnen Antrag ersetzt.',
            ],
            [
                'title' => 'Antrag und Nachweise prüfen',
                'body' => 'Öffne den Vorgang, kontrolliere Person oder Ressource, Zeitraum und verfügbare Nachweise. Bei Spesen prüfe Positionen und Übertragungsstatus.',
            ],
            [
                'title' => 'Entscheidung bewusst bestätigen',
                'body' => 'Wähle die angebotene Freigabe-, Ablehnungs- oder Änderungsaktion und ergänze verlangte Angaben. Prüfe anschließend den aktuellen Vorgangsstatus. Nach unklarem Ergebnis zuerst erneut laden.',
            ],
        ],
        'notes' => [
            'Kacheln und Aktionen hängen von konkreten Rechten ab, nicht allein von einer Rollenbezeichnung. Fehlende Kacheln geben keine indirekten Verwaltungsrechte.',
            'Eine Freigabe führt keine echte Bankzahlung aus. Prüfe Auswirkungen vor jeder verbindlichen Entscheidung.',
        ],
    ],
    'getting_started' => [
        'title' => 'Auf Heute und im Menü orientieren',
        'intro' => 'Die Startansicht zeigt relevante Module, nächste Termine und berechtigte Aufgaben, ohne alle Funktionen doppelt anzuzeigen.',
        'steps' => [
            [
                'title' => 'Konto und Verein prüfen',
                'body' => 'Lies den Vereinsnamen im Kopf und kontrolliere bei Bedarf das Profil. Wechsle vor der Bearbeitung zum beabsichtigten Verein.',
            ],
            [
                'title' => 'Wichtige Module öffnen',
                'body' => 'Nutze unten Spesen, Mitglieder, den mittleren Chatbutton und Termine, soweit freigegeben. Weitere Module erreichst du über das Menü.',
            ],
            [
                'title' => 'Nächste Termine ansehen',
                'body' => 'Öffne auf Heute einen nächsten Termin und prüfe die eigene oder vertretene Person. Rückmeldungen erfolgen im jeweiligen Termin.',
            ],
            [
                'title' => 'Erste Schritte und Hilfe nutzen',
                'body' => 'Nutze angebotene Erste-Schritte-Aufgaben für deine Rolle. Wenn eine Bedienung unklar bleibt, öffne Hilfe & Support und suche die passende Anleitung.',
            ],
        ],
        'notes' => [
            'Nicht jedes Konto sieht dieselben Module oder Aufgaben. Der Server entscheidet anhand der Vereinsfreigabe und persönlichen Rechte.',
            'Eine Einführung vergibt keine Berechtigungen und führt fachliche Änderungen nicht ohne deine ausdrückliche Aktion aus.',
        ],
    ],
];
