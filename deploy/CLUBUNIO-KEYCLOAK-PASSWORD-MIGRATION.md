# ClubUnio: Passwortmigration zu Keycloak

## DEV-Pruefung vom 16.11.2026

Die DEV-Datenbank wurde ausschliesslich lesend nach Hash-Format,
Aktivstatus und Kontotyp aggregiert. Alle 81 Konten verwenden PHP-bcrypt
mit Praefix `$2y$`: 41 aktive und 36 inaktive persoenliche Konten sowie
4 inaktive technische Konten. Keine echten Hashes exportiert oder protokolliert.

## Synthetischer Importtest

Isolierte Keycloak-Instanz 26.8.2 auf Loopback mit In-Memory-Datenbank,
eigenem Test-Realm und zwei synthetischen Konten. Der bestehende Realm
und seine Clients wurden nicht geaendert. Der Testclient erlaubte den
direkten Passwort-Grant ausschliesslich fuer diesen lokalen Test.

- Positivkontrolle, von Keycloak gehasht: Login HTTP 200.
- Importiertes PHP-bcrypt-Credential (`$2y$`, `algorithm=bcrypt`):
  Login HTTP 400, `invalid_grant` / `Invalid user credentials`.
- Die installierte Distribution enthielt keinen bcrypt-Hash-Provider.
  Der direkte Import ohne Erweiterung ist damit kein freigegebener Migrationsweg.
- Testinstanz und temporaere Testdateien wurden nach dem Test entfernt.

## Migrationsentscheidung offen

1. Bevorzugt Einladung bzw. Passwort neu setzen in Keycloak. ClubUnio-Konto,
   Vereinszuordnung und Berechtigungen bleiben bestehen. Identitaet nach
   verifiziertem Onboarding dauerhaft ueber OIDC-Issuer und Subject verknuepfen;
   keine automatische Verknuepfung allein anhand einer E-Mail-Adresse.
2. Falls Passwortbeibehaltung zwingend ist: einen kompatiblen bcrypt-Provider
   separat pruefen. Naechste Abnahme: `$2y$` mit synthetischen Credentials,
   korrektes und falsches Passwort, Rehash auf Argon2 nach erfolgreichem Login
   sowie Rollback. Kein Provider im laufenden Keycloak installiert.

Keine echten Konten migriert, keine Login-Umschaltung vorgenommen. App und
Stage unveraendert. Der Befund zu Hash-Formaten gilt nur fuer DEV.
