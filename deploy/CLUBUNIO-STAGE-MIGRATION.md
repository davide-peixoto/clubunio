# Stage-Erstimport: SV Sissach

Ziel ist
`clubunio_stage` auf `vps.clubunio.com`, SSH-Benutzer `root`, PostgreSQL-Rolle
`userunio`.
Quelle ist der aktuelle PHPMaker-PROD-Bestand (`db_acdf8d_mysvs`, SQL Server),
nicht ClubUnio DEV und nicht dessen historischer Oktober-Snapshot.

## Voraussetzungen

1. SSH-Zugang und tatsaechlich leere PostgreSQL-Datenbank bestaetigen. Kein
   `migrate:fresh`, kein Ueberschreiben und kein automatisches Loeschen.
2. In der ignorierten Datei `C:\ClubUnio\.env.stage-migration.local` separat
   `PGHOST`, `PGPORT`, `PGDATABASE=clubunio_stage`, `PGUSER`, `PGPASSWORD`,
   `PGSSLROOTCERT`, `APP_ENV=staging` und einen Stage-eigenen, dauerhaft
   gesicherten `APP_KEY` im Format
   `base64:<32 Bytes>` hinterlegen. Keine DEV-/PROD-Schluessel wiederverwenden.
   Der Importbenutzer braucht Schema-/DDL-Rechte nur im Stage-Ziel. Keine
   Zugangsdaten im Chat, Git, Kommandoargument oder Protokoll.
3. Auf dem Migrationsrechner muessen PHP `pdo_pgsql` und `pdo_sqlsrv` sowie
   der bereits konfigurierte SQL-Server-PROD-Lesezugang verfuegbar sein.
   PostgreSQL nur privat erreichen. Bei lokalem SSH-Tunnel optional
   `PGHOSTADDR=127.0.0.1` setzen; `PGHOST` bleibt der Name aus dem
   PostgreSQL-Zertifikat, `PGPORT` ist der lokale Tunnelport. TLS bleibt
   `verify-full`; den PostgreSQL-Port nicht oeffentlich freigeben.
4. Stage-Webanwendung und Scheduler bis zur separaten Freigabe nicht auf
   diese DB schalten. Der Importnutzer ist nicht der spaetere Webnutzer.
   Keine Laufzeitrechte auf `legacy_prod` und `migration_audit` erteilen.

## Automatischer Lauf nach Freigabe

```powershell
php tools/clubunio-stage-migrate.php --plan
php -d extension=pdo_pgsql tools/clubunio-stage-migrate.php --apply clubunio_stage
```

`--plan` oeffnet keine Verbindungen. `--apply` verlangt die explizite Stage-ID,
prueft den echten verbundenen DB-Namen und lehnt vorhandene Anwendungsschemata
ab. Der Snapshot-Importer verwendet eine lesende SQL-Server-Transaktion mit
SNAPSHOT-Isolation, schreibt die Daten in `legacy_prod` und verifiziert
Zeilenanzahl und SHA-256 pro Tabelle. Der Abzug bleibt vertraulich (u. a.
Original-Konten und Parameter) und ist kein Laufzeitschema.

Danach erstellt der Runner `clubora`, arbeitet die versionierten Migrationen
ab und ruft die elf bestehenden Fachimporte an ihren Schema-Zwischenpunkten
auf. Der Foundation-Import erstellt genau einen neuen Mandanten `sv-sissach`.
Native IDs werden neu vergeben; Alt-IDs bleiben als `legacy_id` erhalten.
Die Saison wird ueber `general/SaisonID` aufgeloest, nicht per festem Ziel-ID.
Schema, Fachimporte, Lookup-/Vorlagenseeds und Abnahme bilden eine gemeinsame
Zieltransaktion. Nach Fehlschlag bleibt nur der verifizierte Snapshot; mit
`--resume-build clubunio_stage` laesst sich ausschliesslich die fehlende
Fachschicht wiederholen. Erfolgreiche Ziele werden nicht nochmals importiert.

## Abnahme und Grenzen

### Neuer PROD-Abzug fuer den zweiten Mandanten

