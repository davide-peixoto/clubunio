# Allgemeine Parameter: Lookups und Verwendung (03.11.2026)

SaisonID besitzt eine durchsuchbare Auswahl aktiver vereinseigener Saisons.
Anzeige mit Saisonbezeichnung; Speicherung weiterhin legacy_id, bei neuen
Saisons ohne Alt-ID interne ID. Kollisionen mit vorhandenen Alt-IDs werden
nicht angeboten. Keine Umstellung des App-Saisonvertrags. Serverseitige
Validierung innerhalb der vorhandenen Schreibtransaktion und bestehendes Audit.

SommerWinter und StatusPlatz1/2/3 sind importierte Altwerte. Labels stammen
aus training_options/category=period bzw. facility_statuses, ueber deren Codes.
Keine Bearbeitung dieser wirkungslosen Altparameter: aktuelle Platzverfuegbarkeit
und SIGNAGE_PERIOD_ID/Signage-Design sind die jeweiligen fachlichen Quellen.
Die Altwerte werden weder geloescht noch automatisch mit den aktuellen Werten
ueberschrieben. Unbekannte Codes sind als nicht zugeordnet sichtbar.

Die bisherige Spalte Aktiv zeigte enabled, dessen DB-Standard false ist.
Das war kein zuverlaessiger Funktionsstatus: MobileSeasonScope liest SaisonID
ohne enabled-Filter. Anzeige nun Verwendung/Freigabe: Verwendet fuer SaisonID,
Altbestand fuer die vier alten Werte, sonst Freigegeben/Nicht freigegeben mit
Hinweis auf den technischen Schalter. Keine pauschale Aktivierung, insbesondere
keine Aktivierung von Integrationen, Versand oder externen Aufrufen.

Settings-Abfrage explizit auf aktuellen Verein begrenzt, keine Werte ohne
Vereinskontext. Vertrauliche Werte bleiben maskiert. Keine Schema-/Datenmigration.
5 synthetische Rollbacktests/32 Assertions einschliesslich Livewire-Editor,
ungueltiger/fremder/inaktiver Auswahl und Altwert-Schreibsperre erfolgreich.
PHPMaker DEV/PROD unveraendert; parallele Mobile-Entwicklungen ausgeschlossen.

Release20261103-clubunio-setting-lookups auf finance-season-parameter aktiviert.
Vier Servergates gruen, keine Migration; Env und composer.lock unveraendert.
Angemeldete Sichtpruefung: Allgemein zeigt2026/2027(38), Sommer(S), Offen(O)/
Gesperrt(G) mit Verwendet/Altbestand. Suchbare Saisonliste oeffnet mit korrekter
Vorauswahl; ohne Speichern abgebrochen. Keine Bestandswerte veraendert.
