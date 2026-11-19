# ERPNext-Anbindung für die Vereinsbuchhaltung

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

## Freigegebene Spesen

In einem ERPNext-Mandanten können Personen mit Spesenfreigaberecht auf dem zur Zahlung freigegebenen Spesenbeleg **ERPNext-Entwurf anlegen** wählen. Der Beleg wird als CHF-Kreditorenentwurf in ERPNext angelegt. Kreditor ist das Mitglied oder Unternehmen, dem der Verein die Auslagen erstatten soll. Eine direkte Lieferantenrechnung eines Drittanbieters bleibt ein eigener Beleg. In der DEV-Sandbox sind die Zuordnungen Services als Lieferantengruppe, CUT-EXPENSE als Spesenartikel und Vereinsaufwand DEV - CUT als Aufwandskonto.

ClubUnio überträgt Betrag und Spesenart je Position sowie Name und gegebenenfalls die Schweizer Adresse des Empfängers. Bankdaten, Kontaktdaten und Belegdateien bleiben in ClubUnio. Der Export erzeugt ausschliesslich einen Entwurf ohne Steuerpositionen. Die ClubUnio-Referenz im Entwurf verknüpft ihn mit dem Spesenbeleg. Wiederholen nach einem Timeout sucht zuerst nach dieser Referenz. Der Integrationsbenutzer benötigt Lese- und Erstellrechte für Supplier, Address und Purchase Invoice. In allen drei Typen muss die Referenz eindeutig sein.

Der Spesenbeleg zeigt den ERPNext-Link und bietet eine manuelle Statusaktualisierung an. Die separate ClubUnio-Spesenfreigabe und der lokale Status bleiben erhalten. Der DEV-Statustimer betrifft Ausgangsrechnungen. Die Migration 2026_11_16_003020_erpnext_expense_transfers benötigt für die Laravel-Laufzeit Lese-, Einfüge- und Aktualisierungsrechte auf der neuen Tabelle.

## Automatischer Rechnungsstatus

Der DEV-Timer `clubunio-dev-erpnext-status.timer` startet alle fünf Minuten den lesenden Befehl `clubunio:erpnext-reconcile-status`. Er übernimmt den Stand bereits verknüpfter Rechnungen und kann unbestätigte Übertragungen anhand der ClubUnio-Referenz wiederfinden. Er prüft ausschliesslich Mandanten, die ERPNext ausgewählt haben. Fehlgeschlagene Statusprüfungen werden an der Rechnung angezeigt und erneut versucht. Bei bezahlten Rechnungen erfolgt die Kontrolle täglich, damit eine zurückgenommene Zahlung erkannt wird.

Die Zuordnung importierter Kontoauszüge zu Rechnungen ist ein separater Schritt in ERPNext Swiss. Der ClubUnio-Dienst liest das ERPNext-Ergebnis. In DEV darf ausschliesslich der dedizierte Dienst gestartet werden, da der allgemeine Laravel-Scheduler auch Versandaufgaben umfasst.

Bei neuen DEV-Ausgangsrechnungen speichert `CLUBUNIO_ERPNEXT_QRR_ENABLED=true` dieselbe 27-stellige QR-Referenz im nativen Feld `esr_reference` und im eindeutigen Feld `custom_clubunio_qrr`. Das ERPNext-DEV-Skript `deploy/erpnext-dev-qr-reference.py` installiert das Feld und bindet es in die QR-Druckvorlage der Testgesellschaft ein. Bestehende Rechnungen behalten ihre bisher gedruckte Referenz. Mit einer synthetischen CAMT.053-Datei hat die ERPNext-Swiss-Vorschau einen nach diesem Verfahren exportierten Beleg über die vollständige Referenz gefunden. Die Vorschau hat keinen Zahlungseintrag angelegt.

## Spesen, Lieferanten und Mitglieder

Freigegebene CHF-Spesen eines Mitglieds lassen sich auf der Spesendetailseite als Eingangsrechnungsentwurf nach ERPNext übertragen. ClubUnio verwendet das Mitglied oder seine Firma als Kreditor und erstellt bei Bedarf den Lieferanten. Der Beleganhang bleibt in ClubUnio. Der ERPNext-Status ist auf der Spesendetailseite manuell abrufbar. Dieser Ablauf deckt steuerfreie Erstattungen ab. Drittlieferantenrechnungen und Spesen mit Steuern sind gesonderte Prozesse.

Die DEV-Zuordnung verwendet die Lieferantengruppe `Services`, den Artikel `CUT-EXPENSE` und das Aufwandskonto `Vereinsaufwand DEV - CUT`. Die ERPNext-API-Berechtigung erlaubt, Kunden, Lieferanten, Adressen und Kontakte anzulegen oder zu aktualisieren und Rechnungsentwürfe anzulegen. Freigabe und Zahlung erfolgen ausserhalb von ClubUnio.

Im Mitgliederdossier können berechtigte Mandantenadmins die benötigten Daten gezielt als Kunde oder Lieferant synchronisieren. Derselbe ERPNext-Kontakt kann mit beiden Datensätzen verbunden sein. Die Referenzen enthalten den Mandanten und die unveränderliche ClubUnio-Mitglieds-ID. ClubUnio prüft vorhandene Verknüpfungen vor einem Update.

## DEV-Abnahme der Erweiterung

Release `20261116-clubunio-erpnext-workflows` mit den additiven Migrationen `2026_11_16_003020_erpnext_expense_transfers` und `2026_11_16_003025_erpnext_status_reconciliation`. Der Pi-Timer bestätigte zwei Belege ohne Fehler und setzte `ACC-SINV-2026-00009` nach einem simulierten Kasseneingang automatisch von offen auf bezahlt. Der ClubUnio-Spesenbeleg 2431 über CHF 32.45 wurde als `ACC-PINV-2026-00002` exportiert. Ein Wiederholungsversuch fand denselben Entwurf. Ein Testmitglied besitzt einen einzelnen ERPNext-Kontakt mit Kunden- und Lieferantenverknüpfung. Die neue Rechnung `ACC-SINV-2026-00012` über CHF 43.85 enthält die persistierte QR-Referenz `981416108456490613799274171`. Es wurden keine echten Bankzahlungen oder E-Mails ausgelöst.

Vor dem technischen Rücksprung auf `20261116-clubunio-erpnext-sandbox` den Timer stoppen und Spesenübertragungen für betroffene Mandanten aussetzen. Die älteren Programmdateien kennen deren ERPNext-Belegverknüpfungen noch nicht. Die zusätzlichen Datenbankfelder bleiben beim Rücksprung erhalten.
