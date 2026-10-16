# Finanzdialoge in Mitgliedern und Mannschaften

## Umfang 16.10.2026

Mitglieder, Mannschaften und Mannschaften pro Saison verwenden dieselben
Belegkopf-/Positionsdialoge wie Saisons. Interne Rechnungsfreigabe, Storno,
PDF und Spesenstatus verwenden unveraendert die bestehenden Fachservices.
Importierte/Bexio-Rechnungen bleiben gegen Bearbeitung gesperrt.

Im Mitgliederdossier sind neue Rechnungen mit optionaler Saison und neue
Spesen moeglich. Das Mitglied wird serverseitig festgesetzt; eigene
Bankkonten bleiben die einzige Auswahl. Mannschaftsspesen sind auf die
zugeordneten TeamSaison-Datensaetze beschraenkt, bei Mannschaft pro Saison
ist diese Zuordnung fest.

Rechnungen bei Mannschaften stammen ausschliesslich aus der expliziten
Verknuepfung team_players.invoice_line_id -> invoice_lines.invoice_id.
Keine pauschale Anzeige aller Rechnungen eines Mannschaftsmitglieds und
keine automatisch erfundene Zuordnung. Neue Mannschaftsrechnungen entstehen
weiterhin im Beitragsprozess, nicht durch einen unverbundenen Dialog.

Gesperrter Dossier-Typ und ID werden fuer eingebettete Positionen bei jedem
Request gegen Mandant, Berechtigung und aktuelle Belegzuordnung geprueft.
Kopfaenderungen pruefen denselben Zusammenhang in der Transaktion.
Summen werden aktualisiert, Suche und Filter bleiben erhalten.

## Migration und Freigabe

Keine Schema-/Datenmigration. Vorhandene invoice_line_id-Verknuepfungen bei
spaeteren Migrationen erhalten; fehlende Zuordnungen nicht aus Namen ableiten.
Delta auf ClubUnio aus dem aktuellen Release, Caches neu aufbauen.
Ruecknahme auf 20261016-clubunio-season-finance ohne Datenruecksetzung.
PHPMaker DEV/PROD bleiben unveraendert. API, SaaS und Betrieb nicht erweitert.

## Pruefung

SeasonFinanceTest erweitert um Mitgliedsbindung, eingebettete Positionen,
nachtraeglich geaenderte Zuordnung sowie Mannschaftsrechnungen und Spesen
auf beiden Mannschaftsebenen. Transaktionale Testdaten, kein echter Versand.
Zusatzregression: WorkspaceEditingTest, InvoiceDirectSandboxTest,
DocumentUploadTest. Visuelle Dialogpruefung ohne Speicherung echter Belege.
