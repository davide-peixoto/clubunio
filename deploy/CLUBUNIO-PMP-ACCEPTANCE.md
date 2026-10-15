# Fachabgleich58: PMP und ClubUnio

Stand15.10.2026. Technischer Abgleich und gezielte Fehlerbehebung, keine pauschale
fachliche Endabnahme und kein Cutover. API, SaaS, Betrieb und PHPMaker DEV/PROD
bleiben unveraendert. Operative PMP wird ausschliesslich gelesen.

## Nachweise

- [Objekt-/Beziehungsmatrix](CLUBUNIO-PMP-COVERAGE.md): alle104 Objekte zugeordnet.
- pmp-coverage.json: alle814 Felddefinitionen,31 Beziehungen,218 Eventreferenzen;
  Pflicht-/Lookup-/Readonly-/Elternattribute mit expliziter Allowlist.
- Eventtexte/SQL/Defaults/Verbindungen/Parameterwerte werden nicht exportiert.
  Hashes sind Aenderungsindikatoren, kein Beweis semantischer Gleichheit.
- `php tools/export-pmp-coverage.php --check` erkennt neue/entfernte Objekte und
  veraltete Exporte. Nach neuer Generierung lokal neu pruefen/exportieren.
- pmp-coverage-map.php ist die bewertete Zuordnung, kein automatischer Namensvergleich.
  Ein Eintrag Web bedeutet vorhandener Fachweg, nicht vollstaendige Feldgleichheit.

## In diesem Durchgang geschlossen

| PMP-Befund | Umsetzung | Schutz/Abnahme |
|---|---|---|
| Funktionen/StaffQualifikationen nur importiert/lesbar | Stammmasken Vereinsfunktionen/Qualifikationen | Office/Admin, aktive Lookupwerte, Revision, Audit |
| Funktionaere.QualiID Mehrfachauswahl | Dossier-Funktionspflege mit normalisierter Mehrfachzuordnung | fremde/inaktive neue Werte abgewiesen, Bestand bleibt lesbar |
| Schluesselliste Pflichtfelder | Hinzufuegen/Bearbeiten/Deaktivieren im Mitgliederdossier | Nummer/Bezeichnung/Farbe erforderlich, keine echte Hardwareausgabe |
| SPONSOR Schreibpflege | Sponsoring im Dossier | Betrag/Laufzeit/Name, Version, kein Buchungs-/Zahlungsauftrag |
| Events/Gaeste Master/Detail | Veranstaltungen mit Saison/Team und Gaesteliste; Mitgliederdossier | Elternbindung serverseitig, keine Doppelzuordnung, deaktivierbar |
| Saison -> Beitragstabelle nicht im Detail sichtbar | Beitragsregeln im Saisondossier mit gefiltertem Pflegelink | vorhandene Beitragslogik wiederverwendet |
| Bankkonten trotz vorhandener Schreibaktionen als readonly deklariert | explizit schreibbarer RelationManager | bestehender BankAccountService/IBAN-Schutz unveraendert |
| Lookup-Export verwendete falsche Display2-Attributnamen | FldTagLnkDisp2/3/4 ergaenzt | Vor-/Nachname und weitere Lookupteile gehen nicht verloren |

Migration58 fuegt nur Revisionsfelder auf sieben vorhandenen ClubUnio-Tabellen
hinzu. Keine Daten-Neuzuordnung, kein Neuimport oder Loeschen. Alle neuen
Schreibwege sind auf ihren Objektkatalog begrenzt, pruefen Benutzer erneut,
sperren den Vereins-/Elterndatensatz und lehnen alte Revisionen/fremde IDs ab.
Bestehende nicht passende Altwerte werden nicht still bereinigt. Archivierte
Mitglieder bleiben lesbar, neue Dossier-Schreibwege sind gesperrt.

## Bewusste Unterschiede zum PMP

- Mitglieder-Namen sind bei Firmen nicht pauschal Pflicht. Postleitzahlen und
  Passnummern bleiben als Zeichenketten erhalten. Technische IDs/Finanzmerkmale
  sind keine frei schreibbaren Felder. Historische unvollstaendige Datensaetze
  duerfen nicht durch eine blinde Kopie saemtlicher PMP-Pflichtflags unbedienbar werden.
