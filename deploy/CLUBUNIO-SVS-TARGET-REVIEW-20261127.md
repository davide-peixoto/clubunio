# SV Sissach: Zielvertrag157, 27.11.2026

Freigabe: bekannte Importblockade pruefen, beheben und anschliessend Delta laden. Richtung weiterhin ausschliesslich PHPMaker PROD nach ClubUnio PROD, sv-sissach. Keine Kontofreischaltung, Schluesseluebernahme, Zustellung oder Providerjobs.

## Schema und Sicherheit

Die Sicherung vor Laufd78c27ceb8534ae6a9fefc6bc8f3d85a reproduziert den bisherigen155er-Vertrag exakt. Eine neue PROD-Sicherung wurde separat restauriert. Aktuell157 Migrationen; keine bestehenden Importfelder oder bisherigen Constraints entfernt/veraendert.

- 2026_11_24_030000_clubhouse: sieben native Tabellen fuer Einstellungen, Rechte, Oeffnungszeiten/Ausnahmen, Menustatus, Menus und Audit. Zusaetzliche native Clubhaus-Menueoption bleibt lokal.
- 2026_11_25_010000_keycloak_logout_watermarks: persistente Widerrufsuntergrenzen je Konto.
- Sicherheitsrelease: security_replay_cache und security_replay_locks im Runtime-Schema, ebenfalls ausschliesslich lokale Sicherheitsdaten.

Alle zehn Tabellen bleiben ausserhalb des Quellmappings. Bestehender Quellvertrag, Feldmapping, native IDs und dauerhafte Baselineversion bleiben unveraendert. Nur der exakte Zielvertrag wird aktualisiert. Die neuen Laufzeitrollen, Unix-/Dateirechte, MFA-/Logout-Logik und privaten Konfigurationen werden nicht geaendert. Bestehender root-privater Importer bleibt vom gehaerteten Webprozess getrennt. Die History-Runtime entspricht weiterhin allen freigegebenen Hashes.

## Abnahme

Private Reviewakte: /root/clubunio-svs-production/reviews/20261127-schema. Sicherungen, Spalten-/Constraintvergleich, vorherige Manifeste und technische Nachweise bleiben dort privat. Livefreigabe waehrend der ersten Probe entfernt. Negative Tests bestaetigen: PROD ohne Permit abgelehnt, alter155er-Zielvertrag abgelehnt, absichtliche Betrags-Praezisionsabweichung auf157er-Kopie abgelehnt.

Erweiterter Integrationstest:24 native Tabellen, einschliesslich synthetischer Clubhaus-Einstellungen/-Rechte/Oeffnungszeiten/Ausnahmen/Menu/Audit und Logout-Untergrenze. Vorhandene Aufgaben/VEO-, Eltern-/Kontostatus-/Winterplanpruefungen bleiben erhalten. Unabhaengige Feldaenderungen werden zusammengefuehrt; echte Konflikte rollen den ganzen Merge samt Ledger zurueck. Alle synthetischen Writes erfolgen nur auf isolierten Kopien und werden zurueckgerollt.

Der anschliessende echte Lauf verwendet erneut frischen Quelldump, neues PROD-Backup, Restore, Merge/Wiederholung auf Kopie, root-eigenen Permit, Rollback-Vorschau und atomaren Commit. Unbekannte kuenftige Aenderungen stoppen weiterhin. Der12-Stunden-Agent und dessen Einbahnrichtung bleiben bestehen.

## Migrationsplan und Rueckweg

Keine PROD-DDL-/Baseline-/Quellmappingaenderung durch diese Korrektur. Vorhandene Clubhaus-Publikationen, Rechte und Sicherheitsmarkierungen bleiben erhalten. Keine Abschwaechung der Sicherheitsmassnahmen aus dem Release25.11.2026. Anwendungscode oder prod-Ref werden fuer diese Importer-Korrektur nicht promoviert; aktive RunCloud-Webhooks werden nicht durch einen unnoetigen prod-Push ausgeloest. Vertrag, Test und Nachweise werden im eigenen Reviewbranch versioniert.

Rueckweg: Livefreigabe entfernen und alte Vertrags-/Release-Dateien aus der Reviewakte wiederherstellen. Der155er-Vertrag bleibt gegen157 bewusst gesperrt. Niemals Widerrufsmarkierungen, Replay-Schutz oder Feldbaselines loeschen/zuruecksetzen. Private Dumps und Nachweise behalten; nur eigene vier Reviewdatenbanken nach Abschluss entfernen.
## Erfolgreicher Live-Lauf

27.11.2026 um15:57:38 Uhr Europe/Zurich: Lauf12dd1540154b4743a53a1d451769b3e6 erfolgreich:5 Inserts/222 Updates/37697 unveraendert/0 missing_review/0 Konflikte. Ledger11->13; Mappingversion weiterhin e3a5c7251a07a870f7b5c138620b0920238be8469eeef291ff62c74bf9f8928b. Wiederholung0 Writes. Live-Nachkontrolle: Clubhaus-Einstellungen/-Rechte/Oeffnungszeiten/Ausnahmen/Status/Menus/Audit, Aufgaben/Verlauf/Erfassungsbefehle/Erwaehnungen, VEO-Korrekturen, Elternnachweise/-Einwilligungen, aktive Konten/Passwoerter, Winterperiode und Trainingsplaene unveraendert. Bestehende Logout-Untergrenzen nie abgesenkt. Replay-Cache/Locks auf der isolierten Kopie strikt unveraendert; live werden sie regulaer durch die Sicherheitsruntime verwendet. MFA, Runtime-Dateihashes, App-Konfiguration und Rollen nicht veraendert.

Vier eigene Reviewkopien werden nach Nachweissicherung entfernt; Sicherungen bleiben privat erhalten. Der12-Stunden-Agent ist mit exakt157er-Vertrag wieder bereit. Quelle bleibt ausschliesslich lesend, native IDs/Baselines unveraendert. [Sanitisierter Nachweis](evidence/svs-target-review-20261127.json).