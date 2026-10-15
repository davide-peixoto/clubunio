# Ankuendigungen und Rundnachrichten: Fachpaket61

## Webverwaltung

Verein > Ankuendigungen und Rundnachrichten. Admin/Geschaeftsstelle erstellen
und bearbeiten Klartextentwuerfe mit Titel, Nachricht und Empfaengergruppe.
Einzelbenutzer, Rollen aus der Rollen-Hilfstabelle, aktive Mannschaft/Saison
oder alle erreichbaren Benutzer. Mannschaften umfassen Spieler, Staff, TL und
berechtigte Eltern minderjaehriger zugeordneter Mitglieder. Keine Emailadressen
oder Benutzerkennungen frei eingeben; Auswahl aus eigenen Tabellen.

Vorschau zeigt gerenderten Inhalt, genaue Empfaengeranzahl und maximal20 Namen.
Veroeffentlichung braucht die explizite Bestaetigung im Dialog. Die Vorschau
gilt15 Minuten, ist an den Bearbeiter und die Entwurfsversion gebunden.
Geaenderte Empfaenger, Vorlage oder Texte erfordern eine neue Vorschau.
Keine automatische Veroeffentlichung durch Speichern oder Kopieren.

Jeder erreichbare Benutzer erhaelt genau eine interne Notification, auch bei
Mehrfachzuordnung. Eine Transaktion schreibt Empfaengersnapshot, Notifications
und Abschlussstatus. Revisionsschutz und Datenbank-Uniqueindex verhindern
doppeltes Veroeffentlichen. Maximal5000 Empfaenger je Nachricht, Inserts in
250er-Paketen innerhalb derselben Transaktion, keine Scheduler-/Queue-Erweiterung.

Verwendet die bestehende bearbeitbare Vorlage announcement.manual/Kanal push.
Deaktivierte/fehlende Vorlage und leere Empfaengergruppe sperren den Vorgang.
Gerenderter Text samt Vorlagenversion wird bei Veroeffentlichung festgehalten.
Spaetere Vorlagenaenderungen aendern bereits veroeffentlichte Nachrichten nicht.
Inhalt und Zielgruppe sind nach Veroeffentlichung unveraenderlich. Bei Bedarf
als neuen Entwurf kopieren oder zurueckziehen. Redaktionelles Audit enthaelt
Operation, Version, Zielgruppenart und Anzahl, keine Nachrichtentexte/Namenslisten.

Statusbezeichnungen kommen aus announcement_statuses; Darstellung nutzt die
gemeinsamen Statusfarben. Filtern und Suche bleiben kombiniert gespeichert.
Die Liste zeigt die Empfaengerzahl und separat die als gelesen markierte Anzahl.
Letzteres ist keine technische Zustell- oder tatsaechliche Lesequittung.

## Empfaenger und Portal

Aktives Konto und Vereinszuordnung erforderlich. Admin/Office sind erreichbar;
andere Rollen brauchen ein aktiviertes Portal und eine genehmigte eigene oder
Elternzuordnung zu einem aktiven Mitglied. Austritt/archivierte Mitglieder,
ausstehende/widerrufene Links und volljaehrige Kinder geben keinen Elternzugriff.
Mehrfachlinks werden dedupliziert; Vereine und Dokument-/Anmeldeberechtigungen
werden dadurch nicht erweitert.

Portal > Ankuendigungen listet nur eigene veroeffentlichte Empfaengersnapshots.
Detail ist verlinkt aus internen Benachrichtigungen; fremde IDs liefern404,
fehlender Portalzugang403/Anmeldung302. Kein oeffentlicher Newsfeed.
Der Versandkreis ist historisch eingefroren: neue Konten werden nicht
rueckwirkend hinzugefuegt. Spaetere Teamwechsel entfernen nicht automatisch
alte allgemeine Rundnachrichten; ein aktueller Portalzugang bleibt erforderlich.
Keine vertraulichen individuellen Gesundheits-/Anmeldegruende hineinschreiben.

Zurueckziehen sperrt das Portaldetail und ersetzt den kopierten Text in den
zugehoerigen internen Notifications durch einen Rueckzugshinweis. Office-Historie,
Empfaengersnapshot und bisheriger Gelesen-Zustand bleiben erhalten. Bereits
gelesene, exportierte oder im offenen Browser stehende Inhalte lassen sich
dadurch nicht rueckwirkend ungeschehen machen. Kein Harddelete.

## Bewusste Abgrenzung

Nur interne Webzustellung: kein FCM, keine echten E-Mails, keine neuen App-API-
Endpunkte und kein Versandkanal-Override. Outbound bleibt aus. API/SaaS/Betrieb
und bestehendes PHPMaker DEV/PROD bleiben unveraendert. Echte Rundmails, externe
Pushzustellung, Terminversand und Abonnements sind nicht Teil dieses Pakets.
Keine echten Benutzer wurden im Rahmen der Tests angeschrieben.

## Migration und Ruecksprung

Eigene additive Migration000060_announcements (Release61; Branding60 ohne
Migration): Status-Hilfstabelle, announcements, announcement_recipients sowie
announcement_id/Fremdschluessel/Uniqueindex auf notifications. Nur ClubUnio-DB
db_acdf8d_dclubora / Schema clubora. Keine Altnachrichten importiert und keine
Bestehenden Empfaenger automatisch angeschrieben. Branding60 bleibt erhalten.
Ruecksprung auf Release60 mit additiver DB kompatibel, kein blindes Down.
Vor spaeterem Cutover Altankuendigungen/Empfaenger/Archivierung separat pruefen.

## Test und Deployment

Synthetische Konten/Mitglieder/Teams/Entwuerfe in zurueckgerollten Transaktionen.
Tests decken Webformulare, Rollen/Eltern, Mehrfachlinks, leere Gruppen,
Vorlagen-/Zielgruppendrift, Ablauf/Versionsschutz, Duplikate, Rueckzug, Portal,
Gelesen-Zahl und Vereinsgrenzen ab. Mail/HTTP-Doubles verhindern echten Versand.
Finaler Lauf:32 Tests/390 Assertions erfolgreich (Ankuendigungen, Navigation,
Branding, Mitglieder-/Dokumentregression und PMP-Inventar). Keine erneute
Live-Azure-Datei oder reale Rundnachricht ausgeloest.
Pi aktiv:20261015-clubunio-61-announcements; Ruecksprung60 erhalten.
189 verschluesselte Einstellungen/46 Vorlagen geprueft, Outbound aus.
Bestand unveraendert:2438 Mitglieder/69 Anmeldungen/70 Konten.
HTTP Login200, Verwaltungs-/Portalroute ohne Anmeldung302. Hashvergleich
Fachservice, Resource und Webrouten lokal/Pi identisch.
Angemeldeter Browser: Menue, leere Liste, Entwurfs-SlideOver und dynamischer
Rollen-Lookup geprueft; ohne Speichern/Publizieren geschlossen. Blaues Design
erhalten. Echte Veroeffentlichung nur mit transaktionalen Test-Fixtures, keine
manuelle Veroeffentlichung an reale Konten fuer den Sichttest.
