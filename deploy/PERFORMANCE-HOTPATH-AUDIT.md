# Persönliche API: Hotpath-Abgleich06.11.2026

Vorhandene Performancepakete wurden vor dem Abgleich gelesen. Bereits behobene
Push-Snapshots auf Planungslisten, synchroner Chatpush-Fanout, Attachment-Blobs
in Nachrichtenprojektionen, My-Teams-Rosterqueries und Spesenlistenrechte werden
nicht erneut als neue Änderungen gezählt. Keine Migration, TTL oder zusätzliche
Provideraktion ist Bestandteil dieses Deltas.

## Gezielte neue Änderungen

`MobileNotificationService` prüfte verschiedene Planningziele in der gesamten
Historie weiterhin einzeln. `PlanningCalendarService::visible` bildet bereits
die gleiche bestehende Einzelsicht ab: aktuelle zulässige Teams ODER explizite
Mitgliedereinladung, mit Mandanten-/Saison-/Entfernungsscope. Die Notification-
Projektion nutzt diese Query jetzt für höchstens500 Ziel-IDs je Batch. Der
Actorzugang wird separat beibehalten. Andere typisierte Ziele behalten ihre
eigenen Fachrechte. Das Verfahren gilt für Seite, Materialisierung, ungelesenen
Zähler und gesperrte Read-all-Autorisierung; keine Schreibpolicy wird ersetzt.
Die lokale Ergebnismap endet mit der Operation. Ein späterer Aufruf liest neu.

`ChatService::conversationDto` las für jeden akzeptierten Direktchat die andere
Mitgliedschaft und ihren Namen einzeln. Die Übersicht projiziert diese Namen
jetzt mit einem Join ausschließlich für bereits frisch akzeptierte Chats und
aktive andere Mitgliedschaften. Kontakt-/Block-/Team-/Sessionprüfungen werden
nicht übersprungen. Einzelkonversationen behalten ihren bisherigen Detailweg.
Letzte Nachrichten, Unreadzahlen und Join-/Rejoingrenzen sind unverändert.

Gezielte Tests vergleichen die Planning-Batchrechte mit den echten einzelnen
`targetAllowed`-Prüfungen für Parent, Trainer, Admin und Spieler, einschließlich
fremder Teams, expliziter Einladung und gelöschter Termine. Eine gegenüber23
verschiedenen Termin-IDs erzeugt dieselbe Queryzahl im Batch. Die Chatprüfung
beweist gemeinsame Titelprojektion, korrekte andere Konto-ID und sofortigen
Ausschluss eines frisch blockierten Kontakts. Es werden keine Dateibytes geladen.

## Weitere geprüfte Pfade und Grenzen

| Pfad | Aktueller Befund |
| --- | --- |
| Notifications Count/Read-all | Das vorangegangene Paket prüft nur ungelesene beziehungsweise ausdrücklich beobachtete IDs. Historische Readrechte werden dort nicht pauschal erneut gelesen. Neue Batchsicht reduziert zusätzlich verschiedene Planningziele. Vollständige Seitenzählung muss weiterhin alle tatsächlich sichtbaren historischen Ziele berücksichtigen. |
| Chatliste | Memberships, Unreads, letzte Nachrichten und jetzt Direktchatnamen sind gebündelt. Direkte/Eventrechte werden weiterhin frisch je betroffener Konversation geprüft. Diese verbleibenden fachlichen Kosten werden nicht als konstantes Gesamtbudget ausgegeben. |
| By-client-Lookup | Strikt lesende Prüfung vorhandener Mitgliedschaft, eigener UUID, Gesprächsrechte und Beitrittsgrenze. Kein impliziter Join; eine begrenzte Nachrichtenprojektion bleibt notwendig. |
| Planning Listen/Today | Vorhandene Batchprojektion ohne Push-Snapshot bleibt bestehen. Detail lädt den optionalen Snapshot nur explizit; keine weitere pauschale Rechteabkürzung. |
| Spesen | Liste nutzt eager Relations und gemeinsame Read-Capabilities, keine Belegbytes. Detail hat eigene gezielte Dokument-/Provenienzreads; keine neuen und nachgewiesenen Listen-N+1 gefunden. |
| Bootstrap/SharedAuth | Frische Session-, Host-, Wartungs-, Account- und Rollenprüfung bleibt erhalten. Requestgebundene GETfacts bestehen bereits; keine Sitzung oder vereinsübergreifende TTL eingeführt. Mehrere echte HTTP-Aufrufe können getrennte notwendige Prüfungen enthalten. |

