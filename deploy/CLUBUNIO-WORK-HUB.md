# ClubUnio: sechs Alltagspakete auf DEV

Stand: 24.11.2026. Zielrelease `20261124-clubunio-work-hub-v2`, fachlicher
Rückweg `20261124-clubunio-personal-workflows-v4`. Nur ClubUnio DEV.

## Funktionen

1. **Benachrichtigungen** (`/notification-center`): eigene Meldungen mit
   Ungelesenfilter, Einzellesestatus und direkt erreichbarem Fachziel.
   Unterstützt Spesen, Anmeldungen, Mitgliederänderungen, Mannschaftstermine,
   Busreservierungen, Anlagenplanung, Aufgaben und explizite Chaterwähnungen.
   Eigentum und aktuelle Quellberechtigung werden beim Anzeigen und Lesen
   erneut geprüft. Unbekannte oder entzogene Ziele werden ausgeblendet.
   Seiten umfassen maximal 20 gespeicherte Meldungen; ausgeblendete Ziele
   können deshalb eine leere Seite ergeben. Die weiteren Seiten bleiben erreichbar.
2. **Objektsuche** (`/object-search`): Mitgliedername/Firma/Nummer,
   Rechnungsnummer/ID, Dokumentdateiname und Termintitel. Mindestens zwei,
   höchstens 100 Zeichen, sechs Treffer je Fachbereich, Suche erst auf Absenden.
   SQL-Wildcards werden als Text behandelt. Keine Suche in privaten Dokumentbytes,
   Bankdaten oder Änderungsinhalten. Fachressourcen und aktuelle Mandantensicht
   begrenzen die Ergebnisse. Der bisherige Filament-Suchkasten entfällt.
   Der neue Objektsuche-Link verwendet ein Befehlssymbol, keine weitere Lupe.
   Ctrl/⌘+Shift+F öffnet die Objektsuche; Ctrl/⌘+K bleibt die Menüsuche.
3. **Konfliktlöser** in Planungskonflikten: berechtigte Trainingsbelegungen und
   Platzanfragen erhalten bis zu fünf freie Zeit-/Platzalternativen am selben
   Tag, maximal zwei Stunden versetzt. Flächenteilung, Sperren, Verfügbarkeit
   und gleichzeitige Mannschaftstermine werden berücksichtigt. Bestätigung
   erforderlich. Unter Mandantensperre werden Vorschläge und Revision erneut
   geprüft; der bestehende Anlagenservice prüft die verbindliche Verschiebung.
   SFV-Wettkämpfe bleiben im ursprünglichen Ablauf mit ihrer Folgenvorschau.
   Team- und Buskonflikte verlinken weiterhin ihre Fachplanung.
4. **Geführte Erfassung** (`/guided-capture`): drei Schritte für Mitglieder,
   Spesenentwürfe und Mannschaftstermine. Aktive Mandanten-Lookups liefern
   Vorgaben und Auswahlwerte. Der Rechnungsempfänger bestimmt die notwendige
   Kontaktadresse. Spesen umfassen Kopf und eine Position; Belege werden danach
   im Dossier ergänzt. Keine Freigabe, kein Zahlungsauftrag. Speichern erfolgt
   ausschließlich über die vorhandenen Fachservices in einer Transaktion.
   Ein an Benutzer/Mandant und Eingabehash gebundener UUID-Befehl verhindert
   doppelte Erfassung durch identische Wiederholungen.
5. **Teamaufgaben** (`/team-tasks`): verschlüsselter Titel/Beschreibung,
   Zuständigkeit, Fälligkeit und Stand offen/in Bearbeitung/erledigt/archiviert.
   Überfälligkeit, eigener Filter, Wiederherstellung und Revisionsprüfung.
   Ohne `tasks.assign` sind nur selbst erstellte oder zugewiesene Aufgaben
   sichtbar; Neuanlage ist dann nur für sich selbst möglich. Änderungen und
   Zustandswechsel werden ohne Klartextinhalte protokolliert. Neue Zuweisungen
   an andere berechtigte Personen erzeugen eine persönliche Meldung.
6. **Bedienbarkeit und Ladeverhalten**: gemeinsame Mandantenfarben und Dark Mode,
   Fokusrahmen, beschriftete Felder, Live-Status und Fehlerzusammenfassung,
   responsive Karten, mindestens 44px große neue Such-/Erwähnungsziele,
   reduzierte Bewegung, begrenzte Trefferlisten und paginierte Aufgaben/Meldungen.
   Keine neue CDN-, Framework- oder Providerabhängigkeit.

Die Seiten stehen unter „Mein Arbeitsbereich“ und in „Meine Module“ zur
Favoritenauswahl. `REACT_ENABLED` bleibt der zentrale Mandantenparameter;
die Fachfunktionen sind in beiden Designmodi verfügbar. DE und EN liegen in
`lang/{de,en}/work_hub.php`. Benutzereingaben werden nicht übersetzt.

## Erwähnungen

