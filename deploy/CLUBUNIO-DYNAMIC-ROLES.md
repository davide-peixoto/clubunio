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
- Filament-Editor als noch nicht registrierte Resource vorbereitet:
  Modulabschnitte mit Funktionsauswahl, Ohne-Rechte-/Nur-Lesen-Vorlagen,
  Kopieren, Suche, Aktivstatus, Revision und Pflichtbegruendung.
- Editor-Datenkonvertierung und Kopie getestet; manipulierte Funktionen
  werden nicht still verworfen, sondern vom Schreibservice abgelehnt.
- Teststand: AccessProfileTest, 6 Tests / 49 Assertions, vollstaendig
  transaktional mit Rollback. Keine dauerhaften Rollen- oder DB-Aenderungen.

## Ergebnis der Integrationspruefung

Die bestehende Methode Account::canAccessTenant wird sowohl fuer Lesezugriffe
als auch von zahlreichen Schreibservices verwendet. Die blosse Erweiterung
dieser Methode um neue Profile waere daher keine sichere Rollenverwaltung.
Auch Portal-Fallbacks in MemberAccessService sowie direkte Livewire-Methoden,
Downloads und Fachcontroller muessen in die explizite Pruefung aufgenommen
werden. Sichtbare/ausgeblendete Buttons allein reichen nicht.

Eine Zuordnung als office mit zusaetzlichem Profil ist ohne weiteren Schutz
ebenfalls ungeeignet: Nach einem Rollback auf alten Code wuerde das Profil
ignoriert und das Konto haette volle Office-Rechte. Die Aktivierung benoetigt
eine eigene, auch fuer alte Releases gesperrte Systemrolle oder einen gleich
starken getesteten Rollback-Schutz. Der experimentelle office-basierte
Zuordnungsentwurf wurde deshalb verworfen; es gibt keine Migration70 und
keine Profilzuordnung auf echten Konten.

Die Resource bleibt absichtlich unregistriert. Keine halbfertige
Rechteverwaltung wird als verwendbar auf ClubUnio ausgerollt.

## Vor Aktivierung zwingend

1. Vorbereiteten Filament-Editor integrieren und visuell pruefen;
   betroffene Benutzer und wirksame Rechte zusammen anzeigen.
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
