# Saisonplanungs-Wizard

Stand: 21.10.2026. Ausschliesslich ClubUnio-Web; kein PHPMaker-Import und keine
Aenderung an bestehendem DEV/PROD, API, SaaS oder Betrieb.

## Einstieg und Ablauf

Sport -> Saisonplanung, `/season-planning` nach Anmeldung.

1. Neue Planung ohne Vorlage oder mit einer aktiven Quellsaison anlegen.
2. Saisonbezeichnung und Beginn/Ende festlegen.
3. Bestehende Mannschaften wiederverwenden oder neue Stammmannschaften planen.
   Entfernen loescht nur die geplanten Zuordnungen/Trainingszeiten. Zusammenfuehren
   verschiebt diese auf eine andere geplante Mannschaft; gleiche Zuordnungen
   werden dabei zusammengefasst, unterschiedliche Staff-Funktionen bleiben erhalten.
4. Beitragspreise und Geschlechts-/Jahrgangsregeln pruefen. Preise werden separat
   neu erstellt, damit Aenderungen keine bestehenden Saisonbeitraege veraendern.
   Bexio-Artikelreferenzen der Vorlage bleiben im Entwurf erhalten, ohne Versand.
5. Spieler, Staff und technische Leitung zuordnen. Spielerpool mit Namens-/
   Jahrgangsfilter, unzugeordneten Mitgliedern, optional passenden Jahrgaengen,
   40 Ergebnissen je Seite. Zuordnung per Drag-and-drop oder Zielauswahl/Pfeil;
   Entfernen und anschliessende Neuzuordnung ist ohne Drag-and-drop moeglich.
6. Trainingszeiten inklusive Periode, Wochentag, Platz, Garderobe, Platzteil,
   Halle und Sichtbarkeits-/Platzoptionen bearbeiten.
7. Abschlusspruefung aktualisieren, Hinweise bewusst bestaetigen und abschliessen.

Entwuerfe werden nach gueltigen Feldanpassungen automatisch gespeichert, ausserdem
ueber Speichern und beim Schrittwechsel. Unvollstaendige Zeilen sind erlaubt;
ungueltige Werte oder ein Versionskonflikt werden als nicht gespeichert gemeldet.
Browser schliessen erst nach der Speicherbestaetigung. Entwuerfe sind ueber Suche
und geblaetterte Auswahl wieder am zuletzt gespeicherten Schritt erreichbar. Verwerfen archiviert den Entwurf,
ohne ihn oder die Historie physisch zu loeschen. Abgeschlossene Plaene sind lesbar.

## Vorschlaege und Grenzen

Aktive TeamSaison-Zuordnungen, Preise/Regeln und Trainingszeiten der Vorlage werden
kopiert. Fehlende/inaktive Mitglieder und Staff-Funktionen blockieren den Abschluss
bis zur Korrektur; sie werden nicht stillschweigend ignoriert. Nicht mehr aktive
Stammmannschaften werden nicht als neue Saisonmannschaften vorgeschlagen.

Beitrags-Jahrgangsgrenzen werden bei einer Folgeplanung um ein Jahr verschoben.
Die Regel mit eindeutig hoechster Uebereinstimmung zum bisherigen Spielerkader
wird vorgeschlagen. Jeder Mannschaft kann stattdessen ein eigener Bereich oder
eine andere Beitragsregel zugewiesen werden. Sportliche Spielberechtigung wird
dadurch nicht festgestellt; keine SFV-Altersbestimmung oder KI-Abfrage.

Vorschlaege uebernehmen ergaenzt passende, bisher keiner Mannschaft zugeordnete
Spieler bis zur optionalen Zielgroesse. Bestehende Zuordnungen werden nie ersetzt.
Gleiche Rangfolge: Mitglied-ID; keine behauptete Leistungs-/Eignungsbewertung.
Staff/TL stammen aus der Vorlage beziehungsweise der manuellen Mitgliederauswahl,
nicht aus einer Altersautomatik. Bewusste Mehrfachzuordnung und abweichende
Jahrgaenge sind nach bestaetigtem Hinweis erlaubt.

