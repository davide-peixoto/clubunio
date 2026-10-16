# Dynamische Rollen nach Modul und Funktion

Stand 16.10.2026: IN ARBEIT, nicht deployt, nicht zuweisbar.

## Vorbereitet

- Separate vereinsgebundene Profildefinitionen in access_profiles.
- Stabiler, expliziter Katalog mit Modul- und Funktionsschluesseln.
- Nur-Lesen-Vorlage enthaelt ausschliesslich view, keine Downloads/Exporte.
- Schreibfunktionen setzen das Anzeigerecht voraus.
- Speichern nur durch frischen Admin, mit Revision, Audit und Vereinsbindung.
- Migration ist vorbereitet, nicht dauerhaft ausgefuehrt.
- Transaktionale Tests: Profil speichern/Audit, Nur-Lesen-Rechte,
  ungueltige Rechte, fehlende Abhaengigkeit, Revision und Adminentzug.

## Vor Aktivierung zwingend

1. Profilverwaltung als Filament-Modul (Vorlagen, Kopieren, Suche,
   aufklappbare Module, Abhaengigkeiten, betroffene Benutzer).
2. Profilzuordnung getrennt von bisherigen Systemrollen; letzter Admin
   bleibt geschuetzt. Keine pauschale Gleichstellung mit office.
3. Explizite Berechtigungen in Fachservices, Policies und Web-Endpunkten.
   canAccessTenant wird heute auch fuer Mutationen verwendet und darf
   nicht einfach fuer Nur-Lesen-Profile freigeschaltet werden.
4. Untertabellen, Livewire-Methoden, Uploads, Downloads, Exporte,
   Bexio-/Mail-/Push-Aktionen und Dashboard auf Umgehungen pruefen.
5. Datenumfang separat behandeln. Keine Erweiterung vorhandener
   Mitglieds-/Mannschaftsfilter durch ein neues Profil.
6. Negative Tests mit direktem Aufruf, manipulierten IDs, Rollenentzug,
   veralteten Browser-Tabs und fremden Vereinen; visuelle Abnahme.
7. Erst danach Migration, ClubUnio-Deployment, Commit und Sync.

PHPMaker DEV/PROD, API, SaaS und Betrieb bleiben unveraendert.
Die vorbereiteten Profile sind noch KEINE wirksamen Zugriffsrechte.
