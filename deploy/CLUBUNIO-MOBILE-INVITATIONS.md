# Zusaetzliche Mitglieder in der persoenlichen Teamplanung

Stand03.11.2026: Die vorhandene SVS-Mitgliederauswahl und Sofortanmeldung
sind auf den persoenlichen ClubUnio-Vertrag migriert. Hinzu kommt das gezielte
Entfernen einer zusaetzlichen Einladung innerhalb derselben Terminansicht.
Kein gesondertes Deployment durch diesen Arbeitsstrang; Sammelfreigabe separat.

## Fachvertrag

GET /api/mobile/v1/clubs/{tenant}/planning/events/{event}/member-options liest
aktive Clubmitglieder ausserhalb des aktuellen Kaders, ab zwei Suchzeichen,
alphabetisch und mit20 Eintraegen je Seite. Der Controller verlangt echte
persoenliche send- und update-Rechte fuer die Mannschaft. Lookups enthalten
nur ID und Namen; Trainerrechte geben keine privaten Kontakte frei.

POST events/{event}/invitations/{member} fuehrt die bestehende Fachoperation
PlanningGuestService.invite und anschliessend die persoenliche Anmeldung DABEI
atomar aus. Dies erhaelt den bisherigen SVS-Ablauf: Die Auswahl eines fremden
Mitglieds meldet es fuer genau diesen Termin an. Die gemeinsame Transaktion
rollt auch die Einladung und ihren Auditnachweis zurueck, falls Kapazitaet oder
andere Fachregeln die Anmeldung ablehnen. Keine neue Saison-/Teamzuordnung.

DELETE derselben Ressource entfernt nur die explizite Einladung mit echten
cancel-Rechten. Vorhandene Teilnahmehistorie bleibt erhalten. Eine spaetere
regulaere Mannschaftszuordnung wird dadurch nicht entfernt.

Beide Writes verlangen EventRevision und InvitationSnapshot aus der wirklich
angezeigten Auswahl beziehungsweise Teilnehmerzeile. Der verschluesselte Claim
bindet Mandant, persoenliches Konto, Termin, Mitglied und15Minuten Gueltigkeit.
Der Vergleich umfasst Termin-/Mitglieds-/Teilnahmezustand, Kader-/Einladungsstatus
und den Einladungsauditstand. Auch Entfernen und erneutes Einladen derselben
Tuple entwerten deshalb eine alte Bestaetigung. Keine zusaetzliche Migration.
Keine automatische Nachlese oder Wiederholung eines unsicheren Writes.

Mandant und Sitzung werden waehrend des gesamten Writes gesperrt; danach
erfolgen frische Authentifizierung und Fachrechtepruefung. Read-DTOfaehigkeiten
verwenden den gemeinsamen Anfragecache; der Write verwendet ihn nicht.
Die gemeinsame native Planungssperre schuetzt alle drei neuen Routen.

## Vorhandene App und Nachweis

AdditionalMemberOption behaelt den ausgewaehlten Claim. Der persoenliche
Repositorymodus akzeptiert keinen gewoehnlichen Lookup ohne diesen Snapshot.
Der vorhandene Suchdialog liefert das konkrete Objekt an die Bestaetigung;
spaetere Suchantworten ersetzen es nicht. Auch Entfernen verwendet den Claim
der angezeigten Teilnehmerzeile. Unvollstaendige Antworten bestaetigen keinen
Erfolg. Persoenliche Quellenrechte unterscheiden Gastanlage von Mitglieds-
einladung; fehlende Fachrechte bieten keinen unbrauchbaren Auswahlweg an.

Drei Fluttertests fuer Originalauswahl/Entfernen auf390px, Snapshotbindung nach
spaeteren Reads und Abweisung fehlender/ungueltiger Antworten erfolgreich.
Gezielte Analyse ohne Befund. Die persoenlichen PostgreSQL-Routentests pruefen
positive Anmeldung/Entfernung, Lookup-Paging/aktive Mitglieder/Kaderfilter,
Datenschutz, fremde Mandanten, fremde Konten, Terminwechsel, Ablauf der Claims,
volle Kapazitaet mit komplettem Rollback und Entfernen/Wiedereinladen.
Zwei PostgreSQL-Tests/79 Assertions erfolgreich. Alle Daten synthetisch und
vollstaendig zurueckgerollt; keine Provideranfrage.
Native Geraete-/Push-Gesamtabnahme bleibt separat.
