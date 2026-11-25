# Keycloak: MFA und lokaler Sitzungswiderruf

## Aktualisierung nach Bereitstellung

Stage und PROD sind gehärtet und aktiv geprüft; die nachfolgenden Angaben zur lokalen Vorbereitung beziehungsweise unveränderten Bestandsaufnahme beschreiben den früheren Prüfstand. Massgeblich für den Abschluss sind [Release, Nachweise und offene Go-live-Punkte](CLUBUNIO-SECURITY-RELEASE-20261125.md). Lokal zuletzt 161 Tests / 25'917 Assertions; echtes MFA-/Logout-Ende-zu-Ende auf beiden Instanzen bestanden. Automatisches externes Backup bleibt offen.


Stand 25.11.2026. **Nur lokal implementiert und isoliert getestet.** Keine
Migration auf DEV/Stage/PROD ausgeführt, keine Keycloak-Clients umgestellt.
Kein Nachweis einer bereits wirksamen zentralen Kontosperre und keine Go-live-Freigabe.

## MFA unabhängig von App und Einladungen (SEC03)

Alle neuen Vereins-/Plattform-OIDC-Webanmeldungen verlangen frische MFA mit
`max_age=0`, passendem `acr` und höchstens fünf Minuten altem `auth_time`.
Die Anwendung kontrolliert den Nachweis im Callback; eine erfolgreiche
Keycloak-Weiterleitung allein genügt nicht. Fehlende oder ungültige
MFA-Konfiguration wird mit 503 geschlossen abgewiesen.

- Vereinsweb: `CLUBUNIO_VEREIN_OIDC_WEB_MFA_ACR`.
- Plattformweb: `CLUBUNIO_PLATFORM_OIDC_MFA_ACR`.
- Wenn der jeweilige neue Wert nicht gesetzt ist, bleibt der ausdrücklich
  konfigurierte `CLUBUNIO_VEREIN_OIDC_MOBILE_MFA_ACR` als Upgrade-Fallback erhalten.
  Mobile-/Einladungsflags beeinflussen diesen Web-Fallback nicht.
- App-Pairing verwendet weiterhin das eigene Mobile-ACR und seine Freigaben.
  Der spezielle Plattform-Migrationsweg muss auch das Plattformniveau erfüllen;
  bei unterschiedlichen Niveaus kann dieser Weg geschlossen abgewiesen werden.

Web-/Plattformsitzungen enthalten jetzt einen MFA-Nachweis mit Kontokennung,
Niveau, Authentifizierungszeit, Ausstellungszeit und aktuellem Sicherheitsstempel.
Alte OIDC-Sitzungen ohne diesen Nachweis erfordern eine neue Anmeldung. Die
Prüfung erfolgt auch im bisherigen Plattform-Kurzweg der Webmiddleware und
vor Plattformaktionen. Der Callback erstellt den Nachweis **vor** `Auth::login`.
Kontosperre, Sicherheitsstempeländerung und ausgelieferter Logout-Widerruf
werden bei der nächsten geschützten Anfrage berücksichtigt.

## Signierter Backchannel-Empfänger (SEC02)

Vorbereiteter Endpunkt: `POST /auth/keycloak/backchannel-logout`, ausserhalb
Browser-Sessions und CSRF. Er ist standardmässig deaktiviert. Authentifizierung
erfolgt ausschliesslich durch das signierte Logout-JWT, nie durch dessen `sub`
oder eine mitgesendete URL allein.

Prüfungen: exakter konfigurierter HTTPS-Ursprung, Form-POST und Grössenlimit,
RS256-Signatur über die fest konfigurierte Issuer-JWKS-Adresse, exakter `iss`,
zugelassene Audience, ganzzahlige frische `iat`, zukünftige ganzzahlige `exp`,
nichtleere `jti`/`sub`, Logout-Eventobjekt und vollständiges Verbot von `nonce`.
SID-only-Tokens und verschlüsselte JWTs werden nicht unterstützt. Tokens mit
`sub` und optionalem `sid` widerrufen nach unserer konservativen Kontopolicy
alle älteren lokalen Sitzungen dieses Kontos, nicht nur ein einzelnes Gerät.
Keine Logout-Tokens in Logs oder Fehlerdetails speichern.

