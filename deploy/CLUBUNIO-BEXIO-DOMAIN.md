# Zentraler Bexio-Callback, 14.10.2026

Live-Abnahme: Release53-bexio-domain aktiv. APP_URL/Callback verifiziert,
Sandbox SVS Api Test/ID1 erfolgreich lesend geprueft. Oeffentliche Routen
liefern unangemeldet 302 zum Login auf dev.clubunio.ch; .com liefert308
auf .ch. Controller/Routes lokal und Pi SHA256-identisch. Interaktiver
Consent ueber den neuen Callback bleibt dem Benutzer zur Abnahme offen.

Release53-bexio-domain, basiert auf Release52. Nur ClubUnio/Pi; PHPMaker
DEV/PROD, physische Datenbank und deren OAuth-Verbindungen unveraendert.

- APP_URL: https://dev.clubunio.ch
- Verbindungsseite: /integrations/bexio
- Verbindungsstart: POST /integrations/bexio/connect (CSRF, Adminrecht)
- Callback: /integrations/bexio/callback

Ein Adminmandant wird automatisch gewaehlt; bei mehreren erscheint eine
Auswahl. Links aus den Einstellungen uebergeben tenant als Auswahlparameter.
Der Start uebernimmt den expliziten Mandanten aus dem Formular, prueft die
Berechtigung frisch und speichert den OAuth-Kontext benutzergebunden in
der Session (10 Minuten). Der zentrale Callback ignoriert Tenant-Parameter
der URL und verbraucht den gespeicherten Kontext einmalig. Bestehende
State-, PKCE-, Nonce-, Signatur- und Fingerprint-Pruefungen bleiben erhalten.
Beim Callback wird das Adminrecht nochmals geprueft. Pro Benutzer/Sitzung
ist ein zentraler Verbindungsvorgang gleichzeitig vorgesehen.

Alte /clubora-bexio/{tenant}-Routen bleiben kompatibel, werden nicht mehr
fuer neue Links/Callbacks erzeugt. Andere Filament-Mandantenpfade sind
unveraendert. Keine vollstaendige mandantenlose Webnavigation umgesetzt.

Callback ist laut Benutzer bei Bexio freigegeben. Bei erneuter Verbindung
immer SVS Api Test auswaehlen. Neue interaktive Anmeldung erfordert den
Benutzer; automatische Tests ersetzen keine echte Consent-Abnahme.

Regression: BexioConnectionTest 10 Tests / 94 Assertions mit DB-Rollback und
simulierten Providerantworten. Zusaetzlich vor Releaseaktivierung echte
lesende Sandbox-Identitaetspruefung (ID1/Name SVS Api Test), bei Bedarf
normaler Refresh des separaten ClubUnio-Tokens; keine fachlichen Writes.

Deployment: release-clubunio-bexio-domain.sh. Vorversion52 bleibt erhalten.
Shared-ENV-Sicherung .env.before-bexio-domain-20261014; Rollback erfordert
Rueckwechsel des current-Symlinks UND Wiederherstellen dieser ENV-Datei.
Keine Secrets in Releasebericht oder Testausgaben.
