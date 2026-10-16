# ClubUnio: Ankuendigungen und Empfaengerauswahl

16.10.2026. Nur ClubUnio-Web, kein Eingriff in PHPMaker DEV/PROD,
API, SaaS oder Betrieb. Kein neuer Versandkanal und keine echte Rundnachricht.

## Befund und Delta

Die Empfaengerauswahl lud alle aktiven Konten beziehungsweise Mannschaften
in das Formular. Sie sucht nun serverseitig nach Anzeigename/Teamlabel,
maximal50 Treffer, stabile Sortierung nach Label und Schluessel. Beim Oeffnen
des Formulars keine volle Benutzer-/Mannschaftsliste. Gewaehlte IDs werden
separat und gebunden aufgeloest, bis zur bestehenden Auswahlgrenze500; dadurch
bleiben Labels ausserhalb der ersten50 Treffer und grosse Auswahlen erhalten.
Keine Passwort-/Token-/Kontaktdaten geladen, nur Schluessel und Anzeigename.
Rollen bleiben ein kleiner aktiver Hilfskatalog. Gruppenwechsel leert wie
bisher die Auswahl und initialisiert das Suchfeld mit einem gruppenspezifischen
Komponentenschluessel neu, damit keine alten Suchtreffer stehenbleiben.
Kein Request-uebergreifender Cache oder neue API.

Aktivfilter und Vereinszuordnung sind unveraendert. Die Auswahl ist weiterhin
nicht die definitive Erreichbarkeitspruefung: diese erfolgt ausschliesslich
frisch im Fachservice bei Vorschau und Veroeffentlichung, inklusive aktiver
Mitgliedschaft, genehmigter Links, Eltern-/Altersregeln und Teamzuordnung.
Neue Hilfsklasse AnnouncementChoices wird nur im vorhandenen Webformular
verwendet; Seiten-/Livewire- und Fachberechtigungen bleiben unveraendert.

Die Statusbezeichnungen wurden fuer Badge, Farbe und Filter mehrfach je Zeile
abgefragt. Jetzt einmal beim jeweiligen Tabellenaufbau laden und fuer alle
drei Darstellungen verwenden. Neue Requests laden neu; keine veralteten
Bezeichnungen oder Rechte aus Session-/Servercaches. Gelesen-Zaehler weiter
ueber vorhandene vereinsgebundene SQL-Unterabfrage, kein N+1 pro Datensatz.

## Zustellung und Sicherheitsgrenzen

Bereits vorhanden und bewusst unveraendert: maximal5000 erreichbare Empfaenger,
Vorschau mit maximal20 Namen, actor-/versionsgebundener Token fuer15 Minuten,
erneuter Plan-/Vorlagen-/Empfaengerhash vor Veroeffentlichung. Deduplizierte
Empfaenger,250er-Inserts, Empfaengersnapshot und Notifications in einer
Transaktion, Audit und Rueckzug erhalten. Keine Teilveroeffentlichung,
keine neue Queue und kein zeitversetzter Versand. Keine E-Mails oder FCM.
AnnouncementService und Berechtigungsdateien nicht Bestandteil dieses Deltas.

## Pruefung

AnnouncementTest ergaenzt:65 Konten,50er-Suche,65 separat aufgeloeste Labels,
frischer Aktivstatus, fremde Vereine, ungueltige IDs, Teamaktivstatus,
maximale Labelauswahl, Statusqueries unabhaengig von10 Tabellenzeilen,
Speichern von Benutzer-/Team-Entwuerfen ueber Livewire ohne Notifications.
Bestehende Regressionstests fuer Vorschau-Drift/Tokenablauf/falschen Bearbeiter,
Eltern/Spieler/Staff/TL, Austritt, Fremdzugriff, Entwurfsrevision, Kopie,
interne Veroeffentlichung und Rueckzug werden mit ausgefuehrt. Testdaten
jeweils in isoliertem Verein mit Rollback, keine echten Empfaenger.

CLI tools/clubunio-announcement-performance-check.php vergleicht die bisherige
volle Auswahlliste mit begrenzter Suche fuer SV Sissach. Nur lesend auf exakt
gepruefter ClubUnio-DB; Ausgabe nur Zeilen-/Byte-/Abfragezahlen und Dauer,
keine Namen, IDs, Bindings oder Nachrichtentexte. Timing ist eine Stichprobe,
kein Lasttest oder garantierte Antwortzeit. Keine Daten werden veraendert.

Ergebnis:13 Regressionstests/110 Assertions plus Test mit263 internen
Empfaengern/7 Assertions erfolgreich; genau zwei Notification-Inserts fuer
250er-Pakete, vollstaendiger deduplizierter Snapshot. Keine E-Mails/HTTP-
Zustellung; alles Rollback. Nach Komponenten-Key-Anpassung zwei Web-/Versand-
Regressionstests mit29 Assertions erneut erfolgreich. Pint/Syntaxchecks OK.

Pi-Messung bei aktuell37 aktiven Konten: vorher37 Optionen/823 Bytes/32ms,
begrenzter Abruf37/823/24ms, jeweils eine Query. Keine bedeutende Reduktion
bei diesem kleinen Bestand behauptet. Das Formular laedt die Konten jetzt
erst bei Suche. Die65-Konten-Fixture belegt50 Treffer und65 gewaehlte Labels.
Liste mit10 Ankuendigungen: Statuskatalog hoechstens dreimal im gesamten
Livewire-Test statt Badge-/Farbabfrage pro Zeile; je Tabellenaufbau einmal.

Release 20261016-clubunio-announcement-performance-v2; direkte Rollbackbasis
20261016-clubunio-announcement-performance, urspruenglich form-performance.
Routen-/Tenant-/Viewcachechecks erfolgreich. Keine Schemaaenderung.
Angemeldete Chrome-Pruefung: Einzelbenutzersuche und Auswahl erfolgreich;
Gruppenwechsel verwirft Auswahl/Suchtreffer; Mannschaftssuche liefert passende
Labels. Dialog ohne Speichern abgebrochen, keine Konsolenfehler. Keine echten
Nachrichten/Entwuerfe erstellt. Bisherige PHPMaker-Umgebungen unveraendert.

## Migration und Ruecknahme

Keine DB-/Indexmigration, keine neuen Parameter und kein Datenimport.
Vorhandene target_ids, Empfaengersnapshots, Gelesen-Zustand und Audit behalten.
Resource und neue Hilfsklasse gemeinsam ausrollen, Viewcache erneuern.
Ruecknahme ueber vorherigen ClubUnio-Release-Symlink ohne Datenrollback.
Parallele Rollenentwicklung nicht ungeprueft mitnehmen. Keine Secrets,
Uploads, .env oder vendor in Git/Delta.
