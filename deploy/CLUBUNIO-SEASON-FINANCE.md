# Rechnungen und Spesen innerhalb der Saison

## Umfang (16.10.2026)

Saisons > Saison > Rechnungen/Spesen bleibt der Arbeitskontext.
Zeilenklick und Positionssymbol oeffnen die vorhandenen Positionsmanager im
rechten Dialog, ohne Navigation in ein anderes Menue. Kopf bearbeiten und neue
Belege erfassen sind eigene Dialoge. Rechnungen bieten zusaetzlich interne
Freigabe, lokale Stornierung und die bestehende lokale/Bexio-PDF-Vorschau.
Spesen verwenden die bestehenden Statusuebergaenge und Nachrichtenregeln.
Es werden keine neuen externen Versand- oder Zahlungsprozesse eingefuehrt.

Rechnungspositionen: hinzufuegen, bearbeiten, entfernen/wiederherstellen nach
den bestehenden Regeln. Beitragszuordnungen bleiben geschuetzt.
Spesenpositionen: erfassen, bearbeiten/deaktivieren und Dokumentupload ueber
die vorhandenen Services. KI-/Dressreinigungs-Vorschlag, Pruefstatus und
Azure-Freigabespruefungen bleiben unveraendert.

Neue Rechnungen werden serverseitig der geoeffneten Saison zugeordnet.
Spesen-Mannschaftsauswahl ist auf deren TeamSaison-Datensaetze beschraenkt;
dies wird vor dem Schreiben auch serverseitig geprueft. Bankkontooptionen
bleiben mitgliedsbezogen. Neue Rechnungen/Spesen zeigen ersatzweise ihre
ClubUnio-ID an, wenn keine importierte Nummer vorhanden ist.

Nach Positionsaenderungen werden Summen aktualisiert, ohne Suche, Filter oder
Seite zurueckzusetzen. Die normalen Rechnungsdetailseiten behalten ihren
bisherigen Aktualisierungsweg. Mitglieder-/Mannschaftsdossiers bleiben in
diesem Paket unveraendert; die neuen Belegaktionen gelten fuer Saisons.

## Schutz und bewusste Grenzen

Keine pauschale Finanz-Loeschung/Kopie. Importierte und an Bexio uebertragene
Rechnungen bleiben schreibgeschuetzt. Freigegebene Rechnungen erlauben keine
Positionsbearbeitung. Die allgemeinen Bexio-Uebertragungs-, Gutschrift- und
Rueckerstattungsaktionen bleiben auf den bestehenden Fachseiten.

SeasonFinanceActions prueft frische Rolle, Mandant, Saison und zugehoerigen
Beleg unter Transaktionssperre; bestehende Fachservices pruefen Revision,
Lookups und Status nochmals. Eingebettete Positionen tragen einen gesperrten
Saisonkontext, der bei jedem Livewire-Request gegen den aktuellen Beleg
geprueft wird. Ein abweichender oder nachtraeglich verschobener Beleg wird
abgewiesen. Keine neuen Berechtigungen.

## Migration und Rollout

Keine Schema-/Datenmigration, kein PHPMaker-Import oder Datenbankrefresh.
InvoiceWorkflowService akzeptiert eine optionale, mandantengepruefte Saison
bei Neuanlage; bisherige Aufrufer ohne Saison bleiben unveraendert.
Gezieltes Delta ausschliesslich auf ClubUnio, aus dem jeweils aktuellen
Release klonen und Config-/Routen-/Viewcache neu bauen. Ruecknahme durch
vorherigen Release; bereits erfasste Belege bleiben regulaere Datensaetze.
PHPMaker DEV/PROD, API, SaaS und Betrieb bleiben unveraendert.

## Pruefung

SeasonFinanceTest nutzt synthetische Daten in einer Rollback-Transaktion;
HTTP-Stray-Requests gesperrt, E-Mail gefaelscht. Geprueft werden Kopf/Positionen,
Freigabe/Storno, Statuswechsel, Saison-/Mandantentrennung, Versionskonflikte,
entzogene Rolle, Importschutz und unveraenderte Suche/Filter.
Lokale angemeldete Browserprobe: Rechnungspositionen in Saison und
Spesenpositionen samt verschachteltem Bearbeitungsdialog auf390px Mobile.
Keine echten Belege dabei gespeichert und keine Bankzahlung ausgefuehrt.

Ergebnis:32Tests/342Assertions erfolgreich (SeasonFinanceTest,
WorkspaceEditingTest, InvoiceDirectSandboxTest, DocumentUploadTest).
PHP-Syntax und Pint erfolgreich. Release20261016-clubunio-season-finance
auf Basis/Ruecksprung20261016-clubunio-73-performance; keine Migration.