Der Empfänger löst den Account über issuergebundene `keycloak_account_links`,
die Vereins-Subjectliste und beim identischen Plattform-Issuer zusätzlich über
Plattform-Subjectlisten, Migrationszuordnungen und freigegebene Einladungen auf.
Widersprüchliche Zuordnungen brechen mit 503 ab. Vereins-/Plattformclient sind
als Audience zugelassen; weitere ausdrücklich genehmigte Client-IDs benötigen
`CLUBUNIO_KEYCLOAK_BACKCHANNEL_CLIENTS` (Kommaliste, keine Wildcards).

Ein Logout für einen noch unbekannten Subject kann noch keinem lokalen Konto
zugeordnet werden und bestätigt deshalb ohne Account-Watermark. Beim erstmaligen
Verknüpfen durch Einladung oder Kontomigration wird mit dem Verknüpfungstoken
**keine Sitzung und kein MFA-Nachweis ausgegeben**: Der Callback beginnt einen
zweiten OIDC-Durchlauf mit neuem State/Nonce/PKCE und `max_age=0`. Erst die erneute
starke Anmeldung nach bestehender Verknüpfung darf eine Sitzung erstellen. Die
einmalige Einladung ist dann bereits verbraucht. Native Pairings brauchen wie
bisher eine bestehende exakte Kontoverknüpfung. Gleichzeitige erstmalige
Verknüpfungen sind ohne PostgreSQL-Mehrprozessnachweis nicht pauschal abgenommen.

Replay-Verarbeitung verwendet einen gemeinsamen Redis-/Datenbankcache mit
atomarer Sperre. Datei-/Arraycache sind ausgeschlossen. Erst nach erfolgreichem
DB-Commit wird die `jti` zehn Minuten als verarbeitet markiert. Infrastrukturfehler
liefern 503, damit eine fehlgeschlagene Zustellung erneut versucht werden kann.

Eine **persistente, monotone** Widerrufsgrenze pro Account liegt in
`clubora.keycloak_logout_watermarks`; sie bleibt bei Cacheverlust erhalten.
Account-Rowlocks serialisieren Logout, App-Sitzungsausgabe und MFA-Nachweise.
Pairing verwendet wie die Einlösung die Reihenfolge Pair-Zeile → Account-Zeile;
Logout sperrt keine Pair-Zeile. Dadurch wird kein umgekehrter Sperrzyklus ergänzt.
Schon abgeschlossene Pairings erhalten nach Logout einen ungültigen Stempel;
alte Claims dürfen auch bei erst später abgeschlossenem Pairing keinen neuen
Stempel erhalten. Neue Claims müssen `iat` und `auth_time` nach der Grenze haben.
Bereits laufende HTTP-Anfragen werden dadurch nicht rückwirkend abgebrochen.

SQL widerruft ältere `mobile_sessions` zusätzlich explizit. Das gesamte
`iat`-Sekundenintervall wird eingeschlossen (`created_at < iat+1 Sekunde`),
damit zuvor ausgestellte Mikrosekundenwerte nicht durchrutschen. Eine neue
Anmeldung derselben Sekunde wird konservativ ebenfalls abgewiesen; ab der
darauffolgenden Sekunde ist eine neue starke Anmeldung möglich. Verspätete
ältere Events senken die Grenze nicht. Spätere Logins bleiben bei Replay erhalten.

## Voraussetzungen vor einer separat freigegebenen Aktivierung

1. Verschlüsselte Sicherung/Restore-Nachweis und genaue Zielinstanz prüfen.
   Additive Migration `2026_11_25_010000_keycloak_logout_watermarks.php` über
   separat geprüften Owner-Runner ausführen und im Migrationsjournal erfassen.
   `sql()` erlaubt gezielte Vorbereitung ohne Import-/Refresh-Runner. Runtime
   benötigt SELECT/INSERT/UPDATE auf der neuen Tabelle; kein DDL-/Ownerrecht.
