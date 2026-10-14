# Finanz-Stammdaten, Entwurfspositionen und Exporte

Stand 12.10.2026. Nur ClubUnio, keine API, keine Betriebsumstellung.

Release28 auf ClubUnio Pi bereitgestellt. Gesamtregression127 Tests/1248 Assertions,
abschliessende Web-Aktionen4 Tests/62 Assertions erfolgreich. HTTP/Assets und
Anmeldeschutz geprueft. Status-Editordialog lokal visuell kontrolliert.

## Fachumfang

- Stammdaten > Bexio-Statuszuordnungen: Bexio-Statuscode, Bezeichnung,
  Positionsstatus als Lookup und Aktivierung. Codes bestehender Werte unveraenderlich.
  Ziel muss aktiver Positionsstatus desselben Vereins sein. Aenderungen werden
  protokolliert und mit Statusuebernahmen ueber denselben Mandantenlock serialisiert.
- Stammdaten > Rechnungspositionsstatus: Bezeichnung, neue Codes, Aktivierung.
  Keine Loeschaktion. Ein aktiv referenzierter Mapping-Zielstatus darf nicht
  deaktiviert werden. Unbekannte technische Codes erweitern keine Workflowregeln.
- Eigene Rechnungsentwuerfe: manuelle Position mit Begruendung entfernen.
  Soft-Archivierung, Revisionsschutz und Audit mit vorherigem Betrag; kein
  physisches Loeschen. Beitrags-/Spielerzuordnungen, importierte und freigegebene
  Rechnungen bleiben geschuetzt. Archivierte Positionen im Aktivfilter einsehbar.
- Offene Rechnungen / Spesen zur Bezahlung: CSV der gefilterten und sortierten
  Liste, inklusive Suchbegriff. Nicht nur die aktuelle Seite, maximal5000 Zeilen.
  Zu grosse Auswahl wird abgewiesen. UTF-8/BOM, Semikolon, Schutz vor Formeln
  aus Mitgliedstexten. IBAN ausschliesslich maskiert, keine Zahlungsdatei.
  Auswertung wie bestehende Listen, bei offenen Positionen historischer
  Null-Netto/Brutto-Fallback unveraendert. Exportvorbereitung auditierbar als
  finance_exports, nur Art/Anzahl, ohne Klartextdaten oder Tokens im Protokoll.

## Migration und Grenzen

Keine neue Tabelle, keine Migration und keine Bestandsaenderungen erforderlich.
Vorlagen, Tokens, Bexio-Objekte und PHPMaker DEV/PROD bleiben unberuehrt.
Aktuelle Mappingwerte werden nicht automatisch korrigiert; fachlich pruefen.
Status-/Positionsaenderungen bleiben in der separaten ClubUnio-Datenbank.
Rueckkehr zum vorherigen Code moeglich; bereits archivierte Positionen bleiben
archiviert, auditiert und aus aktiven Summen ausgeschlossen.

Weiter offen: echte Bexio-Uebermittlung/Stornierung, automatischer Abgleich,
QR-/Empfaenger-Fachabnahme, breitere Fachlisten/Exporte, Signage- und Busausbau.
Die generelle Entwicklungsfreigabe ersetzt keine Freigabe externen Schreibens,
realer Nachrichten, API-/SaaS-Ausbau oder produktiven Cutovers.
