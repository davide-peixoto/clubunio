# Funktionen, Sponsoring und Veranstaltungen: Kopie und Aktivstatus

Stand 16.10.2026. Im Mitgliederdossier bieten diese drei Untertabellen
Kopieren, Deaktivieren und Wiederherstellen als direkte Zeilenaktionen.
Inaktive Eintraege sind ueber den bestehenden Aktiv-Filter erreichbar.
Schluessel und andere Fachobjekte werden nicht um neue Aktionen erweitert.

Kopien bleiben beim Mitglied, erhalten eine neue ID/Revision und veraendern
die Quelle nicht. Funktion und Veranstaltung muessen neu ausgewaehlt werden;
beim Sponsoring muss die Laufzeit neu eingegeben werden. Qualifikationen,
Betrag und Bemerkungen werden soweit anwendbar vorbelegt. Ungueltige/inaktive
Lookups werden weiterhin vom Fachservice geprueft.

Aktivstatusaenderungen sind bestaetigungspflichtig, versionsgeschuetzt und
auditiert. Wiederherstellen prueft die bestehenden aktiven Dublettenregeln.
Keine physische Loeschung, keine Kopie fremder Mitglieds-/Mandantenschluessel.
Archivierte Mitglieder und entzogene Rollen bleiben gesperrt.

Migration: keine Schema-/Datenmigration. Vorhandene Fremdschluessel,
Revisionen, Aktivstatus und Qualifikationsverknuepfungen erhalten. Keine
Importe, kein DB-Refresh. PHPMaker DEV/PROD unveraendert; API/SaaS/Betrieb
nicht erweitert. Kein E-Mail-/Push-/Bexio-Versand.

Pruefung: DossierMaintenanceTest mit transaktionalen Testdaten, Kopien fuer
alle drei Typen, unveraenderte Quellen, Qualifikationen, Aktivstatuswechsel
und veraltete Revisionen. Bestehende Fachservice-Tests bleiben massgeblich.
Delta-Deployment auf aktuellem ClubUnio-Release mit erneuertem Config-,
Routen- und Viewcache; Ruecknahme auf den vom Installer genannten Vorgaenger.
