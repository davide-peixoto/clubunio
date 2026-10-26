# Plattformverwaltung

## Zugriff und Umfang

Zentraler Einstieg: https://dev.clubunio.ch/platform
Eigene Anmeldung: /platform/login. Bereits angemeldete freigeschaltete Benutzer
sehen zusaetzlich den Link Plattformverwaltung im Vereinsmenue.

Nur aktive persoenliche Konten in platform_admins duerfen diese Oberflaeche
aufrufen. Eine Vereinsrolle admin/office oder ein technischer API-Token genuegt
nicht. Rollen werden pro Anfrage und innerhalb von Schreibtransaktionen frisch
geprueft. Keine automatische Vereinsmitgliedschaft oder Fachdatenfreigabe fuer
Plattformadmins. Die Plattform bleibt auf zentral freigegebenen Hosts; auf
Vereinsdomains sind auch ihre Login-/GET-/POST-Routen gesperrt.

Erster Plattformadmin auf ausdrueckliche Bestaetigung: Konto
davide.peixoto@svsissach.ch. Additive Migration80 legt keine Admins automatisch
an. Der initiale CLI-Bootstrap ist separat und fuer dasselbe Konto idempotent:

```text
php artisan clubunio:platform-bootstrap davide.peixoto@svsissach.ch
```

Er vergibt keine Vereinsrechte. Weitere Plattformadmins sind bewusst nicht ueber
die Vereinsrollen/Zuordnungsformulare vergebbar. Es gibt keine pauschale
Superadmin-Ausnahme in Fachservices oder Datensatzfiltern.

## Funktionen

- Vereine suchen, anlegen und umbenennen; stabile Kennung bleibt unveraendert.
- Neue persoenliche Konten mit bestaetigtem Passwort anlegen, ohne ihnen bereits
  Vereins-/Plattformrechte zu geben. Bestehende gemeinsame Konten bleiben intakt.
- Bei Vereinsanlage einen explizit ausgewaehlten bestehenden Benutzer als ersten
  Vereinsadmin zuordnen. Ein neuer Verein startet ohne kopierte Mitglieder,
  Mannschaften, Fachdaten, Lookups oder Zugangsdaten. Fachliche Stammdaten und
  Integrationen werden anschliessend gezielt im Verein eingerichtet.
- Farbe, sichere Logo-Adresse und Anzeigetitel bearbeiten. Verwendet dieselben
  validierten Designparameter wie Signage und Login; keine parallelen Farben.
- Domains erfassen, Freigabevermerke und Aktivierung aendern/deaktivieren.
- Persoenliche Benutzer vereinsbezogen mit bestehenden Rollen und ausschliesslich
  vereinseigenen aktiven Profilen zuordnen. Letzter aktiver Vereinsadmin bleibt.
- Vereinszugang sperren: Zuordnung bleibt fuer Historie/FKs bestehen, Rolle wird
  Mitglied und Portalzugang inaktiv. Das globale Konto, Kennwort und andere
  Vereine werden nicht deaktiviert/geloescht. Technische Integrationskonten
  werden weiter in der API-Verwaltung verwaltet.
- Je Verein die letzten30 Plattformaenderungen einsehen. Die globale Tabelle
  platform_changes enthaelt zusaetzlich Kontoanlage/initiale Freischaltung.

Ein normaler Vereinsadmin darf ein Plattformkonto auch nicht ueber die bisherige
Benutzerverwaltung durch Kennwortreset uebernehmen oder dessen globale Aktivitaet/
Anzeigename aendern. Der Schutz gilt auch fuer inaktive Plattformfreischaltungen.
Die vereinsbezogene Rollenzuordnung bleibt davon getrennt und historisiert.

## Domains und Sicherheit

Nur exakte kleingeschriebene ASCII-/Punycode-Hosts, keine URLs, Ports, IP-Adressen
oder Wildcards. Zentrale und bereits statisch konfigurierte Hosts sind reserviert.
Ein Domainname kann nicht still zwischen Vereinen uebertragen werden.

Aktivierung setzt Domainbesitz, DNS und TLS als bestaetigte Pruefungen voraus.
Diese werden von einem Plattformadmin nach eigener Pruefung mit Begruendung
erfasst; das Formular bestellt kein Zertifikat und aendert keinen DNS/Proxy.
Erst Besitz pruefen, DNS/Proxy/TLS auf ClubUnio einrichten, dann hier freigeben.
Kein serverseitiger HTTP-Abruf beliebiger Domain-/Logo-Adressen, kein SSRF-Checker.
Fuer neue echte Domains bleibt eine gesonderte externe Abnahme erforderlich.

Die laufende Hostaufloesung liest nur aktive vollstaendig bestaetigte Domainzeilen.
Web und API verwenden denselben Resolver. Der API-Token bleibt an seinen eigenen
Verein gebunden. Domain-Sperren werden sofort frisch gelesen; keine langlaufende
globale Cachefreigabe. Zentrale normale Seiten benoetigen keine Domain-DB-Abfrage.
Login/Logo/Farbe auf Vereinsdomains verwenden die vorhandenen requestbezogenen
Brandingregeln; zentrale ClubUnio-Seiten behalten ihr Plattformdesign.

