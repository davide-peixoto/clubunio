# Historische Spesen: Mannschaftssaison korrigiert03.11.2026

## Freigabe und Ergebnis

Benutzer bestaetigt: Die Belegsaison ist richtig. Bei genau14 besprochenen
Spesenbelegen soll dieselbe Mannschaft in der passenden Saison zugeordnet werden,
ausschliesslich in ClubUnio. Dieser Auftrag ersetzt die offene fachliche
Entscheidung aus CLUBUNIO-SEASON-REFERENCE-AUDIT.md.

Ausgefuehrt mit d5cb1f2 in einer einzigen PostgreSQL-Transaktion. Geaendert:
team_season_id sowie revision1->2 und updated_at. Pro Beleg ein append-only
object_changes-Ereignis mit Vorher/Nachher, bestehendem freigeschaltetem
Vereinsadmin als Akteur und correction_key=expense_team_season_fix_20261103.

| Original-Beleg-ID | ClubUnio-ID | Belegsaison | TeamSaison vorher -> nachher (interne IDs) |
| --- | --- | --- | --- |
|102|46|2020/2021|229 ->256|
|133|62|2019/2020|262 ->233|
|224|129|2022/2023|276 ->309|
|294|172|2023/2024|312 ->321|
|319|192|2022/2023|319 ->310|
|337|206|2022/2023|317 ->306|
|369|230|2024/2025|319 ->346|
|371|232|2024/2025|323 ->350|
|374|235|2024/2025|320 ->347|
|375|236|2024/2025|320 ->347|
|376|237|2024/2025|320 ->347|
|419|268|2023/2024|344 ->317|
|420|269|2023/2024|344 ->317|
|502|335|2024/2025|370 ->344|

Die TeamID wurde unveraendert beibehalten; keine Namens-/Kategorie-Schaetzung.
Jedes Ziel war eindeutig, aktiv und im selben Mandanten vorhanden. Stammdaten,
Mitglieder, Belegsaison, Status-/Zahlungsdaten, Bexio-Zuordnung, Bankkonto und
alle191 Positionen inklusive Betraegen und Dateireferenzen blieben unveraendert.
Keine Push-/Email-/Bexio-Aktion, keine echten Zahlungen, kein Rechtewechsel.
PHPMaker DEV/PROD und legacy_prod-Snapshot unveraendert.

## Sicherung und Verifikation

Vor dem verbindlichen Schreiben: vollstaendiger Test mit realen14 Zeilen und
Auditereignissen, danach Datenbank-Rollback. Vor Apply unter Zeilensperren:
verschluesselte Sicherung aller14 Kopfzeilen und191 Positionen einschliesslich
Aenderungsplan, exklusiv geschrieben, synchronisiert, entschluesselt und
bytegleich verifiziert, Dateirechte0600. Kein Backupinhalt oder Secret in Git.

Private Datei auf dem Pi:
`/var/www/svs-laravel/shared/backups/expense-season-teams-20261103-0d89115aefd3dd3a.cuenc`

SHA256: `e867d772182112cc50a9fb07ec35d1e591e533c9bed479eadc15bad094a1e8ae`.
Nach Commit gegen die private Sicherung erneut READ ONLY verglichen: alle
anderen Kopfzeilenfelder sowie alle191 Positionen identisch,14 Auditereignisse.
Erneute Vorschau erkennt bereits erfolgte Korrektur mit passendem Auditbeleg.

Danach kompletter Saisonreferenz-Audit:0 Beleg-/Teamsaison-Widersprueche in
ClubUnio,0 verwaiste/mandantenfremde Saisonreferenzen,0 unerklaerte Mapping-
Abweichungen. Die14 Unterschiede zum unveraenderten Snapshot werden weiterhin
offen ausgewiesen, aber nur bei exakt passendem Vorher-/Nachher-Auditbeleg als
freigegebene Korrektur erkannt. Originale Vorpruefung bleibt separat erhalten.

Aggregierter Nachweis ohne Finanzinhalte oder Personennamen:
[Korrektur und Folgeaudit](evidence/expense-season-team-correction-20261103.json).
11 gezielte Reparatur-/Audittests mit135 Assertions erfolgreich: Mehrdeutigkeit,
fehlendes Ziel, geaenderte Revision, Backupfehler, Rollback und idempotenter Retry.

## Wiederholung und Rueckweg

CLI-only Werkzeug liegt ausserhalb des Webroots unter
`/var/www/svs-laravel/shared/expense-season-correction`:

```text
php deploy/correct-expense-season-teams.php --root=/var/www/svs-laravel/current --preview
```

Nur mit explizitem --rollback-test oder --apply und --actor=<bestehender-admin>
werden Schreibpfade betreten. Anwendung verlangt einen aktiven nichttechnischen
Vereinsadmin. Das Manifest ist exakt auf diese14 nativen/urspruenglichen IDs,
Belegsaisons, TeamIDs und Ausgangsrevisionen begrenzt. Fehlende/mehrdeutige Ziele,
Mischzustaende oder zwischenzeitliche Aenderungen brechen den gesamten Vorgang ab.
Bei bereits korrekt protokolliertem Zustand entsteht keine zweite Aenderung.

Kein Runtime-Release oder Cache-/Poolneustart fuer die Datenkorrektur erforderlich.
Die normale Bearbeitungsregel (kein Mannschaftswechsel bei vorhandenen Positionen)
wurde nicht aufgeweicht; das Offline-Werkzeug ist kein neuer Web-/API-Endpunkt.

Ruecknahme nur nach neuer fachlicher Freigabe, frischem Ist-Abgleich und gezielter
Rueckverknuepfung mit neuem Audit/Revision. Nicht komplette alte Belege/Positionen
aus dem Backup ueber spaetere Buchungen kopieren; bestehende Auditereignisse nicht
loeschen. Bei einer neuen Datenmigration Mapping anhand der Original-Beleg-IDs
erneut pruefen, keine fest codierten nativen IDs ungeprueft replayen. Ein Refresh
darf diese freigegebenen Korrekturen nicht blind durch den Snapshot ersetzen.
