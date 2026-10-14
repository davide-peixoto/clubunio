# PostgreSQL / mandantenfaehiges Zielschema

Stand: 11.10.2026. Erste technische Bestandsaufnahme, keine Migration.

Nachtrag: Benutzer hat anschliessend eine neue ClubUnio-Datenbank bereitgestellt
und PROD als Quelle festgelegt. Verifizierte Staging-Kopie erstellt, siehe
[CLUBUNIO-PROD-SNAPSHOT.md](CLUBUNIO-PROD-SNAPSHOT.md). Untenstehende DEV-Analyse
bleibt historischer Ausgangspunkt; kein abgeschlossener Anwendungsschema-Umbau.

## Geprueft

Live-Metadaten der bestehenden SQL-Server-DEV-Verbindung ueber die SELECT-only
Sperre des Piloten. VIEW DEFINITION ist vorhanden. Keine Nutzdaten oder
Parameterwerte exportiert, keine SQL-Schreiboperationen. PROD nicht abgefragt.
Wiederholung: `php tools/schema-inventory.php` aus laravel/.

- 84 Tabellen, alle mit Primaerschluessel, 21 Views.
- 51 Fremdschluessel, davon 36 is_not_trusted, keiner deaktiviert.
  Untrusted bedeutet nicht automatisch verwaiste Daten; Konsistenztests fehlen noch.
- 41 Prozeduren: 33 DSS-Synchronisationsobjekte, 7 Diagrammwerkzeuge,
  spWhatsAppSyncMitglieder. Alte Technik nicht pauschal portieren.
- 13 Trigger: 9 DSS, 2 WhatsApp, TR_MitgliederAnhaenge_Quittungen und
  TR_MitgliederQuittungen_Immutable. Quittierungs-/Unveraenderlichkeitsregeln
  muessen vor dem Umstieg fachlich erhalten und getestet werden.
- 2 skalare Funktionen: fn_diagramobjects und fnWhatsAppNormalizePhone.
- Keine mit tenant/mandant benannten Spalten in der Metadatenpruefung.
- Typen u.a. datetime2, bit, nvarchar, ntext, uniqueidentifier, timestamp.
  SQL-Server-timestamp ist eine binaere Versionsmarkierung, kein Datum.
- iniApp, iniBexio und iniWhatsApp haben gleiche Schluessel/Wert-Struktur.
  iniParameter ist anders aufgebaut: SaisonID, SommerWinter, StatusPlatz1/2/3.
  Mehrere Views referenzieren iniParameter unmittelbar.
- Controller-Stichprobe: TOP, DATEADD, ISNULL, OUTER APPLY und dbo-Verweise,
  insbesondere in TeamPlanningAppController und TrainingPlannerAppController.
- Lokales CLI-PHP hat pdo_sqlsrv, aber noch kein pdo_pgsql geladen.

## Bewertung

PostgreSQL ist ein plausibles Ziel fuer den bewusst neu aufgebauten Backendkern.
Kein Drop-in-Ersatz: Views, SQL-Dialekt, Trigger, Zeit-/Dezimaltypen,
Gross-/Kleinschreibung und Sequenzen nach ID-Import brauchen explizite Tests.
Noch keine vollstaendige Analyse saemtlicher Routinen, Nutzdaten, Kollationen,
Indizes oder PROD-Abweichungen. Eine belastbare Aufwandsschaetzung folgt daraus
noch nicht. Hosting-TLS, Rollen, Backup/Restore und Kapazitaet bleiben zu pruefen.

## Zielentwurf (noch nicht implementiert)

- tenants; globale Benutzer plus tenant_user mit vereinsbezogenen Rollen.
- tenant_id fuer vereinseigene Daten; globale Laender separat.
- Mandantenbezogene eindeutige Schluessel und zusammengesetzte Fremdschluessel
  verhindern auch auf DB-Ebene vereinsuebergreifende Verknuepfungen.
- Eine typisierte tenant_settings-Tabelle mit Bereich, Schluessel, Aktivstatus,
  Wert bzw. verschluesseltem Geheimnis und Audit; Schluesselverwaltung ausserhalb
  der DB. Explizite Freigabeliste fuer oeffentliche App-Einstellungen.
- Platzstatus sind fachliche Zustandsdaten: nicht unbesehen als Konfiguration
  behandeln. Saisonbezug ebenfalls mit Fremdschluessel absichern.
- IDs und externe Referenzen erhalten/abbilden; bestehende API-Vertraege ueber
  Adapter erhalten. Dateipfade, Jobs und Caches ebenfalls mandantentrennen.
- Quell-DB bleibt unveraendert. Vor Import Duplikate/verwaiste Beziehungen pruefen;
  danach Mengenabgleich, Summenabgleich und Rollen-/Mandantentrennung testen.

## Zugangsdaten

Vorbereitet: `C:/Projects/SVS/backend/laravel/.env.postgresql.local`.
Git-Ausschluss mit git check-ignore bestaetigt. Nicht in Tickets/Chats kopieren.

Felder: PGHOST, PGPORT (5432), PGDATABASE, PGUSER, PGPASSWORD,
PGSSLMODE (verify-full), optional PGSSLROOTCERT (CA-Dateipfad).
Werte mit Sonderzeichen passend fuer dotenv quotieren.

Eine NEUE, leere, gehostete Backend-Entwicklungsdatenbank verwenden, NICHT die
www2-CMS-Datenbank. Dedizierter Benutzer; fuer spaetere Migrationen DDL-Rechte
im eigenen Schema, spaeter getrennte eingeschraenkte Runtime-Rolle.
TLS-Zertifikatspruefung nicht pauschal deaktivieren.

Diese Datei wird aktuell absichtlich NICHT vom laufenden Laravel-Piloten geladen.
Nach Befuellung zuerst Verbindung/TLS/Rechte lesend testen und pdo_pgsql
bereitstellen. Erst nach Zielschema-Freigabe Migrationen erstellen/ausfuehren.
pgvector ist fuer diese Umstellung nicht erforderlich.