- Alte hart codierte Status-/Rollenwerte wurden durch fachliche Kennzeichen und
  explizite Objektberechtigungen ersetzt. Keine alten Bitmasken zurueckkopieren.
- Lookup-Paare wie BankkontoStaff -> Institut oder Events -> Teams sind Referenzen,
  keine Aufforderung zu inverser Stammdaten-Schreibpflege im Detail.
- Historische Datensaetze werden deaktiviert, nicht entsprechend alten
  HardDelete-Flags unwiederbringlich geloescht. Keine Fremdschluessel umhaengen.
- Fachlisten ersetzen SQL-Views durch gesicherte Abfragen; Spalten/Exporte
  brauchen Benutzerabnahme, auch wenn eine funktionsgleiche Liste vorhanden ist.

## Noch offen, nicht als fertig bewerten

Nachtrag Release59: Punkte1 und3 sind inzwischen als eigene Web-Fachwege
umgesetzt (CLUBUNIO-MEMBER-CHANGES-DOCUMENTS.md). Die folgende Liste dokumentiert
den Befund von Release58; manuelle Fachabnahme/Altantragsmigration bleibt separat.

1. Eigene Web-Pruefwarteschlange fuer MitgliederAenderungsantraege samt
   Antrag/Entscheidung/Konfliktbehandlung. Stammdaten-Direktbearbeitung ist kein Ersatz.
2. Redaktionelle manuelle Rundnachrichten/Ankuendigungen. Bearbeitbare Vorlagen
   und interne Workflow-Nachrichten sind vorhanden, aber kein vollstaendiger Editor.
3. Allgemeine neue Mitglieder-Dokumentuploads ausserhalb einer Anmeldung und
   deren Pflege. Anmeldung/Annahme/Azure-Lesen sind kein Ersatz fuer jede Dokumentart.
4. MitgliederQuittungen: PMP ohne Feld-/Generierungsvertrag. Nutzung und
   Fachregeln klaeren, keine imaginierte Quittungs-/Unterschriftslogik bauen.
5. Vorher bekannte Fachtests: manueller Bexio-Teilgutschrifttest, echte Bus-Keybox,
   historische Bus-/Trainingszuordnungen, KI-Qualitaetsfreigaben und Vereinskonflikte.
6. Benutzerabnahme kritischer Feld-/Listenabweichungen und aller Rollen. Die
   gesamte Semantik der218 Legacy-Hooks ist nicht durch einen Metadatenexport bewiesen.

Diese Punkte sind priorisierte Folgearbeit und keine versteckten API-Aufgaben.
Naechster sinnvoller Webblock: Mitglieder-Aenderungsantraege und allgemeine
Dokumentpflege, danach redaktionelle Ankuendigungen. Externe Zustellung bleibt aus.

## Deployment und Ruecksprung

ClubUnio-Pi: 20261015-clubunio-58-pmp-parity, Ruecksprung57 erhalten.
Eigene additive Migration58. Private Einstellungen, APP_KEY und vorhandene
Dateien bleiben unveraendert. Kein automatisches Down/Loeschen beim Ruecksprung.
PMP und nicht beteiligte lokale Aenderungen werden nicht mitdeployt.

## Testnachweis

30 Tests/359 Assertions in zwei gezielten Laeufen erfolgreich, davon15/203 fuer
neue Dossierpflege, Geheimnisfilter/DTD-Abwehr und Navigation,15/156 Regression
fuer bestehende Dossiers, Schluesselliste, Mitglieder, Beitraege und Teamzuordnung.
Routen-/View-Cache erstellt; Pi-Release58 aktiv,189 verschluesselte Einstellungen
und42 Vorlagen verifiziert. Outbound bleibt aus. Keine realen Stammdaten oder
Zuordnungen fuer Tests veraendert. Angemeldete Sichtpruefung steht nach neuer
Anmeldung noch aus; kein erfolgreicher manueller Fachtest wird vorgetaeuscht.
