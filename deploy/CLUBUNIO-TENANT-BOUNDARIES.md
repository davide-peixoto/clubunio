# Mandantentrennung und Vereinsdomains

Stand25.10.2026. Gepruefter stabiler Webstand704e602 plus nachfolgende vorhandene
Dokumentationscommits. Parallel entstehende API-Dateien/Abhaengigkeiten bleiben
unveraendert und werden nicht mit diesem Paket veroeffentlicht. Keine Aenderung
am PHPMaker DEV/PROD, kein DB-Refresh, keine Abos, SaaS-Abrechnung oder Bankzahlung.

## Ergebnis und Grenzen

Der bestehende Webzugriff verwendet tenant_id, vereinsbezogene Rollen und frisch
gepruefte Fachrechte. Ein Admin eines Vereins ist kein Plattformadministrator.
Ein Benutzer darf im zentralen Arbeitsbereich nur zwischen seinen berechtigten
Vereinen wechseln. Alte Livewire-/Workspace-Formulare werden danach verworfen.

Der Schema-Audit hat128 Tabellen mit tenant_id gelesen.27 einfache Fremdschluessel
auf ebenfalls vereinsbezogene Tabellen besassen noch keine zusaetzliche
zusammengesetzte Vereinspruefung.26 Webverknuepfungen sind durch Migration77
zusaetzlich mit (tenant_id, foreign_id) gesichert. Bestand vorab ohne Rewrite
validiert, Migration auf gemeinsamer ClubUnio-DB angewendet. Kein Stammdatensatz,
keine Zuordnung und kein Dokument wurde geaendert oder verschoben.

Verbleibender separater Punkt: api_integrations.access_profile_id gehoert zur
parallel entwickelten API76 und steht noch als einfacher FK im Schema-Audit.
Diese In-Flight-API ist nicht Bestandteil dieser Webabnahme. Vor Kundenfreigabe
muss deren abgeschlossener Stand integriert und inklusive dieses FK geprueft
werden. Keine Aussage, dass die gesamte Plattform damit bereits fuer fremde
Kunden oder ein oeffentliches SaaS freigegeben ist. Kein externer Penetrationstest
und keine PostgreSQL-RLS-Zertifizierung; der DB-Zugang bleibt serverseitig vertraut.

## Pruefmatrix

| Bereich | Bestehender Schutz / Nachweis | Ergaenzung |
| --- | --- | --- |
| Zentrale Anmeldung/Vereinswahl | Account-Zuordnung, ActiveTenant, Frische-/Revokationspruefung, Snapshotrevision | Domainbindung, sichere Kontextvalidierung, Hostwechsel419 |
| Weblisten/Details/Lookups | Filament-Tenancyscope und explizite Fachabfragen; alle registrierten Webressourcen unter initialisiertem Panel strukturell geprueft | Neue wiederholbare Resource-Query-Regression, kein Ersatz fuer Fachrechte |
| Rollen/globale Benutzer | TenantAccount-/Profilpruefung; AccountAdministrationService sperrt globale Kontodaten bei vereinsuebergreifenden Konten | Test Admin in A / Office in B; Domain verleiht keine Rechte |
| Relationen/Untertabellen | Stammdaten mit zusammengesetzten FKs, Fachservices validieren eigene Lookups |26 weitere FKs: Bus/Keybox, Finanzen, Dokumentserien/Altquittungen, Trainingsimporte, Sponsorenlauf |
| Dokumente/Azure/PDF | Autorisierung vor Dateizugriff, tenant_id in Uploadpfad/Dedup und privaten Routen | FK fuer Original-/Rueckgabe-Uploads und Altquittungen; vorhandene Fremdzugriffs-/Revokationstests |
| Exporte/Auswertungen | ReportExportService erzwingt eigenen tenant_id und scoped Beziehungen, limitiert Projektionen | Bestehende Export-/Berechtigungstests nachgeprueft |
| Planung/Absenzen/Anmeldungen | Fachservice mit eigenem Tenant und Rollen/Fristsperren | Bestehende Cross-Tenant-/Scope-Regression |
| Jobs/Sync/Bexio | SyncWork speichert Tenant, scoped Work-ID und Connectionhash, frische Autorisierung vor Retry; keine fremde ID aus Browsertenant uebernehmen | Bestehende Sync-/Abgleichtests; keine Provider-Schreibaufrufe |
| Cache/Design | Signage-Cachekey enthaelt Tenant und Konfigurationshash; ReadContext nur requestlokal | Domainbranding requestlokal, zwei Vereine und Rueckkehr zur Zentrale ohne Design-Leak getestet |
| Oeffentliche Seiten | Bewusste vereinsbezogene Public-Gates, private Dokumente nicht freigegeben | Domain A blockiert oeffentliche Vereins-URLs fuer B; keine automatische Public-Aktivierung |

Strukturelle Query-/Schema-Checks sind keine Garantie gegen beliebige zukuenftige
Raw-SQL-Abfragen. Neue Module muessen diese Grenzen weiterhin explizit pruefen.
Polymorphe object_id-/kind-Referenzen in Uploads/Sync bleiben im Fachservice
abgesichert; ein generischer FK kann deren fachlichen Typ nicht ersetzen.
Tokenbasierte oeffentliche Sponsorenlinks haben nun ebenfalls eine explizite
Domainpruefung nach Aufloesung des Tokens: Info, Zusage und Bestaetigung fuer B
werden auf Domain A mit404 abgewiesen. Auf der zentralen Domain bleiben explizit
freigegebene Public-Links nach bestehendem Token-/Einwilligungskonzept verwendbar.

