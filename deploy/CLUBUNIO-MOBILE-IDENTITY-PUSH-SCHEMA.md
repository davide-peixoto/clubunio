# Gezielte Vorbereitung400/500/600/700 (04.11.2026)

Bereitstellung: `tools/package-mobile-identity-push-dev.py` verwendet den
festgehaltenen Produktsnapshot d1dc1e5 fuer alle App-/Config-/Routen-/Schema-
und Composerdateien. Laufende800/900/1000-Arbeit wird nicht mitgenommen.
Zieldateien werden gegen den aktiven packages-v1 und bekannte Git-Historie
geprueft. Composerlock darf nur google/auth1.55.3, firebase/php-jwt7.2.1 und
psr/cache3.0.0 addieren; keine bestehenden Paketversionen entfernen/aendern.
Der Installer verwendet den Lock ohne Plugins/Scripts und aktiviert erst nach
alten Modul-/Account-/Planungsgates und vier neuen geschlossenen Routengates.
Release-Env und alle bisherigen Modulflags bleiben unveraendert.

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

DEV-Vorbereitung: Foundation d68874d ist nach allen bestehenden/geschlossenen
Gates aktiviert. Erster Apply wurde durch Spaltenvergleich gestoppt und komplett
zurueckgerollt; nachfolgende Fresh-/Sicherungspruefung bestaetigt unveraenderte
Eltern und fehlende Zieltabellen/Ledger. In der Blueprint standen einige
Constraintausdruecke faelschlich unter Spalten. Nur die Blueprint korrigiert,
Migrationen und FKs unveraendert; jeder Spaltenname wird jetzt vorab validiert.

Korrigierter versionierter Operatorrunner liegt separat unter
shared/schema-mobile-identity-push-v2/ (Helper und fest gehashte Blueprint).
Er bootstrapped ausschliesslich die weiterhin exakt gebundene aktive Foundation;
die unveraenderliche Runtime wird nicht nachtraeglich editiert. Die vorhandene
Elternsicherung bleibt nur nach erneutem vollstaendigem Vergleich verwendbar.
Erfolgreiche Anwendung wird weiterhin erst nach wirklichem Nachweis dokumentiert.
