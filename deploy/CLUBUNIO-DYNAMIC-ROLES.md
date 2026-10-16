# Dynamische Rollen nach Modul und Funktion

Stand 16.10.2026: IN ARBEIT, nicht deployt, produktive Zuordnung gesperrt.

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
- Benutzerzuordnung im Admin-Service und im Formular vorbereitet. Eigene
  Systemrolle `profile`, keine Gleichstellung mit `office`, kein Portalzugang.
  Zuordnung nur mit aktivem Profil desselben Vereins; Audit/Revision und
  Schutz des letzten Administrators bleiben erhalten.
- Migration70 vorbereitet: zusammengesetzter Vereins-/Profil-Fremdschluessel
  und DB-Check fuer zulaessige Kombinationen. Die neue Systemrolle wird
  ausdruecklich INAKTIV angelegt und ist daher nicht auswaehlbar.
- ModulePermissionService prueft einzelne Modul-/Funktionsrechte frisch,
  inklusive deaktivierter Konten/Profile, Entzug, Anzeigerecht-Abhaengigkeit
  und unbekannter Rechte. Noch NICHT in die Fachservices eingebunden.
- Teststand: AccessProfileTest und ModulePermissionTest 13 Tests/88 Assertions;
  bestehende Benutzerverwaltung 5 Tests/40 Assertions. Alle transaktional mit
  Rollback. Keine dauerhaften Rollen- oder DB-Aenderungen.

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
starken getesteten Rollback-Schutz. Der office-basierte Entwurf wurde durch
Migration70 mit der eigenen Rolle `profile` ersetzt. Alte Zugangspruefungen
lassen diese Rolle nicht hinein (negativ getestet). Es gibt weiterhin keine
Profilzuordnung auf echten Konten und keine dauerhafte Anwendung von70.

Die Resource bleibt absichtlich unregistriert. Keine halbfertige
Rechteverwaltung wird als verwendbar auf ClubUnio ausgerollt.

## Vor Aktivierung zwingend

1. Vorbereiteten Filament-Editor integrieren und visuell pruefen;
   betroffene Benutzer und wirksame Rechte zusammen anzeigen.
2. Vorbereitete Profilzuordnung erst nach vollstaendiger Fachintegration
   freischalten; zusaetzlich angemeldete Formularpruefung erforderlich.
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

## Naechste zwingende Integrationsschritte

- Workspace-Zugang von canAccessTenant als pauschalem Fachrecht trennen.
- Resource-/Policy-/Untertabellenleserechte mit expliziter Modulzuordnung
  ergaenzen, ohne schreibende Fachservices pauschal freizuschalten.
- Jeden mutierenden Fachservice und Export/Download mit konkretem
  Modul/Funktion verbinden; verbleibende Altpruefungen verweigern profile.
- Direkte Livewire-/Webaufrufe und Portal-Fallbacks negativ testen, ebenso
  erlaubte Aktionen positiv. Erst danach profile aktivieren, Editor
  registrieren, Migrationen dauerhaft ausfuehren und ClubUnio deployen.
