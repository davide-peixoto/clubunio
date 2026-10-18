# Dossierdialoge: Abnahmebefunde korrigiert (18.10.2026)

## Umfang

- Rechnungsverlauf liest verschachtelte `invoice`-Snapshots sowie `data` bei
  Neuanlagen. Flache historische Snapshots bleiben lesbar; echte Nullwerte
  bleiben sichtbar. Feld-Allowlist und aktuelle Leseberechtigung unveraendert.
- Deutsche Feldbeschriftungen im Verlauf; Rechnungspositionen verwenden
  `Position bearbeiten` statt `Edit line` (Aktion, Tooltip und Dialog).
- Schliessen des Positionsdialogs aktualisiert Owner und Tabellenabfrage
  ausdruecklich. Suche, Filter und Seitenauswahl bleiben erhalten.
- Relation-Tabs und Tabellen zeigen waehrend asynchroner Aktualisierung eine
  Ladeanzeige. Alte Inhalte werden ausgeblendet und sind nicht anklickbar.

Der lokale Nachtest praezisiert den vorherigen Befund: Tab und Betrag wurden
nach Ende der asynchronen Abfrage auch ohne Neuladen aktualisiert. Die vorher
sichtbare alte Tabelle war irrefuehrend; kein Datenverlust festgestellt.

## Pruefung

Gezielte Tests in SeasonFinanceTest, ProfileAcceptanceTest und
WorkspaceEditingTest: Rechnungs-Snapshots, Nullwerte, Redaktion, Beschriftung,
Positionssummen nach Dialogschluss, erhaltene Filter, Tabwechsel und Rechte.
Browserpruefung mit ausschliesslich synthetischem Finanzkonto/Testmandanten;
keine vorhandenen Benutzerrechte angepasst und keine externe Zustellung.
Ergebnis: **36 Tests / 776 Assertions erfolgreich**. Lokal im Browser:
Rechnungsbemerkung im Verlauf mit korrektem Vorher-/Nachher-Wert; Position
CHF30 auf CHF35 geaendert, Elternsumme nach Dialogschluss ohne Reload korrekt.
Tabwechsel blendet alte Inhalte beim Laden aus. Deutscher Positionsdialog
und Aktion sichtbar. Auf dev.clubunio.ch Verlauf, Positionen und Summe
ebenfalls geprueft; Spesentab ohne Reload korrekt geladen, keine Browserfehler.
Testmandant 13030, Konten 12511/12512 und saemtliche synthetische Fachdaten
anschliessend entfernt. Beide Testsitzungen abgemeldet. Rechte-Fingerprints
der bestehenden Benutzer vor/nach Bereinigung identisch.

Releasechecks erfolgreich: PHP-Syntax, View-/Routen-/Konfigurationscache,
Mandantenrouten, Dateischutz und Zugriffsprofile; Login HTTP200, geschuetzte
Mitgliederliste ohne Anmeldung HTTP302. Keine Datenbankmigration.

Bildbeleg: [Verlauf auf ClubUnio](evidence/dossier-ui-20261018/history-deployed.jpg).
Lokal: [Verlauf](evidence/dossier-ui-20261018/history-local.jpg).

## Deployment und Migration

Gezieltes Delta `20261018-clubunio-dossier-ui-fixes`, keine Datenbankmigration.
Basis: `20261018-clubunio-series-absence-performance`. Vor Aktivierung werden
Routen, Views, Mandantennavigation und Zugriffsprofile geprueft. Ruecksprung
auf diese Basis erfordert keinen DB-Rollback. Vor Umschaltung prueft das
Skript nochmals die Basis, um parallele Deployments nicht zu ueberschreiben.
CSS-Version wird erhoeht. Keine Secrets, Uploads oder Testkonten im Release.

PHPMaker DEV/PROD, bestehende Rollen und Benutzerzuordnungen bleiben unveraendert.
API, SaaS, Betrieb und echte Bankzahlungen sind nicht Teil dieses Pakets.
