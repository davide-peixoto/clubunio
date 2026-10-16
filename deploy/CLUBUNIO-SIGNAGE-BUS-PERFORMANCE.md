# ClubUnio: Signage, Anlagen und Busperformance

16.10.2026. Nur ClubUnio. Keine API/SaaS-/Betriebserweiterung oder Aenderung
an PHPMaker DEV/PROD. Keine echten Buchungen, E-Mails oder Bankzahlungen.

## Befund und Anpassung

Signage lud Spielfeld-/Garderoben-/Seiten-Lookups fuer Spiele und Trainings
mehrfach separat. SignageService verwendet jetzt einen requestlokalen Pool:
bereits geladene Spielfelder wiederverwenden, fehlende Optionen gesammelt
mit tenant_id laden und die bisherigen Modellrelationen setzen. Historische
inaktive Garderoben bleiben lesbar; null bleibt null. Reihenfolge, Datum,
Saison-/Teamaktivitaet und Sperr-/Trainingsvarianten unveraendert.

Die Vorschauseite liest Periodenauswahl und Design nur einmal pro Komponenten-
Instanz/Request. Private PHP-Felder werden nicht als Livewire-State persistiert.
Nach Designspeicherung wird der lokale Designwert verworfen; Versions- und
Schreibpruefungen im Fachservice bleiben frisch. Kein Cache ueber Requests.

Die reine Anzeige der eingebetteten Feeds nutzt den bestehenden begrenzten
BackofficeReadContext fuer exakte Admin-/Office-Mandantenpruefung. Ohne
Lese-Kontext wird frisch geprueft. Der darin frisch geladene exakte Tenant
wird nur fuer die Darstellung wiederverwendet. refreshFeeds und Providerabrufe behalten
frische eigene Autorisierung. Oeffentliche Signage/Feeds pruefen weiterhin
die Freigabe aus der DB. Token-/Mandanten-Locked-Properties unveraendert.

Externe Wetter-/Instagram-Daten hatten bereits Cache, Credential-Fingerprint,
Lock, Cooldown, begrenzte HTTP-Timeouts und sanitisierte Fehlerprotokolle.
Diese Mechanismen und das Poll-/Refreshintervall bleiben unveraendert.
Keine HTTP304-/ETag-Abkuerzung, welche Rollen-/Freigabepruefungen umgeht.

Buskontext wird nach der frischen Eintrittspruefung von teams/reservableTeams
aufgebaut; die identische unmittelbar vorherige Doppelpruefung entfaellt.
Neuanlage laedt direkt reservierbare Teams statt zuerst noch eine zweite
komplette Teamliste. Antragsteller-Auswahl laedt nur ID/Vorname/Nachname,
keine Bilder oder privaten Zusatzdaten. Sichtbare Reservationen nutzen fuer
vertretene Mitglieder einen SQL-Unterquery statt einer vorherigen ID-Liste.
Alle Mitglieder-/Eltern-/Trainer-/TL-Praedikate bleiben erhalten.

Busliste ergaenzt historische Teamlabels nur wenn sie in den sichtbaren Zeilen
fehlen. Anzeigenamen werden einmal nach ID indiziert statt pro Zeile linear
gesucht. Filter/Statuszaehler, Sortierung, Pagination, no-store, Konflikt- und
Revisionspruefungen sowie eigene Schreibautorisierungen bleiben unveraendert.

## Messung vor Delta

Lesender CLI-Seiten-Lifecycle mit eigener ClubUnio-DB, READ ONLY/Rollback;
Ausgabe ausschliesslich Status/Laufzeit/aggregierte Querymetriken. Keine
Personen, SQL-Bindings, Tokens oder HTML. Einzelstichproben, ohne Browser/
Proxy; kein Lasttest/SLA. Profileralias /bus/create verwendet /bus/new.