Am 10.11.2026, 15:58:54 bis 16:00:14 Uhr (Europe/Zurich), wurde
`db_acdf8d_mysvs` lesend in `clubunio_stage_refresh_20261110` kopiert.
Der erneute Verifikationslauf bestaetigte 100 Tabellen, 53'388 Zeilen
und die Pruefsumme jeder Tabelle. Die Kopie enthaelt 81
Mitgliederanmeldungen, 18 Mitgliederaenderungsantraege, 697
Trainingszeiten und 35 Teamtermine (inklusive ggf. geloeschter Zeilen).
Die Datenbank ist nicht mit der Stage-Webanwendung verbunden. Nur
Snapshot und Audit wurden angelegt; kein Fachimport und keine Aktivierung
eines weiteren Mandanten. Der bestehende Stand bleibt erhalten.

### Fachimport des isolierten Kandidaten abgeschlossen

Am 10.11.2026 wurde der vorhandene Abzug per `--resume-build` in
`clubunio_stage_refresh_20261110` verarbeitet. Der Lauf wurde direkt von
Windows per SSH gestartet und serverlokal in einem separaten Checkout
ausgefuehrt. Kein neuer PROD-Abzug und keine Aenderung der laufenden Stage.

- Mandant `sv-sissach-refresh`, 118 Migrationen, elf Basisimporte und
  Saison 2026/2027 (Legacy-ID 38) bestaetigt; 697 Trainingszeiten.
- 18 Aenderungsantraege im Ziel: 15 genehmigt, zwei offen, einer abgelehnt.
- Aktive Winterperiode `W`; Projektionspruefung der Sportplatzbelegung
  ergibt 61 Trainings fuer den geprueften Wochenzeitraum. Das ist keine
  angemeldete Browserabnahme der laufenden Stage.
- Keine aktiven importierten Konten und kein Providerbetrieb. 14 historische
  Spesen-/Teamsaison-Konflikte sind im Kandidaten ausgewiesen und ungeaendert.

Der Kandidat ist noch nicht als weiterer Mandant in die laufende Stage
integriert. Der Abgleich ergab 75 gleiche globale Kontoidentitaeten.
Vor der Uebernahme eine explizite, gepruefte Konto- und FK-Zuordnung
festlegen; bestehende Benutzer, Rollen und Sitzungen nicht ueberschreiben
oder automatisch berechtigen.

### Kontoabgleich vor Aufnahme in die laufende Stage

Der lesende Abgleich der Mitgliedschaften ergab 75 eindeutige Treffer
ueber normalisierte Benutzernamen und keine Rollenabweichung. In der
laufenden Stage sind 37 dieser Konten aktiv, davon sechs mit Rolle
Geschaeftsstelle. Eine direkte Uebernahme der Mitgliedschaften wuerde
deren Zugriff erweitern; `portal_enabled=false` allein sperrt
Geschaeftsstelle/Admin nicht. Der isolierte Kandidat bleibt unveraendert.

Vor der Integration in einer separaten Kopie der laufenden Stage
proben: globale Konten nicht duplizieren oder ueberschreiben; historische
Antragsteller und Entscheider explizit zuordnen; Zugriffsfreigaben separat
festlegen. Native IDs und zusammengesetzte Mandanten-FKs neu zuordnen.
Auch native IDs in verschluesselten Einstellungen (Saison und
Trainingsperiode) sowie Lookup-IDs in Antragssnapshots pruefen.
Abnahme: Altmandanten und ihre Rechte unveraendert, Nicht-Testkonten
ohne neuen Zugriff, Browserpruefung der Antraege und Sportplatzbelegung
mit explizit berechtigtem Stage-Konto. Noch keine Uebernahme oder
Browserabnahme erfolgt.

Freigabe vom 10.11.2026: Im neuen Mandanten ausschliesslich das explizit
benannte bestehende Stage-Konto fuer die Browserabnahme berechtigen.
Die 75 Quellkonten dienen nur der historischen Zuordnung; keine
Anmeldedaten oder Berechtigungen aus dem Abzug aktivieren. Insbesondere
kein aktives Bestandskonto mit einer kopierten Mitgliedschaft versehen,
nur um eine Antragsteller- oder Entscheider-FK zu erfuellen. Ist eine
Mandantenmitgliedschaft technisch erforderlich, eine nicht anmeldbare
historische Identitaet verwenden. Bestehende Mandanten, Rollen und
Sitzungen unveraendert lassen.

Vor Commit der Datenbanktransaktion genau eine neue aktive Berechtigung
pruefen und die bisherigen Berechtigungen vergleichen. Danach Antraege
und Winterbelegung angemeldet pruefen, ohne einen Antrag zu entscheiden
oder Benachrichtigungen auszuloesen. Der Integrationsversuch konnte
wegen direktem Windows-SSH-Timeout nicht begonnen werden.

