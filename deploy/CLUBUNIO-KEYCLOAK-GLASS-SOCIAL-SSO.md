# Keycloak: Glasdesign, Social Login und SSO

Stand: 24.11.2026. Ziel des Design-Releases ist ausschließlich DEV.

## Gestaltung

Die vom Betreiber bereitgestellten Vorlagen `Glassy login v3.zip` und
`Glassy otp v20.zip` sowie beide Code-XR-Facebook-Reels wurden geprüft.
Die Vorlagen simulieren erfolgreiche Anmeldungen per Timer, enthalten starre
englische Texte, deaktivieren beim Login teilweise Passwortmanager und bringen
eigene Paketabhängigkeiten mit. Diese Teile werden nicht übernommen.
Übernommen ist die Gestaltungsrichtung: Glasflächen, Lichtkanten, abgesetzte
Ecken und OTP-Paneele, angepasst an die vorhandenen ClubUnio-Farben und Radien.
Keine ZIP-Abhängigkeiten, Fremdschriften oder externen Hintergrundbilder geladen.

Neue Themes:

| Client | Login-Theme | Rückweg |
| --- | --- | --- |
| clubunio-platform-dev | clubunio-glass-dev | clubunio |
| clubunio-verein-dev | clubunio-glass-dev | leere Client-Auswahl/Realm-Fallback |
| clubunio-sv-sissach-dev | sv-sissach-glass-dev | sv-sissach |

Die Themes erben die echten Keycloak-Formulare. React 19.2.0 ergänzt dekorative
Lichtkanten und eine visuelle OTP-Eingabe. Das native, beschriftete OTP-Feld bleibt
das einzige Eingabefeld und wird unverändert an Keycloak gesendet. Einfügen,
`one-time-code`, Tastatur, Passwortmanager und serverseitige Fehlermeldungen
bleiben erhalten. Die Paneele sind für Screenreader ausgeblendet. Keine
Erfolgssimulation, automatische Codeübermittlung, Protokollierung oder Speicherung
von Zugangsdaten. Ohne JavaScript bleiben die nativen Formulare bedienbar.
Bewegung folgt `prefers-reduced-motion`; Forced Colors zeigt native Eingaben.
Die visuelle Mindestzahl von sechs Paneelen entspricht der aktuellen Realm-
OTP-Policy. Längere Eingaben werden nicht abgeschnitten.

Build: `node tools/build-keycloak-glass.mjs` (vorhandenes esbuild/React).
Quellcode: `resources/js/keycloak-glass.jsx`. Ergebnis im Theme-Verzeichnis
`deploy/keycloak/themes/clubunio-glass-dev/login/resources/js` wird mit versioniert.
Installation: beide neuen Theme-Verzeichnisse nach `/opt/keycloak/themes/` kopieren,
Client-Theme in der Admin-Konsole setzen. Keine Realm-weite Login-Theme-Änderung.
Account-, Admin- und E-Mail-Themes bleiben beim bestehenden Stand.

Beim Nachbessern gecachte Ressourcen berücksichtigen: CSS-Revisionsnamen ändern
und Theme-Cache kontrolliert durch Dienstneustart erneuern. Nicht pauschal alle
Keycloak-Caches oder laufenden Sitzungen löschen. Rückweg nur über die drei
Client-Theme-Felder; bisherige Themes bleiben installiert.

## Social Login: Registrierung fehlt

Der Betreiber hat bestätigt, dass noch keine OAuth-Anwendungen registriert sind.
Deshalb ist kein Anbieter als betriebsbereit aktiviert. Es werden keine
funktionslosen Social-Buttons eingeblendet. Die nativen Keycloak-Providerlinks
werden nach Freischaltung automatisch im Glasdesign angezeigt.

Im installierten Keycloak 26.8.2 sind Google, Microsoft und Facebook enthalten.
Apple ist nicht in der angebotenen nativen Social-Providerliste. Für Apple
braucht es eine geprüfte OIDC-/Broker-Erweiterung mit Apples POST-Callback,
korrekter Verarbeitung von Name/E-Mail bei erstmaliger Zustimmung und
Rotation des signierten Client-Secrets. Keine ungetestete Erweiterung installieren.

