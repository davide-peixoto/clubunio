# Mobile: nächste Termine und reguläre Trainingszeiten

Stand: 24.11.2026. Backend-Fix auf DEV, Stage und PROD freigegeben und geprüft.

## Verhalten

Auf Heute sind Datumskacheln gleich groß (44 × 44), mit Innenabstand, derselben
Farb-/Radiusfamilie wie die Modul-Icons, ruhigerer Typografie und zweistelligem
Tag (01 statt 1). Datum in der Terminzeile verwendet die lokale Datumsformatierung
statt einer fest eingebauten Reihenfolge. Wochentag und Datum verwenden dieselbe
bereits lokal normalisierte Startzeit. Lange Texte und vergrößerte Schrift bleiben
berücksichtigt. Keine neuen festen Oberflächentexte oder Übersetzungs-API.

My Teams hatte auch reguläre Trainings ohne besondere Sperrvariante verworfen,
weil sämtliche Flags all_fields/without_main/synthetic_only false waren. Hallen-
trainings waren davon ebenfalls betroffen. Die bestehende Variante bleibt für
explizite Ausweichpläne maßgeblich; ungekennzeichnete reguläre Zeiten werden
zusätzlich berücksichtigt. Der OR-Ausdruck ist innerhalb der bestehenden
Mandanten-, Mannschafts-, Aktivitäts- und Periodenfilter geklammert. Keine neuen
Rechte, kein Wechsel auf eine generische Tabellen-API, keine zusätzlichen Abfragen.
Nur die zentral gewählte Sommer-/Winterperiode erscheint weiterhin.

## Separate Konfiguration auf Stage

Beim ursprünglichen SV Sissach auf Stage (Tenant1) war SIGNAGE_PERIOD_ID unter
Allgemein auf0 gesetzt. Nach ausdrücklicher Benutzerfreigabe am24.11.2026 wurde
Winter (nativer Periodenwert2) über SettingsService aktiviert. Verwendet wurde
das bestehende eigene Backoffice-Konto20; die Änderung ist in setting_changes
protokolliert. Gmail-Zugangsrechte wurden nicht erweitert. Privater Rückweg für
den bisherigen verschlüsselten Wert: /var/backups/clubunio-stage-winter-20261124T175741Z/.
Ein frischer Read mit Gmail liefert nun einen Wintertermin im berechtigten Team;
Teams ohne hinterlegte Winterzeit bleiben leer. Der separate Stage-Refresh-Verein
(Tenant3) bleibt beim bereits gewählten Winterplan.

Auf PROD war Winter schon korrekt gesetzt (Tenant12, native Periode24); keine
Änderung dieses Parameters. Der bestehende Gmail-Zugang erhält nach dem Filterfix
zwei Wintertrainings für sein Ba-Team816. Das Verwaltungsteam844 hat keine
Trainingszeiten. Sommer wird nicht zusätzlich angezeigt.

## Prüfung und Release

2 lokale SQLite-Regressionsfälle (4 Assertions): regulär, Halle, passende und
unpassende Ausweichvariante, fremder Verein/fremdes Team, andere Periode,
inaktive Zeit und fehlender aktiver Plan.19 Fluttertests für Heute, My Teams und
Performanceadapter erfolgreich; DE/EN-Monatswechsel, gleiche Kachelmaße mit
Abstand sowie bisherige Tests mit Textskalierung. Gezielte Analyse sauber.
Zentrale DE/EN-Prüfung:3075 Schlüssel, keine neuen Texte.

Native PostgreSQL-Abnahme: deploy/check-mobile-team-training-read.php mit
--synthetic-rollback clubunio_dev|clubunio_stage. Je5 Prüfungen erfolgreich,
ausschließlich synthetische Transaktion mit vollständigem Rollback, keine
Provideraufrufe. Bestehende frische Teamautorisierung wurde nicht verändert;
der neue isolierte Prüfer testet die interne Projektion, keinen neuen Login.

Gezieltes Zwei-Dateien-Delta (MobileTeamsData + Prüfer), Live-Hashprüfung vor
Installation; fremde Live-Änderungen erhalten. Keine Migration, keine
Konfigurations-/Cacheumstellung, kein PROD-Release.
Sicherung: /var/backups/clubunio-team-training-{dev|stage}-20261124T174036Z.
Rückweg: gesicherte MobileTeamsData.php im jeweiligen aktuellen Release gezielt
zurückspielen, nur den eigenen neuen Prüfer entfernen, keine Daten zurücksetzen.

Die Trainingskorrektur gilt serverseitig nach erneutem Laden. Die Datumskachel
benötigt einen aktualisierten App-Build auf dem Mac/Android; kein signierter
Store-/TestFlight-Release in dieser Sitzung erstellt.


## Freigabe PROD und Abschluss – 24.11.2026

Der Benutzer hat den zuvor begrenzten Rollout nun ausdrücklich für PROD freigegeben.
Übernommen wurden der Trainingsfilter und der noch ausstehende Bild-only-Wiki-Stand.
Aktuelle übrige Live-Arbeiten bleiben erhalten; kein pauschaler Git-/Dateibaum-Abgleich.
Fünf gezielte Quelldateien vorab gegen Live-Hashes geprüft, gesichert und installiert.
423 alte öffentliche Video-/Player-/Untertitel-/Manifestdateien privat archiviert;
146 Bilder bleiben erreichbar. Keine DB-Migration, keine Provider-/Mail-/Pushänderung.
Backups: /var/backups/clubunio-prod-app-fixes-20261124T175929Z/.

PROD:91 Support-/Wiki-Prüfungen und5 Trainingsprojektionstests im vollständigen
PG-Rollback erfolgreich. Insgesamt12 lokale Backendtests/3608 Assertions sowie
3075 DE-/EN-Schlüssel geprüft. Bestehender Gmail-Zugang und Winterfilter bestätigt.
HTTP200 für /up, Hilfe und Bilder; Videos/Player HTTP404, geschützte API ohne
Anmeldung HTTP401. Native Datumskacheln bleiben im App-Repository main enthalten;
für sie muss ein aktualisierter App-Build erstellt werden. Kein neuer IPA-/Store-
oder TestFlight-Upload durch diese Backend-Freigabe.
