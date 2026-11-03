# Persoenlicher SFV-Matchcentervertrag

Stand03.11.2026: bestehende SVS-Matchcenter-/Bestaetigungsmaske mit
persoenlichem ClubUnio-Vertrag verbunden. Kein Ersatzscreen und kein
Integrationstoken. Quellen werden vom gemeinsamen Releaseablauf integriert;
dieses Dokument behauptet noch kein gesondertes DEV-Deployment.

## Lesen und Uebernehmen

GET /api/mobile/v1/clubs/{tenant}/planning/federation-matches liefert die
bereits gespeicherten SFV-Spiele der persoenlich verantworteten/fachlich
freigegebenen TeamSaisons. Keine Providerabfrage beim Laden oder Neu-Laden.
Filter: TeamSaisonID, accepted, from/includeOlder; Pagination start/recperpage.
Ein Spiel kann zwei eigene Mannschaften betreffen: jede Zeile behaelt deshalb
ihre explizite TeamSaisonID. Keine zufaellige Wahl der ersten Mannschaft.

POST federation-matches/{matchId}/accept verlangt TeamSaisonID, SFVRevision
und EventRevision aus der tatsaechlich angezeigten Zeile. TeilnahmeModus,
Kapazitaet, Ort, Beschreibung und Abmeldefrist kommen aus der vorhandenen
Bestaetigungsmaske. Spiel-ID im Pfad ist die externe SFV-Match-ID; interne
Schedule-IDs werden nicht als App-Identitaet verwendet.

Die bestehende SfvPlanningService.import-Operation prueft frische persoenliche
Rechte, passende SFV-Team-ID, SFV-/Terminrevision sowie create/update/sync-
Fachrechte. Der persoenliche Controller sperrt Mandant und Sitzung innerhalb
derselben Transaktion und authentifiziert anschliessend neu. Bestehende
Termine werden nur mit passender angezeigter EventRevision bearbeitet.
Keine automatische Nachlese, Wiederholung oder unbemerkte Ueberschreibung.

Entfernte SFV-Termine bleiben in der Zuordnung erkennbar, werden als Removed
ausgewiesen und erhalten CanAccept=false. POST antwortet409 und legt keinen
Ersatztermin an. Unbekannter Spielbeginn ist ebenfalls nicht uebernehmbar.
Die gemeinsame native Planungs-Freigabesperre schuetzt beide neuen Routen.

## Bestehende App und Nachweise

FederationMatch traegt Team-/SFV-/Eventrevision und explizite Aktionsrechte.
Der bestehende Screen reicht diese angezeigten Werte durch; spaetere Reads
ersetzen keinen offenen Formularstand. PersonalFederationTransport erlaubt
ausschliesslich die zwei expliziten persoenlichen Vertragsaktionen und prueft
Antworten inklusive Rechte/Zaehler/Identitaet. Fehlende Revisionen oder
unvollstaendige Antworten bestaetigen keinen Erfolg. Mandanten-/Kontenwechsel
bleiben durch MobileApi gesichert.

Die bestehende Bestaetigungsmaske war auf390px beim Modus-Dropdown zu breit.
Ein gezieltes isExpanded erhaelt die Maske und beseitigt den belegten Overflow.

Zwei PostgreSQL-Tests/53 Assertions ueber die echten persoenlichen Routen:
Trainer-/Spieler-/Fremdmandantengrenzen, zwei eigene Teams im selben Match,
Paging/Datums-/Uebernahmefilter, Uebernahme, veraltete SFV-/Eventrevisionen und
Entfernen-Sperre erfolgreich. Ausschliesslich synthetische Daten innerhalb
einer zurueckgerollten Transaktion; keine Migration oder Provideranfrage.
Vier Fluttertests fuer persoenlichen Adapter und bestehende390px-Maske gruen:
angezeigter Team-/Versionsstand, Fehlantworten, Originalbestaetigungsmaske und
gesperrte entfernte Spiele. Keine native Geraete-/Push-Gesamtabnahme.