| Anbieter | Registrierung | Exakte Rücksprungadresse bei Alias wie angegeben |
| --- | --- | --- |
| Google | OAuth-Webanwendung, Zustimmungskonfiguration und DEV-Testnutzer | https://auth.clubunio.com/realms/clubunio/broker/google/endpoint |
| Microsoft | Entra-Webanwendung; organisationsübergreifende und persönliche Konten, falls beide gewünscht | https://auth.clubunio.com/realms/clubunio/broker/microsoft/endpoint |
| Facebook | Meta-App mit Facebook Login, E-Mail-Berechtigung und DEV-Testern | https://auth.clubunio.com/realms/clubunio/broker/facebook/endpoint |
| Apple | Services ID, primäre App ID mit Sign in with Apple, Team ID, Key ID und privater Schlüssel | https://auth.clubunio.com/realms/clubunio/broker/apple/endpoint (geplanter Alias; erst nach Brokerprüfung) |

Die Rücksprungadresse führt zu Keycloak, nicht zur Vereinsdomain. App-Registrierungen
und Secrets gehören in die Anbieterportale und die Keycloak-Verwaltung, nie ins Git.
`deploy/keycloak/social-login/providers.example.json` ist eine deaktivierte,
secretfreie Vorlage für die drei eingebauten Provider. Sie ist ohne echte
Client-ID und Secret nicht als ausführbares Importpaket zu verwenden.
Aktivierung zunächst für DEV-Abnahme; im gemeinsamen Realm außerdem die
Sichtbarkeit und Zulässigkeit pro Client prüfen, bevor ein Provider aktiviert wird.

Sicherheitsregeln für die Anbindung:

- Bestehendes Keycloak-Konto erst nach Nachweis der bisherigen Anmeldung
  verknüpfen. Kein automatisches Linking nur anhand derselben E-Mail-Adresse.
- `trustEmail=false`, `storeToken=false`; keine unnötigen Google-/Facebook-
  Zugriffstokens in der Anwendung speichern.
- Standard-First-Broker-Login mit Bestätigung/Authentifizierung bestehender Konten
  beibehalten und mit vorhandenen Konten testen. Private Apple-Relay-Adressen
  sind keine automatische Zuordnung zu einer Vereinsmitgliedschaft.
- Vereinszugang weiterhin anhand issuer/sub und ClubUnio-Mitgliedschaft prüfen.
  Externer Login erstellt keine Vereinsrolle und keine SuperAdmin-Berechtigung.
- Der aktuelle eigene DEV-MFA-Flow muss für den Brokerpfad erweitert und geprüft
  werden; ein Social-Login darf die benötigte MFA-Stufe nicht umgehen.
- Abnahme DE/EN: neuer Nutzer, vorhandenes Konto, abgelehnte Zustimmung,
  fehlende E-Mail, Widerruf, Verknüpfen/Trennen, MFA/Passkey und falscher Verein.

