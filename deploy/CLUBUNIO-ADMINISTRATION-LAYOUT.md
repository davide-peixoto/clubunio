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
Die Betriebsfreigabe ist separat.
