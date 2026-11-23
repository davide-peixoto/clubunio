# Support-Arbeitsbereich

Stand: 23.11.2026. Auf ClubUnio DEV, Stage und PROD bereitgestellt.

## Bedienung

- Vereinsbezogener Support startet direkt mit Tickets. Oben stehen Tickets,
  Wissensbasis und Assistent als eigene Ansichten. Admin/Geschaeftsstelle sehen
  zusaetzlich die Artikelverwaltung.
- Ticketliste mit Betreff, farbigem Status, Erstellungszeit, Suche,
  Statusfilter und seitenweisem Laden. Neue Tickets haben einen eigenen
  Einstieg oben. Antworten, interne Notizen, Anhaenge, Zuweisung,
  Eskalation und Abschluss nutzen die bestehenden Fachregeln.
- Assistent als Chat mit getrennten Nachrichten, Antwortanzeige und
  Quellenverweisen. Enter sendet, Shift+Enter fuegt eine neue Zeile ein.
  Doppelte Sendeklicks sind gesperrt; bei Fehlern bleibt die Frage erhalten.
  Der Verlauf ist nur im aktuellen Tab, kein dauerhaft gespeicherter Chat.
- Anonyme Hilfe bietet Wissensbasis und Assistent, keine Ticketliste.
- Anmeldehilfe «Ich komme nicht rein» oeffnet das oeffentliche Formular
  in einem neuen Tab. Die bisherige Verwaltungsliste ist separat unter
  Artikelverwaltung > Anmeldeanfragen erreichbar und oeffnet ebenfalls
  ausserhalb des eingebetteten Panels.

## Fehlerursache

Der bisherige Staff-Link fuehrte zur gegen Einbetten geschuetzten
Anfragenliste (CSP frame-ancestors none), nicht zum oeffentlichen
Anmeldeformular. Die Anfrage selbst lieferte HTTP 200; im iframe blockierte
der Browser die Darstellung. Die CSP bleibt erhalten. Keine pauschale
Lockerung der Schutzheader oder Berechtigungen.

## Migration und Release

Keine Datenbankmigration und keine Anpassung von Rollen, Schluesseln,
OpenAI-Einstellungen, Queue oder Scheduler. Der mobile JSON-Vertrag
bleibt eine Ticketkollektion; die neuen Web-Tabs aendern ihn nicht.
Vorhandene Grenzen fuer Mandant, anfragendes Konto und interne
Nachrichten bleiben wirksam. Der Assistent verwendet dieselben
kuratierten Hilfen und dieselbe Datenschutzgrenze wie zuvor; keine
Mitgliederdaten und keine Rohfragen werden an den Modellanbieter gesendet.

Alle neuen Texte sind DE/EN gepflegt. ClubUnio Blau, vorhandene Icons
und Statusfarben; responsive Darstellung auch im rechten Supportpanel.

DEV-Release: 20261123-clubunio-support-workspace.
Basis/Rueckweg: 20261123-clubunio-openai-models.
Gezieltes Dateidelta: release-support-workspace.sh.
Konfiguration wird ohne Secret-Aenderung auf die Zielpfade umgesetzt.
Quellviews und kompilierte Views muessen im neuen Release liegen.
Kein Composer-/ENV- oder fremdes lokales Fachdelta wird uebernommen.

## Pruefungen

- 22 PHP-Tests, 3698 Assertions: Club-/Plattformtabs, Mandantentrennung,
  eigene Tickets, eingeschraenkte Artikel und Anfragenliste,
  unveraenderter JSON-Vertrag und Provider-Datenschutz.
- 5 JavaScript-Tests: sichere Textdarstellung, Quellenverweise,
  getrennte Chatnachrichten, Fehler-/Doppelklickverhalten und Eingabetasten.
- Sprachkatalog- und Platzhaltercheck erfolgreich.
- Angemeldeter lesender DEV-Smoke-Test fuer alle fuenf Club-Ansichten:
  HTTP 200 mit neuen HTML-Merkmalen; DB-Transaktion zurueckgerollt.
- Oeffentliche Anmeldehilfe ueber HTTPS: HTTP 200.
- Browserabnahme mit bestehender berechtigter DEV-Sitzung: Desktop
  (1800 x 791) und Mobilbreite (390 x 844), auch als echtes Seitenpanel
  im Verwaltungsdashboard. Ticketliste, oberer Erstellen-Einstieg,
  Chatantwort mit Quellenlink und Anmeldehilfe im neuen Tab bestaetigt.
  Nur synthetische Fragen; keine neuen Tickets, Anmeldeanfragen oder
  Aenderungen von Vereinsdaten.

Der CLI-Smoke-Test prueft das oeffentliche Formular direkt ueber den
Controller, um nicht die unterschiedlichen anonymen und angemeldeten
Throttle-Budgets desselben Testprozesses zu vermischen. Der echte
HTTPS-Aufruf und Browserlink werden separat geprueft. Exceptions im
Smoke-Test liefern explizit Exitcode 1 und verhindern die Freigabe.

## Stage und PROD

Am 23.11.2026 nach ausdruecklicher Freigabe das gepruefte Supportdelta
aus Commit d2227b1 auf stage.clubunio.com und app.clubunio.com uebernommen.
Kein gesamter Branch-/DEV-Abgleich und keine fremden lokalen KI-Entwicklungen.
Die Zielinstanzen verwenden ihr eigenes Schema clubunio, ihre jeweiligen
Konfigurationen, Benutzer und Vereins-IDs.

RunCloud-Webroots bleiben unveraendert. Fehlende Panelassets und die
Vereinsverwaltungs-Einbindung wurden gezielt ergaenzt; bestehende
Portal- und Plattformlayouts nicht pauschal ersetzt. Plattform-Support
bleibt wegen seines Einbettungsschutzes eine normale Navigationsseite.
Blade-Cache erneuert und PHP-FPM sanft neu geladen. Kein ENV-/DB-/Rechte-
oder Schedulerwechsel und kein Mail-, Push- oder OpenAI-Testversand.

Private Dateisicherungen vor dem Update:
- Stage: /root/clubunio-releases/support-stage-20261123-214936
- PROD: /root/clubunio-releases/support-prod-20261123-214956

Rueckweg: gesicherte Quelldateien wiederherstellen, im Manifest als neu
markierte Dateien entfernen, Blade-Cache erneuern und PHP-FPM neu laden.
Es gibt keine Datenbankmigration zurueckzunehmen.

Pruefung je Zielinstanz: fuenf Vereinsansichten und drei Plattformansichten
mit berechtigtem Konto rein lesend gerendert, alle HTTP 200. Anmeldehilfe,
oeffentlicher Chat-/Wissenseinstieg und CSS/JS ueber HTTPS geprueft.
Keine Tickets oder echten Anmeldeanfragen angelegt. Browser-Sichtpruefung
dieses Layouts ist auf DEV belegt; Stage/PROD wurden per HTTP und
angemeldetem Rendering-Smoke-Test geprueft.