Der Backend-Chat bietet eine ausdrückliche Personenauswahl. Nur aktuelle,
berechtigte und bereits materialisierte Chatteilnehmer sind auswählbar,
höchstens zehn. `@Name` im Freitext allein löst keine Meldung aus.
Erwähnungen gehören zum eingefrorenen Versandentwurf; ein erneuter Versuch
verwendet dieselbe Nachrichten-ID und erzeugt keine zweite Meldung.
Der bestehende mobile Versandvertrag bleibt unverändert. Erwähnungsmeldungen
sind interne Meldungen, keine zusätzliche E-Mail oder Pushzustellung.
Aktuelles Chatrecht, Mitgliedschaftsgrenze und nicht gelöschte Nachricht werden
beim Öffnen erneut geprüft. „Als gelesen“ in der Zentrale ist unabhängig vom
Lesestatus des Chats. Der Link öffnet die passende Unterhaltung.

## Schema und Betrieb

Additive Migration `2026_11_24_010000_work_hub`:

- `workspace_tasks`: Mandant, Ersteller, Zuständiger, verschlüsselte Inhalte,
  Datum, sprachunabhängiger Stand, Revision und Zeitstempel.
- `workspace_task_events`: Aufgabe, Mandant, Akteur, Operation und Revision.
- `chat_mentions`: Mandant/Nachricht/Empfänger, eindeutige Kombination und
  zusammengesetzte Fremdschlüssel auf Nachricht und Vereinsmitgliedschaft.
- `workspace_capture_commands`: Mandant/Benutzer/UUID, Eingabehash und Ergebnislink.

Der Weblogin besitzt bewusst keine Schema-Erstellrechte. Die Migration wurde
als PostgreSQL-Eigentümer ausschließlich in `clubunio_dev` ausgeführt, mit
Zielprüfung, Transaktion, Sperrzeitlimit, Advisory Lock und Migrationsledger.
`emit-work-hub-migration.php clubunio_dev` erzeugt das gezielte SQL. Es gewährt
nur die benötigten Rechte auf die vier neuen Tabellen und drei neuen Sequenzen.
Keine bestehenden Profile oder Benutzerrechte wurden erweitert; Admin/Office
erben den registrierten Aufgabenkatalog, Profile benötigen ausdrückliche Grants.

Vorher: vollständiger verschlüsselter Dump des DEV-Anwendungsschemas als Owner,
mit `pg_restore --file=/dev/null` vollständig gelesen. Privater VPS-Pfad:
`/root/clubunio-releases/work-hub-20261124`. Schlüssel bleibt dort, nicht im Repo.
Ein anfänglicher Dumpversuch mit der Webrolle scheiterte an einer bestehenden
Sequenzberechtigung. Es wurden dafür keine bestehenden Rechte erweitert.
Die ergänzende Pi-Schemasicherung liegt privat unter
`shared/backups/20261124-clubunio-work-hub-v1`.

Releasekopien behalten private Konfiguration, APP_KEY, Storage und Composer.
39 Runtime-Dateien werden per SHA-256 geprüft; PHP-Dateien werden für das
bestehende DEV-Schema `clubunio` vorbereitet. Neue Seiten benötigen erneuerten
Route-/View-Cache. Aktivierung durch atomaren `current`-Wechsel und FPM-Reload.
`release-work-hub.sh` bereitet einen inaktiven Kandidaten vor; Owner-Migration,
Fachabnahme und Aktivierung erfolgen ausdrücklich getrennt.

Rollback: auf v4 zurückschalten und FPM neu laden. Alle vier neuen Tabellen
und deren Daten erhalten; der Vorgänger ignoriert sie. Keine Down-Migration
und keine Wiederherstellung eines älteren Datenbestands im laufenden Betrieb.
Stage/PROD und bestehende Integrationen werden nicht umgestellt.

## Prüfstand

- 27 lokale PHP-Tests, 513 Assertions; ein bestehender PostgreSQL-Opt-in-Test
  übersprungen. `php tools/check-translations.php`: 3075 DE/EN-Schlüssel geprüft,
  kein offener Literaltext im dokumentierten Bestand.
- 15 isolierte Chrome-Frontendtests: Chatentwürfe, unveränderliche Wiederholung,
  explizite Erwähnungen, Dateifluss, Moderation, Reaktionsliste, Sprachkataloge
  und 320/390/1280px. Der Fixture-Renderer wurde an Filament-Felder und den
  PHP-Sprachkatalog angepasst. `PLAYWRIGHT_MODULE` und `PHP_BINARY` ermöglichen
  die Nutzung vorhandener lokaler Laufzeiten ohne Paketänderungen.
- 45 DEV-Prüfungen: vier Seiten und drei Assistenten in DE/EN und beiden
  Designs; Suche, Aufgabenstände und veraltete Revision, eigener Lesestatus,
  alle drei Fachspeicherwege, UUID-Wiederholung, Erwähnungsauswahl/-wiederholung,
  synthetisches Profil, unerlaubte Zuweisung, fremde Aufgabe, Rechteentzug.
  Anschließend 15 bestehende Workflow-Fachprüfungen erfolgreich.
- Sämtliche Fachtestdaten wurden zurückgerollt; HTTP-Fremdaufrufe blockiert,
  Mail/Queue im neuen Test abgefangen. Keine echte Nachricht an andere Personen,
  keine Freigabe oder Zahlung ausgeführt.
- Browserabnahme in der angemeldeten DEV-Oberfläche noch offen: Sitzung nach
  der Aktivierung abgelaufen; Anmeldung im offenen Testtab angefordert.

Nach Browserabnahme Prüfgrenzen und gegebenenfalls Korrekturen hier nachführen.
