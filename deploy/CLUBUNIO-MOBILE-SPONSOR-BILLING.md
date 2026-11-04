# Persoenliche Sponsorenabrechnung im vorhandenen SVS-Ablauf

Stand 04.11.2026, lokal geprueftes geschlossenes Folgepaket. Keine echte
Bexio-, Mail- oder Bankaktion durch Tools; keine neue Datenbankmigration.

Der bestehende SponsorBillingScreen bleibt erhalten. Der persoenliche
Sponsorenrepository liefert je Anlass einen neu gebundenen Billingadapter.
Dieser ersetzt die bisher ausschliesslich lesende Ansicht durch die echten
versionierten Ablaeufe: lokale Vorschau/Vorbereitung, bestaetigte Kontakt-ID
oder Kontaktanlage, Bexio-Entwurf, Ausstellung, Testversand, Storno, Statusabgleich,
geschuetztes PDF und begruendeter manueller Versandnachweis. Alte SVS-Transporte
und die bestehende persoenliche Nur-Lesen-Route bleiben unveraendert.

Die neuen persoenlichen Routen unter sponsor-run/billing sind fest:
GET events/{event}, POST events/{event}/prepare,
POST invoices/{invoice}/{action} mit genau neun erlaubten Fachaktionen,
GET invoices/{invoice}/pdf mit verbindlichen event-/revision-Parametern.
MobileMiddleware/SponsorReady gelten immer; Vorbereitung, Aktionen und PDF
benoetigen zusaetzlich native_sponsor_billing_enabled defaultfalse.
Das lokale Lesen erzeugt weder Rechnungssnapshot noch Providerauftrag.

SponsorRunBillingService und SyncWorkService bleiben die fachliche Autoritaet.
Eigene Sponsor-/Bexio-Modulrechte, persoenliche Appberechtigung, Adminrecht fuer
Provideraktionen, Verein/Anlass, unabhaengige Verbindungsbindung und angezeigte
Revision werden frisch geprueft. Vorbereitung bindet die bestaetigte SourceVersion
an die echte freigegebene Zusage; mehrfaches Vorbereiten bleibt genau ein
Snapshot je Zusage. Jede Provideraktion verwendet den vorhandenen dauerhaften
SyncWork-Auftrag. Ein bestaetigt erfolgreicher identischer Aufruf liefert seinen
aktuellen lokalen Nachweis ohne weiteren Provideraufruf; ein unklarer Aufruf
wird ausschliesslich abgeglichen und niemals als zweite Erstellung wiederholt.
Provider-I/O liegt ausserhalb des kurzen Mobile-Guard-Transaktionsblocks,
damit Leases, Auftragsabsicht und unklare Ergebnisse nicht zurueckgerollt werden.

Die Rechnungsempfaenger kommen aus dem verschluesselten kanonischen Snapshot.
Aktionsfaehigkeiten zeigen tatsaechliche Rechte, Konfiguration, Verbindungsstand,
Lease und Unsicherheitszustand; eine Listenaktualisierung allein gilt nicht
als Nachweis eines unklaren Versands. Nur ein ausdruecklich begruendeter
Versandnachweis schliesst diesen Zustand. Zahlungen/Gutschriften werden frisch
aus dem vorhandenen Providervertrag gelesen; bezahlte/teilbezahlte Belege sind
nicht einfach stornierbar. Es gibt keine Bankzahlungsfunktion.

Der unveraenderte SponsorRunBexioClient verifiziert weiterhin die festgelegte
Sandboxidentitaet vor Providerzugriff; Versand bleibt ausschliesslich an
TestMailGuard::RECIPIENT umgeleitet. Kein natives Konfigurationsschreiben,
kein produktiver Bexio-Zielwechsel und keine Kostenfreigabe durch den Client.
Fehlende Konfiguration oder geschlossene Freigabe bleibt sichtbar geschlossen.
PDF bleibt bei deaktivierter Versandkonfiguration mit echten Downloadrechten
moeglich, sobald die gesonderte persoenliche Providerfreigabe bewusst aktiv ist.
Der Client prueft MIME/PDF-Kennung,20-MiB-Grenze, SHA256, Content-Length,
Sitzung und X-Sponsor-Billing-Revision gegen die wirklich angezeigte Version.

Nachweise: vier echte PostgreSQL-/HTTP-Faelle mit87 Assertions erfolgreich,
ausschliesslich synthetische Rollbackdaten und isolierter Provider/Tokentransport.
Sie pruefen SourceVersion/Idempotenz, vollstaendigen Providerzyklus/PDF,
bekannte Wiederholung ohne Providerread, dauerhafte unklare Erstellung mit
reinem Abgleich, bezahlten Stornoschutz und Rollen-/Konfigurations-/Sitzungssperren.
Fuenf neue Flutterfaelle mit echtem MobileApi/FakeTransport pruefen feste Pfade,
Anlass/Revision, PDF-Header/Sitzung, Originalmaskenfolge und manuellen Nachweis.
Vierzehn bestehende Billingmaskenfaelle und fuenf persoenliche Sponsorgrundfaelle
bleiben gruen. check-mobile-sponsor-billing trennt --foundation/--enabled und
fuehrt selbst keine Provider-/Rechnungsaktion aus. Echte Android-/iPhone- und
bewusst freigegebene Providerabnahme bleiben separat.
