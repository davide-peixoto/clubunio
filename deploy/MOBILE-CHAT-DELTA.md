# Persönlicher Chat: kompatibler Delta-Vertrag (04.11.2026)

Auf DEV aktiviert: `20261104-clubunio-chat-delta-v1` aus Quellstand `7442f67`. Keine Migration, keine neuen Routen und keine Freischaltung externer Provider.

## Vollständiger normaler Änderungsfeed

Der bestehende `chat_changes`-Feed enthält normale Neuanlage (`send`, nach Anhangbindung), Bearbeitung/Löschung, Reaktionen und Stimmen. Pollstimmen und Reaktionen ändern den Nachrichtenrevisionszähler nicht. Die Nachrichten-Daten einer Feedseite sind die jeweils aktuelle autorisierte Projektion; mehrere Ereignisse derselben Nachricht dürfen identische aktuelle DTOs liefern. Eine niedrigere Nachrichtenrevision darf einen neueren Clientstand nicht ersetzen. Gleiche Revision allein bedeutet dagegen keine Gleichheit: Stimmen, Reaktionen und andere Projektionen können sich ändern.

`GET .../chats/{chat}/messages` bleibt für initiale Snapshots sowie ältere/neue Nachrichtenpagination verfügbar. `GET .../chats/{chat}/changes?after={cursor}` prüft weiterhin frisch Konto, Sitzung, Mandant, Mitgliedschaft, Team-/Direktchat-/Blockregeln und die Beitrittsgrenze. Im leeren Feed werden keine Nachrichtenseiten, Autoren, Anhangmetadaten, Reaktionen oder Pollstimmen projiziert. So kann der zusätzliche Abruf der letzten50 vollständigen Nachrichten beim Idlepoll entfallen.

## Additive Metadaten

Beide Antworten behalten ihre bisherigen Daten und Paginationfelder und ergänzen:

- `delta_version: 1`: ausdrückliche Kompatibilitätsanzeige, keine Berechtigung.
- `server_time`: ISO8601-Serverzeit für verbleibende Bearbeitungs-/Pollfristen.
- `joined_after`: aktuelle persönliche Nachrichten-Beitrittsgrenze.
- `can_moderate`: frisch geprüfte Chatmoderation; im Direktchat immer false.
- `can_write`: aktuelle Schreibberechtigung, insbesondere für Ankündigungen.

`changes` ergänzt außerdem `reset_required`. Bei einem angeforderten Cursor oberhalb des derzeitigen Chat-Highwaters wird `data:[]`, `reset_required:true`, `has_more:false`, `cursor:{Highwater}` geliefert. Ein neuer Client muss dann seinen Snapshot verwerfen und vollständig frisch laden. Er darf diesen Cursor nicht allein übernehmen und dabei alte Inhalte behalten. Ohne `delta_version:1` bleibt der bisherige kompatible Vollabgleich nötig.

## Cursor, Seiten und Rennen

Der Highwater wird vor Nachrichtenselektion und Projektion erfasst. Eine Deltaabfrage liefert nur Ereignisse bis zu diesem Highwater. Bei mehr als50 sichtbaren Ereignissen setzt die Seite `has_more:true` und den Cursor auf das letzte ausgelieferte Ereignis; andernfalls auf den erfassten Highwater. Damit können ausschließlich unsichtbare Ereignisse vor der Beitrittsgrenze sicher übersprungen werden. Cursor sind globale IDs und müssen innerhalb eines Chats nicht lückenlos aufeinanderfolgen.

Spätere Commits erscheinen auf der nächsten Seite/Abfrage. Wenn ein initialer Snapshot eine inzwischen neu angelegte Nachricht bereits enthält, sein vorab erfasster Cursor aber noch davor liegt, wird das Ereignis anschließend sicher wiederholt. Doppelte aktuelle DTOs sind beabsichtigt und idempotent; ein Cursor darf erst nach erfolgreicher Anwendung der ganzen Seite gespeichert werden. Ein vorzeitig auf einen nach der Projektion gelesenen MAX-Wert angehobener Cursor würde Änderungen verlieren und wird vermieden.

Clientseitige lokale Mutation und laufende Deltaantwort müssen gegeneinander versioniert bzw. serialisiert sein: gleiche Nachrichtenrevision ist bei Reaktionen/Stimmen kein ausreichender Ordnungsbeweis. Der Feed ersetzt weder den Schreib-Revisionsschutz noch UUID-Idempotenz. Die bestehenden Mobile-Schreibcontroller serialisieren Chatmutationen über den Mandantenlock; neue direkte Feedwriter müssen diese Commit-/Cursorordnung erhalten.

## Rechte und Cachegrenzen

Das RAMcache gehört eindeutig zu API-Origin, Konto, Sitzungsgeneration, Mandant und Chat und bleibt begrenzt. Ein zuvor autorisiert gelesener RAMstand kann innerhalb derselben Sitzung vorläufig sofort angezeigt werden; Schreibaktionen bleiben beim Wiederöffnen bis zur frischen Antwort gesperrt. Bei401/403/404 wird es verworfen. Änderung von `joined_after` oder ein expliziter Cursorreset erfordert einen vollständigen neuen Snapshot; insbesondere darf ein Rejoin keine alten Nachrichten aus dem Cache wieder sichtbar machen. Aus einem per `left_at` verlassenen Direktchat entsteht durch GET keine Mitgliedschaft: der tatsächlich autorisierte Wiederbeitritt erfolgt über den bestehenden Direktanlageablauf.