Hinweise: fehlender Staff/TL, fehlende oder abweichende Jahrgangszuordnung,
Mehrfachzuordnung, Zielgroesse und sich innerhalb des Entwurfs ueberschneidende
Trainingszeiten derselben Mannschaft bzw. desselben Platzes/Halle sowie bereits
gespeicherte Belegungen in nachweislich zeitlich ueberlappenden Saisons. Fehlende
Saisondaten erlauben keinen belastbaren saisonuebergreifenden Vergleich. Die Platzteile
bleiben sichtbar; ein Konflikthinweis ist keine automatische Platzsperre.
Bestehende Trainingsslots anderer Saisons werden nicht umgebaut oder gesperrt.

## Sicherheit und Verbuchung

- Anzeige: view fuer seasons, teams, members, contributions, training_slots.
- Entwurf bearbeiten: zusaetzlich seasons.create. Verwerfen: seasons.deactivate.
- Abschluss: zusaetzlich seasons.transfer und create fuer teams, contributions,
  training_slots. Pruefung frisch vor und nach dem Warten auf die Sperren;
  Fachservices behalten ihre eigenen Rechte-/Referenzpruefungen.
- Trainer-/Spieler-/Elternrollen erhalten dadurch keinen Backoffice-Vollzugriff.
  Explizite Verwaltungsprofile koennen gezielt freigeschaltet werden.
- Jede Abfrage nach Verein gefiltert, IDs alleine reichen nicht. Eingaben mit
  fremden technischen Feldern werden abgewiesen. Mandantenfaehigkeit wird nicht
  erweitert; vorhandene Tenant-Grenzen werden nur beibehalten.
- `season_plans.document_encrypted`: verschluesselter Entwurf, keine Personenliste
  im Audit. Vorhandenen APP_KEY und geschuetzte Konfiguration beibehalten.
- Revision gegen verlorene Updates; HMAC ueber gespeicherten Entwurf plus aktuelle
  Auswahl-/Mitgliederdaten verhindert Abschluss einer veralteten Vorschau.
- Ein gemeinsamer DB-Transaction-Abschluss mit Tenant-/Entwurfssperre und stabilen
  Mitglieds-/Lookup-Referenzen. Bestehende Fachservices erstellen Saison, neue
  Stammmannschaften, TeamSaisons, Preise, Regeln, Spieler, Staff, TL und Slots.
- `season_plan_results` ordnet jeden Entwurfsschluessel dem erstellten Datensatz zu.
  object_changes protokolliert Fachobjekte und Entwurfsversion/Abschluss.
- Jeder Fehler rollt alle fachlichen Schreibvorgaenge zurueck. Entwurf bleibt
  offen; bestehende Protokollierung meldet den Fehler. Kein Teilabschluss.
- Wiederholter Abschluss liefert dieselbe Saison statt neuer Datensaetze. Nach
  Verbindungsabbruch Planung neu oeffnen und Status pruefen; nicht neu anlegen.
- Neue Saison ist als Auswahlwert aktiv. Die aktuelle Saison-Einstellung wird
  nicht geaendert. Keine Rechnungen, E-Mails, Pushes oder externen Syncs.

Bewusste Mengenbegrenzung je Entwurf: 100 Mannschaften, 100 Preise, 200 Regeln,
2000 Zuordnungen, 500 Slots. Abschluss ist synchron, keine neue Queue oder
Scheduler-/Proxy-Konfiguration. Ein Vollvereins-Abschluss ist vor produktiver
Nutzung anhand eines echten Planungsentwurfs fachlich abzunehmen.

## Migration und Release

Migration `2026_10_21_000072_season_plans.php`, ausschliesslich neue Tabellen
`clubora.season_plans` und `clubora.season_plan_results` in ClubUnio PostgreSQL
db_acdf8d_dclubora. Keine bestehenden Daten, Parameter oder Quellen importieren.
Lokale und Pi-Installation verwenden bereits dieselbe ClubUnio-Datenbank; Migration
nicht doppelt als SQL ausfuehren. Laravel-Migrationsjournal wird geprueft.

