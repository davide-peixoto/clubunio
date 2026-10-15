# Oeffentliche Webansichten, Release65

## Fehlerursache und Zugaenge

Pi-Log15.10.2026: `Unable to find component: [clubunio.signage-feeds]`.
Die Klasse unter `App\Livewire\ClubUnio` war vorhanden, der Alias aber nach dem
Namenswechsel nicht explizit registriert. AppServiceProvider registriert ihn
jetzt ausdruecklich. Vorschau samt eingebetteter Komponente ist getestet.

- Verwaltungsvorschau: https://dev.clubunio.ch/signage-preview (Anmeldung).
- Oeffentliches Info-Display: https://dev.clubunio.ch/signage/sv-sissach.
- Oeffentliches Test-Anmeldeformular: https://dev.clubunio.ch/join/sv-sissach.

In der Signage-Vorschau fuehrt **Oeffentliche Signage** zum Display; die dort
gewaehlte Trainingsperiode wird mitgegeben. Im Display laesst sich eine aktive
Periode waehlen, die URL behaelt sie bei der Aktualisierung alle60Sekunden.
Bei genau einer aktiven Periode wird diese automatisch gewaehlt. Es wird kein
Winter-/Sommerwechsel geraten. Wetter/Instagram bleiben separat asynchron,
mit bestehendem Cache/Cooldown und ohne Zugangsdaten im Browser.

Die Anmeldeliste verlinkt **Oeffentliches Anmeldeformular**. Diesen Zugang gab
es in ClubUnio bisher nicht; das vorhandene Portal war nur fuer angemeldete
Pruefer. Bestehende PHPMaker-Webformulare werden nicht ersetzt oder umgeleitet.

## Oeffentliche Datengrenze

Signage zeigt ausschliesslich aktive, fuer Signage freigegebene Wettkaempfe
von heute, Mannschafts-Trainingsplan, Anlagenstatus und Signage-Meldungen.
Keine Spielerlisten, Kontaktadressen, Mitgliederdossiers oder Anmeldungen.
Texte werden HTML-escaped. Die oeffentliche Ansicht erlaubt keine Datenpflege.
Wetter-/Instagram-Livewire-Modus und Mandant sind gesperrte Properties;
Publikationsfreigabe wird bei jedem Abruf erneut geprueft.

Zwei nicht geheime boolean-Settings im Bereich `public` steuern die Freigabe:
`SIGNAGE_PUBLIC_ENABLED` und `REGISTRATION_PUBLIC_ENABLED`. Aktiv plus Wert1
erforderlich; fehlend/ungueltig/inaktiv ergibt404. Aenderung nur durch Admins.
Migration64 aktiviert auf ausdruecklichen Wunsch ausschliesslich sv-sissach;
andere bestehende Mandanten bleiben deaktiviert. Keine SaaS-Erweiterung.

## Anmeldeformular

Aktive Vereins-Lookups fuer Land, Nationalitaet, Geschlecht und
Rechnungsempfaenger; gleiche fachliche Datenpruefung und Anfangsstatus
`submitted` wie im Backend. Keine oeffentliche Auswahl von Mitglied-ID,
Status, Mannschaft, Rolle oder Annahmeentscheidung. Vorhandene Anmeldungen
werden weder gesucht noch ausgegeben oder aktualisiert.

Foto bis5MB, bis3Ausweisdateien je10MB, nur JPG/PNG/WebP bzw.PDF. MIME-Pruefung,
private Azure-UUID-Dateien, kein oeffentlicher Dokumentlink/lokaler Fallback.
Uploads sind optional wie im bestehenden internen Erfassungsweg.
Bei Uploadfehler keine erfolgreiche DB-Anmeldung; bei teilweisem externem
Upload und spaeterem Fehler koennen private, nicht referenzierte Blobs
zurueckbleiben. Kein unsicheres automatisches Loeschen von Azure-Bestand.

CSRF, serverseitige Sessions mit einstundiger Form-ID, Honeypot, Mindestzeit,
Rate-Limit5POST/10Minuten, Einwilligung und erlaubte Feldliste. Persistierte
Request-UUID verhindert Doppelanlage auch bei wiederholtem POST. Neuer
Datensatz hat keine fingierte Admin-Urheberschaft: eigener anonymer
Submission-Nachweis mit Einwilligungszeit und eindeutig zugeordneter Anmeldung.
Dokumentmetadata enthaelt keinen erfundenen Bearbeiter. Keine Konto-/
Elternberechtigungen oder Mitgliedschaft werden automatisch vergeben.

Bestehende Ereignisse `registration.created` / `registration.received` werden
einmalig vorgemerkt; Outbound bleibt aus, kein echter E-Mail-/Pushversand.
ClubUnio bleibt Testumgebung: deutlich als Testanmeldung gekennzeichnet,
noch keine verbindliche Vereinsanmeldung. Vor Ablosung der Produktionsseite
rechtliche Datenschutzhinweise, Dokumentpflicht und Versand fachlich abnehmen.

## Migration und Abnahme

Additive eigene Migration64: `public_registration_submissions` mit
Mandanten-Fremdschluessel/UUID-Eindeutigkeit sowie zwei Publikationssettings.
Keine alten Anmeldungen importiert oder veraendert. Historische Formulardaten
und Mitgliedsentscheidung bleiben im bestehenden Pruef-/Annahmeprozess.
Ruecksprung64 moeglich, additive Nachweise behalten; keine Down-Migration.

Tests: anonyme Seiten/Lookups, echte Vorschau mit Unterkomponente, private
Uploadmetadaten, Ereignisse, Wiederholung, Uploadrollback, Fremdfelder,
ungueltige Lookup-ID, Einwilligung, Sessions, Honeypot, deaktivierte Publikation
und Sichtbarkeit heutiger freigegebener Spiele. Azure in diesen Tests gemockt;
kein echter Interessent und kein persoenlicher Ausweis als Test gespeichert.
Release65 auf Pi aktiv. Regression29/168 plus final12/77 ueberlappend erfolgreich,
inklusive aktivem Routen-Cache. Signage/Formular/CSS HTTP200, POST ohne CSRF419;
lokale/Pi-Quellhashes stimmen. 191 Settings/46 Vorlagen geprueft,69
Bestandsanmeldungen unveraendert. API/SaaS/Betrieb/App/PHPMaker DEV/PROD
unveraendert. Keine manuelle Browserabnahme: Chrome ist weiterhin blockiert.
