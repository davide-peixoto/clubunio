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
GET-Pfad. Root koordiniert DEV-Release und lesendes Releasegate; keine dauerhafte
Datenänderung durch diese Implementierungsarbeit.
