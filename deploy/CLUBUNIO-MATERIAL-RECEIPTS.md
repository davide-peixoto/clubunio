# Materialuebergaben und historische Nachweise

Release62, 15.10.2026. Nur ClubUnio-Web und eigene PostgreSQL-Datenbank.
API, SaaS, Betriebsentwicklung und PHPMaker DEV/PROD bleiben unveraendert.

## Webablauf

- Verein > Materialuebergaben; eigener Reiter im Mitgliederdossier.
- Admin/Geschaeftsstelle erfassen Mitglied, Datum, Titel, Bemerkung und einzelne
  Gegenstaende mit Anzahl, Nummer/Groesse und Zustand. Keine Lagerbuchung,
  Kostenforderung oder Hardware-Schluesselausgabe.
- Bereitstellen friert Inhalt, Vereins-/Mitgliedsname und Aussteller als Snapshot
  mit kanonischer SHA256 ein. Statuslabels aus material_receipt_statuses:
  Entwurf, Zur Bestaetigung, Bestaetigt, Storniert; gemeinsame Statusfarben.
- Mitglied oder berechtigter Elternzugang bestaetigen im Portal unter Material
  durch ausdrueckliche Checkbox. Account, Name, Selbst-/Elternvertretung und
  Zeitpunkt werden serverseitig erfasst. Keine Office-Bestaetigung fuer andere.
- Elternrechte nur bei genehmigter Verbindung und minderjaehrigem aktivem Kind.
  Rechteentzug, Volljaehrigkeit, Austritt und fremde Mitglieds-/Vereins-IDs
  sperren auch direkte Lese-/PDF-/Bestaetigungsaufrufe.
- Revision und Vereinssperre schuetzen parallele/veraltete Aktionen. Nach
  Bereitstellung kein Editieren; nach Bestaetigung auch kein Stornieren.
  Unbestaetigte Fehluebergaben koennen begruendet storniert werden.
- Geschuetzte PDF-Quittung mit Bestaetigung oder Hinweis auf ausstehende
  Bestaetigung/Storno. Hashpruefung, private/no-store, keine oeffentliche URL.
  PDF wird aus dem dauerhaften Snapshot erzeugt, ist kein neuer Upload und
  erzeugt kein Azure-Objekt pro Abruf. Keine qualifizierte oder gezeichnete Signatur.
- Kein E-Mail-/FCM-Versand, keine neuen Jobs und keine App-Endpunkte.

## Historischer Nachweis

Eine Quittung aus dem eigenen legacy_prod-Abbild uebernommen: Quelle1,
alte MitgliederID719, OriginalAnhangID63 und SigniertAnhangID64. Beide vorhandenen
privaten Azure-PDFs gelesen und gegen die urspruenglichen SHA256 geprueft.
Import in legacy_material_receipts getrennt vom neuen Workflow; Status
UNTERSCHRIEBEN und urspruenglicher Zeitpunkt bleiben historische Angaben.
Keine neue Signatur oder heutige Bestaetigung erfunden.

Verein > Historische Quittungen und Mitgliederdossier; im Portal nur fuer
aktuell selbst/als Eltern berechtigt vertretene Mitglieder. Nachweis-/Original-
Aufruf pruefen Mitgliedszuordnung, aktives Dokument und Hash erneut. Allgemeine
Dokumentfreigaben unveraendert. Keine Azure-Kopie, Dateiaenderung oder Loeschung.
Wiederholung: unveraendert statt Duplikat; geaenderte Quelle sperrt.

## Historische Trainingsplaene

Weitere18 Plaene privat/archiviert uebernommen (IDs75 bis92), insgesamt19.
Regel: bekannte Quell-Alterskategorie, Originalbesitzer und genau eine fuer ihn
berechtigte Mannschaft in der Saison des Erstellungsdatums (Wechsel01.07).
Keine unscharfe Namenssuche oder pauschale Zuordnung zum neuesten Team.
Originalhash vor Import erneut geprueft, schematische Diagramme und
Qualitaetssperren erhalten. Wiederholung erzeugt keine weiteren Plaene.

Elf Quellen brauchen weiterhin fachliche Zuordnung: 25,2,3,4,7,10,11,22,23,40,41.
Die18 offenen Qualitaetspruefungen bleiben auf Archiv/Queue verteilt bestehen.
Auch historische Bus-/Trainingszuordnungen ohne Beleg werden nicht geraten.

## Bexio

Benutzer meldet GS-00002 als erstellt. Lesetest15.10. fuer eigene Rechnung8440,
Bexio6: Total4025, bezahlt4025, Gutschrift0, Rest0 (Rappen). Gutschrift18 deshalb
nicht blind bestaetigt. Nach Verrechnung und ausreichendem Rueckfluss kann der
vorhandene Bestaetigungsprozess GS-00002 zuordnen. Keine Bankzahlung ausgeloest.

## Migrationsfolge

1. Additive Migrationen61/62 via clubunio:foundation nur in eigener
   db_acdf8d_dclubora: Quittungen, Statuslookup, Historie und Indizes.
2. Werkzeuge: tools/clubunio-material-history-check.php import-receipts,
   training-preview, training-import, credit-status. Keine Live-MSSQL-Schreibrechte.
3. Imports wiederholbar; Altdateien/Quelldaten unveraendert, keine Freigabe alter
   Plaene und keine neuen Vereinsgeschaeftsvorfaelle aus historischen Nachweisen.
4. Vor spaeterem Cutover Quellen erneut vergleichen. Heutige IDs/Zuordnungen
   nicht ungeprueft auf neue PROD-Abzuege anwenden. Kein Cutover beschlossen.
5. Release61 als Ruecksprung; additive Daten beim Code-Rollback erhalten.

## Abnahme

Rollen-/Workflow-/PDF-/Hash-/Revisionspruefungen und historische Dokumenttests
mit synthetischen Rollback-Fixtures; keine realen Empfangsbestaetigungen.
Live-Quittungimport hat vorhandene Azure-PDFs ausschliesslich gelesen.
Testzahlen und Sichtpruefung werden im Releaseabschluss ergaenzt.

Abgeschlossen: 53 Tests/496 Assertions in drei disjunkten Laeufen
(Material/Navigation/PMP15/203, Alttraining/Gutschriften18/103,
Ankuendigungen/Mitgliederaenderungen20/190; Live-Azure-Testupload ausgelassen).
Pint, Diff-/PMP-Check sowie Routen-/Viewcache erfolgreich.
Pi: 189 verschluesselte Settings,46 Vorlagen, Outbound aus; Quellhashes gleich.
Beide historischen Azure-PDFs auch auf Pi HTTP200 mit erwarteten Hashes.
Angemeldet Materialliste, Erfassungs-SlideOver und historische Quittung geprueft;
Maske ohne Speichern geschlossen, keine echten Bestaetigungen/Versandaktionen.
Manuelle Spieler-/Eltern-Browserabnahme bleibt moeglich; automatisierte
Portal-POST-/PDF-Tests sind nicht als solche manuelle Abnahme auszugeben.
