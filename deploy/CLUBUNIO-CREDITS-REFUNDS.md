# ClubUnio: Gutschriften und Rueckerstattungen

Nachtrag19.10.2026: Korrektur18 ist inzwischen ueber GS-00005/RE-00013
bestaetigt. Historische Offen-Vermerke unten sind ueberholt. Aktuelle
Abnahme und naechster Schritt: CLUBUNIO-FINANCE-ACCEPTANCE.md.

Nachtrag67: Mehrrechnungsverrechnung aus Originalkorrekturen und getrenntes
Kundenguthaben-Journal siehe CLUBUNIO-CUSTOMER-CREDITS.md. Historische Grenzen
unten gelten fuer Release55; manuelle Bexio-Teilkorrektur RE-00006 bleibt offen.

Nachtrag63: RE-00009/GS-00004 vollstaendig mit CHF10.05 ohne Geldzahlung
verrechnet, lokal107 bestaetigt. Nummer/Voucher/Betrag/Eintrag/Rechnung werden
jetzt einzeln geprueft. CLUBUNIO-CREDIT-ALLOCATION.md beschreibt den aktuellen
Nachweis. Alter Test18/GS-00002 wurde nicht umgehaengt; eigenstaendiges Guthaben
und Verteilung auf mehrere Rechnungen sind dadurch nicht als geloest zu bewerten.

14.10.2026, Fachpaket55. Nur ClubUnio und eigene PostgreSQL-DB.
PHPMaker DEV/PROD, App-API, SaaS und Betriebsentwicklung unveraendert.

## Webablauf

Rechnungsdossier > Gutschrift erfassen. Nur eigene freigegebene oder stornierte
Rechnungen, keine ungeprueften importierten Altbelege. Verfuegbare Positionen
und Betraege werden vorbelegt; fuer Teilgutschriften Betraege reduzieren oder
Positionen entfernen. Begruendung ist Pflicht. Entwurf reserviert Betragsraum.
Interne Freigabe friert den Beleg ein. Kein automatischer Bexio-POST.

Finanzen > Gutschriften: Liste mit Suche, Statusfilter und persoenlichen
Standardansichten, Dossier, Originalrechnung und unveraenderlichen Positionen.
Storno nur vor externer Bestaetigung; Historie bleibt erhalten, Betragsraum frei.
Statusbezeichnungen/Aktivierung unter Stammdaten > Finanzkorrekturstatus.
Codes sind technische Workflowgrenzen, nicht beliebig neue Uebergaenge.

Bexio-Zuordnung bestaetigen: nur bestehende Sandbox-Adminberechtigung,
Gutschriftsreferenz und ausdrueckliche manuelle Zuordnungsbestaetigung.
Frisch gelesene Sandbox-/Beleg-/Waehrungs-/Steuer-/Gutschriftbetraege erforderlich.
Bereits zugeordnete Betraege und Referenzen werden nicht doppelt verwendet.
Keine Behauptung, dass das API einzelne Gutschriften identifiziert: die
Referenzzuordnung ist MANUELL, der Gesamtbetrag aus Bexio technisch bestaetigt.

Rückerstattung vormerken aus Rechnung/Gutschrift nur mit frisch bestaetigtem
Zahlungslisten-Guthaben. Gutschrift ohne Zahlungseingang erlaubt keine Auszahlung.
Bestaetigte Gutschrift muss zur gleichen Rechnung gehoeren. Vormerkungen und
bestaetigte Nachweise verbrauchen den Raum; Storno eines Entwurfs gibt ihn frei.
Finanzen > Rueckerstattungen: eigener Statusfilter, Ansichten und Dossier.
Manuellen Nachweis erfassen: Datum, eindeutige Referenz und explizite
Bestaetigung. Rueckwirkende Nachweise erlaubt, Zukunftsdatum verboten.
Bestaetigte Nachweise bleiben unveraenderlich; kein Loeschen oder zweiter Nachweis.

## Finanzlogik

Originalpositionen, Rabatt und Steuersnapshots bleiben unveraendert.
Originale CHF-Rundung wird proportional und deterministisch auf Positionen
verteilt. Korrekturen werden NICHT erneut auf5Rappen gerundet. Summe mehrerer
Teilkorrekturen darf weder Originalbetrag noch enthaltene Originalsteuer
uebersteigen. Letzte Teilkorrektur uebernimmt den verbleibenden Steuerrest.
Keine Neuermittlung mit spaeter veraenderten Katalogsteuern.

Unbestaetigte ClubUnio-Gutschriften werden NICHT vom Bexio-Rest abgezogen.
Guthaben ist kein negativer offener Rechnungsbetrag. Vorzeichenbehafteter
Providerrest wird bei konsistenter Gleichung in Rest0/Guthaben ueberfuehrt.
Die Bexio-Rechnung begrenzt ihre received-payments live auf den Rechnungsbetrag.
Deshalb separate vollstaendige Zahlungsliste: feste Rechnung, eindeutige IDs,
keine Kreditbelege/Kontoeinloesungen/Skonto als neue Bareingaenge zaehlen.
Zahlungslisten-Guthaben = max(0,Eingaenge+Gutschriften-Rechnungsbetrag),
hoechstens tatsaechliche Bareingaenge. Keine freie URL oder Bankdaten im Formular.