Jeder Schreibvorgang braucht CSRF, Plattformrecht, Begruendung und die aktuelle
platform_revision des Vereins. Parallele Formulare erhalten409 statt Ueberschreiben.
Name, Design, Domains und Zuordnungen sind atomar mit Vorher-/Nachherwerten im
Plattformjournal erfasst. Keine Kennwoerter/Hashes, Tokens oder Providersecrets
im Journal. Domain-/Kontonamen konkurrierender Anlagen werden transaktional
serialisiert; FKs und die Domain-Aktivierungsbedingung werden zusaetzlich in
PostgreSQL geprueft. Keine Loeschfunktion fuer Vereine/globale Konten.

## Migration und Rollout

Migration2026_10_26_000080_platform_administration ist gezielt auf der gemeinsamen
ClubUnio-DB installiert: tenants.platform_revision, platform_admins,
tenant_domains, platform_changes. Keine Datenimporte/-auffrischung oder
Berechtigungskopie. Eigene Bootstrap-Freischaltung ist getrennt dokumentiert.
Nicht automatisch alle Migrationsdateien aus einem parallelen Arbeitsstand ausfuehren.

Auslieferung als festgehaltenes Delta auf dem bestaetigten aktuellen Release;
Shared-Konfiguration/Storage erhalten, Cache neu, acht Servergates vor Umschalten.
Bestehende Read-only-Supportfunktionen bleiben erhalten; deren sechs gemeinsame
Datei-Vorversionen wurden vor Ueberlagerung gegen den gemeinsamen Basisstand
abgeglichen. Zusaetzlicher Integrationslauf prueft Plattform und Support gemeinsam.
App-/Mobile-Arbeitsstand, Abos, SaaS-Abrechnung und bestehendes PHPMaker DEV/PROD
sind nicht Bestandteil. Keine echten Zahlungen/Provider-Schreibaktionen.

## Abnahme und Auslieferung (26.10.2026)

- Erste komplette Grenzgruppe mit Plattform, Web-/API-Mandanten, Modul-/Profil-
  und Replay-Sicherheit:60 Tests/786 Assertions gruen, mit gebautem Routecache.
- Finaler Integrationslauf mit Kontenuebernahmeschutz, Plattform, Modulrechten,
  bisheriger Kontoverwaltung und Nur-Lesen-Support:41 Tests/313 Assertions gruen,
  mit gebautem Routecache. Testgruppen ueberlappen; nicht als eindeutige Summe
  aller Projektfaelle interpretieren. Kein umfassender externer Penetrationstest.
- Tests ausschliesslich mit synthetischen Daten/aeusseren Rollbacktransaktionen;
  echte Provider und Mail gesperrt. Temporaere isolierte Quellstaende unter
  storage/framework/testing, nicht zusaetzliche kanonische Projektkopien.
- Migration80 installiert (Batch79), initiales bestaetigtes Konto freigeschaltet.
- Aktiv:20261026-clubunio-platform. Rueckweg:20261026-clubunio-impersonation.
  Delta-Quellrevision5262c6d5544c6a8152fc6bf9db09fb67144b465f,21 Laufzeitdateien.
  Archiv-SHA256 ff6e1b6aaf231a2e8ddd55123c3603f59e9b921ca68ae3e0fdda66a40e2f7cb4.
- Vor Umschaltung acht Servergates erfolgreich, einschliesslich API/Plattform;
 196 Settings und46 Vorlagen entschluesselbar. Vorherige gemeinsame Dateien
  gegen Basis geprueft; zuletzt zusaetzlich die bestehende Kontoverwaltung.
  Read-only-Support und seine vier Routen erhalten. Alle21 Delta-Dateien nach
  Umschaltung per Git-Blobhash mit dem festgehaltenen Stand abgeglichen.
- HTTPS anonym: /platform302, /platform/login200, Vereinsdetails302, /login200,
  API-Kontext401. GET auf den reinen POST-Anlagepfad405. Neue Domains wurden
  nicht fuer echte Vereine aktiviert; keine DNS-/TLS-Aenderung.
- Login-Oberflaeche in Chrome am Desktop und390px visuell geprueft; keine
  horizontale Ueberbreite (Dokument390/Viewport390), Viewport danach zurueckgesetzt.
  Angemeldete Sichtpruefung steht
  noch aus: die vorherige Browseranmeldung war abgelaufen, Benutzer um erneute
  Anmeldung gebeten. Automatisierte angemeldete Seiten-/Sicherheitstests gruen.

Ruecksprung nur durch atomaren Symlinkwechsel auf den genannten vorherigen
Release plus PHP-FPM-Reload. Keine down()-Migration oder Loeschung von
Plattformjournal, Domainreservierungen, Konten oder Shared-Storage. Beim
Ruecksprung geht die neue Plattformoberflaeche verloren; keine echte Kunden-
Customdomain ist bisher eingerichtet. Vor spaeteren Domainaktivierungen den
Rueckweg erneut bewerten und die Domain-Grenzen beibehalten.