### Pflichtpunkte und bisherige Importversuche

**Versuch 10.11.2026:** Der isolierte Aufbau in
`clubunio_stage_refresh_20261110` verifiziert den bestehenden Snapshot
erneut, ohne PROD neu zu lesen. Der vorbereitete Zusatzimport bildet
`iniParameter.SommerWinter = W` auf die native Winterperiode ab, uebernimmt
die Aenderungsantraege mit Status und verschluesseltem Alt-/Neuwert und
prueft projizierte Trainings in der Sportplatzbelegung. Der erste Lauf
stoppte beim Trainingsimport; der aktuelle PHPMaker-Katalog belegt den
fehlenden Platzcode `L` (Halle) fuer zehn aktive Wintertrainings. Die
Zuordnung wurde ergaenzt. Der Wiederanlauf stoppte frueher im Objektimport;
parallel brach der direkte SSH-Pruefzugriff mit Timeout ab. Kein
erfolgreicher Fachimport und keine Abnahme behauptet.

Der Erstimport-Runner baut den Kandidaten separat auf und erzeugt keine
zweite Mandanteninstanz in der laufenden Stage-Datenbank. Vor einer
Uebernahme in die laufende Stage sind globale Konten und native
Fremdschluessel fuer den neuen Mandanten explizit zuzuordnen. Keine
bestehenden Konten oder Zuordnungen ueberschreiben. Im Kandidaten bleiben
importierte Logins und Provider deaktiviert.

Den neuen, lesenden PROD-Abzug in einer separaten, leeren Stage-Datenbank
aufbauen. Den bisherigen Stand und die Stage-spezifischen Testkonten und
Einstellungen erhalten. Erst nach Abnahme und separater Freigabe umschalten.

- **Mitgliederaenderungsantraege:** Den fehlenden Fachimport fuer
  `legacy_prod.MitgliederAenderungsantraege` nach
  `clubunio.member_change_requests` ergaenzen. Im bisherigen Abzug lagen
  18 Antraege, im Ziel 0. Mitglieder und weitere Verknuepfungen ueber
  Legacy-IDs im Zielmandanten aufloesen. Status und Historie erhalten;
  keine Genehmigung, E-Mail oder Push ausloesen. Wiederholbarkeit und
  Vollstaendigkeit pruefen; ausgeschlossene Datensaetze begruenden.
- **Trainingsperiode und Sportplatzbelegung:** Den aktiven Trainingsplan
  aus PROD ermitteln, auf eine aktive Periode des Zielmandanten abbilden
  und den Zielwert `general/SIGNAGE_PERIOD_ID` setzen. Keine Quell-ID
  ungeprueft als native ID uebernehmen. Fehlt die eindeutige Zuordnung,
  vor der Umschaltung zur manuellen Entscheidung melden. Im bisherigen
  Stand waren 651 Trainingszeiten und 34 nicht geloeschte Teamtermine
  vorhanden, aber die aktive Periode fehlte. Die Sportplatzbelegung
  deshalb fuer einen bekannten Zeitraum als berechtigte Person im
  SV-Sissach-Mandanten pruefen; ein Pilotkonto ist kein Ersatz.
- **Datenabgleich:** Mitgliederanmeldungen, Aenderungsantraege,
  Trainingszeiten und Teamtermine fuer den neuen Abzug zwischen Quelle
  und Ziel abgleichen. Neben Summen auch Verknuepfungen und Anzeige in
  den relevanten Ansichten pruefen.

### Allgemeine Abnahme

- Verifizierter Snapshot, genau ein Mandant, vollstaendiges Migrationsjournal,
  Fachimport-Marker, gueltige Saisonauflosung und keine fremden oder unerklaert
  geaenderten Saisonreferenzen. Der Runner gibt nur technische Summen aus.
- Historische Spesen-/Teamsaison-Konflikte aus der Quelle werden ausgewiesen.
  Die bereits genehmigten 14 DEV-Korrekturen vor Freigabe gegen die aktuelle
  Quelle neu zuordnen; niemals alte native IDs nach Stage kopieren. Ist die
  Quelle bereits korrekt, nur bestaetigen. Die gepinnte DEV-Reparatur ist kein
  Stage-Schritt.