2. Gemeinsamen Redis- oder Datenbankcache samt atomaren Locks nachweisen.
   `CLUBUNIO_KEYCLOAK_BACKCHANNEL_CACHE_STORE` muss diesen Store benennen.
3. `CLUBUNIO_KEYCLOAK_BACKCHANNEL_ORIGIN` auf den exakten HTTPS-Ursprung setzen.
   `CLUBUNIO_KEYCLOAK_LOGOUT_STATE_ENFORCED=true` dauerhaft konfigurieren.
   Aktivierung der Stempelbindung ändert auch die Null-Grenze und erzwingt
   für vorherige föderierte Sitzungen/Pairings eine Neuanmeldung.
4. Erst dann `CLUBUNIO_KEYCLOAK_BACKCHANNEL_ENABLED=true` und Konfigurationscache
   erneuern. Ohne Tabelle/Cachebereitstellung muss der Betrieb geschlossen scheitern.
5. In jedem beteiligten Keycloak-Client die exakte Backchannel Logout URL
   registrieren, Backchannel-Zustellung aktivieren und **Backchannel Logout
   Session Required deaktivieren**, damit subjectbasierte Zustellung möglich ist.
   Client-IDs/Realm und Encrypted-Logout-Einstellungen gegen diese Implementierung prüfen.
6. Mit synthetischem Konto tatsächlichen Admin-/Account-Logout auslösen und
   Zustellung, Signatur, lokale App-/Webablehnung, neues Login und Replay prüfen.
   Einen echten PostgreSQL-Mehrprozess-Racetest separat durchführen.

**Kontosperre oder Passwortänderung allein ist kein verlässliches Logout-Event.**
Der IdP-Betriebsprozess muss bei Sicherheitsvorfällen zusätzlich alle betroffenen
Keycloak-Sitzungen beenden bzw. ein geprüftes Logout-Ereignis auslösen. Ohne
tatsächliche Zustellung gilt keine Garantie zentraler Sperrwirkung. Plattform-
oder Webidentitäten müssen zu einem eindeutigen lokalen Account auflösbar sein.

## Sicher pausieren und zurückgehen

Zum Pausieren nur den Empfänger deaktivieren bzw. seine Zustellung sperren;
`CLUBUNIO_KEYCLOAK_LOGOUT_STATE_ENFORCED=true` und die Watermark-Tabelle erhalten.
Das Abschalten beider Flags, Löschen der Watermarks oder Rückrollen auf Code ohne
Stempelbindung kann alte Nachweise/Pairings wieder gültig machen und ist kein
normaler Rückweg. Explizit widerrufene SQL-App-Tokens bleiben zwar gesperrt,
das genügt allein jedoch nicht für alle Nachweise. Ein Code-Rollback benötigt
einen gesonderten vollständigen lokalen Sitzungs-/Pairingwiderruf.

## Lokale Prüfung und Grenzen

`tests/Unit/KeycloakSecurityHardeningTest.php` lädt keine Anwendungsumgebung und
verwendet ausschliesslich SQLite-Arbeitsspeicher, echten RSA-Signaturcode und
HTTP-Fakes ohne Providerzugriff. Es prüft MFA-Pflicht, alte Sessions, signierte
Formzustellung, falsche Signaturen/Claims/Algorithmen, Replay, Accountgrenzen,
persistente Watermarks, alte Pairing-Claims sowie neue Anmeldungen nach Logout.
Bestehende OIDC-/Pairingtests behalten ihre fachlichen Assertions und verwenden
synthetische starke Claims bzw. einen tatsächlich vorhandenen Fixture-Account.
SQLite beweist keine PostgreSQL-Mehrprozess-Sperrsemantik; reale Keycloak-
Zustellung, Kontosperrprozess und Geräteabnahme bleiben separat offen.

Referenzen (25.11.2026 geprüft):
[OIDC Back-Channel Logout 1.0 mit Errata, Tokenprüfung und Aktionen](https://openid.net/specs/openid-connect-backchannel-1_0.html),
[Keycloak Server Administration, Logout-Clientkonfiguration](https://www.keycloak.org/docs/latest/server_admin/index.html).
