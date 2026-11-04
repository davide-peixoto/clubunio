# Appbetrieb, Banner und persoenliche Rollenhilfe (G7/G8)

Stand04.11.2026: lokal abgeschlossene Quellen-/Vertragstests. Migration800
ist sichtbar reserviert, nicht dauerhaft angewandt. Keine Legacyimporte,
Provider-/Mailzustellung oder Store-/Betriebsumstellung durch Agenten.

## Betriebsvertrag

GET /api/mobile/v1/public/bootstrap liegt bewusst ausserhalb persoenlicher
MobileMiddleware: Wartungsstatus muss vor Anmeldung/Tokenlesen erreichbar sein,
auch wenn die mobile Fachschnittstelle deaktiviert ist. Feste konfigurierte
HTTPS-Origins, Rategrenze und bestehende verifizierte TenantDomainService-
Hostbindung. Keine Benutzer-, Rollen-, Mitglieder- oder freie Vereinsliste.
Kein Query-/Payloadtenant darf den Custom-Origin-Verein bestimmen. Zentrale
Origin zeigt nur generischen Plattformstatus; eine gebundene Vereinsdomain
nur eigenen Betriebsstatus und ausdruecklich oeffentliche Banner.

Persoenliches GET clubs/{tenant}/bootstrap prueft echte Session/Mitgliedschaft,
gibt eigenen Vereinsbetrieb und freigegebene Mitgliederbanner zurueck.
MaintenanceMode, MaintenanceMessage und RegistrationActive stammen aus
vorhandenen verschluesselten tenant_settings area=app. Fehlende Parameter
werden in800 additiv/idempotent angelegt, vorhandene Werte unveraendert erhalten.
Die bestehende autorisierte Einstellungsverwaltung bleibt deren Bedienweg.
Ungueltige Wartungswerte/Chiffren fallen geschlossen aus.

MobileBootstrapState.authorizeLogin wird vor Login benutzt; authorizeTenant
nach echtem MobileAccess.enter vor Fachrouten. Wartung ist damit keine reine
Bildschirmdekoration. Exakt persoenliches GET bootstrap bleibt lesbar,
globale Sessionloeschung/Logout erreichbar. RegistrationActive begrenzt echten
PublicIdentity-Registrierungsvertrag, ersetzt keine Emailproof-/Rechtepruefung.

Defaults: bootstrap.enabled=false, origins leer, native_role_guide_enabled=false;
globale Wartungsflags separat. Die eigene config/clubunio-bootstrap.php erlaubt
ausdrueckliche Betreiberfreigabe ueber CLUBUNIO_MOBILE_BOOTSTRAP_ENABLED/ORIGINS
und MAINTENANCE_MODE/MESSAGE (mit demselben CLUBUNIO_MOBILE_-Prefix).
CLUBUNIO_MOBILE_NATIVE_ROLE_GUIDE_ENABLED aktiviert die Rollenhilfe separat.
Ungueltige Boolwerte/Origins bleiben geschlossen; keine aktuelle Env wurde
durch Agenten geoeffnet. Productiondefault fragt Bootstrap vor Vaulttoken,
Biometrie und Login. Netz-/Vertragsfehler erlauben keinen stillen permissiven
Restore. Eigene MemoryStore-Tests koennen explizit einen Bootstrapadapter
injizieren; bestehende einfache In-Memory-Konsumenten erzeugen keine Plugins.
Original MaintenanceScreen bekommt Retry/Marke/optionalen Logout injiziert.
Vereinswartung laesst Wechsel in einen anderen frisch erlaubten Verein zu;
Fachnavigation/Notificationziel wird vor deren Anzeige nochmals begrenzt.

## Eigene Appbanner

800.mobile_app_announcements ist von privaten Chatankuendigungen getrennt.
Keine privaten Chattexte, Empfaenger oder Kommunikationsrechte werden anonym
veroeffentlicht. Default ist unveroeffentlicht und nur fuer Mitglieder.
Bestehende echte mandantengebundene Adminrechte erlauben neue eigene Backendresource
Appbanner mit Zeitraum, Klartext, Sichtbarkeit und angezeigter Revision.
Oeffentliche aktive Veroeffentlichung braucht ausdrueckliche Bestaetigung;
Aendern/Zurueckziehen ist revisioniert und auditiert. Max20 aktuell aktive
eigene Banner; serverseitige Zeitgrenzen. Keine automatische Altdatenuebernahme.

Original AnnouncementsBanner bekommt bereits berechtigte Daten injiziert:
oeffentliche Banner auf Login, persoenliche Banner im Vereinsbereich. Kein
Legacyfallback. Auf-/Zuklappen, Weiterblaettern und Ausblenden bleiben erhalten.

## Rollenhilfe und Notifications-Einstieg

GET clubs/{tenant}/role-guide liefert eigene echte Rollenkatalogbezeichnung
und eng berechnete Capabilities aus MemberAccess, Planning-, Saison-, Modul-
und Freigabepolicies. Kein erfundener UserLevel oder Trainer-/Elternrecht.
Original RoleGuideScreen bleibt erhalten und zeigt nur fachlich moegliche
Schritte; Push wird ohne echte separate Freigabe nicht versprochen. Today und
Profil verlinken den Originalscreen ueber persoenlichen Adapter.

Die gemeinsame Appintegration verbindet zusaetzlich den von Directory/expenses
fertiggestellten Original NotificationsScreen mit PersonalNotificationsRepository.
Repository pro Konto/Verein/echter Sessionversion gecacht; isCurrent haengt
nicht am allgemeinen Navigationszaehler. Unlesenzaehler/Badge, alle/ungelesen,
Pagination, watermarkgebundenes Readall und echte Fachzielnavigation bleiben
erhalten. Mittiger Chat unveraendert. Alte Inbox ist kein Ersatzbildschirm.

## Lokaler Nachweis

Finaler TEMP800-/HTTP-Lauf6/133 gruen: anonyme/customgebundene Wartung ohne
privaten Banner, echte Filament-Banneranlage/-Veroeffentlichung, Admin-/Version-/
Oeffentlichkeits-/Zeitraumgrenzen, Trainer-/Eltern-/Teamwiderrufscaps und echte
zentrale RegistrationActive-Ermittlung ohne Bekanntgabe von Vereinsnamen.
Bei global enabled=false: Bootstrap200/eigener Logout200; andere Route503,
fremde Session404, unauthed Logout401. Kein Rollen-/Kontozugriff wird erweitert.
TEMP-DDL/FK-Eltern und Parameterseedtests bleiben streng temporaer; reale
synthetische Fachfixtures werden vollstaendig zurueckgerollt.

Neue5 Flutter-Bootstrap-/Guide-/Badge-/Originalscreenfaelle gruen; mit
Auth/Push/Konto-/Tenant-/Chatnavigation und allen Originalmodulen36/36 gruen.
Runtime-Konfiguration2/13 gruen, Produktanalyse sauber. Sourcegate
check-mobile-bootstrap.php --closed bestanden. Keine Mail-/Providerwirkung.
Permanent800/DEV und Betriebsfreigabe bleiben Rootkoordination.
