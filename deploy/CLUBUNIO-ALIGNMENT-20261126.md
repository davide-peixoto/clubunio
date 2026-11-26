# Releaseabgleich 26.11.2026

Freigabe: abgeschlossene Arbeiten einschliessen und DEV, Stage und PROD
nachfuehren. Keine Datenauffrischung und keine private Konfiguration uebertragen.

## Gemeinsam ausgelieferte Anwendung

32 Dateien: gemeinsame React-Laufzeit mit manifestgebundenen ESM-Einstiegen,
bedarfsgerechte Diagramme, Dashboard-Rechnungsaggregate, gebuendelte autorisierte
Meldungszaehler, Reservierungs-Kennzahlen, Assistenten-Chat/Tagesuebersicht und
Navigation. Sprach-Dropdown und Veo/SFV-Nachfuehrung waren bereits auf beiden
VPS-Instanzen aktiv und bleiben erhalten. Clubhaus war ebenfalls bereits aktiv;
dessen abgeschlossene Quellen und Abnahme werden jetzt mit versioniert.

Stage und PROD: je 19 native Pruefungen mit zurueckgerollter Transaktion und
gesperrten ausgehenden HTTP-/Mail-/Queue-Aufrufen. Rechnungsaggregate gegen
native Liste und Meldungszaehler gegen autorisierte Meldungsseite abgeglichen.
DE/EN-Dashboard und Assistent gerendert; drei ESM-Einstiege je Sprache geprueft.
Alle 32 Dateihashes auf beiden Zielen identisch. HTTPS: /up 200, Login 302,
Assistenten-CSS und React-Manifest 200. Geschuetzte Dateien unveraendert.

Lokal: 119 Tests / 1472 Assertions, sieben Navigationstests, React-Buildbudget
und 3075 DE/EN-Schluessel erfolgreich. Ein alter synthetischer Uebersetzungstest
verwendete noch ein nicht mehr freigegebenes Modell. Nur dessen Testkonfiguration
wurde auf OpenAiModels::TEXT aktualisiert; keine Modell-/Rechtepruefung gelockert.

Die Browser-Sitzung war abgelaufen: korrekte Weiterleitung zur zentralen Anmeldung
geprueft, aber keine angemeldete Desktop-/Mobile-Sichtpruefung behauptet.

## Instanzvarianten bewusst erhalten

Eine Quellinventur vor dem Release erfasste 1404 lokale, 1373 Stage- und 1376
PROD-Dateien. Das sind keine Zahlen fehlender Releases. Neben Zeilenenden und
Schema-Alias unterscheiden sich historisch auch aktive Login-, Profil-,
Migrations- und Azure-Zugriffspfade. Insbesondere duerfen die strengeren
VPS-Authentifizierungswege und Stage-SAS-Zugriffe nicht durch DEV ersetzt werden.

Deshalb bedeutet der Abgleich gleicher gemeinsamer Fach-/UI-Release, nicht
byteidentische Authentifizierung, DB-Daten, Secrets oder instanzspezifische
Importprogramme. Die Laufzeitvarianten werden bei der Branch-Promotion erhalten.
Kein blindes git reset/checkout ueber die Server und keine vorgetaeuschte
vollstaendige Gleichheit der historisch gewachsenen Quellbaeume.

## Deployment und Rueckweg

VPS: deploy/promote-release-alignment.py prueft die vorher gelesenen Quellhashes,
bricht bei parallelen Aenderungen ab, sichert jede ersetzte Datei mit ACL und
schreibt ausschliesslich die explizite Paketliste. Root bleibt Code-Eigentuemer,
clubunio-stage bzw. clubunio-prod haben Leserechte, runcloud-www nur die
erforderlichen oeffentlichen Assetrechte. Kein DDL und keine Rechtezuweisung.

Private Sicherungen, Basis-/Zielhashes und Abnahme:
/root/clubunio-releases/alignment-20261126/{stage,prod}/.
Rueckweg: Dateien unter before gemaess baseline.json zurueckkopieren,
urspruengliche ACL wiederherstellen, neue Dateien aus der Paketliste entfernen,
als jeweiliger Runtime-Benutzer view:cache und up ausfuehren, PHP-FPM neu laden.
Der Deployhelfer fuehrt das bei einem Fehler selbst aus. Kein DB-Restore noetig.

DEV: neuer Kandidat 20261126-clubunio-alignment-v1 auf Basis von
20261125-clubunio-react-performance-v1; private Konfiguration und gemeinsame
Ablage erhalten. DB-Pflichtcheck, kompilierte Views und Rollback-Messung vor
Aktivierung. Rueckweg: current auf den vorherigen Release und FPM neu laden.

## Git

main bleibt DEV, stage und prod bleiben getrennte Umgebungsbranches.
Die Umgebungen hatten echte divergierende Historien, nicht nur fehlende Tags.
Quellen/Abnahmen werden daher ohne Force-Push aufgenommen; vorhandene
Umgebungsvarianten bleiben versioniert. Aktive RunCloud-Push-Webhooks muessen
waehrend des kontrollierten Ref-Abgleichs pausiert und danach wiederhergestellt
werden, damit kein ungepruefter Komplettcheckout den gehaerteten Code ersetzt.
Ein Push allein ist kein Ersatz fuer Paket-, DB-, ACL- und HTTP-Abnahme.

Der erste atomare Git-Abgleich war erfolgreich, beide RunCloud-Webhooks wurden
danach wieder aktiviert. Die Laufzeitdateien wurden vorher kontrolliert deployt,
nicht durch den Push. record-runtime-release.py erstellt die Promotion-Commits
aus dem geprueften gemeinsamen Commit und den tatsaechlich gelesenen
Anwendungsquellen des jeweiligen Servers. Beide bisherigen Historien sind Eltern;
umgebungseigene Skripte/Dokumente bleiben erhalten. Kein Force-Push oder Reset.
Die versionierten Quellhashes unter evidence/alignment-20261126 ermoeglichen
den spaeteren Abgleich, ohne Secrets oder private Laufzeitdateien zu speichern.

Waehrend des Abschlusses wurde parallel der abgeschlossene Google-Drive-
Sicherungsnachweis 6feddfea committet. Dieser betrifft nur Diagnose-/Backupskripte
und Dokumentation, keine Anwendungslogik. Er wird mit in die Historie aufgenommen;
das eigentliche Anwendungsrelease und seine 32 Hashes bleiben unveraendert.
