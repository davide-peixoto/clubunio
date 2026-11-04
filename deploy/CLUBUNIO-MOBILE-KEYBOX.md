# Persoenliche Igloo-Schluesselboxvertraege (G4)

Aktueller DEV-Nachweis04.11.2026: Identity-/Push-/Challenge-/Keybox-Unterbau ist
auf DEV,400/500/600/700 atomar nach gepruefter verschluesselter Sicherung
angewendet.14 leere neue Tabellen/30 FKs, Elternzeilen unveraendert; nur
zusaetzliche Session-Unique wie geplant. Runtime-/Mail-/Zustellungs-/PINflags
bleiben geschlossen; externe Konfiguration/Abnahme und weitere Integration
bleiben offen. Details: PI.md und CLUBUNIO-MOBILE-IDENTITY-PUSH-SCHEMA.md.
Fruehere Vorbereitungsangaben unten sind historisch, keine aktuelle Aussage
ueber den inzwischen bestaetigten Schema-/DEV-Stand.


Stand04.11.2026: Quellen lokal getestet, noch keine Betriebsfreigabe. Migration
700 wurde durch Root als geschlossene DEV-Foundation angewandt; Verwaltungsdelta
und echte Ausgabe bleiben separat freizugeben. Keine echte PIN-, Schluessel-,
Igloo-, BLE-, Bridge- oder Hardwareaktion durch Entwicklungstools.

## Original und Provider

Original IglooKeyboxRepository erstellt7Tage-Hourly-AlgoPINs mittels OAuth
Client Credentials und variance1. Original Profil-/Igloo- und Busmasken bleiben
erhalten, erhalten jedoch persoenliche Repository-/Callbackinjection. Keine
Clientsecrets, Provider-URLs, Rohgeraete-ID oder Notfall-PIN in der App;
keine technischen Integrationstokens, Legacy-HTTP-Ausweichwege oder simulierten
PINs im persoenlichen Vertrag. Der bestehende Web-Simulationsdienst bleibt
separat; dessen TEST-Codes werden niemals als echte Ausgabe verwendet.

Fester OAuth-Endpunkt auth.igloohome.co/oauth2/token. Originales Protokoll
legacy: api.igloodeveloper.co/igloohome/devices/{id}/algopin/hourly. Optionaler
aktueller home-Modus nur gemaess offizieller Doku, festeapi.igloohome.co/home-
Origin. Keine frei konfigurierbaren ausgehenden URLs, Redirects oder Retry.
OAuthscope ausschliesslich igloohomeapi/algopin-hourly; keine Lock-/Unlock-
oder Master-PIN-Scopes. Credentials liegen nur in geheimen verschluesselten
tenant_settings area=keybox, IGLOOHOME_CLIENT_ID/IGLOOHOME_CLIENT_SECRET.

