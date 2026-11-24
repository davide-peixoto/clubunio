# Bexio-Sandbox: Wiederabnahme24.11.2026

## Ziel und Umgebung

Nach Verlaengerung des Testkontos laut Benutzer bis12.02.2027 die bestehenden
Nachweise frisch pruefen, statt bereits erledigte Tests erneut zu buchen.
Angemeldeter Bexio-Browser bestaetigt Firma SVS Api Test und80 verbleibende Tage.
Der API-Client bestaetigt exakt Profil1/Name SVS Api Test vor weiteren Abfragen.

ClubUnio DEV: clubunio_dev auf PostgreSQL, Schema clubunio, Mandant4 sv-sissach.
Aktive Pi-Basis20261124-clubunio-daily-v1. SSH direkt von Windows, jeweils als
endlicher Einzelaufruf ohne persistente SSH-Sitzung. Keine Pi-/VPS-Konfiguration
geaendert, keine Stage-/PROD-Abfrage oder Aenderung und kein neuer Datenabzug.

## Frische API- und lokale Fachnachweise

| Bexio | ClubUnio | Betrag CHF | Gutschrift CHF | Geldzahlung CHF | Rest CHF |
| --- | --- | --- | --- | --- | --- |
| RE-00006 | 8440 | 40.25 | 0.00 | 40.25 | 0.00 |
| RE-00009 / GS-00004 | 8546 | 10.05 | 10.05 | 0.00 | 0.00 |
| RE-00013 / GS-00005 | 9774 | 20.00 | 10.00 | 0.00 | 10.00 |
| RE-00014 | 10451 | 30.00 | 0.00 | 0.00 | 30.00 |
| RE-00015 / GS-00007 | 10452 | 20.00 | 10.00 | 0.00 | 10.00 |
| RE-00016 / GS-00007 | 10453 | 25.00 | 20.00 | 0.00 | 5.00 |

Zuordnungen unveraendert: GS-00004/Payment20, GS-00005/Payment28,
GS-00007/Payment36 und37. GS-00004 ist eine groessere Gutschrift; die Tabelle
belegt nur den auf RE-00009 verrechneten Teil, nicht deren Gesamtbetrag.

Lokale Guthaben anhand GS-Referenz statt alter Mandanten-ID ermittelt:
- GS-00005: CHF10, eine Zuordnung, Rest0, Originalsteuer0.748933, confirmed.
- GS-00007: CHF30, zwei Zuordnungen, Rest0, Originalsteuer2.247919, confirmed.

Browser bestaetigt GS-00005 mit CHF10 inklusive8.1%/CHF0.75 MWST und abgerechnet10.
GS-00007: CHF30 inklusive8.1%/CHF2.25 MWST, abgerechnet30, Rest0.
RE-00015 zeigt GS-00007/CHF10 und ausdruecklich keine Geldzahlung.
Keine neue Gutschrift, Verrechnung, Stornierung oder Testzahlung angelegt.

Spesen ueber bestehende identitaetsgepruefte Sandbox-Fachservices gelesen:
-1083: DRAFT, CHF15, Rest15, lokal O. Absichtlich unveraenderter Testentwurf.
-1114: PAID, CHF20, Rest0, lokal B. Bereits abgenommener manueller Sandbox-Zahlfall.
Kein echter Bankauftrag und keine erneute Zahlung. Der Nachtest bestaetigt den
vorhandenen Rueckfluss, nicht einen neuen Lauf eines automatischen Statusjobs.

## Dokumente und Web

InvoicePdfService unter echtem DEV-Webbenutzer fuer RE-00006 undRE-00013:
HTTP200, PDF-Signatur geprueft,109813 bzw108405 Bytes. Kein Klartext-PDF im Git.
Rechnung10452 auf der angemeldeten DEV-Vereinsdomain geoeffnet: Betrag20,
Gutschrift10, Rest10, Steuer1.50 und Position korrekt. PDF-Vorschau rendert
RE-00015 in der rechten Vorschau mit zwei Seiten und Zoom-/Anpassungswerkzeugen.
Dialog danach geschlossen; keine Formularwerte gespeichert.

Zusaetzlich lokal: sechs Steuer-/Zahlungsarithmetiktests mit31 Assertions
erfolgreich; DE/EN-Kataloge mit3075 Schluesseln und PHP-Platzhaltern geprueft.
PHP-Syntax des neuen CLI-Pruefers fehlerfrei. Die historischen Datenbanktests
mit alter DB-Bindung wurden nicht als aktuelle Vollregression ausgegeben.

## Pruefwerkzeug und Migrationsplan

tools/clubunio-bexio-reacceptance-read.php ist CLI-only und ausschliesslich fuer
clubunio_dev/sv-sissach freigegeben. Aktueller Vereinsadmin und bestehende
Sandbox-/Dispatch-/Empfaengerpruefungen bleiben zwingend. Native Testrechnungen
werden ueber akzeptierte Dispatches und Remote-ID aufgeloest, legacy_id muss leer
sein. Keine Uebernahme von gleichlautenden importierten Bexio-IDs.

Ausfuehrung ausserhalb des Webroots im shared-Ordner mit CLUBUNIO_BASE auf current
als www-data. Kein Runtime-Releasewechsel und keine Schemamigration erforderlich.
Das Werkzeug hat keinen Schreib-/Apply-Modus. Nur bestehende OAuth-Tokenrotation
und normale Laufzeitcaches koennen bei den Services anfallen. Fingerprints aller
Vereinsrechnungen/-positionen, Spesen/-positionen, Korrekturen/-positionen,
Kundenguthaben, Zuordnungen, Rueckerstattungen und Dispatches vorher/nachher gleich.
Keine Fachdaten geaendert und keine offene Vorschau als Ersatz angewandt.

Alte Werkzeuge mit Guard db_acdf8d_dclubora werden nicht durch Abschwaechung ihrer
Guards erneut gestartet. Migrationen/Refreshes muessen synthetische Testbelege und
Sandbox-Bindungen weiterhin von produktiven Importbelegen trennen; diese Abnahme
ist keine Freigabe zum Kopieren von Sandbox-IDs in PROD oder andere Mandanten.

## Grenzen

Dieser Lauf bestaetigt die frischen Lesepfade und vorhandenen Finanznachweise.
Kein neuer Webversand, neuer Dateiupload, Storno, Zahlungs- oder Schedulerlauf.
Bank-/QR-Verarbeitung bei einem echten Geldinstitut, produktive Zustellung und
Provideraktionen fuer unbekannte Belege sind damit nicht abgenommen.
Gutschriftanlage weiter ueber den bestehenden manuellen Bexio-Webweg, kein
erfundener API-Endpunkt. SaaS-/Anbieterwechsel und produktive Buchungen unberuehrt.
