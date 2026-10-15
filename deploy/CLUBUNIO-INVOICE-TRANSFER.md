# Direkte Rechnungsuebertragung, Release64

## Bedienung

Rechnung im Web erfassen, Positionen pruefen und **Intern freigeben**. Danach
erscheint fuer Admins direkt in der Rechnungsansicht **An Bexio uebertragen**.
Der Dialog verlangt eine ausdrueckliche Sandbox-Bestaetigung. Nach bestaetigter
Anlage wird die Bexio-ID gespeichert und der Button ausgeblendet. PDF-Vorschau
und bestehender Sandbox-Abgleich verwenden die registrierte Zuordnung.

Das System ist ausschliesslich mit **SVS Api Test** verbunden. Die serverseitige
Pruefung von Firmenname und Profil-ID bleibt als Schutz bei Neuverbindungen
erhalten. Keine produktive Bexio-Verbindung wird benoetigt oder angesprochen.

## Weitere Testmitglieder

Neue ClubUnio-Rechnungen koennen auch fuer aus dem Altbestand importierte
Mitglieder getestet werden. Je Mitglied/Verbindung wird ein separater Kontakt
`ClubUnio Testmitglied <Registry-ID>` mit generischer Testadresse und ohne
E-Mail angelegt. Originale Bexio-Kontakt-IDs, Mitgliederdaten und historische
Rechnungsimporte werden nicht veraendert. Bereits importierte Rechnungen selbst
bleiben von einer erneuten Uebertragung ausgeschlossen.

Rechnungstexte, Positionen und Betraege werden hingegen tatsaechlich uebertragen;
deshalb nur bewusst gewaehlte Testinhalte verwenden. Der Sandbox-Beleg ist ein
**Entwurf**, keine automatische Bexio-Freigabe, E-Mail oder Bankzahlung.
Eltern-/Originalempfaenger werden fuer diesen direkten Testweg nicht verwendet;
deren bestehender gesonderter Fachtest bleibt unveraendert.

## Sicherheit und Wiederholung

Mandant, aktuelle Adminrolle, interne Freigabe, Version, Mitglied und aktuelle
Verbindung werden serverseitig geprueft. Sandbox-Empfaenger sind getrennt von
den Mitgliedern registriert und an die Versandpruefung gebunden. Kontakt-ID,
Testname, UUID-Marker und leere E-Mail werden vor der Uebertragung geprueft.

Ein Kontakt-/Rechnungs-Timeout fuehrt zu einem unklaren, gespeicherten Auftrag.
Erneutes Ausloesen gleicht diesen nur lesend ab, statt einen weiteren POST zu
senden. Nicht eindeutige oder unvollstaendige Ergebnisse bleiben gesperrt;
Verwaltung unter Bexio-Sandbox pruefen. Nach einer Neuverbindung muessen auch
die registrierten Kontakte nachgewiesen werden. Kein automatisches Loeschen
oder Zuruecksetzen von unklaren Auftraegen.

## Migration und Abnahme

Eigene additive Migration `2026_10_15_000063_sandbox_recipients.php`:
`bexio_sandbox_recipients` mit mandantengebundenen Fremdschluesseln und
Eindeutigkeiten; `bexio_dispatch_trials.sandbox_recipient_id`. Keine Uebernahme
oder Umschreibung bestehender Kontakt-IDs. Datenbankziel bleibt ausschliesslich
die eigene ClubUnio-PostgreSQL-Datenbank. Ruecksprung auf Release63 belaesst
die additive Tabelle und ihre Nachweise; keine automatische Down-Migration.

Live-Test: ausschliesslich synthetisches Mitglied53550, Rechnung9298, CHF1.00,
Bexio10 / RE-00010, Kontakt5. Entwurfstatus7 und PDF-Berechtigungspruefung
bestaetigt. Keine fremde Benutzerrechnung versendet, keine E-Mail oder Zahlung.
Wiederholbarer Test: `tools/clubunio-invoice-transfer-check.php`, standardmaessig
lesend; `apply` ausschliesslich fuer diesen fest markierten synthetischen Fall.

Automatisierte Abnahme umfasst Webdialog, Bestaetigung, Versions-/Rollen-/
Mandantenschutz, unveraenderte Originalkontakt-ID, Kontaktmanipulation,
Kontaktwiederverwendung, PDF-Zuordnung und Timeout-Abgleich ohne Doppelanlage.
Regression32/246 und68/311 (ueberlappend), abschliessender Nachlauf8/45 erfolgreich.
Pi-Release64 aktiv, Quellhashes und lesender Sandbox-Test identisch. Login200,
Rechnung ohne Anmeldung korrekt302; 189 Settings und46 Vorlagen geprueft.
Keine manuelle Browserabnahme behauptet: Chrome blockiert wegen eines offenen
Erweiterungsfensters. Der neue Rechnungsdialog ist per Livewire getestet.

API, SaaS, Betrieb, App und PHPMaker DEV/PROD bleiben unveraendert.