- Der native Fachumfang ist der bestehende Migrationsstand. Nicht gemappte
  Legacy-Tabellen bleiben nur im Snapshot. Anhaenge werden nicht binaer
  kopiert; Speicher-/Dateifreigabe und manuelle Altbestand-Uebernahmen separat
  pruefen. Kein Kopieren von ClubUnio-DEV-Testdaten.
- Importierte Logins sind gesperrt, native als geheim klassifizierte Parameter
  geleert und deaktiviert. Vor Browserabnahme separat Stage-Testkonten und
  Stage-Provider konfigurieren. Kein Mail-, Push-, Bexio- oder anderer
  Providerversand und keine Schreiboperation auf der PROD-Quelle.
- SSH direkt von Windows als `root` (ohne Pi) und anfangs leere Ziel-DB
  bestaetigt. PostgreSQL 16.15,
  UTF8/libc, `lc_collate` und `lc_ctype` jeweils `C.UTF-8`, anders als DEV (`C`).
  TLS ist serverseitig aktiv, `userunio` hat CONNECT und CREATE auf Stage.
  Anmeldung als `userunio` mit `verify-full` ueber den lokalen SSH-Tunnel
  bestaetigt. Locale nicht automatisch aendern; Sortierung und Textsuche bei
  der Stage-Abnahme pruefen.

## Ausfuehrungsnachweis

09.11.2026 23:58 bis 23:59 Uhr (Europe/Zurich): aktueller PROD-Snapshot
mit 100 Tabellen und 53305 Zeilen ausschliesslich nach Stage kopiert. Eine
unabhaengige Wiederholungspruefung bestaetigte alle Tabellen und Zeilen.
Der erste Fachaufbau stoppte im Anlagenimport und wurde atomar zurueckgerollt:
Der aktuelle Importer setzt den Wegfall von `facility_statuses.color` voraus.
Der Import-Zwischenpunkt wurde deshalb hinter Migration 000019 verschoben.
Ein erster Wiederanlauf scheiterte am direkten SSH-Tunnel (Port 22 Timeout).

10.11.2026: `--resume-build clubunio_stage` direkt von Windows erfolgreich
ausgefuehrt. Unveraenderter Snapshot erneut verifiziert, kein neuer PROD-Abzug.
117 Migrationen und elf Fachimporte abgeschlossen. Separater lesender Check
nach Commit: genau ein Mandant `sv-sissach`, 2439 Mitglieder, 75 deaktivierte
Konten ohne Passwort, 6791 Rechnungen, 483 Spesenbelege und 34 Teamtermine.
Keine aktiven geheimen Einstellungen. Saison 2026/2027: Legacy-ID 38, Ziel-ID 37.
Die Referenzpruefung meldete keine fremden oder unerklaert veraenderten
Saisonverknuepfungen. 14 bereits in der Quelle vorhandene Spesen-/Teamsaison-
Konflikte wurden anschliessend separat korrigiert (siehe unten).
Kein Webdeployment und keine Aenderung an DEV oder PROD.

## Freigegebene Spesen-Korrektur auf Stage

10.11.2026: 14 freigegebene Legacy-Belege auf die gleiche Mannschaft in der
bereits korrekten Belegsaison umgehaengt. Beleg, Saison, Mannschaft sowie alte
und neue Mannschaftssaison mit den Legacy-IDs im unveraenderten Snapshot
abgeglichen. Pro Beleg genau eine aktive Zielzuordnung; keine DEV-Ziel-IDs
uebernommen. Die exakte Zuordnung steht in
[correct-stage-expense-season-teams.sql](correct-stage-expense-season-teams.sql).

Rollback-Test und anschliessender Commit bestanden. Alle 191 betroffenen
Positionen und alle anderen Belegfelder unveraendert; lediglich Zuordnung,
Revision und Zeitstempel nachgefuehrt. 14 Audit-Eintraege enthalten vorherige
Zuordnung, Revision und Zeitstempel fuer einen gezielten Rueckweg. Separater
Lesecheck: 0 verbleibende Beleg-/Teamsaison-Konflikte, 14 verifizierte
Audit-Eintraege. Kein neuer PROD-Abzug, DEV und PROD unveraendert.

Historische Dateireferenzen koennen nach der separat freigegebenen
Container-Kopie gelesen werden: [Stage-Azure](CLUBUNIO-STAGE-AZURE.md).