Dossier: Providerrest/Guthaben, Zahlungsliste, offene lokale Gutschriften,
vorgesehene/manuell bestaetigte Rueckerstattungen und noch verfuegbarer Betrag.
Dashboard: offene Korrekturen und verfuegbares Guthaben nach Reservierungen.
Offene Rechnungslisten/CSV behalten den bestaetigten Providerrest; keine
scheinbare Minderung durch einen internen Entwurf oder simulierten Nachweis.
Ein lokaler Nachweis aendert die Bexio-Zahlungsliste nicht.
Neue Korrekturmodelle schreiben Zeitstempel mit explizitem UTC-Offset.
Hosting-DB ist America/Los_Angeles; naive UTC-Werte waeren sieben Stunden
verschoben. Nur synthetische Belege17/18/7 einmalig auditiert korrigiert.
Keine globale Umdeutung historischer ClubUnio- oder PHPMaker-Zeitstempel.

## Grenze der Bexio-API

Gepruefter oeffentlicher Vertrag: https://docs.bexio.com/.
Kein Anlage-Endpunkt fuer eigenstaendige Gutschriften; credit-voucher-PDF-Pfad
im eingebetteten Vertrag ohne beschriebenes Operationsschema. Kein geratener
POST, keine negative Rechnung, keine negative Zahlung als Ersatzgutschrift.
Die Gutschriftanlage/-steuerkorrektur erfolgt vorerst manuell in Bexio.
Unverknuepfte freie Kundengutschriften und echte Auszahlung sind nicht enthalten.
Keine Bankuebermittlung, Banking-Payment oder Zahlungsdatei, keine Emails/Push.
Rueckerstattungsnachweis in diesem Test ausdrücklich synthetisch, nicht bezahlt.

## Live-Abnahme SVS Api Test

- Gutschrift17 zu Rechnung7950/Bexio4: CHF33, Status bestaetigt; vorhandener
  echter Storno-Gutschriftrueckfluss ohne neue externe Buchung zugeordnet.
- Gutschrift18 zu Rechnung8440/Bexio6: CHF10 inkl. Originalsteuer0.748933,
  lokal freigegeben. Neue manuelle Teilgutschriftanlage in Bexio noch ausstehend.
- Rechnung8519/Bexio7: freigegeben, EingangCHF10 (ID12), zusaetzlicher
  EingangCHF5 (ID13) angenommen. Rechnungsbetrag10, begrenzter paid10/Rest0,
  Zahlungsliste15, Guthaben5. Unklarer Erstabgleich nur lesend geklaert;
  KEIN Wiederhol-POST. Beide Finanzaktionen accepted.
- Rueckerstattung7 zu dieser Rechnung: CHF5, synthetischer manueller Nachweis
  SANDBOX-SIMULATED-REFUND-INVOICE-7. Keine echte Rueckzahlung. Wiederholung
  erzeugt keinen zweiten Beleg; lokal verfuegbar0, Bexio-Zahlungsliste bleibt15.

## Migration und Schutz

000052: Lookup, credit_notes/credit_note_lines/refunds, Guthabenfeld/Gleichung.
000053: feste Sandbox-Testoperation payment_overpaid, einmaligCHF5 mit
bestehender Aktionssperre und ausschliesslich Registry-/Admin-/Sandboxschutz.
000054: getrennte cash_received_cents/cash_excess_cents im Bexio-Finanzsnapshot.
Alle drei nur db_acdf8d_dclubora / Schema clubora. Keine Bestandsbetraege,
Dateien, PHPMaker-Tabellen, ini-Parameter oder Jobs veraendert.
Rollen bei jeder Mutation frisch, Tenantlock, Versionspruefung, UUID/Hash fuer
Doppelanlagen, begrenzte Summen und datensparsames Audit. Technische Fehler
mit Referenz und IDs protokolliert, keine Tokens/Providerantworten im Log.

Vor Rollback auf54 offene Entwuerfe/Nachweise klaeren; alte Version kennt
Korrekturen und Cashfelder nicht. Migrationen behalten, kein destruktives down.
CLI tools/clubunio-credit-refund-check.php status|credit|tax-credit|
reconcile-cash|refund|overpay|repair-fixture-times. Nur registrierte synthetische Fixturebelege.
Tests und Deploymentnachweis: PI.md.
Regression62Tests/440Assertions erfolgreich; Nachlauf15/77 fuer aktuelle
Korrekturmodelle, Webaktionen, Statusschutz, Revisions-/Doppelanlagenschutz,
Teil-/Vollsteuer, Cash-/Gutschriftgrenzen und Zeitoffsets (ueberlappend).
Gecachte Routen und bestehende Tenantnavigation erhalten; Listen/Dossiers
angemeldet auf Pi sowie Dialog Desktop/Mobil visuell geprueft.
