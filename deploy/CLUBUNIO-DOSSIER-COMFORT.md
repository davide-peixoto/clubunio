# Untertabellen: Mehrfachaktionen, Verlauf und Saisonuebernahme

Stand 16.10.2026. Ausschliesslich ClubUnio. Keine Schema-/Datenmigration,
kein DB-Refresh und keine PHPMaker-DEV/PROD-Aenderungen.

## Mehrfachaktionen

Ergaenzung 16.10.2026: Auch Funktionen, Sponsoring und Veranstaltungen im
Mitgliederdossier bieten Auswahl deaktivieren/wiederherstellen. Maximal 100,
gesperrter Snapshot, Eltern-/Mandantenbindung und atomarer Rollback. Keine
Mehrfachkopie oder physische Loeschung. Qualifikationen bleiben erhalten.

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
Die aktive Quellmannschaftsauswahl ist vorbelegt und kann reduziert werden.
Nur ausgewaehlte Mannschaften samt Spieler/Staff/TL werden uebernommen.
Abgewaehlte Konflikte blockieren nicht. Aendern der Auswahl setzt die
Bestaetigung zurueck und berechnet die signierte Vorschau neu. Leere,
fremde oder nachtraeglich manipulierte Auswahlen werden abgewiesen.
Nach Auswahl der Zielsaison zeigt die Vorschau neue, vorhandene und
blockierte Mannschafts-/Spieler-/Staff-/TL-Zuordnungen samt Grund.
Explizite Bestaetigung erforderlich. Nur aktive Quellzuordnungen; ausgetretene
oder archivierte Mitglieder und ungueltige Staff-Funktionen blockieren.
Vorhandene Zielzuordnungen werden nicht ueberschrieben oder reaktiviert.
Inaktive/mehrdeutige ausgewaehlte Zielmannschaften blockieren die Uebernahme.
Keine Uebernahme von Rechnungsreferenzen, Spesen, Terminen, Trainingszeiten,
Anmeldungen, Beitraegen oder historischen IDs. Maximal 1000 Vorschauzeilen.

Signierter Datenstand wird beim Speichern neu berechnet: Aenderungen seit
der Vorschau erzwingen eine neue Vorschau. Verarbeitung in einer Transaktion
mit Mandantensperre, frischen Rechten und Audit durch TeamAssignmentService.
Wiederholung erzeugt keine weiteren vorhandenen Zuordnungen.

## Pruefung und Rollout

Auswahlerweiterung: 29 Tests / 462 Assertions erfolgreich
(WorkspaceEditingTest und DossierMaintenanceTest). Enthalten: drei Bulk-
Objekttypen, Wiederherstellung, kompletter Rollback bei veralteter Revision,
Teiluebernahme, manipulierte/leere Auswahl und neue Bestaetigung nach Auswahl.
Browser: Mannschaft abwaehlen und reduzierte Vorschau geprueft; keine
echten Zuordnungen gespeichert. Release: 20261016-clubunio-dossier-selection.

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
