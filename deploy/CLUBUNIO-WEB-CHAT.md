# Chat im ClubUnio-Backend

Stand04.11.2026: neue Anforderung aufgenommen, Bestand geprueft. Weboberflaeche
und neue Kommunikationsrechte noch nicht implementiert oder bereitgestellt.
Ziel: gleicher fachlicher Funktionsumfang wie der persoenliche Appchat, mit
komfortablem Nachrichtenversand fuer Admins und Geschaeftsstelle/Backoffice.
Keine zweite Chatdatenbank und keine Kopie des Flutter-Codes ins Backend.

## Gemeinsamer Bestand

`ChatService`, `ChatControls`, `ChatAttachments` und `ChatPreferences` enthalten
bereits Nachrichten, Rechte, private Dateien und persoenliche Einstellungen.
`ChatService::send` uebergibt bei aktiviertem Push innerhalb des vorhandenen
Schreibablaufs an `MobilePushOutbox::chatMessage`. Der Webadapter muss denselben
transaktionalen Dienstweg verwenden; kein direkter Tabelleninsert und kein
FCM-Aufruf im Webrequest. Die Websession liefert den angemeldeten Account und
den aktuell gewaehlten Mandanten; kein technisches Sammelkonto oder neuer JWT.

Im Bestand fehlen eine Chatseite in der normalen Filament-Shell und ein eigenes
Chatmodul im `PermissionCatalog`. Vereinsankuendigungen sind derzeit ueber
`MobileAccess::admin` an die Rolle Admin gebunden. Dieser allgemeine Admincheck
wird auch ausserhalb des Chats verwendet und darf fuer Backoffice NICHT global
aufgeweicht werden. Chatbezogene Rechte muessen gezielt und fuer Web/App
konsistent implementiert und getestet werden. Dynamische Profile sind bislang
nicht pauschal zum persoenlichen Mobile-Zugriff berechtigt.

Quellen fuer den Funktionsabgleich: `mobile/docs/CHAT-FEATURES.md`,
`mobile/docs/DIRECT-CHAT.md`, `deploy/MOBILE-CHAT-DELTA.md`,
`deploy/CLUBUNIO-MOBILE-PUSH.md` und die genannten Dienste. Aeltere Hinweise in
Kommunikationsdokumenten sind Entwicklungsverlauf, nicht der aktuelle Release-
oder Providerstatus. Aktuelle Pushgrenze: `CLUBUNIO-PITCH-FOLLOWUP.md`.

## Funktionsumfang und Abnahme

| Bereich | Webziel | Verbindliche Grenze |
| --- | --- | --- |
| Unterhaltungen | Direkt-, Mannschafts-, Staff-, Terminchat und Vereinsankuendigungen | Gleiche IDs, Inhalte und Sichtrechte wie App; keine freien Fremdchat-Zugriffe |
| Uebersicht | Suche, ungelesene Zaehler, Mannschaft/Saison, letzte Nachricht | Nur aktuell berechtigte Kontakte und Unterhaltungen |
| Nachrichten | Senden, Verlauf nachladen, Entwurf erhalten, Bearbeiten/Entfernen | Bestehende Zeitfenster, Revisionen, stabile client_id und Konfliktbehandlung |
| Aktualisierung | Neue/geaenderte Nachrichten, Reaktionen und Lesestand ohne Vollreload | Bestehender Delta-/Cursorvertrag, sichtbarkeitsabhaengiges Polling, keine neue WebSocket-Infrastruktur |
| Anhaenge | Bild-/PDF-Auswahl, Vorschau, privater Download; Kamera soweit Browser unterstuetzt | Vorhandene Typ-/5MiB-Grenze, Quarantaene, Scan und Publikation; kein Scannerfallback |
| Umfragen | Erstellen, einfach/mehrfach abstimmen, Frist und Ergebnisse | Bestehende Autorisierung und Stimmregeln, keine Terminabmeldung durch Abstimmung |
| Reaktionen | Hinzufuegen, ersetzen, entfernen und Reagierende anzeigen | Serverberechtigung und paginierte Teilnehmerliste |
| Termine | Bestehenden Mannschaftstermin auswaehlen und verknuepfen | Frische Team-/Terminsicht; Auswahl allein versendet nichts |
| Einstellungen | Stummschaltung, Direktkontaktsperre, persoenlicher Lesestand | Konto-/Mandantenbezug; gleiche Wirkung in App und Web |
| Moderation | Melden, berechtigte Gruppenmeldungen bearbeiten/abschliessen | Kein allgemeines Mitlesen privater Direktchats; bestehende Grenzen fuer Direktmeldungen erhalten |
| Sprache/KI | DE/EN-Oberflaeche und vorhandene optionale Text-/Uebersetzungsaktionen | Aktivierte Sprachen, explizites Opt-in/Einwilligung, geschlossene Provider nicht als aktiv darstellen |
| Rundnachrichten | Erlaubte Mannschaften/Staff/Vereinsankuendigungen mit Vorschau | Empfaengeranzahl/Fingerprint vor Versand, erneute Bestaetigung bei Aenderung |
| App-Push | Webnachrichten loesen denselben deduplizierten Pushweg aus wie Appnachrichten | Aktuelle Bindung, Rechte, Stummschaltung, kein Selbst-/Doppelversand; keine privaten Texte in der Sperrbildschirm-Payload |

