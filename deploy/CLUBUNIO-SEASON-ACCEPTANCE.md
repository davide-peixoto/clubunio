# Saisonplanung: technische Fachabnahme

Stand: 23.10.2026. Ausschliesslich ClubUnio-Web. API, SaaS und Betrieb sowie
PHPMaker DEV/PROD unveraendert. Kein Bestandsimport, keine echte Saisonumstellung,
kein Rechnungsversand, keine Push-Nachrichten oder externen Synchronisationen.

## Ergebnis

Der zusammenhaengende Saisonprozess ist mit isolierten synthetischen Daten
verbindlich im Browser abgeschlossen und anschliessend auf Datenbankebene
abgeglichen. Damit sind die zuvor offenen Staff-/TL-Drag-and-drop- und
Gesamtabschlussnachweise technisch erbracht. Das ersetzt nicht die sportliche
Freigabe eines spaeteren echten Vereinsplans durch die Verantwortlichen.

## Isolation und Nachweis

Temporarer QA-Bereich ohne Vereinsdaten, externe Zugangsdaten oder Einstellungen.
Bestehende Vereinsgrenzen wurden benutzt, nicht erweitert. Nur das pruefende
bestehende Verwaltungskonto erhielt voruebergehend eine Zuordnung zum rein
synthetischen QA-Bereich. Seine SVS-Rolle und vorhandenen Rechte blieben gleich.
Vorlagensaison2040/2041 aus kuenstlichen Daten ueber den Fachservice erstellt;
Folgeplanung2041/2042 aus dieser Vorlage, mit zusaetzlicher neuer Mannschaft.
Keine fremden Mitglieder, Lookups oder echten Teams referenziert.

Browserabschluss: Plan177 -> Test-Saison3135. Drei Mannschaften (zwei bestehende
QA-Stammteams, ein neues), drei neue Beitragspreise/Regeln, elf Zuordnungen
(fuenf Spieler, drei Staff, drei technische Leitungen) und drei Trainingszeiten.
Alle25 Ergebniszuordnungen gegen ihren Zieldatensatz abgeglichen: Saison,
neues Stammteam, TeamSaison-IDs, Mitglieder/Funktionen, Preis-/Regelverknuepfungen,
Jahrgaenge sowie saemtliche Trainingszeitfelder und booleschen Optionen.

Alle vorher erfassten Quellsaetze sind unveraendert. Hashvergleich des SVS-
Bestands ueber Mitglieder, Saison-/Team-/Beitrags-/Zuordnungs-/Trainingszeitdaten
und Planungs-/Ergebnistabellen ebenfalls unveraendert. Keine Rechnungen,
Notifications oder Einstellungen im QA-Bereich erzeugt. Nachweis und Backup nur
im privaten lokalen Laufzeitordner, nicht in Git oder im Webroot.

Anschliessend zurueck zu SV Sissach gewechselt und ausschliesslich die genau
identifizierten synthetischen QA-Daten samt temporaerer Kontozuordnung in einer
Transaktion entfernt. Kein bestehendes Konto geloescht. Keine Testselektion oder
abgeschlossene Testsaison bleibt in den normalen Vereinslisten zurueck.

## Abnahmematrix

| Bereich | Nachweis | Ergebnis |
| --- | --- | --- |
| Vorlage | Folgejahr, Datumsbereich, Beitragsspannen +1, Staff/TL/Slots uebernommen | Browser und transaktionaler Gesamttest |
| Mannschaften | Stammteams wiederverwenden, neues Stammteam erzeugen, richtige TeamSaison-IDs | Alle Zielverknuepfungen abgeglichen |
| Spieler | Passende Kandidaten, Jahrgangsgrenzen, Kapazitaet, Quellspieler neu verteilen | Browser und automatisierte Tests |
| Staff | Verschieben mit unveraenderter Quellfunktion trotz anderer Poolauswahl | Echter Browser-Drag und Regressionstest |
| TL | Verschieben sowie bewusste Zuordnung zu weiteren Teams | Echter Browser-Drag und Datenabgleich |
| Beitraege | Neue Preise, saisongebundene Regeln, alte Preise/Regeln unveraendert | Browser, Quellsnapshot und Zielvergleich |
| Trainingszeiten | Drei angrenzende Belegungen mit Lookups und Optionen | Browser und vollstaendiger Feldvergleich |
| Konflikte | Ueberlappende Regeln, falsche Kategorie, archivierte Mitglieder | Abschlusspruefung blockiert |
| Hinweise | Jahrgang/Geschlecht, Mehrfachzuordnung, Zielgroesse, Platzkollision | Bestehende und ergaenzte Tests, Bestaetigung erforderlich |
| Abschluss | Frische Pruefung, explizite Seitenbestaetigung, danach schreibgeschuetzt | Live-Browserabschluss mit QA-Daten |
| Wiederholung/Fehler | Gleiche Saison bei Wiederholung; spaeter Fehler rollt alles zurueck | Transaktionale Tests |
| Rechte | Profil-Seed, Fremdverein, entzogene oder fehlende Teilrechte | Modul-/Wizard-Regressionen |
| Nebenwirkungen | Keine Saisonwahl, Rechnungen, Nachrichten oder externen Aufrufe | Datenabgleich und Test-Fakes |

## Behobene Luecken

- Staff-Drag uebertraegt die Funktion des gezogenen Eintrags explizit mit dem
  Verschiebeaufruf. Die allgemeine Pool-Funktionsauswahl wird dabei nicht mehr
  serverseitig umgestellt. Unbekannte/inaktive Funktionen bleiben abgewiesen;
  mehrere Funktionen derselben Person werden nicht verwechselt.
- Die dokumentierte ID-Suche findet jetzt auch Mitglieder mit normalem Namen.
- Der verbindliche Abschluss hat wie Neue Planung/Verwerfen eine explizite
  Seitenbestaetigung statt eines nativen JS-Dialogs. Die zweite Aktion bleibt
  erforderlich; Warnungsannahme, Rechte, HMAC/Revision und Atomaritaet bleiben.

## Tests und Release

SeasonPlanningTest + ModulePermissionTest:39 Tests/408 Assertions erfolgreich.
Separater nachtraeglicher Negativmatrix-Test:1 Test/12 Assertions erfolgreich.
SportsProfilePermissionTest + SeasonFinanceTest:29 Tests/524 Assertions erfolgreich.
Zusammen69 unterschiedliche Tests/944 Assertions in diesen Laeufen. Pint,
Syntaxpruefung und Git-Diff-Pruefung erfolgreich; Runtime-Hashes lokal/server gleich.
Alle synthetischen PHPUnit-Fixtures werden ueber die aeussere Transaktion
zurueckgerollt. Kein Refresh/Seed einer bestehenden Vereinsdatenbank.

Runtime-Delta20261023-clubunio-season-planning-acceptance auf
20261023-clubunio-season-planning-interactions: nur Page und Blade, keine Migration,
Parameter- oder Rechteaenderung. Caches, Tenant-/Profil-/Entwurfschecks erfolgreich.
Vorherige Releasekopie bleibt als Code-Rueckweg erhalten.

Vor spaeterer echter Nutzung: eigenen Vereinsplan pruefen/fachlich freigeben,
aktuelle Abschlusspruefung ausfuehren und Warnungen bewusst bestaetigen.
Vollvereins-Laufzeit ist nicht durch die kleine Browserfixture garantiert;
der vorhandene synthetische101-Spieler-Test bleibt eine separate Messung.