Die30-Minuten-Bearbeitungsfrist und Pollschluss können ohne Feedereignis ablaufen. Der Client berechnet die wirksame Frist mit der frischen Serverzeit und entfernt abgelaufene Aktionen auch bei leerem Feed. Fremde Nachrichten können nur bei aktuellem `can_moderate` gelöscht werden; eigene Löschrechte bleiben wie bisher. Schreibzugriffe werden immer nochmals serverseitig geprüft. Ein Server-/Transportfehler darf einen zuvor gelesenen Snapshot erhalten, aber keine neue Offline-Schreibberechtigung erzeugen.

Anzeigenamenänderungen an Konten erzeugen derzeit keinen Chatfeed-Eintrag. Ein autorisierter Vollabgleich bei manueller Aktualisierung bzw. Resume bleibt daher sinnvoll; der normale Idlepoll benötigt keine50er-Projektion. Es gibt keinen Feed-Pruning-/Retentionauftrag im aktuellen Code. Eine spätere Kürzung oder Reparatur muss einen expliziten Reset-/Floorvertrag ergänzen; stille Lücken dürfen nicht als verlässlicher Deltafeed behandelt werden. Anhangscans finden im normalen Ablauf vor Sendung statt; das Sendereignis entsteht nach sicherer Bindung, Löschung erzeugt den Tombstone und entfernt private Bytes. Binärdownloads bleiben gesondert frisch autorisiert.

## Prüfung

Neue unabhängige `deploy/check-mobile-chat-delta.php --synthetic-rollback`-Kernelprobe mit explizitem DEV-/DB-/Providerguard, kurzer Lock-/Statementgrenze und geprüftem vollständigem PostgreSQL-Rollback. Keine PHPUnit-Abhängigkeit im Servergate. Ein ausdrücklicher Include-only-Testschalter erlaubt Wiederverwendung in `tests/Feature/MobileChatDeltaTest.php`; direkte CLI- und Releasewrapper-Ausführung führen die Probe aus.

Die Probe prüft echte Send/Edit/Reaktions-/Vote-/Löschrouten, Anhangbindung und -entfernung, Zeitablauf, leeren Feed ohne DTOabfragen, Moderations-/Schreibwiderruf ohne Nachrichtenevent,60Erstellungen über zwei Seiten mit globaler Cursorlücke, zwei deterministische Highwater-Rennen, Cursorreset, expliziten Rejoin samt Beitrittsfilter und nachfolgenden neuen Nachrichten sowie fremdes Konto, Mandant, Blockierung und Sitzungswiderruf. Keine realen Nachrichten, Provider-, Mail-, Bank-, Import- oder DDL-Aktionen.

## Lokaler Abschlussnachweis

Neue Probe mit tatsächlich aktivierter Bootstrapprüfung:62 Kernel-/Vertragsprüfungen grün,1 PHPUnit-Fall/5 äußere Assertions, Idlepfad14SQL und ausdrücklich keine Nachrichtenselektion oder Anhang-/Reaktions-/Stimmenprojektion. Beide deterministischen Highwater-Rennen,60er-Pagination und Rejoinfall bestanden; vollständiger Rollback verifiziert. Bestehende Kommunikations-/ReadContextsuite:56 Tests/896 Assertions grün. PHP-Lint aller vier PHP-Dateien und gezieltes git diff --check erfolgreich.

Runtimeänderungen ausschließlich `ChatService.php` (messages-Meta und gemeinsamer deltaMeta-Builder) sowie `ChatControls.php` (changes). Neu: diese Dokumentation, `deploy/check-mobile-chat-delta.php`, `tests/Feature/MobileChatDeltaTest.php`. Kein Controller, keine Route, kein Schema und keine fremde Runtimequelle verändert. Produkt-, Gate-, Test- und Dokustand an Root eingefroren übergeben; PostgreSQL-Fenster frei. Kein Commit oder Deployment durch den Fachagenten.


## DEV und App-Abschluss

Aktiv `/var/www/svs-laravel/releases/20261104-clubunio-chat-delta-v1`, Rückweg
`20261104-clubunio-direct-contacts-v1`. Drei explizite Dateien aus Git7442f67
gegen bekannte Quellstände und Roh-SHA-Manifeste geprüft, unveränderliche Kopie
des aktiven Vorgängers erstellt. Sämtliche bisherigen Gates einschließlich
Direktkontakt272 sowie neuer Delta62-Check erfolgreich. Nach Aktivierung erneut
Hashes bestätigt, FPM aktiv, HTTPS `/up`200 und anonymer persönlicher Context401.
Env-/Composerbytes unverändert. Keine Schema-/Provider-/PHPMaker-Umstellung.

App660f0d9: sitzungsgebundener begrenzter RAMcache für Liste und Nachrichten,
sofortiges vorläufiges Wiederöffnen mit frischer Hintergrundverifikation,
Delta-only im normalen Takt, manueller/Resume-Vollabgleich.13 neue Cache-/
Deltafälle einschließlich verspäteter Send-/Reaktionsantwort nach neuem
Scope/neuester Projektion.653/653 Gesamttests, Analyse und lokale1921-Schlüssel-
Sprachprüfung bestanden. Grenzen:12 Einträge,200 Nachrichten pro Verlauf,
2MiB,10 Minuten, keine Dateien/Tokens/Entwürfe/Übersetzungen/Binäranhänge.
Kein nativer iPhone-/Simulatorlauf. Details `docs/CHAT-CACHE.md` im App-Repository.