Page, Blade, CSS, Service, Panelregistrierung, TeamAssignmentService und CatalogService zusammen ausrollen.
CatalogService serialisiert Team-/Saisonkatalog mit der Saisonplanung und erneuert
Teamlabels nur bei Aenderungen bestehender Stammsaetze, nicht bei Neuanlage.
TeamAssignmentService.createMany prueft Mengen, Whitelist, Vereinsreferenzen,
aktive Auswahlwerte, Duplikate und frische Rechte gemeinsam, erstellt aber jedes
Modell mit seinen Ereignissen. Audits werden gesammelt geschrieben. Die vorhandene
Einzelbearbeitung bleibt unveraendert; Abschlussrechte werden am Ende nochmals
frisch geprueft. So bleiben grosse Kader innerhalb einer atomaren Verbuchung moeglich.
Vorherige Releasekopie bleibt fuer Code-Rollback erhalten. Neue Planungstabellen
bei Rollback erhalten; kein automatisches Down oder Zuruecksetzen fertiger Saisons.

Release: `20261021-clubunio-season-planning`. Kontrollierte Delta-Auslieferung mit
`release-clubunio-season-planning.sh`; vorhandene Konfiguration/Storage bleiben.
Pruefungen: Syntax, gezielte transaktionale Service-/Livewire-Tests, bestehende
Sport-/Saison-/Webeditor-Regression, Caches, Tenant-/Profil-/Verschluesselungscheck
und anonyme HTTP-Zugriffssperre.

Deploy bestaetigt: aktiver Pi-Release wie oben, FPM aktiv, Login und neue CSS200,
Wizard anonym302 zur Anmeldung. Laufender Page-/Service-/Blade-Stand per SHA256
identisch zur lokalen Quelle. Cache-/Tenant-/Profilchecks erfolgreich,195
verschluesselte Einstellungen und46 Vorlagen weiterhin gueltig, keine verbliebenen
Testentwuerfe. Keine echten Saisons oder Nachrichten fuer den Deploy erzeugt.

Regressionsstand: 61 Sport-/Saison-/Webeditor-Tests mit846 Assertions erfolgreich.
Finaler Lauf: alle17 Wizard-Tests und11 Modulrechtetests, zusammen28 Tests/
202 Assertions erfolgreich. Separater Nachlauf der Fortschrittsspeicherung und
Webzuordnung: drei Tests/38 Assertions erfolgreich. Alle synthetischen Daten
zurueckgerollt; lokale Checks finden keine zurueckgebliebenen Planungsentwuerfe.

Unabhaengiger bestehender Befund: ModulePermissionTest erwartet im historischen
Seed71 dieselben Leserechte wie im heutigen Modulverzeichnis. Dort kam inzwischen
field_availability hinzu. Seed71 vergibt absichtlich nur seine versionierte Liste;
neue Module werden nicht automatisch nachberechtigt. Dieser einzelne Test zeigt
deshalb eine Erwartungsabweichung, keine vom Wizard erweiterte Berechtigung.
Migration71, PermissionCatalog und bestehende Profile bleiben unveraendert.

Lokale synthetische Messung, jeweils mit abschliessendem Fixture-Rollback:
101 Spieler plus Staff/TL, ein Team, Preis/Regel/Slot: Abschluss zuvor22.67s/
931 DB-Statements, final mit gesammelter Referenz-/Auditverarbeitung5.97s/248 Statements.
Keine Aussage ueber Browserlaufzeit oder garantierte Dauer bei einem Vollverein.

Offen: angemeldete visuelle Desktop-/Mobilabnahme und echtes Drag-and-drop im
Browser. Das Windows-Steuerungswerkzeug hat die Sichtpruefung wegen unklarer
Browser-URL aus Sicherheitsgruenden beendet. Keine Umgehung oder Anmeldung per
extrahierter Session; automatisierte Livewire-Tests ersetzen diese Sichtabnahme
nicht. Keine echte Saison aus Bestand zu Testzwecken abgeschlossen.
