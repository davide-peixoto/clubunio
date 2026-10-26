# Gemeinsamer Web-/API-Release (26.10.2026)

## Umfang

Abgeschlossene API-Basis cb8d89a und zuvor gepruefte Web-Mandantentrennung
gemeinsam bereitgestellt. Quellrevision a09a197157594bc6a532c30a6d5c7d11f27806f4
enthaelt zusaetzlich die gesonderten Release-Pruefungen. Die zeitgleich neu
entstehende Provider-API (einschliesslich Migration79) gehoert nicht dazu.

Ziel: /var/www/svs-laravel/releases/20261026-clubunio-tenant-api
Rueckweg: /var/www/svs-laravel/releases/20261026-clubunio-api-v1-final

Das Archiv kommt ausschliesslich aus der festgehaltenen Git-Revision, nicht aus
dem weiter bearbeiteten Arbeitsverzeichnis.1440 Archivpfade,4559674 Bytes,
SHA256 6c153ebb693a486521d9176b41b91184db595d61fb2e54b510e4bbc81ab86010.
Keine .env-Dateien, vendor, Uploads oder Laufzeitcaches im Archiv.

## Freigabe und Schutz

Der erste Testversuch im aktiven Arbeitsordner wurde nach zwischenzeitlichen
API-Aenderungen abgebrochen; er ist kein Abnahmenachweis fuer diesen Release.
Ein erster isolierter Aufbau hatte die beiden separaten Konfigurationsdateien
noch nicht verlinkt; die Datenbank-Zielpruefungen haben diesen Aufbau abgewiesen.
Keine Datenbankaenderungen aus diesem Fehlversuch.

Finale Tests verwenden einen isolierten temporaeren Build unter
storage/framework/testing/clubunio-release-qa-cb8d89a. Anwendung und Tests stammen
aus cb8d89a; eigene Vendor-Kopie, nur Links auf die drei lokalen .env-Dateien,
eigene Storage-/Cacheverzeichnisse. Damit koennen neue parallel geschriebene
API-Klassen weder den Test noch das Deployment veraendern. Keine weitere
kanonische Projektkopie, keine Aufnahme dieser Artefakte ins Git.

Die neue kombinierte Release-Pruefung verlangt Routecache, aktiven
TenantBoundaryServiceProvider, Web-Domainguard und Authentifizierung vor
API-Domainguard auf allen16 API-Routen sowie Migrationen76/77/78 und den
validierten API-Profil-Fremdschluessel. Positiver lokaler Lauf erfolgreich;
negativer Lauf ohne Routecache muss explizit mit Status1 abbrechen. Keine
rohen Datenbankfehler oder Konfigurationen im Fehlertext.

## Deployment

deploy/release-clubunio-tenant-api.sh kopiert den bisherigen Release in ein neues
Verzeichnis. Vor dem Umschalten muessen alle sieben CLI-Pruefungen sowie das
read-only Tenant-Schemaaudit erfolgreich sein. Aktuelle Symlink-Zuordnung wird
vor Vorbereitung und direkt vor Umschaltung erneut geprueft. Der alte Release
bleibt bestehen. Composer-Lock muss identisch sein; keine neue Abhaengigkeit.

Bestehende .env-/Storage-Ziele bleiben erhalten. Config-/Routen-/Viewcache werden
fuer den neuen Release aufgebaut. Keine Migration wird pauschal ausgefuehrt:
76/77/78 sind bereits installiert;79 wird durch diesen Release weder ausgeliefert
noch angewendet. Die gemeinsame ClubUnio-DB kann bereits additive Erweiterungen
aus der parallelen Entwicklung enthalten; kein Ruecksetzen fremder Migrationen.
Keine Domainzuordnungen, DNS-/TLS-Aenderungen, Scheduler- oder Bankoperationen.
Providerzugriffe sind nur beim expliziten eigenen API-HTTPS-Test vorgesehen;
keine Bexio-/SFV-/Azure-Schreibaktionen. Bestehendes PHPMaker DEV/PROD, App-Neubau
und SaaS-Abrechnung bleiben unveraendert.

## Abnahme und Ergebnis

- Isolierter finaler Lauf:128 Tests/26755 Assertions erfolgreich,7:06 Laufzeit,
  mit aufgebautem Routecache.119 API-Tests plus8 Web-Mandantentests und ein
  Public-Token-Domaingrenztest. Keine ganze Projekt-/Penetrationstestfreigabe.
- Negativer und positiver kombinierter Release-Check erfolgreich. Auch die
  anderen CLI-Gates laufen mit einem aeusseren Throwable-Fang und Status1 bei
  Fehler, statt sich auf einen moeglicherweise erfolgreichen PHP-Exit zu verlassen.
- Sieben CLI-Gates auf Pi erfolgreich: Navigation, Kernschema/Templates,
  Profile, Saisonplanung, Dokumentenserien/Sponsorenlauf, API und kombinierte
  Tenant-/API-Grenzen.196 verschluesselte Settings und46 Vorlagen pruefbar.
- Schema-Metadatenaudit:129 Tenanttabellen, keine ungesicherten Tenant-FK-Paare.
- Atomar auf20261026-clubunio-tenant-api gewechselt, PHP-FPM neu geladen,
  vorheriger Release vorhanden. Config-/Routen-/Viewcache releasebezogen neu.
- HTTPS: Login/Portal-Login/up200; Mitglieder/API-Verwaltung/Swagger/Spec anonym
  302 zum Login; API-Kontext anonym401; /.env403.
- Echte HTTPS-QA mit eigenem Verein15889/Integration941: Kontext und vier
  Websitefeeds200; Mitglieder und Schreibversuch403 ohne angelegtes Team;
  nach Tokenentzug401. Tokens gesperrt, QA-Konten/Profil deaktiviert,
  Metadaten/Auditnachweise bleiben erhalten. Keine echten Vereinsrechte vergeben.
- Alle1220 archivierten Dateien per Git-Blobhash gegen die Quellrevision
  abgeglichen: keine Abweichung. Der Vergleich verwendet LF im generierten
  Manifest; Windows-CRLF war zuerst eine reine Manifest-/Pfadabweichung.

Keine echten Customdomains konfiguriert, keine DNS-/TLS-/Browserabnahme neuer
Vereinsdomains behauptet. Es bleibt die zentrale Domain dev.clubunio.ch.
Nachfolgende Provider-API-Arbeiten sind ausdruecklich nicht Bestandteil dieser
Freigabe und brauchen eigene Tests/Deployment. Die Release-Dokumentation wird
im Git nachgefuehrt; der Laufzeitmarker CLUBUNIO-REVISION bezeichnet weiterhin
die tatsaechlich ausgelieferte Quellrevision, nicht einen spaeteren Doku-Commit.

## Rueckweg

Nur bei bestaetigtem aktuellen Ziel und vorhandenem alten Release einen neuen
Symlink auf20261026-clubunio-api-v1-final anlegen, atomar nach current verschieben
und PHP-FPM neu laden. Nicht aktuelle Anwendung oder Shared-Storage loeschen.
Keine down()-Migration, kein Zuruecksetzen von Schluesseln, Tokens, Audit oder
Idempotenznachweisen. Der alte API-Release behaelt Tokenschutz; die zusaetzliche
Web-Domainfunktion ist dort noch nicht vorhanden. Reale Vereinsdomains werden
ohnehin erst nach gesonderter Besitz-/DNS-/TLS-Abnahme freigeschaltet.
