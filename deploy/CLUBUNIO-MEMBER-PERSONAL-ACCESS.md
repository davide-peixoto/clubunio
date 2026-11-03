# Benutzerzugriff im Mitgliederdossier (03.11.2026)

Im Mitgliederdossier neuer Tab **Benutzerzugriff**, ausschliesslich fuer
bestehende Admin-/Geschaeftsstelle-Konten des aktiven Vereins.
Zeilen zeigen ausdrueckliche account_member_links (Eigenes Mitglied oder
Elternvertretung), Namen, Benutzernamen, Freigabe und wirksamen persoenlichen
Zugriff. Rollenbasierte Zugriffe werden nicht als Benutzerzuordnung erfunden.
Trainer/TL/Admin mit einer ausdruecklichen Elternzuordnung bleiben sichtbar;
dieser persoenliche Zugriff ist unabhaengig von ihrer weiteren Funktion.

Ausstehende/widerrufene Zuordnungen bleiben zur Diagnose sichtbar und filterbar,
werden aber nicht als freigegebener Zugriff bezeichnet. Aktives menschliches
Konto, Vereinszuordnung/Portal, aktive Mitgliedschaft sowie Minderjaehrigkeit
bei Elternvertretung werden fuer die Anzeige beruecksichtigt. Benoetigte
Kontospalten werden gesammelt geladen; keine Abfrage je Tabellenzeile.

Lesen und Aenderungsantrag ist kein direkter Stammdaten-Schreibzugriff. Die
bestehenden Portal-/Aenderungsservices bleiben die verbindliche Zugriffskontrolle.
Die Uebersicht vergibt keine neuen Edit-/Lesen-Flags und inferiert keine Eltern
aus Kontaktadressen oder Familienzugehoerigkeit ohne freigegebene Benutzerbindung.

## Direkte Aktionen

- Zuordnung pruefen: vorhandener AccountLinkService, bestehende unveraenderliche
  Zuordnungsfelder, Begruendung, Revision und Audit. Admin/Geschaeftsstelle wie bisher.
- Benutzer bearbeiten: vorhandene TenantAccountResource-Felder und
  AccountAdministrationService; weiterhin ausschliesslich Admin. Kein zusaetzlicher
  Zugang fuer Geschaeftsstelle/TL/Trainer oder reine Mitglieder-Leseprofile.

Beide Aktionen als seitliche Dialoge direkt im Dossier. Mitglied und aktueller
Verein werden auch bei direkten/veraenderten Requests erneut geprueft;
entzogene Rollen sperren nachfolgende Livewire-Anfragen.

## Migrationsplan und Rueckweg

Keine DB-Migration, Profilzuweisung, Neuverknuepfung oder Datenkopie. Neue
Relation am Mitglied, eigene RelationManager-/Anzeigeservice-Klasse und
Einbindung als zusaetzlicher Tab. Bestehende Tab-Reihenfolge/IDs bleiben
erhalten. Kein API-/Mobile-Vertrag geaendert. PHPMaker DEV/PROD unveraendert.

Gezieltes Delta auf aktuellem ClubUnio-Release aufbauen, private Umgebung,
APP_KEY und Storage erhalten. Elf bestehende Gates vor Aktivierung pruefen,
Caches releasebezogen aufbauen, vorherigen Release als Rueckweg behalten.
Keine Migration oder automatische Freigabe durch Deployment.

## Pruefung

6 isolierte PostgreSQL-Rollbacktests/61 Assertions gruen: explizite Elternlinks
trotz Staff-/Adminrolle, keine rein rollenbasierten Nutzer, pending/revoked,
Konto-/Portalsperre, Altersgrenze, direkte Zuordnungs-/Kontobearbeitung,
Versionskonflikt, Office-Grenze, fremde Dossiers und Rechteentzug nach Mount.
Bestehende Identitaets-/Portal-/Profil-/Dashboardregression:4 Tests/36 Assertions
ebenfalls gruen. Deploymentabnahme folgt.
