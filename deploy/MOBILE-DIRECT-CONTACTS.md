# Persönliche Direktchatkontakte (04.11.2026)

Auf DEV aktiviert: `20261104-clubunio-direct-contacts-v1`, Quellstand `956af46`. Keine Migration oder automatische Freischaltung/Zustellung.

## Kontaktregeln

Die Suche ist gerichtet. Spieler sehen Konten bestätigter Selbstzuordnungen von Spielern und Staff ihrer eigenen aktiven Mannschaften der aktuellen konfigurierten Saison. Eltern sehen ausschließlich Staff der Mannschaften ihrer bestätigten Kinder. Bestehende `MemberAccessService.represented()`-Regeln für aktive Mitglieder, bestätigte Links und Elternvertretung gelten unverändert; keine Zuordnung aus einer E-Mailadresse und keine zusätzliche Altersgrenze.

Trainer erhalten additiv ihre eigenen tatsächlich trainierten Spieler-/Staffmannschaften, die bestätigten Eltern ihrer dortigen Spieler, sämtliche aktive Teamstaffkonten des Vereins sowie den Staff eigener Kinderteams. Die Kinderrolle schaltet keine anderen Spieler oder Eltern im Kinderteam frei. Für den eigenen Trainerroster wird der unveränderte kanonische `MemberTeamAccessService.trainerTeams()`-Builder wiederverwendet (einzig seine Methodensichtbarkeit wurde public): aktive Trainer-/Assistenztrainerfunktion, aktives eigenes Mitglied, aktuelle Saison und aktive Mannschaft. Helfer/Coach/NULL im Kinderteam allein schalten dessen Spieler nicht frei; vereinsweite aktive Staffkontakte bleiben für Trainer gemäß Auftrag zulässig. Technische Leiter, Admin und Backoffice sehen aktive, persönlich zugangsberechtigte Vereinskonten. Rollen wirken additiv: ein Elternteil mit eigener Spielerzuordnung behält die eigene Spielermannschaft.

Ein Vereinskonto benötigt einen aktiven persönlichen Zugang: Admin/Office oder aktiviertes Portal mit einer wirksamen Selbst-/Elternzuordnung. Profil-, API-only- und inaktive Konten erhalten keinen Kontaktgrant. Ausgeschiedene Mitgliederdaten begründen keinen Selbst-/Elternvertretungsgrant; der bestehende Admin-/Office-Zugang bleibt davon getrennt. Staff-/Spielerzuordnungen, Mannschaft und Saison müssen aktiv und aktuell sein. Blockierungen wirken in beiden Richtungen.

## Antworten und Widerruf

`MobileAccess.canContact()` wird weiterhin von Direktanlage, bestehendem Chat, Nachrichtenlesen und Senden aufgerufen. Neue Chats benötigen die gerichtete Berechtigung des Initiators. Bei einer bereits bestehenden kanonischen Zweierkonversation mit genau zwei aktiven passenden Mitgliedschaften darf auch die Gegenrichtung antworten, solange wenigstens eine der beiden gerichteten Berechtigungen frisch besteht. Damit ist eine Antwort auf einen berechtigten Adminchat möglich, ohne dass Spieler dadurch alle Admins in der Kontaktsuche erhalten. Eine alte Konversation allein ist kein Grant; Rollen-/Link-/Rosterwiderruf oder Blockierung schließen sie, wenn keine aktuelle Richtung mehr zulässig ist. Vor Nicht-GET-Kontaktprüfung wird ein möglicher vor dem Schreiblock gelesener Saisonwert verworfen. Keine TTL und kein POST-Rechtecache.

## API und Abfragebudget

`GET /api/mobile/v1/clubs/{tenant}/contacts?q=Suchtext&limit=40` liefert unverändert `{data:[{id,name}]}`. `q` ist optional, höchstens120 Zeichen; Leerwert liefert die ersten erlaubten Namen. `limit` ist1..40, Standard40. Reihenfolge: Anzeigename, anschließend Konto-ID. Prozent, Unterstrich und Ausrufezeichen werden als tatsächlicher Suchtext behandelt. Keine Telefonnummern, E-Mailadressen, Dossierfelder oder vollständige Kontoliste im Client.

Die bisherige PHP-Schleife über Vereinskonten mit mehreren `canEnter`-/`canContact`-/Teamabfragen pro Kandidat entfällt. Frische Akteurs-/Familien-/Teamfakten und autorisierte Ziel-MitgliedsIDs werden ausschließlich innerhalb des aktuellen Aufrufs in wenigen Batchabfragen gebündelt; die begrenzte abschließende Kontoprojektion prüft Links, Zugang und Blockierung über Unterabfragen. Damit wird auch tief korrelierte Rosterarbeit pro Konto vermieden. Es werden keine Kandidaten einzeln als Account geladen. Ein kleineres Ergebnis wird nicht durch unberechtigte Kandidaten vor dem Limit verdrängt.

## Prüfung

