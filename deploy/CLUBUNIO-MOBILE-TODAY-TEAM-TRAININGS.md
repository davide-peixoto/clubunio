# Mobile: nächste Termine und reguläre Trainingszeiten

Stand: 24.11.2026. Backend-Fix auf DEV und Stage; PROD unverändert.

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

Beim ursprünglichen SV Sissach (Tenant1) ist SIGNAGE_PERIOD_ID unter Allgemein
auf 0 gesetzt. Es wurde deshalb KEIN Sommer-/Winterplan willkürlich gewählt.
Die Rückfrage zur Aktivierung des Winterplans ist noch offen. Ohne aktive Periode
bleibt die Ausgabe absichtlich leer, statt Sommer und Winter zu vermischen.
Beim separaten Stage-Refresh-Verein (Tenant3) ist Winter gültig gewählt. Mit dem
bestehenden Gmail-Zugang liefern seine zwei berechtigten Teams 2 bzw.1 Trainings-
zeiten. Konfigurationen und Benutzerdaten wurden nicht verändert.

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
