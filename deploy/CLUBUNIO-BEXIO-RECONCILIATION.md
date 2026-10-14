# Bexio-Abgleich in ClubUnio

Stand 12.10.2026, Release27. Nur ClubUnio; PHPMaker DEV/PROD bleiben unveraendert.

## Webablauf

Finanzen > Bexio-Abgleich: Admin/Geschaeftsstelle waehlen Rechnungen oder Spesen,
suchen Mitglied/Bexio-Referenz und pruefen einen Datensatz. Bexio wird dabei
ausschliesslich per GET gelesen, mit der eigenen OpenID-Verbindung und Refresh.
Eine Vorschau gilt zehn Minuten. Nur ihr Ersteller kann sie bestaetigen.
Geaenderte Datensaetze oder Lookup-Zuordnungen erfordern eine neue Pruefung.

- Rechnungen: Bexio-Statuscode wird ueber invoice_statuses.code und position_code
  auf Rechnung und aktive Positionen abgebildet. Unbekannte Zuordnungen scheitern
  geschlossen. Mappingtabellen sind unter Stammdaten als eigene Editoren vorhanden;
  siehe CLUBUNIO-FINANCE-EDITORS-EXPORTS.md.
- Spesen: nur Z nach B bei PAID; ein vorhandener Restbetrag muss null sein.
  Der bestehende Spesen-Nachrichtenablauf erzeugt Ereignis, interne Notification
  und Mail-Outbox. Testversand bleibt auf admin@svsissach.ch beschraenkt.
- Rechnungsstatuswechsel ausser Pendent erzeugen interne Notifications aus den
  vorhandenen editierbaren Vorlagen. Kein physischer FCM-Versand in diesem Paket.
- Wiederholte Bestaetigung erzeugt keine doppelte Aenderung oder Nachricht.
- BEXIO_ACTIVE/BEXIO_AKTIV/BEXIO_ENABLED deaktiviert den Abgleich; fehlender Wert
  ist deaktiviert. PDF-Abruf bleibt unabhaengig davon moeglich.

## Betrieb und Migration

Migration 2026_10_12_000028_bexio_reconciliation.php legt bexio_checks additiv an.
Auf der gemeinsamen ClubUnio-Datenbank ausgefuehrt. Keine Quelldatenmigration.
Pruefungen speichern Status, Vorschlag, Fingerprint und Korrelations-ID, keine
Tokens oder kompletten Providerantworten. Uebernahmen werden in object_changes
protokolliert und laufen zusammen mit Benachrichtigungen in einer Transaktion.

Manuelle paketweise Vorschau (keine automatische Uebernahme):

```sh
php artisan clubunio:bexio-preview sv-sissach 19 --type=invoice --after=0 --limit=10
```

Maximal 25 Eintraege, weiches Zeitbudget 45 Sekunden zwischen Requests;
Folgepaket mit ausgegebenem Cursor. Noch kein Scheduler fuer diesen Abgleich.
Echter lesender Test Rechnung 6790: Status 8, unveraendert. Keine echten
Statusaenderungen, Bexio-Schreiboperationen oder Nachrichtenversand ausgefuehrt.
16 gezielte Tests / 115 Assertions sowie 20 Rechnungs-/Spesen-Regressionschecks
mit 226 Assertions bestanden; Desktop und 390px-Ansicht geprueft.
Release27 auf Pi aktiv, HTTPS login 200, geschuetzte Seite ohne Anmeldung 302,
FPM und Scheduler aktiv; echter lesender Servercheck ebenfalls unveraendert.

Rollback: Release26 wieder aktivieren; additive Tabelle darf bestehen bleiben.
Bereits bestaetigte Fachaenderungen nicht blind zuruecksetzen, Audit einzeln pruefen.

## Noch offen

Bexio-Rechnungen/Lieferantenrechnungen erzeugen, externe Stornierung,
automatischer Statuslauf mit Betriebsfreigabe und physische
Push-Zustellung. API und SaaS bleiben geparkt. Keine Produktivabloesung.
