# ClubUnio Produktwebseite – 05.11.2026

## Stand und Einstieg

Lokal umgesetzt unter `/de` und `/en`, Vorschau auf http://127.0.0.1:8093/de.
Bestehendes Dashboard `/` und `/login` bleiben erhalten. Kein Serverrelease,
keine Domain-/DNS-Aenderung und keine SaaS-/Store-Aktivierung.

DE/EN-Kataloge: `lang/{locale}/marketing.php`. Freigaben weiterhin aus
`config/clubunio-languages.php`; gesperrte Sprache liefert 404. Explizite URL-Sprache
hat Vorrang fuer diese oeffentliche Seite, ohne persoenliche Sprachpraeferenzen
zu speichern. Alle Assets lokal, kein Tracking oder externer Fontdienst.

## Inhalt und Preise

Mitglieder/Familien, Saison-/Team-/Platz-/Busplanung, Kommunikation/Dokumente,
Spesen/Beitraege, Signage und Mobile App. Eigener Integrationsbereich fuer Bexio,
SFV und API. Keine behauptete offizielle Partnerschaft oder vollstaendige
Clubcorner-Synchronisation. App-/Finanzfreigaben und Drittanbieterkosten benannt.

Geplante Monatsrichtpreise bei Jahreszahlung: 100 Mitglieder CHF15,
300 CHF29, 750 CHF49, 1500 CHF79; darueber individuelles Angebot.
Konfiguration in `config/clubunio-marketing.php`. Elternzugriffe, Sponsorenkontakte
und archivierte Mitglieder werden nicht zusaetzlich gezaehlt. Kein Checkout.
Rechner behandelt Grenzen inklusive, ungueltige Eingaben neutral.

Extras: Domain CHF5/Monat + CHF99 Einrichtung, Design CHF390–990,
Einfuehrung CHF290–1490, Marken-App separat mit Einrichtung und Wartung,
weitere Schulungen/Speicher/SMS/KI nach Umfang. Preise exkl. allfaelliger MWST.
Alle Anfragen oeffnen einen lokalisierten E-Mail-Entwurf an info@clubunio.com;
keine automatische Nachricht oder Speicherung persoenlicher Anfragedaten.

## Demoverein und Screenshots

Auf ausdruecklichen Benutzerauftrag angelegt: `fc-horizon-demo`, Tenant19490,
Name `FC Horizon · DEMO`. Ausschliesslich synthetische neue Daten: 12 Mitglieder
mit example.invalid-Adressen, 4 Teams, 16 Termine, 4 Spesen mit Positionen,
4 Trainingszeiten mit eigenen Lookupdaten und aktiver Saison2026/2027.
Keine Kopien echter Mitgliederdaten oder Providerverbindungen.

Erstellung ueber vorhandenen Plattformservice, protokolliert mit Begruendung.
Dediziertes Konto `fc-horizon-demo@example.invalid` hat ausschliesslich
Adminzugriff auf diesen neuen Verein. Persoenlicher Plattformadmin bleibt
ebenfalls explizit diesem Demoverein zugeordnet. Keine globale Freischaltung
des Demokontos. Passwort zufaellig, nur privat unter
`storage/app/marketing-demo-access.json`; niemals committen oder oeffentlich ausgeben.

CLI-Werkzeuge (nur gezielt, keine Migration/import/refresh):

1. `php -d extension=pdo_pgsql tools/clubunio-marketing-demo.php --create`
2. `php -d extension=pdo_pgsql tools/clubunio-marketing-demo-account.php`
3. `php -d extension=pdo_pgsql tools/clubunio-marketing-demo-display.php`

Idempotente Wiederholung laesst bestehenden Demobestand unveraendert.
Screenshots im angemeldeten DEMO-Kontext von dev.clubunio.ch, fuer Mitglieder,
Teamplanung, Spesen und Signage jeweils DE/EN. Acht unverfaelschte PNGs unter
`public/images/marketing/{de,en}`; kein eingebauter Fremdmandanten-/Profilwechsel.
Die englischen Screenshots dokumentieren den echten Bestandsstand inklusive
der verbleibenden Labels `Meine Ansichten` und `Beleg-Nr.`; keine Bildretusche.
Keine Wetter-/Instagram-/Bexio-/SFV-Zugaenge eingerichtet, keine echten
Nachrichten, Bankzahlungen, Belegfreigaben oder Storeaktionen.

Lookupvariante fuer Demo-Spesenstatus Englisch separat gespeichert. Standard-
Fallback der Anwendung bleibt erhalten; eigene Namen/Termintitel werden als
Freitext nicht automatisch uebersetzt. Keine neuen produktiven Templates.

## Abnahme und spaetere Bereitstellung

MarketingWebsiteTest: 2 Tests/38 Assertions, darunter explizit kein DB-Zugriff
beim isolierten Rendern, korrekte Sprachen, Kontakt, Bilder und Preisgrenzen;
gesperrte Sprache404. Gesamte Katalog-/Platzhalterpruefung erfolgreich.
JavaScript-Syntax geprueft. Desktop DE/EN visuell, Sprachwechsel und Rechner
im Browser geprueft; mobile Ansicht390px DE/EN ohne horizontalen Ueberlauf.
Browserrechner751 => CHF79, 1501 => individuelles Angebot.

