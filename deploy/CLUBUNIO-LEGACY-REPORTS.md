# ClubUnio: Altlisten-Abgleich, Release43

14.10.2026. Quelle ist ausschliesslich der bereits gesicherte PROD-Snapshot
in ClubUnio, einschliesslich migration_audit.catalog/modules. Kein neuer Zugriff
auf PHPMaker DEV/PROD; keine Hardware-/Bexio-/Push-Aktion.

## Schluesselliste

Neue zentrale Liste unter Listen/Schluesselliste, Route key-reports.
Schluesselnummer, Bezeichnung, Farbe, Mitglied, Mitgliederstatus, Aktiv und
Archivkennzeichen. Felder entsprechen dem bestehenden Import der Schluesselliste
(SchuesselID, SchluesselNummer, SchluesselBezeichnung, Schluesselfarbe, MitgliederID).
Keine erneute Datenkopie. Pflege bleibt im Mitgliederdossier; zentrale Liste
nur lesend. Archivierte und ausgetretene Halter bleiben sichtbar, damit eine
offene Schluesselrueckgabe nicht durch einen Mitgliedschaftsfilter verschwindet.
Kein Dossierlink fuer archivierte Mitglieder; kein physischer Schluesselzugriff.
Aktivfilter standardmaessig Ja, Farbfilter, Suche, persoenliche Ansichten und CSV.

## Spots-Versandliste

Die gesicherte SQL-View ListeSpotsFamilie kombiniert Einzelmitglieder ohne
Familienzuordnung mit allen Familien. Es ist NICHT die Spieler-/Kinderliste.
Neue tenantgebundene PostgreSQL-View spots_recipients und Filament-Liste unter
Listen/Spots-Versandliste, Route spots-recipients. Pro Familie eine Zeile;
Mitglieder mit aktiver Zuordnung zu aktiver Familie nicht noch einmal einzeln.
Familien weiterhin unabhaengig von einem Spots-Flag ihrer Kinder enthalten.

Bewusste Verbesserungen gegenueber Alt-SQL:
- Nur aktive Familien/Zuordnungen; deaktivierte Zuordnung blockiert Einzelversand nicht.
- Archivierte Mitglieder und wirksame Austritte ausgeschlossen.
- Statusregel als spots_eligible pflegbar, nicht dauerhaft an numerische IDs gebunden.
- Migration41 uebernimmt Ausschluss der Altstatus1/4 fuer SV Sissach; fuer neue Status
  ist Einzelversand zunaechst erlaubt. Zusaetzlich gilt ends_membership als Ausschluss.
- Keine fingierten Werte Aktiv/Mitglied bei Familien; diese haben keinen Mitgliederstatus.
- Zwei verschiedene Familien mit gleicher Adresse/Bezeichnung werden nicht verschmolzen.
  Stabile m-/f-Schluessel ersetzen das informationsverlustbehaftete SQL-UNION-Deduping.

CSV: Empfaengerart,Firma,Name,Strasse,PLZ,Ort,Land; keine Elternkontakte oder
Geburts-/Ausweisdaten. Filter/Suche/Sortierung gelten fuer die Gesamtauswahl,
5000-Zeilen-Grenze, Formelschutz und Audit wie bei bestehenden Berichten.
Listen/Exporte nur aktive Admin/Office-Konten des Vereins. Kein automatischer Versand.

## Migration

000040 legt ausschliesslich die Report-View an.000041 ergaenzt die pflegbare
Statusregel und ersetzt die View-Definition; keine Mitglied-/Familien-/Schluesseldaten
geloescht oder umgeschrieben. Eigene gemeinsame ClubUnio-DB lokal/Pi.
Ruecksprung auf Release42 kann die zusaetzlichen Schemaobjekte behalten.

## Trainings-Altbestand

tools/clubunio-package-audit.php prueft nur Struktur/Zaehler, keine Namen,
Zugangsdaten, Fehlertexte oder Trainingsinhalte in der Ausgabe.
41 Auditzeilen:31 success,10 Fehler/Zwischenstaende. Nur1 erfolgreicher Plan
hat SharedTeamSaisonID;30 haben keine eindeutige TeamSaison-Zuordnung.
Planformat verwendet diagram statt markers/diagramDescription. Keine Umdeutung
der alten SVGs als vertrauenswuerdiges HTML; kein ungepruefter Import erfolgt.
Naechster Migrationsschritt: Vorschau mit Besitzer-/Teamzuordnung und sicherer
Grafikkonvertierung, danach idempotente bestaetigte Uebernahme. Ursprungs-Snapshot
unveraendert aufbewahren; keine generischen KI-Neugenerierungen als Ersatz.

Tests/Deploymentnachweis siehe PI.md. Physische Buscodes, Instagram-Livetest,
14 historische Buszuordnungen und angemeldete visuelle Abnahme weiterhin offen.
