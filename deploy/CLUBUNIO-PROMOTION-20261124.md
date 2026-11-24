# Stage/PROD: fertige DEV-Pakete – 24.11.2026

Vom Benutzer ausdrücklich für Stage und PROD freigegeben und ausgeführt.
Quelle: fertig versionierte Änderungen von `b4048fb6` bis `d0bcdc5a`;
DEV-Laufzeit `20261124-clubunio-daily-v2` zusätzlich als Vergleich gesichert.
Keine ungeprüften lokalen Assistant-, Marketing-, Mobile- oder Bexio-QA-Änderungen.

## Veröffentlichter Umfang

Je 140 zusammengeführte Laufzeitdateien auf
`/home/runcloud/webapps/ClubUnioStage` und `ClubUnioProd`:

- Glasdashboard mit Animationen und 12–20 px Innenabstand der Kennzahlen.
- Zentraler React-Parameter, animierte Navigation und Mandantenfarben.
- Gemeinsame Backend-Oberflächen und modulspezifische Arbeitsansichten.
- Mein Verein, Freigabezentrale, Mitglieder-Zeitleiste, Planungskonflikte,
  Favoriten, Verlauf und gespeicherte Listenansichten.
- Aufgabenboard, gruppierte Meldungen, Suche, geführte Erfassung und Belegentwurf.
- Freie Mannschafts-/Busalternativen und automatische SFV-Nachführung mit
  erhaltenen Wettkampf-IDs, Garderoben und lokalen Zusatzdaten.
- Fertige Workflow-Mailgestaltung über bestehende Versandwege.

Stage/PROD enthalten eigenständige Sicherheits-, Login-, Passkey-, Upload- und
App-Korrekturen. Deshalb kein vollständiges Kopieren von DEV. Drei-Wege-Abgleich
mit Git-Basis; Composer, Konfiguration, Authentifizierung, Routen, Integrationen,
private Dateien und zusätzliche Ziel-Dateien bleiben erhalten. Konflikte bewusst
aufgelöst: Dashboard-Methoden ergänzt, Fotoaktionen im Mitgliederdossier erhalten
und Zeitleiste ergänzt, Social-Stylesheet im bisherigen Modus erhalten.

React aktiviert und über den nativen SettingsService auditiert: Stage-Mandanten
1 `sv-sissach` und 3 `sv-sissach-refresh`, PROD-Mandant 12 `sv-sissach`.
Andere Testmandanten bleiben unverändert. FC Horizon existiert in diesen beiden
Instanzen nicht; kein Mandant und keine DEV-Daten wurden dafür kopiert.

## Migration und Sicherungen

Einzige neue Migration: `2026_11_24_010000_work_hub` in beiden Datenbanken.
Vier additive Tabellen: `workspace_tasks`, `workspace_task_events`,
`chat_mentions`, `workspace_capture_commands`. Ziel-/Ownerprüfung, Transaktion,
Advisory Lock, Sperrzeitlimit und Ledger. Nur benötigte Tabellen-/Sequenzrechte
für die bestehende Anwendungsrolle `userunio`; keine bestehenden Profile erweitert.

Vorher vollständiger verschlüsselter Owner-Dump des Anwendungsschemas je Instanz,
anschließend vollständig entschlüsselt durch `pg_restore --file=/dev/null` gelesen.
Keine Datenauffrischung und kein Import. Code-/Cache-Sicherung, Vorherdateien,
Paketmanifest, Migration und Abnahme liegen privat auf dem VPS unter:

- `/root/clubunio-releases/workflows-stage-20261124`
- `/root/clubunio-releases/workflows-prod-20261124`

Erster Stage-Versuch wegen fehlender Leserechte neu angelegter Quellordner
zurückgesetzt; Verzeichnisrechte korrigiert und Stage vollständig erneut geprüft.
PROD lief anschließend mit der korrigierten Auslieferung erfolgreich durch.
Beide Instanzen sind aus dem Wartungsmodus zurück. FPM und PROD-Pushworker aktiv.