| Ansicht | ms | Queries |
| --- | ---: | ---: |
| Signage-Vorschau | 1240 | 37 |
| Anlagenstatus | 553 | 13 |
| Wettkaempfe | 686 | 15 |
| Signage-Meldungen | 476 | 11 |
| Busliste | 656 | 18 |
| Bus-Neuanlage | 687 | 16 |

Bus-Neuanlage erste Stichprobe1137ms mit kurzfristig hoeherer DB-Latenz;
zweite Stichprobe687ms als Vergleich notiert, Queryanzahl jeweils16.
Anlagenlisten ohne nachgewiesenen weiteren Engpass unveraendert belassen.
Keine spekulativen Indizes oder RAM-/Poolaenderungen.

Oeffentliche Signage separat per HTTPS geprueft: HTTP200, ca1.17s inklusive
Netz/Proxy. CLI-Lifecycle dieser oeffentlichen Route lieferte vor DB-Zugriff
HTTP500; keine gueltige Performancezahl fuer diese Route und kein Nachweis
eines HTTP-Produktfehlers. Oeffentliche HTTP-/Freigabetests sind massgeblich.

## Migration und Ruecknahme

Keine DB-/Indexmigration, kein Import, keine neuen Einstellungen. Bestehende
Lookup-IDs, manuelle Garderoben, Vereinsfreigaben, Bus-/Team-/Mitgliederlinks
und Revisionen beim spaeteren Import erhalten. Gezieltes Runtime-/Dokudelta
auf verifiziertem aktuellem Release; vorherigen Release per Symlink und
graceful FPM reload wiederherstellen. Kein Schema-Rollback notwendig.

## Bereitgestellt und nachgemessen

Release `20261016-clubunio-signage-bus-performance`, Basis/Ruecksprung
`20261016-clubunio-dossier-selection`. Vorhandene Dossierpakete erhalten;
parallele neue Rollenfunktionen nicht pauschal in Runtime uebernommen.

| Pi-Stichprobe | ms vorher/nachher | Queries vorher/nachher |
| --- | ---: | ---: |
| Signage-Vorschau | 1240 / 823 | 37 / 25 |
| Busliste | 656 / 587 | 18 / 16 |
| Bus-Neuanlage | 687 / 562 | 16 / 13 |

Alle HTTP200, keine Lasttest-SLA. Die oeffentliche Signage blieb HTTP200;
zweite HTTPS-Stichprobe1.53s inklusive Netzwerk/Proxy, dort keine gemessene
Gesamtzeitverbesserung behauptet. Visuell oeffentliche Spiele, Trainings,
Garderoben, Anlagenstatus, Vereinsfarben/Logo sowie Wetter und Instagram
geprueft. Angemeldete Busliste zeigt weiterhin Bus-/Teamnamen, Statuszaehler
und Filter korrekt; keine Bestandsreservation angelegt oder geaendert.

66 unterschiedliche gezielte Tests erfolgreich: Signage-/Anlagenregeln,
Bus-/Schluesselboxsimulation, Kalenderprivatsphaere, oeffentliche Freigaben,
Feed-Locks/Tokenrotation/Redaktion und Readkontext. Geaenderte Varianten-/
Designassertionen und Feed-/Readkontextsuite nochmals gezielt ausgefuehrt.
Neuer Signage-Test:20 Spiele mit zwei direkten Optionsabfragen, historische
inaktive Garderobe und geaenderter Name beim naechsten Aufbau. Neuer Feedtest:
Mount/Render im verifizierten Kontext ohne DB-Queries; Rollenentzug ausserhalb
des Kontexts sofort403. Busformular prueft genau eine Teamliste und nur die
drei erlaubten Mitgliederauswahlattribute. Keine echte Schluesselbox angesteuert.

Caches und Workspace-/Dokumentguards auf Pi erfolgreich;195 verschluesselte
Settings/46 Nachrichtenvorlagen lesbar. Login/Health HTTP200. Keine neuen
Einstellungen, keine DB-Migration, kein Refresh historischer Daten.
