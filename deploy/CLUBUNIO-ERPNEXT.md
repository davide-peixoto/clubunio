# ERPNext-Anbindung für Mitgliederbeiträge

## DEV-Sandbox

Auf `erp-dev.clubunio.com` dient die Schweizer CHF-Gesellschaft **ClubUnio Testfirma** (`CUT`) als Vereins-Sandbox. Der Beitragsartikel ist `CUT-MEMBER-DUES`, das Erlöskonto `Mitgliederbeitraege DEV - CUT`, die Kundengruppe `Individual` und das Gebiet `Switzerland`. Der Ablauf verwendet fiktive Mitglieder, steuerbefreite Testbeiträge und einen synthetischen QR-Zahlteil. Zahlungen werden ausschliesslich als Testbuchung in ERPNext erfasst.

Der Integrationsbenutzer benötigt Zugriff auf die Sandbox-Gesellschaft. Die API-Berechtigungen decken das Erstellen von Entwürfen und das Lesen des Belegstatus ab. Freigabe und Zahlungsbuchung erfolgen in ERPNext. Das Feld `custom_clubunio_reference` muss in den angesprochenen ERPNext-Dokumenttypen einen eindeutigen Schlüssel erzwingen. Vor einem Update der ERPNext-Installation die Felder und ihre Eindeutigkeit prüfen.

## ClubUnio

Die Auswahl des Buchhaltungsanbieters gilt pro Mandant. Bei der Migration erhalten bestehende aktive Bexio-Mandanten die Auswahl **Bexio**. Ein neuer Mandant beginnt mit **Keine**. In ERPNext übertragene Rechnungen behalten ihre ERPNext-Belegnummer in ClubUnio. Ein erneuter Export mit derselben ClubUnio-Referenz findet den bestehenden Beleg auch nach einem Timeout wieder.

DEV verwendet `CLUBUNIO_ERPNEXT_ENABLED=true`, `CLUBUNIO_ERPNEXT_ORIGIN=https://erp-dev.clubunio.com` und `CLUBUNIO_ERPNEXT_NAMESPACE=clubunio-dev`. Der Namespace gehört zur stabilen externen Referenz. Nach dem ersten Export darf er für diese Umgebung nicht geändert werden. Die API-Zugangsdaten werden pro Mandant verschlüsselt gespeichert.

Unter **Einstellungen → Buchhaltungsanbindung** wählen Mandantenadmins den Anbieter. Bei ERPNext erfassen sie die Zugangsdaten und Zuordnungen unter **ERPNext-Verbindung**. Der Belegexport erscheint auf der Detailseite einer intern freigegebenen Rechnung. Der Beitrag muss steuerbefreit sein und das Mitglied eine vollständige Schweizer Rechnungsadresse haben. Die Detailseite zeigt den ERPNext-Beleg und bietet eine manuelle Aktualisierung seines Status an.

## DEV-Rollout und Abnahme

Release `20261116-clubunio-erpnext-sandbox` auf dem Pi, Basis und Rücksprung `20261116-clubunio-keycloak-cleanup`. Die beiden Migrationen `2026_11_16_003000_tenant_accounting_provider` und `2026_11_16_003010_erpnext_invoice_transfers` wurden auf der dedizierten PostgreSQL-Datenbank `clubunio_dev` nach einem Transaktionstest angewendet. Der Rücksprung benötigt keine Rückmigration. Er ist nur für die technische Wiederherstellung gedacht. Vor einem Rücksprung mit bestehenden ERPNext-Belegen müssen Exporte für die betroffenen Testmandanten ausgesetzt sein, weil die Vorgängerversion die Anbieterauswahl noch nicht beachtet.

Die drei synthetischen Mandanten `test-accounting-none`, `test-accounting-bexio` und `test-accounting-erpnext` decken die Anbieterauswahl ab. Es gab keinen Bexio-Live-Aufruf. Der produktive Bexio-Ablauf ist nicht Teil der Sandbox-Abnahme.

| ClubUnio | ERPNext | Betrag | QR-Referenz |
| --- | --- | ---: | --- |
| 11172 | ACC-SINV-2026-00008 | CHF 56.25 | `000000000000000002026000080` |
| 11173 | ACC-SINV-2026-00009 | CHF 71.90 | `000000000000000002026000091` |

Ein erneuter Export lieferte jeweils denselben ERPNext-Beleg. Die Swiss-Druckvorlage lieferte beide QR-Nutzdaten. Lokal daraus erzeugte und gescannte QR-Codes bestätigten Wert, Währung, Test-QR-IBAN `CH4431999123000889012` und die verschiedenen 27-stelligen Referenzen samt Prüfziffer. Für den Scan wurde der externe QR-Bilddienst nicht aufgerufen.

Nach der ERPNext-Freigabe und einer simulierten Kassenzahlung von CHF 20.00 las ClubUnio den ersten Beleg als **teilbezahlt** zurück. Nach weiteren CHF 36.25 erschien er als **bezahlt**. Der zweite Beleg bleibt **offen**. Es gab keinen Mailversand und keine Bankzahlungen.

Die lokalen Prüfungen decken die exklusive Anbieterauswahl, den Bexio-Tokenabruf mit simuliertem Anbieter, den ERPNext-Export nach einem simulierten Timeout und die Statuszuordnung ab. Insgesamt liefen 15 Tests mit 1850 Assertions. Die Sprachprüfung für Deutsch und Englisch war erfolgreich.
