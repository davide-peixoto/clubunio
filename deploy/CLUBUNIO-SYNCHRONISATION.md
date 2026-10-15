# ClubUnio: normale Bexio-Bedienung und zentrale Synchronisation

## Umfang (Release71, 16.10.2026)

Die einzige verbundene Bexio-Organisation bleibt SVS Api Test. Die technischen
Identitaets-, Kontakt-, Steuer- und Doppelschutzpruefungen bleiben erhalten.
Keine Produktivanbindung, kein Bankauftrag und kein automatischer Mailversand.

Die Entwicklungsseiten Bexio-Sandbox und Bexio-Versandpruefung bleiben fuer
gezielte technische Tests erreichbar, erscheinen aber nicht mehr im Menue.
Auch Testartikel-/Testrechnungsaktionen entfallen aus dem normalen Katalog.
Rechnungen bieten normale Aktionen: Uebertragen, Bexio-Freigabe/Stornierung und
Statusabgleich. Spesen bieten direkten Statusabgleich. Die verbundenen
synthetischen Kontakte bleiben bewusst bestehen; diese Aenderung ist keine
Freigabe importierter Altbelege fuer die fremde Sandbox-Datenbasis.

## Persistente Arbeitswarteschlange

Verwaltung > Synchronisation, nur frische aktive Adminberechtigung des aktuellen
Mandanten. Neue eigene Migration67: sync_work_items und sync_work_events.
Vor dem externen Aufruf werden Auftrag, Quelle, verschluesselte Parameter und
Verbindungsfingerabdruck gespeichert. Danach getrennte Zustandsaktualisierung.
Fehlertexte/Antwortkoerper, Secrets und signierte URLs werden nicht gespeichert.
Jeder Versuch und manuelle Abschluss bleibt im Journal. Kein Loeschen im Web.

Direkt eingebunden:
- Rechnungsuebertragung inkl. vorgelagertem Kontakt und Anhaengen;
- Bexio-Rechnungsfreigabe/-stornierung;
- lesender Rechnungs-/Spesenabgleich und direkter bestaetigter Statusabgleich;
- Artikel-/Steuerkatalog;
- SFV-Spielplanabruf (Datumsspanne bleibt erhalten);
- fehlgeschlagene KI-Trainingsanfragen mit verschluesselten Eingabedaten zur
  manuellen Pruefung, ausdruecklich ohne automatischen kostenpflichtigen Replay.

Bereits vorhandene unbestaetigte Bexio-Dispatches, Rechnungs-/Finanzaktionen,
jeweils letzte fehlgeschlagene Bexio-Statuspruefungen und fehlgeschlagene
KI-Anfragen lassen sich ueber Offene Protokolle uebernehmen importieren.
Je Quellart maximal500 pro Sammellauf; dieser ist wiederholbar/dedupliziert.
Es wird dabei nichts extern ausgefuehrt. Historische KI-Eingaben koennen nicht
rekonstruiert werden, wenn sie im alten Requestjournal nie gespeichert waren.

## Wiederholung und manuelle Klaerung

Erneut pruefen fuehrt nur die fest verdrahtete fachliche Operation aus, nie eine
frei eingetragene URL oder einen frei bearbeitbaren Request. Alle bisherigen
Fachberechtigungen/Revisionen/Verbindungszuordnungen werden neu geprueft.
Ein unbekanntes Bexio-Anlageergebnis wird nur anhand des bestehenden Markers
gesucht. Keine neue Rechnung, kein neuer Kontakt und kein erneuter Zahlungs-
auftrag auf blossen Timeout. Nicht eindeutige Ergebnisse bleiben offen.

Ein Lease sperrt parallele Bearbeitung fuer5 Minuten; abgebrochene Prozesse
bleiben sichtbar und koennen danach geprueft werden. Fachservices behalten
zusaetzlich ihre atomaren Versand-/Markierungspruefungen. Ein alter Browser-
zustand oder geaenderter Verbindungsfingerabdruck sperrt die Wiederholung.

Ein manueller Abschluss verlangt eine verschluesselte Begruendung und bedeutet
nur: Pruefauftrag abgeschlossen. Er bestaetigt keine externe Synchronisation,
setzt keinen Rechnungsstatus und entfernt keinen offenen Providerbeleg.
Nach erneutem fachlichem Normalaufruf kann ein abgeschlossener Lesauftrag wieder
bearbeitet werden; seine bisherigen Ereignisse bleiben erhalten.

## Grenzen und Ausbau

