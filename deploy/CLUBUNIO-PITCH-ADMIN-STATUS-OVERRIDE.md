# Explizite Admin-Genehmigung trotz aktuellem Platzstatus

Stand05.11.2026. Die aktuelle Anlagenampel ist keine datierte Sperre für sämtliche
zukünftigen Belegungen. Eine ausdrückliche Ausnahme kann ein Admin beim
Genehmigen einer Platzanfrage setzen. Der Anlagenstatus bleibt dabei unverändert.

## Rechte und Konflikte

Persönlicher Reviewvertrag: optional `override_field_status: true`, nur gemeinsam
mit `decision: approved`. Ohne Flag bleibt die bisherige Prüfung erhalten.
Frische Mandantenrolle admin UND bestehende Modul-/Updateberechtigung sind nötig;
Backoffice/Trainer/TL, Leserechte und Support-Impersonation erteilen kein
Ausnahmerecht. Die Capability `override_field_status` wird nur dafür geliefert.

Die Ausnahme entfernt ausschliesslich `field_unavailable` aus der Konfliktprüfung.
Ein fehlender/archivierter/fremder Platz, zeitliche Sperrblöcke, kollidierende
Belegungen, entschiedene Anfragen und alte Revisionen bleiben gesperrt. Tenant-
und Sessionlocks sowie vorhandene Audit-/Benachrichtigungswege bleiben erhalten.
Das Genehmigungsereignis enthält `after.override_field_status=true`; keine neue
Datenbankspalte oder Migration. Keine echte Anfrage für die Abnahme genehmigen.

## App-Ablauf

Die App wertet die neue Capability geschlossen aus. Nur bei reinem aktuellen
Platzstatuskonflikt erscheint «Trotz aktueller Platzsperre genehmigen». Eigener
Bestätigungsdialog erklärt den unveränderten Anlagenstatus und weiterhin
geprüfte Belegungskollisionen. Erst die Bestätigung sendet das Flag; Abbrechen
sendet nichts. Normale Genehmigung und Ablehnung senden kein Overrideflag.
Die bisherige Trennung Speichern/Verschieben versus Genehmigen bleibt erhalten.
Drei neue Texte in fünf Offlinekatalogen; zentrale Sprachauswahl unverändert.

## DEV und Migration

Auslieferung als schmaler Delta auf Basis von
20261105-clubunio-dashboard-reservations-v2; keine Migration, ENV-, Composer-,
Provider-, Profil- oder PHPMaker-Änderung. Drei vorhandene Runtimequellen und
lesendes Capabilitygate, Ausgangsdateien vor Upload gegen Gitbaseline prüfen.
Vorheriger Release bleibt als Rückweg erhalten. App benötigt den neuen Build;
alte Apps behalten das geschlossene Verhalten. Web bietet in diesem Paket
keine neue Overrideaktion. Keine automatische Genehmigung offener Anfragen.

## Prüfung vor DEV

53 Backendtests/824 Assertions erfolgreich, davon16 echte PostgreSQL-
Domänenfälle mit145 Assertions und zurückgerollten synthetischen Daten.
64 App-Tests erfolgreich, Analyse und1988/5-Sprachkatalogprüfung sauber.
Keine echte Vereinsanfrage genehmigt. Native Geräteabnahme bleibt offen.