Gleicher Funktionsumfang bedeutet fachliche Paritaet, nicht identische mobile
Bedienung: Desktop mit Chatliste links und Unterhaltung rechts, auf schmalen
Ansichten Liste und Unterhaltung nacheinander. Normale ClubUnio-Navigation,
Mandantenwahl und blaues Design; vorgesehener Pfad `/chats` ohne Verein im URL.
Keine eigenstaendige zweite Shell. Gruppen-/Empfaengerauswahl in Dialogen,
verstaendliche Tooltips, Tastaturbedienung und kontrollierter Fokus.

## Rechte und Backoffice

Vorgeschlagenes eigenes Modul Kommunikation/Chat mit getrennten Funktionen:
Lesen, Senden, Anhang hoch-/herunterladen, Umfragen erstellen,
Rundnachrichten veroeffentlichen und Gruppenmoderation. Endgueltige technische
Schluessel bei Umsetzung in Katalog, Policies und Tests gemeinsam festlegen.

Admin und berechtigte Geschaeftsstelle sollen Vereinsnachrichten bequem
veroeffentlichen koennen. Die konkrete Funktions-/Empfaengerfreigabe wird
explizit abgebildet, nicht durch Zuweisung der allgemeinen Adminrolle.
Ein Nur-Lesen-Profil darf keine Nachrichten senden, bearbeiten, entfernen,
abstimmen, reagieren oder moderieren. Leserecht allein erschliesst keine
privaten Fremdunterhaltungen. Moderationsrechte sind nicht Teilnahme an fremden
Direktchats. Bestehende Benutzer- und Profilzuordnungen nicht pauschal aendern.

Alle Aktionen pruefen Serverrechte erneut, auch bei offenen alten Browser-Tabs,
Widerruf und Mandantenwechsel. Supportsimulation darf ihre Schreibsperre nicht
ueber Chataktionen umgehen. Eine gemeinsame chatbezogene Policy darf bestehende
persoenliche App-/Finanz-/Mitgliederdatenrechte nicht erweitern.

## Push und Betriebsgrenze

Gemeint ist Webversand mit Benachrichtigung in der App. Betriebssystem-Push
im Desktopbrowser ist eine separate Erweiterung und nicht automatisch enthalten.
Nachrichten, Empfaenger und Outbox werden gemeinsam committed; keine
Benachrichtigung bei Rollback und keine zweite bei erneutem Senden desselben
unveraenderten Entwurfs. Gelesene/ungelesene Daten bleiben auch ohne FCM nutzbar.

Aktuell fehlen die ClubUnio-Firebase-Registry/Providerkonfiguration und eine
aktive Bindung des freigegebenen Testkontos. Push-Erfassung und Versand sind
ausgeschaltet. Workerbereitstellung/-ausfuehrung, FCM/APNs und echte Android-/
iOS-Anzeige/Oeffnen gesondert abnehmen; kein funktionierender Echtversand
behaupten, bevor dieser Nachweis vorliegt. Bisherige Testfreigabe betrifft nur
klar gekennzeichnete Meldungen an das eigene Konto des Auftraggebers.
Keine echten Vereinsrundnachrichten als Test und kein neuer Chat-E-Mailversand.

## Umsetzungspakete

1. Gemeinsame Chatberechtigungen, sichere Websession-/Mandantenanbindung und
   Funktionsmatrix fuer Admin, Backoffice, zustandige Trainer/TL und Nur-Lesen.
2. Integrierte Weboberflaeche mit gemeinsamen Diensten und vollstaendigen
   bestehenden Chataktionen, inklusive Fehler-/Leer-/Ladezustand und Entwurfsschutz.
3. Verwaltungsversand mit Suche, Empfaengergruppen, Vorschau und transaktionalem
   Publizieren; vorhandene Ankuendigungswege fachlich abgleichen, nicht duplizieren.
4. App/Web-Regressions- und Rechtepruefungen, Mandantenwechsel, Wiederholung,
   Rollback, Upload/Scan, Pagination/Pollingbudgets und DE/EN-Abnahme.
5. Echte isolierte Push-Abnahme nach Konfiguration und Geraetebindung sowie
   angemeldete Desktop-/Mobilpruefung. Benutzer kann sich aktuell nicht anmelden;
   die Sichtpruefung bleibt eine explizite Pendenz, kein bestandener Test.
6. Gezielter ClubUnio-Release, Doku/Migrationsplan und Commit&Sync. Keine
   PHPMaker-DEV-/PROD-Aenderung, Storeveroeffentlichung oder Datenauffrischung.

Bestehende Chattabellen bleiben gemeinsame Quelle. Falls zusaetzliche Metadaten
fuer Rechte oder Versandauftraege erforderlich sind, nur additive gepruefte
Migration nach Sicherung; keine Nachrichten kopieren oder Lesestaende resetten.
