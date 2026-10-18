# Dynamische Rollen nach Modul und Funktion

Stand 16.10.2026: Fachintegration getestet und auf dev.clubunio.ch bereitgestellt.

Abnahme 17.10.2026: [Beispielprofile und Ergebnisse](CLUBUNIO-PROFILE-ACCEPTANCE.md).
Nur Lesen und Finanzen bearbeiten mit isolierten Testkonten geprueft;
bestehende Benutzerrechte unveraendert, keine dauerhafte Profilzuordnung.
Auch im Browser auf ClubUnio geprueft. Die drei UI-Nebenbefunde sind seit
18.10.2026 korrigiert: CLUBUNIO-DOSSIER-UI-FIXES.md.

18.10.2026: Sieben konkrete SVS-Profile inklusive Platzwart angelegt, ohne
Benutzerzuordnung. Rechteumfang und Wiederholung:
[SVS-Verwaltungsprofile](CLUBUNIO-SVS-ACCESS-PROFILES.md).

## Bedienung

Unter Einstellungen > Rollen & Berechtigungen legen Administratoren eigene Profile
an, kopieren bestehende Profile oder verwenden die Vorlage Nur Lesen. Die
Funktionen sind nach Modul gruppiert. Jede Funktion braucht das Anzeigerecht
des Moduls. Neue oder unbekannte Funktionen werden niemals automatisch erlaubt.

Unter Benutzer und Rollen wird die Systemrolle Berechtigungsprofil gewaehlt
und ein aktives Profil desselben Vereins zugeordnet. Bestehende Benutzer werden
nicht automatisch umgestellt. Die Zuordnung, Profildefinitionen und Aenderungen
werden mit Revision, Bearbeiter und Begruendung protokolliert. Der letzte Admin
kann nicht durch ein eingeschraenktes Profil ersetzt werden.

Nur Lesen erlaubt Listen und Details, aber keine Mutationen, Uploads, Downloads,
Exporte, Freigaben, Synchronisation oder Versand. PDF/Anhaenge und Exporte
koennen getrennt freigeschaltet werden. Eigene gespeicherte Ansichtsfilter
bleiben mit dem jeweiligen Anzeigerecht bearbeitbar; sie aendern keine Fachdaten.

## Zugriffsgrenzen

- Die eigene Systemrolle `profile` ist keine Office-Rolle. Alte Releases
  verweigern ihr den Zugang, statt sie nach einem Rollback zu privilegieren.
- `canAccessTenant` bedeutet nur Arbeitsbereich betreten. Fachoperationen
  verwenden explizite Modul-/Funktionsrechte in `ModulePermissionService`.
- `hasUnrestrictedBackofficeAccess` behaelt die bisherigen Admin-/Office-
  Grenzen. Trainer-, Spieler-, Eltern- und TL-Portalfilter bleiben unveraendert.
- Profile erlauben die ausgewaehlten Verwaltungsfunktionen innerhalb ihres
  Vereins. Sie sind keine Kombination mit Portalrollen oder Teamfiltern.
- Unbekannte Modelle, Funktionen und Aktionen sind fuer Profile gesperrt.
  Fremde Vereins-/Datensatz-IDs bleiben unzulaessig.
- Untertabellen brauchen das Leserecht des Elternobjekts und des Untermoduls;
  jede Mutation prueft zusaetzlich ihre konkrete Funktion im Fachservice.
- Anzeigen und Buttons sind eingeschraenkt, direkte Livewire-Aktionen werden
  erneut geprueft. Eine bereits geoeffnete Maske behaelt keine Schreibrechte.
- Rechte werden fuer Aktionen frisch gelesen. Nur innerhalb eines einzelnen
  Seitenaufbaus darf ein begrenzter Lesesnapshot wiederverwendet werden.
- Benutzer-/Profilverwaltung, Verbindungsgeheimnisse, Sandbox-Konfiguration,
  zentrale Einstellungen und vollstaendiges Verwaltungs-Audit bleiben ausserhalb
  delegierter Fachprofile. Fachbezogene Verlaufdialoge folgen dem Objektleserecht.
- Das moduluebergreifende Gesamt-Dashboard bleibt Admin/Office vorbehalten;
  einzelne Kennzahlen und Listen folgen ihrem jeweiligen Modul.
- Testmail-Umleitung, Sandbox-Schutz, keine echten Bankzahlungen sowie bestehende
  Revisions-, Status- und Datenschutzregeln werden nicht durch Rechte aufgehoben.

## Migration und Rollout

1. `000069_access_profiles`: vereinsgebundene Profile und Rechte-JSON.
2. `000070_account_access_profiles`: eigene zunaechst inaktive Systemrolle,
   zusammengesetzter Vereins-/Profil-Fremdschluessel, kein Portal fuer Profile.
3. Geschuetzten Code und Oberflaeche gemeinsam bereitstellen.
4. `000071_enable_access_profiles`: Nur-Lesen-Profil fuer bestehende Vereine
   anlegen, sofern nicht vorhanden, und Systemrolle aktivieren. Keine Konten
   zuweisen und keine bestehenden Profile ueberschreiben.

Die Migrationen70/71 haben bewusst keinen automatischen Rueckbau, der Konten
zu Office konvertiert oder Rechte vernichtet. Bei Code-Rollback bleiben neue
Profilkonten gesperrt; Administratoren koennen weiterhin zugreifen.

Deployment erfolgt nur als ClubUnio-Delta auf dem unmittelbar zuvor geprueften
Serverrelease. Keine `.env`, Secrets, Uploads oder Laufzeitdaten im Git/Archiv.
PHPMaker DEV/PROD, API, SaaS und Betrieb bleiben unveraendert.

## Pruefung

Transaktionale Testdaten werden zurueckgerollt. Abgedeckt werden Profilpflege,
Benutzerzuordnung, letzter Admin, Vereinsbindung, deaktivierte Konten/Profile,
unbekannte Funktionen, Entzug nach Modaloeffnung, getrennte Datei-/Exportrechte,
Untertabellen sowie positive und negative Fachaktionen in Finanzen, Mitgliedern,
Teamplanung, Bus und Anlagen. Abschlussresultate und Release stehen in PI.md.

Abschlusspruefung vor dem Release: 156 Bestandspruefungen mit 1720 Assertions,
73 gezielte Profiltests mit 1059 Assertions und 22 Lese-/Navigationsregressionen
mit 441 Assertions bestanden. Zusaetzliche Renderpruefungen sichern die
Schaltflaechen in den Portal-/Workspace-Ansichten ab. Der Profil-Editor wurde
auf Desktop und Mobilbreite, die Benutzerzuordnung lokal visuell geprueft;
dabei wurden keine echten Benutzerrechte geaendert.
