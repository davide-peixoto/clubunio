# Mitgliederaenderungen: feldbezogene Freigabe

## Fachregel und Migration

Keine DB-Migration und kein Datenrefresh. Vorhandene verschluesselte
Antragssnapshots enthalten die Ausgangswerte und Zielwerte der Aenderung.
Bei der Freigabe wird jeder beantragte Wert mit dem aktuellen Datensatz
verglichen: Ausgangswert bedeutet uebernehmen, Zielwert bedeutet bereits
erledigt, jeder andere Wert bedeutet Konflikt. Ein Konflikt blockiert
den gesamten Antrag. Nicht beantragte Felder bleiben unangetastet.
Leere Zeichenketten und NULL sowie numerische Lookup-IDs werden wie bei
der Antragserfassung verglichen. Fehlende Ausgangswerte und unzulaessige
Felder blockieren die Freigabe.

Die Schreiboperation nutzt die aktuelle Version des bereits gesperrten
Mitglieds. Vollstaendig uebernommene Aenderungen schliessen nur den Antrag
mit Pruefer, Revision und Audit ab, ohne das Mitglied erneut zu speichern.
Antragsrevision, Mandant, Mitgliedsstatus und aktuelle Rechte des
Antragstellers und der freigebenden Person werden weiterhin geprueft.
Fotoantraege behalten den gesamten Versionsschutz, da historische
Snapshots keine separate Foto-Baseline besitzen.

## Abnahme und Rueckweg

Lokal: `php vendor/bin/phpunit --filter MemberChangeConflictsTest` und
`php tools/check-translations.php`.
DEV: `php deploy/check-dev-member-change-conflicts.php`.
Der DEV-Test prueft ausschliesslich synthetische Konten und Mitglieder
in einer Rueckrolltransaktion, keine echten Antraege und kein Versand.
Er prueft unabhaengige Aenderungen, bereits geleerte Telefonnummern,
echte Konflikte ohne Teilschreiben und widerrufene Antragstellerrechte.

Stage und PROD bleiben unveraendert. Rueckweg ist der vorherige
DEV-Release; die Datenstruktur bleibt gleich.