## Login-Themes

V6-Theme mit animiertem Login, OTP-Automatik und laufendem Prüfstatus unter
stabilen Namen `clubunio-glass` und `sv-sissach-glass` installiert.
SV-Sissach erbt das stabile Glas-Theme und keine DEV-Verzeichnisnamen.

| Client | Theme |
| --- | --- |
| clubunio-platform-stage | clubunio-glass |
| clubunio-verein-stage | sv-sissach-glass |
| clubunio-platform-prod | clubunio-glass |
| clubunio-verein-prod | clubunio-glass |

Mangels konfigurierter Admin-API-Zugangsdaten ausschließlich die vier
`login_theme`-Attribute bei gestopptem Keycloak in einer geprüften Transaktion
geändert, anschließend kontrolliert gestartet. Kein Schema-, Realm-, Konto-,
MFA-, SSO-, Redirect- oder Berechtigungswechsel. Bestehende Sitzungen bleiben
gespeichert. DEV-Clients unverändert. Private Vorherwerte und Rückweg:
`/root/clubunio-releases/glass-promotion-20261124/rollback.sql`.
Kein Social-Provider freigeschaltet; offene Anbieterregistrierungen bleiben offen.

## Abnahme und Grenzen

- Stage: 53 native Prüfungen bestanden, PROD: 49. DE/EN und React Ja/Nein,
  Dashboard, Anlagenplanung, Cockpit, Aufgaben, Meldungen, SFV und Belegentwurf.
  Social Studio auf Stage gerendert; auf PROD aufgrund vorhandener Konfiguration
  bewusst nicht freigeschaltet und vier entsprechende Renders übersprungen.
- Native Fachtests erzeugen synthetische Daten nur in zurückgerollten
  Transaktionen. HTTP-, Mail- und Queuezugriffe isoliert; keine realen Spiele,
  Belege, Zustellungen oder Zahlungen erzeugt. Neue Aufgabentabellen danach leer.
- Lokal 32 Tests / 603 Assertions erfolgreich; 3075 Übersetzungsschlüssel DE/EN.
- Beide Vereins- und beide Plattform-Logins liefern HTTP 200 mit V6-Assets.
  OIDC-Discovery HTTP 200; öffentlicher Login in Chrome auf Stage/PROD sichtbar.
  Keine erneute gültige Anmeldung oder physische Kameraprüfung in diesem Release;
  authentifizierte Oberflächen über echte native Renders geprüft.
- 140 Laufzeitdateien je Instanz über `deploy/promotion.manifest` verifiziert.
  Asset-/Anwendungsprüfungen und verschlüsselte Sicherungen abgeschlossen.

## Rückweg

Anwendung in Wartung nehmen, `source.tar.gz` aus der jeweiligen privaten Sicherung
zurückspielen, nur die dort protokollierten neuen Dateien entfernen, den gesicherten
Bootstrap-Cache zurückspielen, Views neu kompilieren, Anwendung freigeben und
PHP-FPM kontrolliert neu laden. Die vier additiven Tabellen bleiben erhalten;
keine produktiven Aufgaben oder Entwürfe löschen. React ist über den bestehenden
Parameter unabhängig rückschaltbar. Die zuvor fehlenden React-Parameter können
bei einem vollständigen Design-Rückweg wieder auf Nein gesetzt werden.

Login-Rückweg bei gestopptem Keycloak: nur das gesicherte `rollback.sql` auf der
Keycloak-Datenbank ausführen, Keycloak starten und OIDC/Login erneut prüfen.
Die alten Themes bleiben installiert. Datenbankvollrestore ist kein normaler
Rückweg und erfordert eine gesonderte fachliche Entscheidung.

Werkzeuge: `tools/build-workflow-promotion.py`,
`deploy/promote-reviewed-workflows.sh`, `deploy/check-promoted-workflows.php`,
`deploy/emit-promoted-work-hub-migration.php`, `deploy/promote-react-settings.php`
und `deploy/promote-glass-login-themes.sh`.