Dies ist eine fachliche, manuell bedienbare Warteschlange, kein neuer Cronjob
und kein globaler Laravel-Queueworker. Erfolgreiches Statuslesen ist nicht mit
fachlicher Uebernahme gleichzusetzen; die jeweilige Operation ist beschriftet.
Ein vollstaendiger Datenverlustschutz auch gegen DB-/Hosting-Ausfall braucht
weiterhin Backups. Bereits erfolgte externe Aenderungen werden nicht durch
eine lokale DB-Ruecknahme rueckgaengig gemacht.

Azure-Uploads und oeffentliche Anmeldeuploads bleiben bewusst atomar mit
Fehlermeldung. Sie werden NICHT ohne privates binaeres Staging und zugehoerigen
Berechtigungs-/Aufbewahrungsprozess in eine automatische Uploadqueue verschoben.
Ebenso bleiben Signage-Feeds mit ihrem bestehenden letzten gueltigen Cache und
deaktivierter externer Nachrichtenversand unveraendert. Weitere Adapter muessen
diese zentrale Struktur verwenden, sobald ein asynchroner Sync hinzukommt.

## Gutschrift GS-00005

In Bexio SVS Api Test bestaetigt: CHF10 brutto, UN81/8.1%, enthaltene Steuer
CHF0.75; wieder ausgestellt/Offen und nicht verrechnet. Bexio-Gutschriften
unterstuetzen MWST, wenn Einstellungen zuerst Inkl. Steuer und Brutto statt
von Steuer befreit enthalten und die Position anschliessend gespeichert wird.
Original RE-00006 bleibt bezahlt/CHF40.25. Kein Rueckfluss auf die bezahlte
Originalrechnung fingieren; Kundenguthaben und spaetere Verrechnung getrennt
nachweisen. Lokale Korrektur18 ist keine Bexio GS-00018.
Lokal wurde GS-00005 anhand der Browserpruefung explizit manuell registriert:
Kundenguthaben41, CHF10.00 unverrechnet, Originalsteuer0.748933 unveraendert.
Das ist keine automatisierte Provider-Steuerbestätigung; Anzeige in Bexio0.75.
Keine externe Write-Operation, keine Auszahlung, keine Verrechnung fingiert.

## Migration / Deployment

Nur eigene ClubUnio-PostgreSQL, additive Migration67 vor neuen Aufrufen;
lokal/Pi benutzen dieselbe Datenbank. Aktive Codebasis70 plus geprueftes Delta,
Caches neu erzeugen, bisheriges Release behalten. Kein PHPMaker DEV/PROD,
keine API-/SaaS-Aenderung und keine Betriebsautomatisierung.

Gezielte Abschlusspruefung:11 Tests/73 Assertions (Queue inkl. historischem
Import, Leaseablauf, SFV und KI-Fehler) sowie18 Bexio-Tests/133 Assertions
(Freigabe/Storno/Zahlungen/Upload/Timeouts/Identitaet) erfolgreich. Ausschliesslich
synthetische Transaktionen mit Rollback und gemockten Providerantworten.
Die alte PDF-Testadresse prueft jetzt ausdruecklich die vorgesehene Weiterleitung
zur Workspace-Dokumentadresse. Routen-/Viewcache und Navigationchecker OK.
Keine Aussage ueber die komplette Gesamtsuite; parallel entstehen weitere
Oberflaechen-/Listenanpassungen, die nicht Teil dieses Deltas sind.

Liveabschluss: Release71-sync-final wurde veroeffentlicht und anschliessend
vom parallelen Workspace-Editing-v2-Paket vollstaendig uebernommen. Aktuell
20261016-clubunio-workspace-editing-v2; dessen Navigation/Dossierpflege erhalten.
Die letzte Sichtbarkeits-/Neulade-Korrektur von BexioRecordActions wurde nur
nach Pruefung dieses aktiven Zielpfads atomar nachgezogen. Bezahlte Rechnungen
zeigen keine Freigabe-/Stornoaktion. Nach Statusabgleich wird die Ressource
komplett neu geladen, damit SQL-Aggregatsummen nicht beim ORM-refresh verloren
gehen (separater Regressionstest1/11 erfolgreich; insgesamt30/217 gezielt).

Angemeldeter Browser: Desktop und390x844, Seitenbreite390 ohne Ueberlauf,
Testmenues verschwunden, Filter/Journal erfolgreich. Echter lesender Bexio-
Abgleich der eigenen Rechnung8440 per Web bestaetigt und lokal uebernommen;
ein erfolgreicher invoice_sync-Auftrag, keine offenen Altfehler gefunden.
Keine externen Buchungen oder Zahlungen.195 Settings/46 Vorlagen entschluesselbar,
Outbound bleibt aus.22 Laufzeit-/Migrations-/Tooldateien lokal/Pi SHA256 gleich.
