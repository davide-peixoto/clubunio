# ClubUnio: Beitragsberechnung und Rechnungsentwuerfe

17.10.2026. Nur ClubUnio-Web; API, SaaS, Betrieb und bestehendes PHPMaker
DEV/PROD unveraendert. Kein Bexio-Versand, keine Zahlungen oder Zustellung.

## Umsetzung

Die Stapelvorschau liest weiterhin maximal26 Kandidaten per Keyset, zeigt25
und behaelt den bestehenden Fortsetzungsschluessel. Fuer diese Kandidaten
nur IDs laden. Die frische Berechnungsgrundlage wird fuer maximal25 Mitglieder
gebuendelt geladen: aktive Saison/Mitglieder, Spielerzuordnungen, aktive
Regeln/Preise, aktive Familien und bestehende aktive Beitragspositionen.
Keine wiederholte Saison-/Preis-/Familienabfrage fuer jedes Mitglied.
Rechnungspositionen per vereinsgebundenem Join nach Mitglied gruppieren;
keine fremden oder inaktiven Rechnungen einbeziehen.

Einzel- und Stapelvorschau verwenden dieselbe reine Ergebnisberechnung.
Rundung in Rappen, Ehren-/Freimitgliedschaft, maximaler Familienrabatt,
Regel-/Familienmehrdeutigkeit, bestehende Beitragspositionen und abweichende
Spieler-Rechnungsverknuepfung behalten Reihenfolge und Fehlermeldungen.
Ergebnisfelder und SHA256-Fingerprint bleiben unveraendert. Fehler bleiben
mitgliedsbezogene Vorschauzeilen; keine automatische Entwurfserstellung.

Einzelvorschau und damit interne Entwurfserstellung lesen aktive Spieler-
Verknuepfungen einmal statt zweier exists-Abfragen. Keine preis-/rechte-
Cachefelder im Service: jeder create-Aufruf liest die aktuellen Grundlagen
erneut unter bestehenden Vereins-/Mitgliedslocks. Rollenpruefung auch in der
gebuendelten Vorschau fuer jedes Ergebnis frisch. Create weiterhin mit
contributions.create und invoices.create, erneut nach Lock geprueft.

Idempotenz,25er-Grenze, Pendent-/Entwurfsstatus, Spieler-Verknuepfung und
Audit unveraendert. Stapelverarbeitung behaelt bestehende Teilergebnis-
Semantik: fachlicher Fehler eines Mitglieds erscheint in dessen Ergebnis,
kein neuer globaler Rollback und kein automatischer Wiederholungsversand.
Keine Freigabe oder Bexio-Uebertragung aus diesen Services.

## Messung und Tests

CLI tools/clubunio-contribution-performance-check.php erzeugt nur synthetische
Daten in einem eigenen Verein auf exakt gepruefter ClubUnio-DB:25 Mitglieder,
ein Tarif, eine Saison, aktive Spielerzuordnungen. Misst Stapelvorschau und
einen internen Rechnungsentwurf, anschliessend alles Rollback. PG-Sequenzen
koennen Luecken erhalten. Ausgehende HTTP-Aufrufe gesperrt, Mail gefaked;
kein vorhandenes Mitglied oder bestehende Rechnung geaendert. Ausgabe nur
aggregierte Zeiten/Queryzahlen, keine IDs, Namen oder Fingerprints.

Pi vorher:25er-Vorschau5941ms/228 Queries/5812ms DB. Einzelner interner
Entwurf685ms/25 Queries/646ms DB. Stichproben, kein Lasttest oder SLA.

Pi nach Delta:25er-Vorschau991ms/36 Queries/947ms DB. Einzelentwurf649ms/
24 Queries/608ms DB. Deutliche Reduktion in der Stapelvorschau; interne
Entwurfserstellung bewusst weiterhin frisch geprueft statt Schreibrechte
oder Finanzgrundlagen zu cachen. Beide Szenarien vollstaendig zurueckgerollt.

ClubUnioTest erweitert: Einzel-/Stapelvergleich inklusive Fingerprints,
Rundung, Befreiung, gedeckeltem Rabatt, Fehlerzeilen und bestehender Rechnung;
gemeinsame Datentabellen nur einmal lesen; Preiswechsel und Rollenentzug
vor weiterer Verarbeitung abweisen. Bestehende Tests fuer25er-Keyset,
Entwurfs-Idempotenz, doppelte Regeln/Familien, Audits und Webbestaetigung.
Finanz-Profiltests fuer Nur-Lesen, Entzug und getrennte Rechte mitpruefen.

Ergebnisse:8 fachliche Tests/64 Assertions erfolgreich; nach ID-Projektion
Keyset/Erstellung plus neuer Fremdmitglied-/26er-Grenztest erfolgreich (3/23).
Vier Finanz-Profiltests/54 Assertions erfolgreich. Insgesamt13 verschiedene
Tests in getrennten Laeufen. Pint, Syntax, View-/Routen-/Tenant- und
Profil-Aktivierungschecks erfolgreich. Release20261017-clubunio-contribution-
performance aktiv, Rollbackbasis20261017-clubunio-season-bulk-performance.
Angemeldete Chrome-Pruefung auf ClubUnio: Saisonlauf2026/2027 zeigt25 Zeilen
mit Betraegen, Befreiungen und bereits vorhandenen Beitraegen. Fortsetzung
laedt25 weitere Mitglieder ohne Ueberschneidung am Seitenuebergang. Keine
Konsolenfehler. Im Web weder Entwuerfe erstellt noch Bexio-/Zahlaktionen
ausgefuehrt. Interne Erstellung ausschliesslich mit Rollback-Fixtures getestet.

## Migration und Rollback

Keine Schema-/Indexmigration, Parameter oder Datenimporte. Zwei Fachservices
gemeinsam ausrollen; bestehende Vorschaufingerprints behalten Bedeutung,
frische Berechnung beim Schreiben bleibt erforderlich. Historische
Rechnungs-/Positions-/Spieler-IDs und Audit unveraendert lassen.
Ruecknahme per vorigem Release-Symlink ohne Datenbankrollback. Keine Secrets,
.env, Uploads, vendor oder Laufzeitdaten committen.