Als separates drittes Runtimefile ergänzt `MobileController` den gezielt
autorisierten Chat-GET-Fix: Nur Chatliste und Einzelkonversation erwerben einen
gemeinsamen Tenantlock. Die Einzelkonversation sperrt zusätzlich ihre echte
Chatzeile exklusiv vor der Mitgliedschaftsprojektion, genau wie Send. Damit
bleiben diese GETs mit dem Tenant-Sharedlock einer Providerzustellung kompatibel.
Andere Schreibaktionen und Rollenänderungen behalten den exklusiven Tenantlock.
Die Liste behält ihr bedingtes Bulk-Upsert: eine inzwischen aktive Mitgliedschaft
wird mit ihren Join-/Readmarkern und Mute-Einstellungen nicht überschrieben.

Nach dem Erwerb der Tenant-/Sessionsperren und erneuter Authentifizierung
verwirft ein gesperrter GET die vor dem Warten gelesenen Facts und beginnt eine
neue request-/sessiongebundene GET-Projektion. Das Ende stellt keine alten
Facts wieder her. Tests simulieren eine während des Wartens abgeschlossene
Widerrufstransaktion und verweigern anschließend die Liste ohne Wiederbeitritt.
Ein weiterer Test simuliert konkurrierenden Beitritt/Lesevorgang vor dem
bedingten Upsert und prüft unveränderte aktive Grenzen. SQL-Locktypen werden
über die ausgeführte Queryprojektion mit PostgreSQL-Grammatik geprüft; die
reale Zwei-Verbindungs-Lockkompatibilität misst Root auf DEV.

## DEV-Messung und Veröffentlichung

Runtime `85378af` als `20261106-clubunio-mobile-hotpaths` aktiv, Basis
`20261106-clubunio-notifications-read-all`. Drei Runtimequellen plus zwei Gates
über Rohdateihashes und Baselines ausgerollt. Keine Migration; vollständige
Konfiguration einschließlich privater Firebase-Registry, Push-/Assistantflags,
ENV und Composer erhalten. FPM/Pushtimer aktiv, Worker erfolgreich,
Health200/context401. Keine Providerzustellung oder neue Fachnachricht erzeugt.

Vergleich am gleichen vorhandenen Konto und Datenumfang (direkte Controller-
Projektionen, nicht iPhone-Netzwerk/Rendering):

| Ablauf | Vorher | Inaktiver Kandidat | Aktivierungsgate |
| --- | --- | --- | --- |
| Inbox26/Page20/Unread0 | 3926ms/138SQL | 691ms/26SQL | 766ms/26SQL |
| Read-all26/Observed20 | 3558ms/130SQL | 1339ms/40SQL | 1185ms/40SQL |
| Chatliste7/Direct0 | 749ms/23SQL | 744ms/26SQL | 759ms/26SQL |
| Chatdetail unter Provider-Sharedlock | nicht gemessen | 641ms/25SQL | 710ms/25SQL |

Die drei zusätzlichen Chatlistenqueries sind die beabsichtigte neue frische
Prüfung nach Sperrerwerb, kein pauschaler Durchsatzgewinn der warmen Liste.
Das Gate hält in einer zweiten echten PostgreSQL-Verbindung einen Tenant-
Sharedlock wie beim Provider. Liste und Einzelchat sind damit tatsächlich
kompatibel; ein exklusiver Rollenwrite wird weiterhin blockiert. Im realen
Kontobestand existieren keine Direktchats: deren Titelquerybudget wird durch
synthetische Tests belegt, ohne einen realen Geschwindigkeitswert zu erfinden.

Synthetische Sitzung, Marker- und Metadatenwrites werden in allen Gates
zurückgerollt; das Read-all-Gate vergleicht anschließend auch die Originalmarker.
97 SQLite-/FCM-Regressionen mit2555Assertions und fünf PostgreSQL-Paritätsfälle
mit103Assertions grün. Sprachprüfung2806DE/EN ohne Backlog. Synthetische Query-
Parität ist kein Nachweis für physische iPhone-, Netzwerk- oder Providerlatenz.

Manifest `4c48dc94d002029bdab033d4918ec7c0bedf0e4c44946db4d4de0d409d293b0c`,
Script `464c5bed8dbe099ba098814d3d07947a2fc0fc2b9e6d8164345fac428b790015`.
Appänderungen, Grenzen und Gesamttest: mobile/docs/PERFORMANCE-UX-AUDIT.md.

Finale Appabnahme: gesamte894Fluttertests grün, zwölf geänderte Quell-/Testdateien
ohne Analysebefund,2052Texte in fünf lokalen Katalogen einschließlich Literalprüfung
sauber. App spart redundante Metadaten-/Navigations-/Spesenrückkehrrequests und
parallelisiert unabhängige My-Teams-Sammelreads begrenzt. Kontokontextnachweise
bleiben getrennt; keine abgeschlossenen Rechte-/Betriebswerte werden zwischengespeichert.
Für den neuen Appablauf aktuellen Stand neu bauen/installieren.
