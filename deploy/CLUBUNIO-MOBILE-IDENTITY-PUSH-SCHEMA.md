# Gezielte Vorbereitung400/500/600/700 (04.11.2026)

Noch keine Anwendung. `prepare-mobile-identity-push-schema.php` ist nur fuer die
exakte aktive DEV-Basis20261104-clubunio-mobile-identity-push-foundation und die
bekannte ClubUnio-Datenbank freigegeben. Identity/Mail, Push/Delivery, Challenge
und Keybox inklusive Betriebs-/Widerrufsfreigaben muessen geschlossen sein;
Providerregistries/Allowlisten bleiben leer. Kein800/900/1000, Import, Refresh,
down, Prozessabbruch oder Provideraufruf.

Die feste gehashte Blueprintdatei beschreibt vier gehashte Migrationen,14 neue
Tabellen mit exakten Spalten/Primaerschluesseln und30 Fremdschluesseln. Neun
Eltern-/Ledgertabellen werden mit Metadaten, Indizes, Constraints und gesamten
Zeilen als gezaehlter/gehashter verschluesselter0600-Stream gesichert. Nur eine
neue Sicherung unter shared/backups; getrennte export/verify-backup/apply-
Stufen, maximal eine Stunde alt, fester Eigentum-/Inode-/Pfad-/Kettenschutz.

Apply verwendet eine einzige Transaktion, Advisorylock und nichtwartende Locks
aller Eltern/Ledger; zwei Sekunden Lock- und15 Sekunden Statementlimit. Jede
vorhandene Zieltabelle, Ledgerzeile oder vorbereitete Session-Unique-Ergaenzung
bricht ab. Vor Anwendung muessen alle Eltern und Ledger mit der Sicherung
uebereinstimmen. Danach muessen alle14 Tabellen leer,30 FKs validiert/nicht
aufschiebbar und die exakten tenantgebundenen Primaerschluessel/PUBLIC-Sperren
vorhanden sein. Kein bestehendes Konto oder Sitzung wird umgeschrieben.

Einzige erlaubte Eltern-Metadatenaenderung ist der in500 beschriebene
mobile_sessions_id_account_unique auf exakt(id,account_id), samt zugehoerigem
normalen Uniqueindex. Definition und Eigenschaften werden exakt geprueft;
alle vorherigen Felder/Indizes/Constraints und Zeilendigests bleiben identisch.
Der Ledger bekommt genau vier neue Zeilen in einem Batch. Jeder Fehler rollt
DDL und Ledger zurueck. Eine gesicherte Datei ist kein Vollrestore-Nachweis.

Lokale Syntax-/Quellpruefung ist Vorbereitung; erfolgreicher Linuxlauf,
Sicherungsdigest und Anwendung werden erst nach wirklicher Ausfuehrung in PI.md
und CLUBUNIO-MIGRATION.md festgehalten. Externe SMTP/FCM/APNs-/Igloo-Konfiguration
und physische Abnahme sind separate, weiterhin offene Voraussetzungen.
