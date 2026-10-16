# Untertabellen: Mehrfachaktionen, Verlauf und Saisonuebernahme

Stand 16.10.2026. Ausschliesslich ClubUnio. Keine Schema-/Datenmigration,
kein DB-Refresh und keine PHPMaker-DEV/PROD-Aenderungen.

## Mehrfachaktionen

In Zuordnungs-Untertabellen (Mannschaft/Saison, Spieler, Staff, TL, Familie,
Beitragsregeln, Trainingszeiten) Auswahl deaktivieren/wiederherstellen.
Maximal 100 Eintraege, bestaetigter Snapshot beim Oeffnen. Jede Zeile bleibt
an Mandant und Dossier gebunden. Frische Rechte und bestehende Fachservices;
Versions-/Dublettenfehler rollen die ganze Auswahl zurueck. Keine pauschale
Mehrfachloeschung oder Massenbearbeitung von Bank-/Finanzdaten.

## Verlauf

Dossier-Untertabellen erhalten einen rechten lesenden Verlaufsdialog. Die
letzten 100 object_changes-Ereignisse fuer exakt Mandant/Objekt/ID, mit
Zeitpunkt, Akteur und Vorher/Nachher-Werten. Feld-Allowlist schliesst Secrets,
private Speicherpfade und verschluesselte Bankdaten aus. Keine erfundene
Althistorie: importierte oder separat protokollierte Vorgaenge koennen leer
sein. Vor jedem Lesen werden Dossierzugriff und aktuelle Rolle geprueft.

## Saisonuebernahme

Saison > Mannschaften pro Saison > In andere Saison uebernehmen.
Nach Auswahl der Zielsaison zeigt die Vorschau neue, vorhandene und
blockierte Mannschafts-/Spieler-/Staff-/TL-Zuordnungen samt Grund.
Explizite Bestaetigung erforderlich. Nur aktive Quellzuordnungen; ausgetretene
oder archivierte Mitglieder und ungueltige Staff-Funktionen blockieren.
Vorhandene Zielzuordnungen werden nicht ueberschrieben oder reaktiviert.
Inaktive/mehrdeutige Zielmannschaften blockieren die gesamte Uebernahme.
Keine Uebernahme von Rechnungsreferenzen, Spesen, Terminen, Trainingszeiten,
Anmeldungen, Beitraegen oder historischen IDs. Maximal 1000 Vorschauzeilen.

Signierter Datenstand wird beim Speichern neu berechnet: Aenderungen seit
der Vorschau erzwingen eine neue Vorschau. Verarbeitung in einer Transaktion
mit Mandantensperre, frischen Rechten und Audit durch TeamAssignmentService.
Wiederholung erzeugt keine weiteren vorhandenen Zuordnungen.

## Pruefung und Rollout

Transaktionale Livewire-/Service-Tests: Bulk-Aktivstatus, veraltete Auswahl
und kompletter Rollback, Verlauf/Redaktion/Rollenentzug, Vorschau und
Uebernahme, Wiederholung/Dubletten, inaktive Ziele und Mitgliedsaustritt.
Bestehende Dossier-, Finanz-, Dokument- und Workspace-Regressionen.
Regression: 50 Tests, 777 Assertions, ein optionaler Test uebersprungen.
Abschliessende gezielte Pruefung: 5 Tests, 47 Assertions erfolgreich,
einschliesslich Staff-/TL-Uebernahme. Lokale Sichtpruefung von Vorschau,
leerem Verlauf und Bulk-Bestaetigung ohne Speicherung echter Datensaetze.
Keine echten E-Mails, Push-Zustellungen oder Bankzahlungen im Test.
Dateidelta auf aktuellem ClubUnio-Release, Caches und Tenant-Guards pruefen.
Rollback auf vorheriges Release ohne Datenruecksetzung; angelegte
Zuordnungen bleiben als regulaere, auditierte Datensaetze erhalten.
API/SaaS/Betriebserweiterungen bleiben ausgeschlossen.
