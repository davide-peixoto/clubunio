# Persoenliche Anlagenstatus- und Busvertraege

Stand03.11.2026: Bestehende SVS-Karten, Statuswechsel, Buslisten/Kalender,
Erfassungsformular und Entscheidungsdialoge verwenden eigene persoenliche
ClubUnio-Repositories. Keine zusaetzliche App-Oberflaeche oder Integrationstokens.
Die neuen Flags native_facilities_enabled/native_bus_enabled bleiben bis zur
gemeinsamen Serverfreigabe standardmaessig geschlossen. Kein eigener Release
oder Schemaeingriff durch diesen Arbeitsstrang.

## Anlagenstatus

GET facilities/fields unter /api/mobile/v1/clubs/{tenant}/ liefert reale aktive
FieldAvailability-Datensaetze und aktive Statusdefinitionen. Keine feste Zahl
von drei SVS-Plaetzen oder fest angenommene Status-IDs. Konfigurierte Namen und
Statusbezeichnungen bleiben erhalten. Offen/Gesperrt-Zusammenfassung folgt
blocks_training, die konkrete Auswahl dem echten Statusdatensatz.

PATCH facilities/fields/{field} verlangt angezeigte revision, status_id und
status_revision. Aendert sich die Statusdefinition nach dem Anzeigen, wird
abgewiesen statt unbemerkt eine andere Bedeutung zu uebernehmen. FacilityService
prueft echte field_availability-Rechte einschliesslich enger Platzwartrechte.
Platzzuordnung, Aktivierung und bestehende Notiz werden nicht mitgeaendert.
Read-/Write-Rechte folgen dem bestehenden Modulvertrag, keine neue globale
Mitgliederfreigabe. Das Original-Statuskartenlayout bleibt erhalten; der offene
Hero verwendet die ClubUnio-/Custom-Themefarbe.

## Busreservationen

GET bus/reservations mit status/start/recperpage/period, GET einzelner
Reservation, GET bus/options und GET bus/members verwenden BusReservationService
und persoenliche Mitglieds-/Verantwortlichkeitsrechte. Die mandanteneigene
SaisonID begrenzt den sichtbaren Bestand zusaetzlich. Besitzer behalten ihre
vorhandenen eigenen Reservationen auch nach Wegfall einer Trainerzuordnung.
Vergangene Reservationen werden weiterhin nur ausdruecklich geladen.
Historischer Status UNBEKANNT bleibt lesbar und in Gesamtzaehlern enthalten.

POST bus/reservations fragt wie bisher eine Reservation an. Ein eindeutiges
eigenes Mitglied wird persoenlich aufgeloest, andernfalls muss die vorhandene
Antragstellerauswahl verwendet werden. Auswahlrechte folgen dem echten
create-Vertrag; Suchdaten enthalten ausschliesslich ID/Namen, keine Kontakte.
Regulaere Antragsteller koennen sich durch einen gesendeten GENEHMIGT-Wert
keine Freigabe geben. Zeiten kommen als ISO-Zeitpunkte mit Offset; die
Service-Normalisierung wird nach dem Speichern abgeglichen und eine nicht
eindeutige Zeitumstellung rollt die Transaktion zurueck.

POST bus/reservations/{reservation}/{approve|reject|cancel} verlangt die
angezeigte revision. Der echte Dienst prueft Besitzer/Fachrechte, Zustand,
Zeitfenster und Ueberschneidung. Keine automatische Nachlese oder Wiederholung
bei Konflikt. Die persoenlichen Screens verwenden explizite zeilenbezogene
Faehigkeiten anstelle erfundener numerischer SVS-Rollen.

Listenstatuszaehler werden gemeinsam mit der Liste geliefert, Labels und
Anzeigerechte gebuendelt je Seite gelesen. Die persoenlichen Repositories
uebersteuern alle vorhandenen oeffentlichen Methoden und sperren ihren
Legacy-Transport. Konto-/Mandantenwechsel verwerfen alte Listen, Formulare und
nachlaufende Antworten; Root muss die Repositories je Kontext neu erzeugen.

## Schluesselboxgrenze und Nachweise

GET bus/reservations/{reservation}/keybox verwendet den vorhandenen
BusKeyboxAccessService zur persoenlichen Statuspruefung. Dieser Dienst ist
derzeit ausschliesslich SIMULATION, issuanceEnabled=false. Der native Vertrag
liefert deshalb ehrlich available=false und keinen PIN/Notfallcode. Es gibt
keine native Generierungs- oder Oeffnungsroute. Die bestehende Karte zeigt die
fehlende Freigabe, keine scheinbar erfolgreiche physische Aktion. Einrichtung,
echte Ausgabe und physische Abnahme bleiben separat offen.

Drei PostgreSQL-Routentests/76 Assertions mit synthetischen zurueckgerollten
Daten: dynamische Plaetze/Statusrevisionen, Berechtigungen, fremde Zuweisungen,
Busantrag/Freigabe/Stornierung, Kapazitaets-/Zeitkonflikt, Mandanten-/Saison-
grenzen, Historie und Besitzerzugriff erfolgreich. Keine Provider-/Fahrzeug-
oder physische Schluesselboxaktion, keine Migration.

Releasegate check-mobile-facilities-bus.php --foundation bestaetigt die zwei
Anlagen- und sieben Busrouten mit persoenlichem Middleware-/Freigabeschutz
sowie das bereits vorhandene Schema. Lokal erfolgreich, keine Datenmutation.
Bei Aktivierung die expliziten --facilities-enabled/--bus-enabled verwenden.

25 Fluttertests fuer neue persoenliche Adapter und vorhandene Bus-/Anlagen-
regressionen bestanden; darunter9 neue Tests mit390px-Masken, Versionskonflikten,
Konto-/Mandantenwechsel und Hintergrundschutz. Ein alter Entscheidungsdialog
entsorgte den TextEditingController waehrend seiner Schliessanimation; das
bestehende Dialoglayout besitzt den Controller jetzt bis zum echten Unmount.
Ein spaeter Keybox-Read konnte zuvor Hintergrunddaten erneut anzeigen;
Requestgeneration und Timer werden nun beim Hintergrundwechsel entwertet.
Native Android-/iPhone- und Hardware-Gesamtabnahme weiterhin offen.

Nach dem gemeinsamen Modulprofilumbau: bisherige3 PG-Faelle/76 Assertions
und neuer persoenlicher Menuevertrag1/19 separat gruen. Der Menuevertrag
prueft alte Clients, echte Trainer-/Eigenzugriffe auf Bus/Dokumente sowie
fehlende Anlagenrechte. Das neue Server-Kernelgate verwendet ausschliesslich
synthetische Daten mit Rollback und laesst reale Keyboxausgabe geschlossen.