`tests/Feature/MobileDirectContactsTest.php` nutzt dieselbe eigenständige Kernelprobe wie `deploy/check-mobile-direct-contacts.php --synthetic-rollback`. Sie erzeugt ausschließlich synthetische Konten, Teams, Links und Chats in einer vollständig zurückgerollten PostgreSQL-Transaktion. Die Probe prüft Such-/Rollenparität einschließlich Assistenztrainer und Helfer im eigenen Kinderteam, bestätigte und ausstehende Elternlinks, Antworten, Rollen-/Linkwiderruf, gegenseitige Blockierung, aktuelle Saison, aktive Zuordnungen, Fremdverein, minimale Daten, Parametervalidierung und ein konstantes vollständiges HTTP-SQL-Budget für Spieler-, Trainer- und Elternsuche mit1 gegenüber100 passenden Konten. Danach werden verschwundene Fixturekonten und Vereine überprüft. Kein PHPUnit im DEV-Gate erforderlich; der Gate-Einstieg arbeitet auch bei Einbindung über den Releasewrapper und wird nur durch eine ausdrückliche Testkonstante übersprungen.

Die direkte CLI-Ausführung verlangt den exakten DEV-Origin, aktivierte eigene Mobile-API und geschlossene externe Providerflags. Mail und HTTP sind zusätzlich gefälscht bzw. gesperrt. Keine produktiven Nachrichten, Provideraufrufe, DDL, Importe, Datenauffrischung oder pauschalen Rollbacks.

## Lokaler Abschlussnachweis

Finaler Kernel-/Rollbacklauf mit aktiviertem echtem Bootstrap:272 Prüfungen grün;1 PHPUnit-Fall/4 äußere Assertions. Vollständiger HTTP-Pfad bei1 gegenüber100 passenden Konten (Ausgabe1/40):

| Rolle | SQL1/100 | Laufzeit1/100 |
| --- | --- | --- |
| Spieler |14/14 |351/393ms |
| Trainer |17/17 |434/542ms |
| Eltern |15/15 |389/414ms |

Diese Werte stammen aus dem lokalen PHP-Kernel gegen die Remote-PostgreSQL-Verbindung, nicht aus einem iPhone-/Simulatorlauf. Der Bootstrap fügt eine konstante Availabilityabfrage hinzu. Das Gate verlangt gleiches SQL-Budget für beide Größen und einen konservativen Gesamtrahmen von24 Abfragen, damit die echte Bootstrap-/Originprüfung erhalten bleibt. Die zusätzliche Batchabfrage vermeidet die vorher gemessene verschachtelte Rosterauswertung (Eltern100≈1976ms bzw. erster Trainerentwurf am15s-Sicherheitslimit); sie ist bewusst kein Rechtecache.

Bestehende Kommunikations- und ReadContext-Regressionssuite:56 Tests/896 Assertions grün. Die SQLite-Kommunikationsfixture wurde ausschließlich um die vorhandene staff_functions-Struktur und eine explizite TRAINER-Zuordnung ergänzt; keine Fachassertion entfernt oder abgeschwächt. PHP-Syntaxprüfung aller Produkt-/Gate-/neuen Testdateien und gezieltes git diff --check erfolgreich. PostgreSQL-Fenster nach Abschluss freigegeben, alle synthetischen Zeilen zurückgerollt und Cleanup nachgeprüft. Kein Provider, keine echte Nachricht und keine Daten-/Rollenänderung außerhalb der synthetischen Transaktion.

Geänderte Runtime: MobileAccess.php, ausschließlich contacts() in MobileController.php und ausschließlich Sichtbarkeit private→public von MemberTeamAccessService.trainerTeams(). Neue Dateien: dieses Dokument, deploy/check-mobile-direct-contacts.php und tests/Feature/MobileDirectContactsTest.php. Alle anderen Runtimequellen bleiben erhalten. Noch kein Commit oder DEV-Release durch den Fachagenten; Root ergänzt den Bereitstellungsnachweis.


## DEV-Bereitstellung und App-Abschluss

Aktiv ist `/var/www/svs-laravel/releases/20261104-clubunio-direct-contacts-v1`.
Rückweg: `20261104-clubunio-language-policy-v1`. Vier explizite Dateien aus dem
geprüften Gitstand956af46 paketiert; vorhandene Rohdateien gegen bekannte
Gitbaselines und vor/nach Installation gegen SHA256-Manifeste geprüft.
Die bestehende Trainerfunktionsabfrage wird unverändert wiederverwendet.

Alle bisherigen Releasegates plus neuer272-Check bestanden. Der echte DEV-Kernel
misst bei1/100 passenden Konten Spieler14/14 SQL347,57/391,59ms,
Trainer17/17 SQL453,46/541,63ms, Eltern15/15 SQL369,24/414,79ms.
Dies sind synthetische Servermessungen, keine native iPhone-Messung.
FPM aktiv, HTTPS `/up`200 und anonymer persönlicher Context401; Hashes nach
Aktivierung nochmals bestätigt. Env-/Composerbytes unverändert, keine Migration,
Provideraktivierung, Datenübernahme oder PHPMaker-Änderung.

App: sofortiger neuer Nachrichtenbildschirm mit serverseitiger Namenssuche,
40er-Grenze, Debounce, gebundenen späten Antworten und Originalchat nach Auswahl.
640/640 App-Tests, vollständige Analyse und zentrale1921-Schlüssel-i18n-Prüfung
bestanden. Sechs neue Such-/Einstiegsfälle einschließlich nicht blockierender
fehlgeschlagener Benachrichtigung im selben Konto/Verein.
App-Quellstand `8e38148`, Details im Mobile-Repository `docs/DIRECT-CHAT.md`.
