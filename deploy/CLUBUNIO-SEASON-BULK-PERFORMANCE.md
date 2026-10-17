# ClubUnio: Saisonuebernahme und Mehrfachaktionen

17.10.2026. Nur ClubUnio-Web. API, SaaS, Betrieb und bestehendes PHPMaker
DEV/PROD bleiben unveraendert. Keine Bankzahlung oder externe Zustellung.

## Optimierung

Saisonvorschau gruppiert Ziele nach Mannschaft und Zuordnungen nach
TeamSaisonID statt die gesamten Collections pro Mannschaft erneut zu filtern.
Duplikatpruefung verwendet gruppierte Mitglieder und aktive IDs als Sets.
Mitglieder fuer Spieler/Staff/TL gemeinsam laden, nicht pro Zuordnungstyp;
weiterhin vollstaendige Modelldaten fuer den unveraenderten Fingerabdruck.
Es werden nur Zuordnungen der ausgewaehlten Quellen und dazugehoerigen
Zielmannschaften geladen, keine unbeteiligten Zielzuordnungen. Die vollstaendige
Zielmannschaftsliste bleibt Bestandteil des bestehenden Vorschautokens.

Der Quellmannschafts-Select liest nur ID/Label statt ganzer Modelle plus
unbenutzter Teamrelation. Auswahl, Gruppenfilter und Vorschauverhalten bleiben.

Mehrfachaktionen fuer Zuordnungen und Dossierobjekte laden die maximal100
ausgewaehlten, exakt zum Dossier/Verein gehoerenden Datensaetze einmal mit
Zeilensperren in ID-Reihenfolge. Fehlende/fremde Auswahl bricht mit404 ab.
Snapshotvergleich bleibt pro Zeile. Die bestehenden Fachservices pruefen
danach jeden Schreibvorgang frisch, mit Versionsschutz, Referenzen, Rechten
und Audit. Kein Bulk-UPDATE als Ersatz fuer Fachregeln und kein Rechtecache.
Alle Aenderungen bleiben in derselben Transaktion; ein Konflikt rollt alles
zurueck. Saisonuebernahme weiter maximal1000 Zeilen, frisch gerechneter HMAC-
Plan beim Anwenden, Konflikte/Austritt/inaktive Funktionen/Ziele unveraendert.

## Tests und Messung

WorkspaceEditingTest erweitert: ein gemeinsamer Mitglieder-Read plus aktiver
Mitgliedercheck statt drei Relations-Reads, HMAC-Referenzaufbau unveraendert,
gebuendelter Lock-Read bei drei ausgewaehlten Zuordnungen. Bestehende Tests
pruefen Duplikate, Teilauswahl, inaktive Ziele, veraltete Vorschau, Rollenentzug,
fremde Elternobjekte und atomaren Rollback bei einer veraenderten Auswahl.
DossierMaintenanceTest deckt zusaetzlich Wiederherstellen/Deaktivieren von
Funktionen, Sponsoring und Veranstaltungszuordnungen ab.

CLI tools/clubunio-season-performance-check.php: genau gepruefte ClubUnio-DB,
synthetischer eigener Verein mit Officekonto, vier Teams/80 Mitgliedern,
Spielern/Staff/TL. Nur Vorschau, keine Uebernahme, keine echten Vereinsdaten
geaendert. Gesamte Fixture zurueckrollen; PG-Sequenzen koennen Luecken haben.
Ausgabe nur aggregierte Zeilen-/Query-/Zeitwerte, kein Token, keine Namen,
IDs oder SQL-Bindings. Keine ausgehenden API-/Mail-/Push-Aufrufe.

Pi-Basis:96 Vorschauzeilen,15 DB-Abfragen/4 Mitglieder-Reads,497ms insgesamt,
423ms DB. Stichprobe, kein Lasttest oder garantiertes Antwortzeitversprechen.

Pi nach Delta: gleiche96 Zeilen,13 Abfragen/2 Mitglieder-Reads,424ms insgesamt,
352ms DB. Beide Fixtures vollstaendig zurueckgerollt. Weniger wiederholte
Collection-Scans und Modellhydration; Sicherheits-/Schreibabfragen unveraendert.
Bei drei Bulk-Zuordnungen genau ein gebuendelter Snapshot-Lock-Read statt drei;
die nachfolgenden Fachservices laden und pruefen weiterhin pro Aenderung.

Bestandslauf WorkspaceEditingTest/DossierMaintenanceTest:28 erfolgreich,
ein veralteter Navigationstest erkannte den bereits manuell registrierten
Trainingsplan-Link als zusaetzlich. Erwartung gezielt um diesen Link ergaenzt,
erneut erfolgreich (1/9). Keine Produktnavigation geaendert. Zwei neue Tests
fuer HMAC-/Lese-Buendelung separat erfolgreich, zusammen mit fuenf bestehenden
Transfer-/Bulk-/Rollenentzugspruefungen (7 Tests/44 Assertions). Insgesamt31
verschiedene Tests nach Korrektur erfolgreich, in getrennten Laeufen.
Pint, PHP-Syntax, View-/Routen-/Tenantchecks und Profil-Aktivierungscheck OK.
Release 20261017-clubunio-season-bulk-performance aktiv; Rollbackbasis
20261016-clubunio-access-profiles. Login HTTP-Pruefung erfolgreich.
Angemeldete manuelle Sichtpruefung noch offen: Browser zeigt Loginformular;
Livewire-Dialoge inklusive Auswahl/Bestaetigung automatisiert geprueft.

## Migration und Rollback

Keine Schema-/Indexmigration oder Datenuebernahme. Bereits geoeffnete
Vorschautokens behalten ihre Bedeutung; Referenztest prueft die HMAC-Struktur.
Bestehende Quell-/Ziel- und Mitglieder-IDs, inaktive Zuordnungen und Audit
unveraendert lassen. Dateidelta auf aktueller ClubUnio-Version inklusive
dynamischer Rechte, Viewcache erneuern. Ruecknahme per vorigem Release-Symlink;
keine Datenbank zuruecksetzen und keine Profilrechte zurueckrollen.
