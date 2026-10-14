# ClubUnio: DEV-Domains und Mandantenrouting

Stand 14.10.2026. Domainvorbereitung, keine abgeschlossene Umstellung des
Mandantenroutings. PHPMaker DEV/PROD unveraendert. Keine DB-Migration.

## Auf dem Pi umgesetzt

- Hauptadresse vorgesehen: https://dev.clubunio.ch.
- https://dev.clubunio.com leitet mit 308 und unveraendertem Pfad/Query auf .ch.
- dev-my2.svsissach.ch bleibt kompatibel erreichbar.
- Nginx auf 192.168.3.157:8092 akzeptiert ausschliesslich diese Hostnamen;
  unbekannte Hosts erhalten 404. Zugriff weiterhin nur Proxy/Loopback.
- Vorhandener Nginx Proxy Manager Host19 besitzt bereits die Wildcardnamen
  *.clubunio.ch und *.clubunio.com, TLS und Ziel 192.168.3.157:8092.
- Lokale Vorlage deploy/pi-nginx.conf entspricht der aktiven Pi-Konfiguration.
- Sicherung: /etc/nginx/sites-available/svs-laravel.before-domains-20261014.
- Release52 unveraendert; keine PHP-Dateien, Secrets oder APP_URL umgestellt.

Pruefung: nginx -t erfolgreich, Reload erfolgreich. HTTPS via explizitem
DNS-Override auf Proxy 192.168.2.105 ohne Abschalten der Zertifikatspruefung:
dev.clubunio.ch/clubunio/login = 200, .com = 308 auf .ch; alter Login = 200.
Unbekannter Host am Pi = 404.

## Noch erforderlich

Oeffentlicher Resolver 1.1.1.1 meldete fuer beide dev-Hostnamen NXDOMAIN.
DNS-Eintraege muessen auf denselben oeffentlichen Proxy wie dev-my2 zeigen,
nicht auf die interne Pi-IP. Die Proxy-Wildcard ersetzt keinen DNS-Eintrag.
Erst nach oeffentlicher Erreichbarkeit APP_URL und OAuth-Callback umstellen.
Die nackten Domains clubunio.ch/com werden hier bewusst nicht auf DEV gelegt.

Aktuell bleibt der Loginpfad /clubunio/login und der Mandantenpfad
/clubunio/sv-sissach. Domainkonfiguration allein entfernt diese nicht.

## Zielbild fuer den naechsten Anwendungsschritt

Login /login, danach Dashboard /; Fachobjekte ohne sichtbaren Mandantenslug.
Aktiver Mandant wird serverseitig aus den berechtigten Mandanten gewaehlt.
Bei einem Mandanten automatische Auswahl, bei mehreren Auswahl/Dropdown.
Jeder Wechsel erfolgt autorisiert per CSRF-geschuetztem POST; nach Wechsel
neue Navigation statt Wiederverwendung alter Komponenten/Formulare.

Filament tenant()-Scoping NICHT entfernen, um URLs zu verkuerzen.
Derzeit verwenden Filament-Routen IdentifyTenant und den Slug fuer Scoping,
URL-Erzeugung, Model-Binding und Livewire-Persistenz. Auch separate Portal-,
Planungs-, Bus- und Dokumentrouten referenzieren den Mandanten explizit.
Eine Umstellung benoetigt gemeinsamen TenantContext plus Integration mit
Routing, URL-Erzeugung, Authorisierung und signiertem Livewire-Kontext.
Alte Browser-Tabs duerfen nach Wechsel niemals unter dem neuen Mandanten
schreiben. Membership muss frisch geprueft werden, nicht nur beim Login.
Alte GET-Links koennen nach Berechtigungspruefung weitergeleitet werden;
Mutationen keinesfalls pauschal umschreiben. Bestehende Kalenderlinks und
Dokumentlinks bleiben kompatibel oder werden gezielt migriert.

Abnahme: zwei Mandanten, direkte/fremde Datensatz-IDs, entzogenes Membership,
parallele Tabs, Livewire-Aktionen, Downloads, gespeicherte Filter, Portal und
Bexio-State/Callback. Kein globales Umgehen von Tenant-Scopes.

## Kundendomains

CNAME ist nur der DNS-Teil. Vor Freischaltung Domainbesitz nachweisen,
Domain eindeutig einem Mandanten zuordnen und TLS bereitstellen. Unbekannte
Hosts bleiben gesperrt. Hostmapping ersetzt keine Benutzerberechtigung.
Auf einer Kundendomain nur deren Mandant; auf zentraler Domain Auswahl aus
den eigenen Berechtigungen. Keine domainuebergreifenden Sessioncookies.
Automatisierte Domainregistrierung/Zertifikate bleiben spaeteres SaaS-Paket.

## Bexio

Bestehender Callback und APP_URL bleiben bis zur DNS-/Routingumstellung
erhalten. Neue exakte Callback-URL zuerst in derselben Bexio-App zusaetzlich
registrieren, dann App-Konfiguration umstellen und Sandbox pruefen.
Keine bestehenden PHPMaker DEV-/PROD-Callbacks entfernen. Clientsecret und
Refresh Tokens weder in DNS noch im Proxy hinterlegen.
