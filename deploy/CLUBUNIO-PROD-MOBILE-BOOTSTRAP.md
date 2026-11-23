# PROD-App: Verbindungspruefung und nativer Keycloak-Einstieg

23.11.2026: Der iPhone-PROD-Build zeigte "Verbindung pruefen", bevor die
Anmeldung moeglich war. Der oeffentliche Bootstrap antwortete tatsaechlich
mit HTTP503: Mobile war aktiv, aber Bootstrap deaktiviert und dessen
Origin-Allowlist leer. Auch die bestehende native MFA-Anbindung war noch
nicht fuer PROD konfiguriert. Es handelt sich um fehlende Runtime-Freigaben,
nicht um einen Bedarf nach Schluesselimport oder einen Flutter-Codefehler.

## Gezielte private PROD-Konfiguration

Nur auf `app.clubunio.com`, nach Sicherung der bisherigen privaten .env:

| Einstellung | Wert |
| --- | --- |
| CLUBUNIO_MOBILE_BOOTSTRAP_ENABLED | true |
| CLUBUNIO_MOBILE_BOOTSTRAP_ORIGINS | https://app.clubunio.com |
| CLUBUNIO_STRONG_LOGIN | true |
| CLUBUNIO_SECURITY_ORIGIN | https://app.clubunio.com |
| CLUBUNIO_VEREIN_OIDC_MOBILE_ENABLED | true |
| CLUBUNIO_VEREIN_OIDC_MOBILE_MFA_ACR | clubunio-mobile-mfa |

Die letzte Einstellung verwendet exakt die bereits bestehende Web-MFA-Stufe
des PROD-Clients. Keine Aenderung an Clients/Secrets/Subjectzuordnungen oder
Benutzerrechten. Keycloak-only bleibt true; Einladungen und dynamische
Erstverknuepfung bleiben deaktiviert. Kein Passwortfallback, keine Registrierung
oder Mitgliedschaftsanmeldung, kein neuer Provider- oder Hintergrundjob.

Configcache als runcloud mit restriktiver umask erneuert, PHP-FPM graceful
reloaded. Andere private Einstellungen und parallele Arbeiten unveraendert.
Die Sicherung und privaten Pruefwerkzeuge liegen unter
`/root/clubunio-svs-production/mobile-bootstrap-20261123` beziehungsweise
im uebergeordneten root-geschuetzten Betriebsordner. Keine .env im Repository.

## Nachweis

- Oeffentliches GET `/api/mobile/v1/public/bootstrap`: HTTP200, exakt erwarteter
  Flutter-Datentypvertrag; loginAllowed=true, maintenanceMode=false,
  keycloakLoginAvailable=true, passwordLoginAvailable=false.
- Zentraler Origin: tenant_id=null, keine Ankuendigungen oder Vereinsdaten;
  Registrierung und Mitgliedschaftsanmeldung bleiben false.
- Query-Parameter zum Bootstrap abgewiesen (422), persoenlicher Vereinsbootstrap
  ohne Bearertoken abgewiesen (401), nicht freigegebener Origin abgewiesen (503).
- Synthetischer nativer Pair-Beginn ueber echte HTTPS/FPM-Route: HTTP200;
  Weiterleitung zu `clubunio-verein-prod` HTTP302 mit PKCE S256 und explizit
  erforderlicher MFA `clubunio-mobile-mfa`.
- Keine Benutzeranmeldung abgeschlossen, keine authentifizierte Sitzung oder
  Mobile-Session erzeugt; der allein erzeugte anonyme Test-Pair wurde gezielt
  entfernt. Keine Credentials/Pair-Geheimnisse im Nachweis.

Auf dem iPhone "Erneut pruefen" verwenden. Der echte Abschluss von Passwort,
MFA und App-Ruecksprung bleibt beim Benutzer; nicht als bereits getestet ausgeben.
Rollback: nur die sechs gesicherten Einstellungen zuruecksetzen, Configcache
erneuern und FPM graceful reload. Das wuerde den vorherigen geschlossenen
Bootstrap-/Mobile-Einstieg wiederherstellen.
