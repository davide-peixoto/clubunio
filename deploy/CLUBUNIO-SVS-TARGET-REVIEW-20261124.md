# SV Sissach: Zielvertrag152, 24.11.2026

Der Betreiber hat die Anpassung des Importvertrags, erneute Abnahme auf einer frischen PROD-Kopie und anschliessende Wiederaufnahme des einseitigen Delta-Abgleichs freigegeben. Richtung bleibt PHPMaker PROD nach ClubUnio PROD, Verein sv-sissach. Keine Quellschreibzugriffe, Schluessel-/Passwortimporte, Kontofreischaltungen, Zustellungen oder Providerjobs.

## Gepruefte Schemaaenderungen

Eine Sicherung vor dem letzten erfolgreichen Delta reproduziert den bisherigen Vertrag exakt:147 Migrationen und unveraenderter Spaltenhash. Eine neue PROD-Sicherung wurde auf eine isolierte Datenbank restauriert. Der aktuelle Stand hat152 Migrationen. Keine bestehende Spalte des Importmappings hat sich geaendert.

- 2026_11_23_000010_parent_access: drei nullable Einwilligungsfelder in account_member_links, neue account_parent_email_proofs sowie Elternmailindizes. Die drei Felder und die Beweiszeilen sind lokale ClubUnio-Daten. Bestehende Inserts erhalten NULL; native Einwilligungsregeln bleiben wirksam.
- 2026_11_23_000160_mobile_keybox_emergency: native Notfall-/Fehlerfelder und Indizes, erweiterte Statusregel. Keine Tabelle im Quellmapping betroffen.
- 2026_11_23_000170_chat_translation_languages: laengeres Sprachfeld und erweiterte Sprachregel; native Chatpraeferenzen sind kein Importziel.
- 2026_11_23_000180_support_help_topics: erweiterte native Themenregel, kein Importziel.
- 2026_11_23_000200_tenant_ai: fuenf native KI-Tabellen einschliesslich privater Konfiguration; kein Importziel.

Nur deploy/svs-target-schema-v2.json wird auf den exakt geprueften Zielstand aktualisiert. Dateiname bleibt wegen Mappinggeneration2 erhalten. Quellvertrag, Feldmapping, Projektion, Delta-Entscheidungen und Mappingversion bleiben unveraendert; keine Baseline-Umschreibung. Zukuenftige unbekannte Schemaaenderungen stoppen weiterhin den Lauf.

## Abnahme

Privates Review: /root/clubunio-svs-production/reviews/20261124-schema. Dort liegen frische Sicherung, Schema-/Constraintvergleich, vorherige Vertraege/Manifeste und technische Testberichte. Probelauf6eae0305b71442ab975c6960938c9332:7 Inserts/221 Updates,0 Konflikte; Wiederholung0 Inserts/0 Updates. Livefreigabe war waehrend dieses Laufs entfernt.

Werkzeug tools/test-svs-schema-extension.php prueft auf den isolierten Datenbanken lokale Eltern-Einwilligung und Emailproof, neun native Tabellen, Kontostatus/Passwort, Winterperiode, unabhaengige Feldaenderungen sowie Konfliktrollback. Beide aeusseren Testtransaktionen werden zurueckgerollt. Negative CLI-Pruefungen bestaetigen Ablehnung von PROD ohne Permit und Ablehnung des alten147-Schemas.

Die beiden PROD-Sprachdateien haben gegenueber dem zuletzt freigegebenen Runtimebeleg jeweils29 neue Schluessel, keine geloeschten oder veraenderten bisherigen Uebersetzungen. Die History-Runtimedateien sind sonst unveraendert. Sprachpruefung:3100 Schluessel DE/EN, keine Platzhalterabweichung. Nach erfolgreicher Abnahme werden ausschliesslich diese zwei Runtimehashes und der Importreleasehash neu gebunden.

## Betrieb und Rueckweg

Bestehender12-Stunden-Agent und Root-Permitablauf bleiben erhalten. Die Livefreigabe wird erst nach allen isolierten Pruefungen wieder an den neuen Release gebunden. Der anschliessende echte Lauf erstellt erneut eine frische Sicherung, restauriert eine eigene Kopie, prueft Merge und Wiederholung und fuehrt eine Rollback-Vorschau auf PROD aus. Erst dann atomarer Commit.

Rueckweg: Livefreigabe entfernen; bisherige Vertrag-/Release-/Runtime-Dateien aus dem privaten Review wiederherstellen. Niemals Daten oder Feldbaselines manuell zuruecksetzen. Ein alter Vertrag bleibt mit152 Migrationen bewusst gesperrt; Datenbankrollback nur separat geprueft. Lokale Konfiguration, neue Fachrechte und fremde Workspaceaenderungen bleiben erhalten.