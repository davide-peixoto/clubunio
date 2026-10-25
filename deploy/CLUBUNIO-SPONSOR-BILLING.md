# Sponsorenlauf: Abrechnung im Web

Stand: 25.10.2026. Eigenes ClubUnio-Modul, kein PHPMaker- oder App-API-Delta.

## Ablauf

Unter Sport / Sponsorenlauf einen Anlass oeffnen, Runden endgueltig freigeben
und Abrechnung oeffnen. Jede Zusage wird einzeln vorbereitet. Fixbetrag oder
Rundenbetrag mit Obergrenze wird in Rappen berechnet; Nullbetraege bleiben ohne
Rechnung. Empfaengeradresse, Runden, Quelle und Konfiguration werden eingefroren.
Mehrfaches Vorbereiten derselben Zusage liefert denselben Beleg.

Admin ordnet unter Buchhaltung die aktuell aus Bexio geladenen Katalogwerte zu.
Keine erfundenen Steuer-/Kontonummern. CHF ist Voraussetzung. Externe Sponsoren
werden als Bexio-Kontakt angelegt oder nach exaktem Adressabgleich zugeordnet,
nicht als Mitglied. Danach Entwurf erstellen, ausstellen, optional versenden.
Dokumentierter Providervertrag: https://docs.bexio.com/.

Jeder Schreibaufruf prueft die feste Bexio-Identitaet SVS Api Test / ID1 und
frische Admin-/Modulrechte. Der Versand ist bis auf weiteres ausschliesslich an
admin@svsissach.ch umgeleitet. Keine Bankzahlung, kein produktives Bexio-Ziel.
Geschaeftsstelle darf mit passenden Modulrechten vorbereiten, nicht live schreiben.

## Wiederaufnahme und Nachweis

Durable Ausfuehrungsabsicht vor Netzwerkzugriff, Revisionspruefung und Lease.
Netzwerkaufrufe liegen ausserhalb der SQL-Transaktion. Unklare Kontakt-/Beleg-
Erstellung sperrt weitere Neuerstellung. Synchronisation sucht ausschliesslich
die exakte Referenz; bei Wiederholung eines Pruefauftrags wird niemals erneut
geschrieben. Unklarer Mailversand bleibt unklar: Offen ist kein Versandnachweis.
Eine manuelle Versandbestaetigung erfordert eine protokollierte Begruendung.
Audits enthalten keine Klartext-Adressen, Providerantworten oder Tokens.

Zahlungs-/Gutschriftbetraege werden nur aus frisch gelesenen Bexio-Belegen
uebernommen. Belegreferenz, Kontakt und Total muessen zum Snapshot passen.
Bei Zahlungen/Gutschriften wird ein einfaches Storno abgewiesen. PDF-Zugriff
bleibt bei inaktivierter Versandkonfiguration moeglich, weiterhin mit Download-
Recht, Vereins-/Verbindungsbindung und Belegpruefung.

## Migration und Grenzen

Nach Migration74 Migration75 gezielt auf ClubUnio ausfuehren. Neue Tabellen:
sponsor_run_invoices und sponsor_run_billing_audit, zusammengesetzte Vereins-FKs.
Kein Import und keine Aktualisierung von Legacy-Daten. Bei Code-Ruecksprung die
Tabellen samt Referenzen, verschluesselten Snapshots und Journal beibehalten.

Sammelzusagen sind nachvollziehbar dem bestaetigenden Sammler zugeordnet; eine
einzige zusammengefasste Rechnung ueber mehrere Zusagen ist nicht implementiert.
Keine automatische Zahlungsbuchung, Gutschrift oder Rueckerstattung in diesem
Modul. Bestehende Finanzprozesse bleiben getrennt. Live-Abnahme des neuen
Sponsorenadapters erfordert eine bewusst freigegebene synthetische Testrechnung;
automatisierte Tests nutzen einen isolierten Provider und SQL-Rollback.

## Technische Abnahme

Provider- und SQL-Rollback-Tests pruefen vollstaendige Erstellen/Freigeben/
Testversand/Status-Folge, Empfaengerabgleich, Konfigurationswechsel samt weiter
lesbarem PDF, abgelaufene Schreib-Leases und echte Unterbrechungen vor dem
lokalen Ergebnis. Bei abgebrochenem Versand bleibt Offen kein Zustellnachweis.
Unbekannte Erstellungen bleiben auch nach erfolglosem Abgleich gesperrt.
Eindeutige Provider-Ablehnung erhaelt dagegen den korrigierbaren Ausgangsstatus.
Lease- und Versandzeiten enthalten einen expliziten Zeitzonenoffset.

Die bestehende SyncWork-Navigationspruefung erwartete noch eine ausgeblendete
Versandpruefung, obwohl diese bereits im vorherigen Commit sichtbar war.
Nur die veraltete Testannahme wurde an den bestehenden Vertrag angepasst;
zusaetzlich bleibt die neue Abrechnungs-Hilfsseite ohne eigenen Menueeintrag.
Keine Aenderung von Bestandsnavigation oder Berechtigungen zu diesem Zweck.

Final: Billing10 Tests/60 Assertions und Provider4 Tests/13 Assertions bestanden.
Zusammen mit Dokumentenserien18/146 und Sponsorenlauf18/171 sind das50 neue
Tests/390 Assertions, ohne echte Provider-Schreibaufrufe oder Zustellungen.
Bestehende Profil-/Modul-/Queue-Regression gezielt mitgeprueft. Angemeldete
Browserabnahme und ein echter neuer Azure-/Bexio-End-to-End-Durchlauf sind separat
offen; die automatisierten Provider-Doubles ersetzen diese Abnahme nicht.

Nachtrag25.10.2026: echter Azure-/Bexio-Durchlauf inzwischen erfolgreich mit
synthetischen Daten, einschliesslich RE-00017 und Testversand an Admin.
Laenderzuordnung auf reales Feld iso_3166_alpha2 korrigiert. Frueheren offenen
Live-Stand ersetzt CLUBUNIO-SPONSOR-DOCUMENT-ACCEPTANCE.md; manuelle angemeldete
Browserabnahme bleibt separat offen. Keine Bankzahlung oder Produktivfreigabe.
