# Mobile Funktionsparität und Rechtekorrektur — 04.11.2026

## Mitglieder und Mannschaften

Das persönliche Mitgliederverzeichnis entspricht wieder dem SVS-Ablauf: ohne
`members.view` nur eigene aktive Mitgliedschaft und bestätigte minderjährige
Elternvertretungen. Trainer-Roster bleiben im eigenen Mannschaftsdetail; deren
Kontakt-/Detailberechtigung erweitert nicht das persönliche Verzeichnis.
Beim Öffnen wird das Dossier frisch geladen. Ausweis, Rechnungen und
Änderungsantrag erscheinen nach den tatsächlichen serverseitigen Fähigkeiten.
Probetrainings verantwortlicher Trainerteams besitzen eigene lesende Routen;
Basisdaten berechtigen nicht zur Aufnahme, zu Privatdateien oder Elternangaben.
Siehe `mobile/docs/MEMBERS-MYTEAMS-RIGHTS-PARITY.md` im App-Repository.

## Chat und Navigation

- Plus links an der Eingabe: Bild/Datei, Teamplanungstermin, Umfrage und
  vorhandene Textassistenten. Die vorhandene Uploadprüfung/Quarantäne bleibt
  zwingend. DEV-Uploads waren bereits eingeschaltet.
- Umfrage mit einzelnen Antwortfeldern, Ergebnissen als Balken und eigener
  Auswahl. Dialogeigene Controller leben bis zum tatsächlichen Widget-Abbau;
  der gemeldete Fehler beim Schliessen wird dadurch vermieden.
- Eine eigene Emoji-Reaktion pro lesbarer Nachricht, sechs feste Emojis,
  Wechsel/Entfernen als idempotenter gewünschter Zustand. Keine Teilnehmernamen
  im Reaktionsaggregat. Dieselben Mandanten-, Chat-, Beitritts- und
  Widerrufsregeln wie für Nachrichten; gelöschte Nachrichten sind gesperrt.
  Reaktionen gehen durch den bestehenden Änderungsfeed ohne Textrevision zu
  erhöhen. Aggregation erfolgt gesammelt pro Nachrichtenstapel.
- Chat-Einstellungen oben in der Chatübersicht: Übersetzung standardmässig aus,
  Zielsprache explizit, weiterhin Zustimmung je angeklickter Nachricht.
  Einstellungen gelten pro Konto und Mandant mit optimistischer Revision.
  Der Server prüft Opt-in und übereinstimmende Zielsprache auch bei Cachetreffern.
  Frische Einstellungen kommen mit der Chatliste, ohne zusätzlichen Start-GET.
- Posteingang über die Glocke oben samt Zähler. Unten Heute, Termine, zentral
  hervorgehobener Chat, My Teams und Profil. Bestehende Push-Zielart Posteingang
  behält ihre Bedeutung und prüft beim Wechsel Konto/Mandant/Objekt erneut.

Der externe Übersetzungs-/KI-Dienst bleibt auf DEV ausgeschaltet. Diese
Änderung implementiert Einstellungen und Zustimmung, aktiviert aber keinen
kostenpflichtigen Anbieter. Tatsächliche Übersetzungen sind daher noch nicht
verfügbar; Bilder/Dateien können den bestehenden DEV-Uploadpfad verwenden.

## Bus, Keybox und Profil

Leere Buszählungen werden als JSON-Objekt geliefert statt als Liste; die App
akzeptiert zusätzlich die alte leere Liste. Alte Bus-/Keybox-Repositories
verweigern Anfragen nach einem Konto-/Mandantenwechsel. Der Keybox-Einstieg ist
auch für einen rechtmässigen leeren Verein sichtbar; fehlende Boxen bedeuten
keine zusätzlichen Grants oder PIN-Rechte. Physischer Anbieter und Ausgabe
bleiben geschlossen. Auf SV-Sissach-DEV sind derzeit keine Boxen eingerichtet.

Das Profil zeigt verständliche Sprachbezeichnungen und lokale Ablaufdaten.
E-Mail-Wiederherstellung erscheint nur bei frischer, origin-/mandantengebundener
Capability des tatsächlich freigegebenen Identitätsablaufs. Keine rohe
503-Platzhalterkarte. Push-Einwilligung bleibt ausdrücklich und ist bei
verfügbarer Funktion im Profil erreichbar.
Siehe `CLUBUNIO-MOBILE-BUS-PROFILE-ENTRYPOINTS.md`.

## Ausdrücklich freigegebene DEV-Datenkorrektur

Nach getrennten Bestätigungen des Benutzers wurden auf **SV-Sissach-DEV** genau
zwei bestehende ausstehende Elternvorschläge bestätigt und der fehlende
Vereinskatalogeintrag `Penalty` aktiviert. Keine E-Mail-Suche als automatische
Rechtefreigabe, keine Änderung der Trainerrolle oder persönlicher Modulprofile.
Die eigene Mitgliedszuordnung wurde gegen den vorhandenen SVS-Snapshot gelesen
und entspricht dessen ursprünglicher Zuordnung; sie wurde nicht geändert.

Die Operatortransaktion wurde zunächst zurückgerollt geprüft, anschliessend
gezielt angewendet, vorher verschlüsselt und privat gesichert und in
`object_changes` mit der ausdrücklichen Operatorfreigabe protokolliert.
`reviewed_by` wurde nicht mit einem fremden Backoffice-Administrator befüllt.
Bestätigter Ergebnisumfang: drei vertretbare Mitgliedschaften inklusive der
beiden Kinder, unveränderte Trainerrolle, Challenge nur für diesen Mandanten.
Backups und personenbezogene Diagnoseausgaben liegen ausserhalb von Git.

## Additives Schema und DEV-Release

`2026_11_04_001100_chat_experience` fügt ausschliesslich `chat_preferences` und
`chat_reactions` hinzu. Bestehende Nachrichten, Mitgliedsrollen und Daten werden
nicht migriert oder überschrieben. FK-Mandantenscope, erlaubte Sprachen/Emojis
und PK pro Benutzer/Nachricht gelten auch in PostgreSQL.

`prepare-chat-experience-schema.php` benötigt den festen inaktiven DEV-Release,
einen eigenen verschlüsselten und verifizierten Backup-Export sowie
unveränderte Elternzeilen/-definitionen unter Sperren vor dem Apply. Es gibt
keinen allgemeinen Migrationslauf und kein Refresh/Import. Das Paket belässt
Umgebung, Abhängigkeiten und externe Betriebsflags unverändert.
Der ausführliche Test- und Aktivierungsnachweis wird nach dem Release ergänzt.