## Vereinsdomains

Vorbereitet fuer eigene Subdomains und kundeneigene Domains, etwa
verein.clubunio.ch und my.verein.example. Keine unbekannten Hosts, kein Wildcard-
Fallback und keine automatische Ableitung eines Vereins aus dem Hostnamen.
Ein Host darf genau einem Verein zugeordnet werden; mehrere Hosts je Verein
sind moeglich. Zuordnung ist Plattformkonfiguration, nicht frei aenderbare
Vereinseinstellung. Ein Vereinsadmin kann keine fremde Domain uebernehmen.

Konfiguration in config/clubunio-domains.php, erst nach Besitz-/DNS-/TLS-Pruefung:

```php
'hosts' => [
    'verein.clubunio.ch' => 'verein-slug',
    'my.verein.example' => 'verein-slug',
],
'central_hosts' => ['dev.clubunio.ch'],
```

APP_URL ist zusaetzlich als zentrale Domain erlaubt; weitere zentrale Hosts
koennen mit CLUBUNIO_CENTRAL_HOSTS als kommaseparierte Liste konfiguriert werden.
localhost/Loopback sind nur lokal/im Test automatisch erlaubt. Keine Domain
wurde fuer einen realen Kunden eingetragen. Es wurden keine DNS-, Proxy-, TLS-
oder Hostingeinstellungen geaendert. Noch kein ClubUnio-Code-Deployment dieses
Pakets; hier explizit angefordert sind Pruefung, Korrekturen, Doku und Commit/Sync.

Beim spaeteren Aufschalten: Domainbesitz nachweisen, DNS und gueltiges Zertifikat
auf das ClubUnio-Webroot fuehren, originalen Host weitergeben, dann exakten Host
eintragen und Config-/Route-/Viewcache releasebezogen aufbauen. Host und
X-Forwarded-Host duerfen sich nicht widersprechen. SESSION_DOMAIN muss leer sein
(hostgebundene Cookies); andernfalls scheitert die Vereinsdomain geschlossen503.
HTTPS, sichere Cookies und getrennte Provider-OAuth-Callbackfreigaben pruefen.
Keine fremde URL als Redirectziel aus Host-/Queryparametern uebernehmen.

Auf einer Vereinsdomain ist die Vereinswahl gesperrt und nur der gebundene Verein
sichtbar. Die Anmeldung/Datennutzung setzt trotzdem eine eigene berechtigte
Kontozuordnung voraus. Die zentrale Domain behaelt die Mehrvereinsauswahl.
Sitzungen auf verschiedenen Customdomains sind bewusst getrennt; dieselbe
Kontokennung ist moeglich, aber ein Domain-uebergreifendes SSO ist nicht umgesetzt.

Logo, Vereinsname, Favicon und Farbe erscheinen vor der Anmeldung. Verwendet
werden die bereits sicher validierten Werte des bestehenden Vereinsdesigns
(derzeit unter Signage-Vorschau > Vereinsdesign). Kein neues paralleles
Branding-Parameterset. Die Zentrale behaelt das ClubUnio-Design. Der neue Provider
verwendet requestbezogene Closures statt einer tenantabhaengigen globalen Mutation.

## Wiederholung und Migration

Read-only Schemaaudit, ohne Kundendaten/Secrets auszugeben:

```powershell
php -d extension=pdo_pgsql tools/clubunio-audit-tenant-boundaries.php
```

Migration77 ist auf der gemeinsamen ClubUnio-DB angewendet. Nicht nochmals per
direktem up() ausfuehren; spaetere Releases pruefen das Migrationsjournal.
Sie validiert alle26 Referenzen vor der ersten Schemaaenderung, erstellt nur
fehlende (tenant_id,id)-Eindeutigkeitsindizes und ergaenzt FKs. PostgreSQL fuehrt
die Migration atomar aus, Locktimeout5s/Statementtimeout60s statt unbegrenzt
zu blockieren. Bei Widerspruch abbrechen und manuell klaeren, niemals IDs reparieren.
Keine destruktive down()-Migration. Alte Code-Releases behalten diese Regeln.

Finale isolierte neue Tests:8 Tests/196 Assertions einschliesslich konkreter
unterschiedlicher Designwerte und Public-Token-Domaingrenze, mit geladenem
Routecache bestanden. Zusaetzlich32 fachliche Grenztests mit479 Assertions.
Alle DB-Fixtures werden zurueckgerollt; echte HTTP-Provider und Mail sind gesperrt.
Weitere gezielte Fachregression:45 Tests/529 Assertions bestanden (unter anderem
Exporte, Finance, Quittungen/PDFs, Rundnachrichten, Bus, Sponsorenlauf, Public-Web
und Profilgrenzen). Die Testgruppen ueberschneiden sich teilweise und sind nicht
als Summe eindeutiger Tests auszugeben. Alle Laeufe ohne Fehler oder Skips.
Keine Browser-/DNS-/TLS-Abnahme einer echten Customdomain behauptet.