Offizielle Quellen:
[Individual Account](https://docs.igloohome.co/home/individual-account),
[AlgoPIN Codes](https://docs.igloohome.co/home/algopin-codes),
[offizieller Legacyendpoint](https://www.igloohome.co/developers).
Hourly erlaubt1..672h und drei Varianzen. Laengere Codes brauchen reale
Erstaktivierung am Schloss innerhalb24h ab Gueltigkeitsbeginn. Quelle und
Protokoll-/Geraetekompatibilitaet sind vor echter Freigabe zu pruefen.

## Rechte, Zeitraum und Revisionen

Alle sechs Routen unter persoenlichem clubs/{tenant}, bestehende
MobileMiddleware plus MobileKeyboxReady. Origin-/Custom-/Tenant-/Sessiongrenzen
bleiben erhalten. Profil zeigt nur aktuell erlaubte aktive Boxen: echte
bestehende Admin-Verwaltungsrechte oder explizite versionierte
accountgebundene Freigabe. Busboxen ausserhalb Reservationskontext nur fuer
Verwaltung bzw. explizit freigegebene technische Leitung. Kein Rollenname aus
der App, keine Kontakt-/Familiengleichheit als Schluesselfreigabe.

Reservationszugriff benutzt MobileBusData (konfigurierte aktuelle Saison)
und echte BusKeyboxAccessService.inspect-Objektrechte: genehmigte aktive
Reservation, eigener Antragsteller bzw. aktueller berechtigter Staffscope,
aktive Buszuordnung. Zeitfenster4h vor/nach Reservation, volle Stunden;
bei spaeterer Erstellung Start fruehestens aktuelle Stunde. Profilcode168h.
POST verlangt die angezeigte Boxrevision und bei Bus die Reservationsrevision,
UUID; keine vom Client gewaehlten PINs, Namen, Zeitraeume oder Provider IDs.

## Ausgabe und Widerrufsgrenze

700 speichert eigene inaktive Boxzuordnungen, explizite Accountfreigaben und
verschluesselte Auftraege/PINs. POST reserviert unter Tenant-/Sitzungslock einen
held202-Auftrag, Providerstart wird vor HTTP atomar einmalig beansprucht.
GET-Polls erzeugen keine Providerwirkung. Vor/nach HTTP gelten frische
Person-/Session-/Freigabe-/Box-/Reservations-/Providerrevisionen; unbekanntes
Ergebnis bleibt attention, ohne automatische Wiederholung. Antworten und
Ausnahmen werden nie mit PINs/Credentials/Providertoken geloggt.

Offline-AlgoPINs koennen durch Ausblenden oder Datenbankwiderruf nicht physisch
ungueltig werden. Der Vertrag behauptet das niemals. Zentrale Busmutation
invalidiert lokale Ausgabe innerhalb derselben Transaktion; begonnene Ausgabe
erhaelt remote_revocation_state=required. Grantwiderruf hat dieselbe Wirkung.
Jeder noch gueltige ungewisse/physisch zu widerrufende oder inzwischen wegen
Session-/Rechte-/Versionwechsel unberechtigte Code blockiert weitere Ausgabe
dieser Box bis Gueltigkeitsende. Kein angeblicher erfolgreicher Remote-Revoke.
Konkrete physische Loesch-/Blackliststrategie und Geraeteabnahme bleiben offen.

Defaultgeschlossen: native_keybox_enabled=false; keybox.issuance_enabled,
provider_enabled,operations_reviewed,offline_revocation_acknowledged=false;
tenant_slugs und approved_keybox_ids leer. Eine spaetere enge Betriebsfreigabe
verlangt konkrete Boxallowlist, Protokoll-/Geraetenachweis und ausdruecklich
bestaetigten Umgang mit Offlinewiderruf. Konfiguration allein ersetzt keine
physische Abnahme. Migration700/DEV/Commit nur koordiniert durch Root.

## Mobile

PersonalKeyboxRepository injiziert Original IglooKeyboxScreen; serverseitige
Boxauswahl/Revisionen und eigenes Auftragspolling. PINs bleiben nur im aktuellen
Screen, kein Legacy-PINcache/SecureStorage-Ausweichspeicher. Bei Background
wird die private Ausgabe entfernt; Resume liest frische Rechte. Profilhook
openKeybox erscheint nur fuer serverseitiges keybox_tenants containingClub.
PersonalBusRepository nutzt den neuen reservationsgebundenen Vertrag; geschlossene
Ausgabe deaktiviert den Originalbutton. Keine Notfallcode-Herleitung aus
fehlgeschlagenen persoenlichen Provideraufrufen.

## Echte Einrichtung und versionierte Freigaben

Eigene Backendresource **Persoenliche Schluesselboxen** unter Anlagen und
Spielbetrieb; getrennt von der bisherigen reinen Bus-Simulation. Nur echte
mandantengebundene Admins und ausdruecklich aktivierte administration_enabled-
Konfiguration. Neue Box mit Name, verschluesselter Providergeraetezuordnung,
optionalem eigenem Bus, Aktivzustand und Begruendung anlegen. Aendern/
Deaktivieren verlangt die angezeigte Boxrevision; leere Geraeteeingabe behaelt
die gespeicherte Zuordnung. Providergeraet und rohe Chiffre erscheinen nicht
in persoenlichen DTOs. Logische Box-ID ist fuer die enge Betreiberallowlist
sichtbar. Keine SQL-Handeintraege als Produktbedienweg.

Die Aktion **Persoenliche Freigabe** waehlt ein echtes eigenes Vereinskonto,
zeigt aktuelle Grant- und Boxrevision und erfasst Aktivzustand/Begruendung.
Neue Freigaben setzen echten persoenlichen Vereinszugang voraus. Auch alte
Freigaben inzwischen inaktiver Konten koennen widerrufen werden. Profilzugriff
auf Busboxen bleibt auf Admin/ausdruecklich freigegebene Geschaeftsstelle bzw.
technische Leitung begrenzt; Trainer erhalten Buszugriff reservationsgebunden.
Keine erfundenen UserLevels oder automatische Eltern-/Trainerfreigabe.

Headeraktion **Providerzugang** erfasst geheime OAuthwerte verschluesselt im
Tenantsetting, gebunden an den angezeigten Konfigurationsfingerprint. Alte
Secrets werden nicht an die Maske zurueckgegeben; leer behaelt bestehende
Werte. Angezeigt wird nur, ob Werte hinterlegt sind, keine behauptete
Provider-/Hardwareabnahme. Box-, Grant- und Credentialaenderungen laufen unter
Tenant-/Actorlock, sind versioniert/auditiert und invalidieren lokale Ausgabe;
begonnene/ausgegebene Offlinecodes bleiben wahrheitsgemaess physisch zu widerrufen.

config/clubunio-keybox.php unterstuetzt getrennte ausdrueckliche
CLUBUNIO_KEYBOX_ADMINISTRATION_ENABLED/ISSUANCE_ENABLED/PROVIDER_ENABLED/
OPERATIONS_REVIEWED/OFFLINE_REVOCATION_ACKNOWLEDGED-Schalter sowie TENANT_SLUGS,
APPROVED_IDS(JSON je Slug mit ganzzahligen Box-IDs) und PROTOCOL, jeweils mit
demselben CLUBUNIO_KEYBOX_-Prefix. Ungueltige Flags/Allowlists bleiben geschlossen.
Alle Defaultsfalse, keine aktuelle Env/Providerfreigabe durch Agenten.

Finale Tests: Keybox6 HTTP-/TEMP700-Faelle122Assertions gruen, einschliesslich
echter Filament-Boxanlage/-Aenderung/Grantform und PINinvalidierung bei Grant-/
Box-/Credentialrevision, ohne zusaetzliche Providerwirkung. Runtime-Konfiguration
2/13 gruen. Bestehendes geschlossenes
MobileFacilitiesBus4/95 und gemeinsame BusReservation/Simulation17/128 gruen.
TEMP-DDL referenziert ausschliesslich TEMP-Eltern; synthetische Fachfixtures
werden zurueckgerollt. Echte persoenliche Stornierung testet zentralen
Servicehook und entfernt lokale Ausgabe, ohne physischen Widerruf zu behaupten.
5 neue persoenliche Flutterfaelle, mit Bus-/Legacy-/AuthApp-/Kontowechsel-
regressionen29/29 gruen. Geschlossenes6Routen-/Flag-/Allowlistgate und PHP-Lint
bestanden. Keine permanenten Schema-/Provider-/Hardware-/PINaktionen.
