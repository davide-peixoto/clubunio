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

Reale DEV-Zeiten und Veröffentlichung koordiniert Root. Synthetische Query-
Parität ist kein Nachweis für physische iPhone-, Netzwerk- oder Providerlatenz.
