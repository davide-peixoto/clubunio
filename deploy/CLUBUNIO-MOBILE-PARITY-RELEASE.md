# Gemeinsame Originalparitaet: DEV aktiv

Iststand 04.11.2026: `parity-v1` aus Produktsnapshot `3560c6e` ist auf
ClubUnio-DEV aktiv; 56 Runtime-Dateihashes sind geprueft. Tooling `cdf8889`,
Aktivierung `a5b253f`. Runtime-Rollbackziel ist `parity-foundation-v2`.
Migrationen800/900/1000 wurden nach verifizierter privater Sicherung atomar
angewendet: vier neue Tabellen und elf Fremdschluessel, bestehende Elternzeilen
und Metadaten bis auf die exakt geprueften Additionen unveraendert.

Sicherung:
`/var/www/svs-laravel/shared/backups/mobile-before-800-900-1000-20261104-20261104012923-235b6a199ff2ae189d721cc87ce5689e.cuenc`.
SHA256 `3678ba69e56812ce9e438b28e12f417b6b9a055836ffd597f32c2c10ac82a0e3`;
Spec-SHA256 `9bc49f6f1700358e2fe175e4075bacb20e3311d7c1a9ee8e18af23b28612a06c`.
[Vertrag und Schemanachweis](CLUBUNIO-MOBILE-PARITY-SCHEMA.md).

Sieben interne Freigaben sind aktiviert: Bootstrap, Rollenhilfe,
OCR-Betragsprovenienz, Pushadmin, Penalty, Keybox und Keyboxadministration.
Bootstrap-Origin ist ausschliesslich `https://dev.clubunio.ch`. Bestehende
Sponsorabrechnung bleibt aktiviert. Externe SMTP-/FCM-/Azure-/Igloooperationen
und kostenpflichtige oder physische Betriebsfreigaben bleiben geschlossen;
interne Keyboxverwaltung bedeutet keine PIN-/Hardwarefreigabe.

Alle Releasegates bestanden: Common13, Planung21, Anmeldungen11, Training11,
Sponsorenlauf15, Billing4, Mitgliederausweis1, Identity8+2, Push6+4, Keybox6,
Challenge5, Bootstrap1+2 und die Provenienz-Gates. Zusaetzlich Admin18,
Menue79 sowie Login-/Profil-/My-Teams-/Planungs-Kernelgates mit synthetischen
Rollbackdaten erfolgreich. Androidbuild und468 App-Tests bestanden. Dies ist
keine externe Zustellungs- oder native Geraeteabnahme.

## Abgeschlossener Bereitstellungsweg

Produktsnapshot3560c6e enthaelt Bootstrap/Banner/Rollenhilfe, echte Keybox-
Verwaltung, OCR-Betragsprovenienz, Original-Rundnachrichten, frische Team-
Anmeldedetails, Heute-Kalender und echte Custom-Shareidentitaet. Die getesteten
Originalmasken und persoenlichen Fachrechte bleiben die Grundlage.

`package-mobile-parity-dev.py` paketiert ausschliesslich committete Dateien
auf der exakt bekannten identity-push-foundation. Bereits vorhandene Dateien
werden gegen Git-Historie und SHA256 geprueft; unbekannte parallele Aenderungen
stoppen die Vorbereitung. Composerlock, Env und bisherige Modulfreigaben werden
unveraendert uebernommen. Keine neuen Pakete oder Provideraufrufe.

`release-clubunio-mobile-parity-foundation.sh` aktiviert erst nach alten Gates,
geschlossenen Bootstrap-/Keybox-/Identity-/Push-/Provenienzvertraegen und
synthetischen persoenlichen Menue-/Konten-/Planungs-/Rundnachrichtenchecks.
Anschliessend wurde die exakt gehashte, unabhaengig gepruefte Schemahilfe
getrennt mit verschluesselter Sicherung erfolgreich ausgefuehrt.

Bestehende Sponsorabrechnung bleibt eingeschaltet. Nur die neuen Freigaben
blieben in der Foundation geschlossen und wurden danach ausschliesslich im
oben bestaetigten internen Umfang aktiviert; SMTP/FCM/APNs/Azure-/Igloo-Provider und
echte native Kamera-/Biometrie-/Share-/Geraeteabnahme sind weiterhin gesondert
offen. Eine vorbereitete Quelle oder ein bestandener Mocktest ist kein Nachweis
erfolgreicher externer Zustellung oder Hardwareausgabe.

Erster inaktiver Staginglauf f2c5c5b wurde vor Aktivierung im Rundnachrichten-
Gate gestoppt: Laravel-Fakeasserts verlangten auf dem bewusst PHPUnit-freien
Server eine Testbibliothek. Providerfreiheit wird nun direkt aus den leeren
HTTP-/Mailable-/Queueaufzeichnungen geprueft. Neuer unveraenderlicher Pfad
parity-foundation-v2. Dieser gepruefte Foundationstand wurde danach zur
Grundlage des abgeschlossenen Schemalaufs und bleibt Runtime-Rollbackziel.

Abgeschlossener interner Freigabeschritt: release-clubunio-mobile-parity-enabled.sh
setzt ausschliesslich sieben interne Flags und die kanonische DEV-Bootstrap-
Origin in einer separaten Release-Env. Bootstrap/Rollenhilfe, OCR-Herkunft,
Rundnachrichten und Challenge-/Keybox-Originaleinstiege werden serverseitig
bereit; bestehende mandantenbezogene Modul- und Fachrechte gelten weiter.
Keyboxadministration erlaubt reale Einrichtung ohne SQL-Handeingriffe, jedoch
keine PIN-Ausgabe. Externe SMTP-/FCM-/Azure-/Igloo-/Kosten-/Widerrufsfreigaben
bleiben unveraendert geschlossen. Die Aktivierung erfolgte erst nach den
erfolgreichen Schema-, geschuetzten Routen- und synthetischen Kernelgates.

Nach Aktivierung separat ueber externes HTTPS geprueft: Login200, anonymer
Bootstrap200 mit no-store; Identity503, persoenliche Notifications/Keybox/
Bootstrap401. Aktiver current-Pfad und PHP-FPM bestaetigt. Vollstaendige
Flutteranalyse ohne Befund,468 Tests und finaler Android-Debugbuild erfolgreich.