Fuer ein spaeter freigegebenes Release nur dieses Dateidelta bereitstellen;
keine Schemaaenderung oder Datenmigration erforderlich. Routecache neu erstellen.
Oeffentliche Zielhosts/Startseitenrouting, Betreiberangaben, Impressum und
Datenschutzhinweise vor einer oeffentlichen kommerziellen Veroeffentlichung
konkret ergaenzen. Noch keine Rechts-/Hostingzusagen erfunden.
Demodaten nicht aus Datenbankdumps mitliefern; nur bei ausdruecklichem Bedarf
gezielt anlegen. Rueckweg: Marketingrouten und Assets aus dem Release entfernen,
bestehendes Dashboard und Demobestand bleiben davon unabhaengig.

Vorhandene fremde Aenderungen in README, Roadmap, Migrationsplan, AGENTS,
.gitignore und Mobile-Uebernahmewerkzeugen nicht Bestandteil dieses Commits.

## Aktualisierung 09.11.2026

DE/EN-Produktseite mit Baselbieter Herkunft, Hosting in Deutschland und Backups
in Finnland. Ergaenzt um Chat, Sponsorenlauf, Dokumentenserien und konfigurierbare
Dokumentenspeicher. Das Anfrageformular erzeugt im Browser einen Mailentwurf an
info@clubunio.com. Es benoetigt JavaScript und ein E-Mail-Programm.

Datenschutz fuer Website und App unter /de/privacy und /en/privacy mit der
bestaetigten Betreiberanschrift. Vor Store-Einreichung die oeffentliche URL
in App und Store-Metadaten hinterlegen und Angaben zu Dienstleistern,
Datenstandorten, Aufbewahrung und Kontoloeschung mit dem Zielbetrieb abgleichen.
Bei Kontoerstellung in der App die separaten Loeschanforderungen der Stores
beruecksichtigen. Keine Veroeffentlichung oder Betriebsaenderung.

## Direktversand fuer Demo- und Angebotsanfragen

Das Formular sendet serverseitig an info@clubunio.com. Die Bestätigung erscheint
nach Annahme durch den Mailtransport. Antworten gehen an die im Formular
angegebene Adresse. Laravel-Route mit CSRF, Eingabeprüfung und Rate-Limit.
Die Website braucht für diesen Versand einen Laravel-Endpunkt.

Für den Zielbetrieb den dedizierten SMTP-Zugang privat konfigurieren:
CLUBUNIO_MARKETING_SMTP_HOST, CLUBUNIO_MARKETING_SMTP_PORT (Standard 465, SMTPS),
CLUBUNIO_MARKETING_SMTP_USERNAME, CLUBUNIO_MARKETING_SMTP_PASSWORD und
CLUBUNIO_MARKETING_MAIL_FROM. Erst danach CLUBUNIO_MARKETING_MAIL_ENABLED=true
setzen und den Konfigurationscache neu laden. Der Mailguard beschränkt diesen
Versandweg auf info@clubunio.com. Ohne Konfiguration zeigt das Formular einen
Fehler. Der Transporttest nutzt ausschließlich den In-Memory-Transport.

## Statische Bereitstellung auf dem VPS

Die Website liegt unter /home/runcloud/webapps/unio. Der Export aus der lokalen
Vorschau auf Port 8093 erfolgt mit python tools/export-clubunio-static.py und
einem neuen Ausgabeordner. Startseite, DE/EN und Datenschutz werden als HTML
ausgeliefert. Der Anmelden-Link führt zur bestehenden ClubUnio-DEV-Anmeldung.
Die alte Startseite wurde vor dem Kopieren ausserhalb des Webverzeichnisses
gesichert. Der Export benötigt Laravel lokal zur Ausgabe der Vorlagen.

Nur inquiry.php läuft auf dem Webserver. Es verwendet die cURL-Erweiterung
und SMTPS. Die fünf SMTP- und Absenderwerte aus dem vorherigen Abschnitt
müssen dem PHP-Pool als Umgebungsvariablen bereitgestellt werden. Das PHP-Skript
enthält keine Zugangsdaten. Die Abnahme prüfte die öffentliche Startseite,
beide Sprachfassungen, Datenschutzseiten, CSS und einen ungültigen Formular-POST.
Der echte Mailversand benötigt die SMTP-Konfiguration und einen Zustelltest.

## reCAPTCHA und Analytics

Statischer Export mit CLUBUNIO_RECAPTCHA_SITE_KEY (reCAPTCHA v3) und
CLUBUNIO_GA4_ID (GA4-Mess-ID). Beide sind öffentliche Werte. Bei aktiviertem
reCAPTCHA braucht PHP-FPM den geheimen Schlüssel in CLUBUNIO_RECAPTCHA_SECRET.
Den Schlüssel vor dem Export-Deployment im Pool konfigurieren. Die Prüfung
erfolgt vor SMTP mit Aktion inquiry, den beiden öffentlichen Hostnamen und
einem Mindestscore von 0.5. GA4 lädt erst nach Zustimmung und lässt sich über
die Analyse-Einstellungen widerrufen. Beide Sprachfassungen und die
Datenschutzseiten enthalten die entsprechenden Hinweise.