Quellen: [Keycloak Identity Brokering](https://www.keycloak.org/docs/latest/server_admin/#_identity_broker),
[Google OIDC](https://developers.google.com/identity/openid-connect/openid-connect),
[Microsoft OIDC](https://learn.microsoft.com/en-us/entra/identity-platform/v2-protocols-oidc),
[Apple Web-Konfiguration](https://developer.apple.com/help/account/capabilities/configure-sign-in-with-apple-for-the-web).

## SSO zwischen DEV und Stage: Befund und Vorgehen

Beide Instanzen verwenden den Realm `clubunio` auf `auth.clubunio.com`.
Die Code- und Datenbankprüfung am 24.11.2026 zeigte drei konkrete Hindernisse:

1. `PlatformOidcController::start()` fordert für den Keycloak-only-Vereinslogin
   `max_age=0` und den Essential-ACR `clubunio-mobile-mfa` an.
2. `KeycloakMobileAssurance::accepts()` akzeptiert `auth_time` nur bis fünf Minuten.
3. Der Client-Override `clubunio-verein-dev-mfa` enthält keinen Cookie-Schritt;
   sein LoA-2-Condition-Config hat `loa-max-age=0`.

Dieser trotz seines Namens gemeinsame Flow wird auch von den Vereinsclients
DEV/Stage/PROD und Plattformclients Stage/PROD verwendet. Nicht direkt ändern.
Die Design-Aktivierung ändert keine dieser Sicherheitsregeln.

Gewünschtes SSO bedeutet: Stage startet seinen eigenen Authorization-Code-PKCE-
Ablauf; Keycloak erkennt seine vorhandene Sitzung im Browser und gibt Stage
einen neuen, für dessen Client bestimmten Code/Token. Der DEV-Token, App-Key
oder Laravel-Sitzungscookie wird nicht zwischen Umgebungen kopiert. Rollen,
Mitgliedschaft und Kontozuordnung werden in jeder Instanz erneut geprüft.
Beim erstmaligen Besuch kann ein Klick auf Anmelden nötig bleiben, wenn die
Anwendung den OIDC-Ablauf nicht automatisch startet.

Umsetzung als gesonderter Authentifizierungsrelease vorbereiten:

1. Eigenen DEV-Step-up-Flow gemäß Keycloak-Dokumentation mit Cookie und
   getrennten LoA-1-/LoA-2-Bedingungen erstellen. Den bestehenden Flow erhalten.
2. Gemeinsame begrenzte Web-SSO-Frist in Keycloak und Backend konfigurieren;
   Web-Prüfung von der weiterhin frischen mobilen Gerätebindung, Einladungs-
   und Admin-Migrationsprüfung trennen. Nicht nur `max_age` entfernen.
3. Passwort+OTP und Passkey mit User Verification müssen die tatsächlich
   erreichte Stufe belegen. Niedrige/fehlende/falsche ACR, alter Nachweis und
   fehlende Mitgliedschaft müssen weiterhin scheitern.
4. DEV zuerst mit zweitem Testclient abnehmen: frischer Login, Clientwechsel,
   Fristablauf, Abmelden, neuer Browser, widerrufene Berechtigung und mobile Bindung.
5. Erst danach Stage-Client und Stage-Backend gemeinsam umstellen; PROD separat.

Siehe [Keycloak Step-up](https://www.keycloak.org/docs/latest/server_admin/#_step-up-flow).
Es wurde keine vollständige DEV→Stage-SSO-Abnahme behauptet oder die MFA
zur Umgehung der erneuten Anmeldung abgeschwächt.

## Abnahme und verbleibende Schritte

Die drei DEV-Client-Theme-Felder wurden über die angemeldete Admin-Konsole
gespeichert. Neue Theme-Dateien auf dem VPS installiert; Keycloak nach
Cacheerneuerung aktiv, lokale und externe Discovery HTTP 200. DE/EN auf beiden
DEV-Domains HTTP 200; alle referenzierten Styles/Skripte abrufbar und neue
CSS-/JS-Dateien bytegleich mit dem Quellstand. Native Passwort-Autovervollständigung
vorhanden. `php tools/check-translations.php`: 3075 Schlüssel DE/EN erfolgreich.

Chrome prüfte den echten DEV-Login in beiden Sprachen und die echte Keycloak-
Ablehnung synthetischer, nicht existierender Zugangsdaten. Desktop und 390×844:
ein Logo, mittiges Formular, sichtbarer Fokus, kein horizontales Scrollen.
Der isolierte lokale OTP-Oberflächentest prüfte sechs und acht sichtbare Ziffern
sowie die unveränderte native POST-Übermittlung eines synthetischen achtstelligen
Codes. Dieser Test ersetzt keine erfolgreiche Keycloak-MFA-Anmeldung.

Die echte OTP-Seite wurde nach Benutzeranmeldung ebenfalls aufgerufen: sechs
Paneele, ein natives POST-Eingabefeld, Autocomplete und Tastatur-Sprachwechsel
best�tigt. Der deutsche OTP-Text wurde dabei ausdr�cklich als `Einmalcode`
erg�nzt. Die erfolgreiche Codepr�fung bis ins Vereinsportal bleibt offen. Social-Login-E2E hängt von den Anbieterregistrierungen
und dem geprüften Broker-/MFA-Pfad ab. Die SSO-Umstellung ist als eigener
Authentifizierungsrelease offen. Stage-/PROD-Client-Theme und Authentifizierungs-
regeln wurden nicht geändert. Kein DB-Schema und keine Fachmigration erforderlich.

[HTTP-Prüfprotokoll](evidence/keycloak-glass-20261124/http-checks.json) und
[SV-Sissach-Sichtprüfung](evidence/keycloak-glass-20261124/sv-sissach-login.jpg).
