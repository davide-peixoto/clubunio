# Einheitliche ClubUnio-Verwaltung

Die Teamplanung, Busverwaltung und KI-Trainingspläne verwenden im
Verwaltungsbereich einheitliche Filament-Navigation, Eingabefelder und
Aktionen. Teamkalender und Platzplantafel behalten ihre Arbeitsflächen.
Die Benutzersimulation verwendet die normale Verwaltungsnavigation.
Bexio-Verbindung, API-Integrationen und Dokumentenserien nutzen die
gemeinsamen Status-, Formular- und Tabellenstile. Chat und Social Media
behalten ihre spezialisierten Oberflächen mit angepassten Bedienelementen.
Das separate Mitgliederportal bleibt unverändert.

Keine Datenbankmigration oder Änderungen an Fachlogik, Berechtigungen,
Integrationen und Formularwerten. Für ein Release den Vite-Build für
Social Media erneuern sowie Routen- und View-Cache aktualisieren
(Benutzersimulation lädt den Filament-Panel-Kontext beim GET).

## Deployment und Abnahme (13.11.2026)

DEV: Layoutcommit `f6acc44`, Pi-Release
`20261113-clubunio-administration-layout`; Rückweg
`20261113-clubunio-admin-campaigns`. Stage: Layoutcommit `20654fb`;
Rückweg nur über den Layoutcommit und das vorige Vite-Manifest.
Auf beiden Umgebungen Vite-Build, Routen- und View-Cache erneuert.

Angemeldete Playwright-Prüfung bei 1440 × 900 und 390 × 844:
10 Ansichten auf DEV, 7 Ansichten auf Stage, jeweils HTTP 200 ohne
horizontalen Seitenüberlauf. Das Stage-Konto ist Geschäftsstelle;
Benutzersimulation, Bexio-Verbindung und API-Integrationen wurden
zusätzlich mit einem synthetischen Stage-Admin im HTTP-Kernel geprüft
(HTTP 200, Testdaten zurückgerollt). Keine Fachaktion ausgelöst.
Keine Migration. PROD unverändert.
