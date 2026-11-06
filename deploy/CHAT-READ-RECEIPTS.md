# Persönliche Lesemarkierungen

`GET /api/mobile/v1/clubs/{tenant}/messages/{message}/readers`
liefert ausschließlich dem Autor einer vorhandenen, nicht gelöschten Nachricht
die aktuell berechtigten Konten, deren Unterhaltungs-Lesecursor diese Nachricht
erreicht hat. Das ist keine Bestätigung tatsächlicher Betrachtung und keine
exakte Lesezeit. Es werden keine Zeitstempel abgeleitet oder erfunden.

Query: optional `after` (Konto-ID ab 0) und `limit` (1–50, Standard 30).
Andere Parameter werden abgewiesen. Antwort:

```json
{"data":[{"account_id":42,"name":"Example"}],"meta":{"has_more":false,"next_after":42}}
```

Die API liest vorhandene Mitgliedschaften und erzeugt keine neuen Beitritte.
Autor und Leser benötigen jeweils frische Unterhaltungsrechte. Der Autor muss
noch beigetreten sein; der Nachrichtenstand muss nach seinem Beitrittscursor
liegen. Leser müssen aktiv, keine API-Konten, dem Mandanten zugeordnet und noch
in der Unterhaltung sein. Ihr `joined_after` muss kleiner als die Nachrichten-ID
und ihr `read_message_id` mindestens so groß sein. Der Autor selbst wird nicht
aufgelistet. Gesperrte Direktchats und widerrufene Team-/Eltern-/Profilrechte
gelten unverändert. Namen werden ausschließlich für tatsächlich berechtigte
Leser ausgegeben; es gibt keine Liste ungelesener Mitglieder oder Gesamtzahlen.

Eine Seite prüft höchstens 100 Kandidaten frisch und liefert höchstens `limit`
Einträge. Nach vielen Widerrufen kann eine Seite leer sein und trotzdem
`has_more=true` liefern. In diesem Fall steigt `next_after` und die Oberfläche
lädt weiter. Ein erster zusätzlicher berechtigter Leser bleibt für die nächste
Seite erhalten. Dieser Scan-Cursor ist ausdrücklich keine Leseranzahl.

Nachrichten-DTOs enthalten `can_view_readers` nur für eigene, nicht gelöschte
Nachrichten. Messages-/Changes-Meta enthalten `read_receipts_supported=true`.
Die eigentliche GET-Prüfung bleibt verbindlich; DTO-Fähigkeiten sind keine
Berechtigungsnachweise. Keine Migration, Provideraktion oder Zustellung nötig.

Synthetic SQLite-/Kernelprüfungen umfassen Autorenbindung, minimale Felder,
Paging, frische Leserrechte, Beitrittsgrenzen, fehlende/verlassene Mitgliedschaft,
gesperrte Direktchats, fremde Mandanten, Löschung und ausschließlich SELECTs im
GET-Pfad.

## DEV-Auslieferung

Runtime `be81c6c` als `20261106-clubunio-chat-readers` aktiv, Basis
`20261106-clubunio-chat-interaction`. Vier Runtimequellen plus lesendes Gate
über geprüfte Rohdateihashes und Baselines ausgerollt. Keine Migration und kein
Fachdaten- oder Providerauftrag. Vollständige Konfiguration einschließlich privater
Firebase-Registry, Push-/Assistantflags, ENV und Composer unverändert erhalten.

Inaktives und Aktivierungsgate prüfen einen vorhandenen Own-UUID-Datensatz sowie
den Leserpfad mit Limit5 innerhalb einer READ-ONLY-Transaktion. Kombiniert149SQL,
4195 beziehungsweise4111ms im nicht memoisierten CLI-Kontext; keine Aussage zur
kompletten HTTP-Sendezeit. FPM und Pushtimer aktiv, Worker erfolgreich,
Health200/context401. Kein künstlicher Chat oder Push erzeugt.

84 Backendtests mit 2382 Assertions grün. Anschließend alle vier Leserfälle mit
929 Assertions nach der letzten Katalogkorrektur erneut erfolgreich.
40 Appfälle inklusive Paging/Refresh, Rechte-/Kontowechsel, großer Schrift DE/EN,
Composer/Reaktionen und Delta grün; fünf Dateien ohne Analysebefund.
2046 Apptexte in fünf Katalogen und 2806 Backendtexte DE/EN geprüft.
Paketmanifest `c0ec1dcf6d48a7c7e73418b1f0fbde519d19a7e701442a8aaf7403d2b1f4fdbe`,
Releasescript `fb0869c86bb28d84d1dccfb3ab10607701ebe712072e539d26f557a5034795e4`.
Appbedienung und Grenzen: mobile/docs/CHAT-READ-RECEIPTS.md.

Abschließende Katalogkorrektur: unzulässige Queryparameter verwenden den bestehenden
DE/EN-Schlüssel „Ungültige Anfrage.“. Runtime `67ee58e` als
`20261106-clubunio-chat-readers-i18n` aktiv, Basis readers unverändert erhalten.
Erneute lesende Gates: kombiniert149SQL/3741 beziehungsweise3624ms CLI;
Konfiguration erhalten, Dienste aktiv, Worker erfolgreich, Health200/context401.
Manifest `dc55c1b779f64c25a3f5fb50264bf0726bbc706547e8ebc313262c451746733c`,
Script `80c7674c1a30180b5decf526a0f24e5323e4d56cfd58287317df057f8937020e`.
