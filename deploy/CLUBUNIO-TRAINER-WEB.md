# Trainer-Webportal

Mitgliederportal, Teamplanung, Trainingsplaene und Busreservierungen verwenden
im Web dieselbe ClubUnio-Navigation: Desktop-Seitenleiste, einklappbares
Mobilmenue, aktive Menuepunkte und Sprachwechsel (DE/EN). Das Vereinsbranding
auf kundeneigenen Domains bleibt erhalten. Die ClubUnio-Verwaltung nutzt
weiterhin Filament.

## Saisonfilter

Bei der Rolle Trainer gilt im Web die aktuelle Saison des Vereins aus
`Einstellungen > Allgemein > SaisonID`. Aktive Zuordnungen aus alten oder
zukuenftigen Spielzeiten geben keine Sicht mehr auf deren Mannschaften,
Mitglieder, Termine, Trainingsplaene, Reservierungen, Spesen oder Rechnungen.
Die Sperre gilt auch fuer direkte Links, Einladungen und den Kalenderexport.
SFV-Spiele und Absenzen werden anhand der gepflegten Saisontermine eingegrenzt.
Ohne gueltige Saison bleiben saisongebundene Listen leer; fehlende Start- oder
Enddaten schliessen die zeitbezogenen Abfragen. Persoenliche Profildaten und
der Elternzugang zu eigenen verknuepften Kindern bleiben erhalten. Die
Verwaltung, andere Portalrollen und die App behalten ihre eigenen Regeln.

## Migration und Abnahme

Keine Datenbankmigration, keine neuen Einstellungen und keine App-Anpassung.
Beim Web-Release Blade-Templates und beide neuen Portal-Assets gemeinsam
ausrollen und den View-Cache mit dem regulaeren Releaseverfahren erneuern.
Der Portal-Guard, die Rollen und die bestehenden Fachberechtigungen bleiben
unveraendert. Menue-Sichtbarkeit ersetzt keine serverseitige Rechtepruefung.

Mit einem Trainerkonto nach dem Release Mitglieder, Kalender, Absenzen und
Bus auf Desktop und Mobilbreite oeffnen. Einen Sprachwechsel pruefen und
sicherstellen, dass keine Menuepunkte wie Anmeldungen oder Bus-Stammdaten
erscheinen. Die lokale Renderpruefung ist `TrainerPortalShellTest`.
`TrainerWebSeasonScopeTest` prueft aktive Altzuordnungen, die Sicht nach
Saisonwechsel und den Direktzugriff auf alte Einladungen. Bei der Abnahme
einen Trainer mit Zuordnungen in mindestens zwei Saisons verwenden.

## DEV-Release 17.11.2026

Im authentifizierten Anwendungskontext mit einem vorhandenen DEV-Trainer
schreibgeschuetzt geprueft: Mitglieder, Kalender, Absenzen und Bus liefern
HTTP 200 im gemeinsamen Layout, DE/EN und der Kalenderexport funktionieren.
Eine aktuelle Mannschaft bleibt sichtbar, vier historische Zuordnungen
werden ausgeschlossen. Fuer dieses Konto gab es keinen alten Veranstaltungstermin
fuer einen Live-Direktlink; den 404-Fall deckt der lokale Regressionstest ab.
Der interaktive Browserlogin bei Keycloak war nicht Teil dieser Abnahme.

## DEV-Nachbesserung: Oberflaeche und Antwortzeiten

Spesen und Bankkonten haben jetzt kompakte Tabellen mit Status und Aktionen.
Spesen, Bus und Teamplanung nutzen eine gemeinsame Filter- und Tabellenoptik
mit passenden Leerzustaenden. Die Trainer-Lesesicht bestimmt Rollen und
Teamzuordnungen pro Anfrage nur einmal. Keine Cache-Wiederverwendung bei
Schreibvorgaengen, Simulation oder zwischen HTTP-Anfragen.

Gemessen mit demselben DEV-Trainer im schreibgeschuetzten Anwendungskernel.
Die Zeiten enthalten die Abfragen ueber den DEV-PostgreSQL-Tunnel, nicht
Internet, Browseraufbau oder Keycloak-Anmeldung:

| Ansicht | SQL-Abfragen vorher/nachher | Serverzeit vorher/nachher |
| --- | ---: | ---: |
| Spesen | 54 / 14 | 2.13 s / 0.67 s |
| Bankkonten | 42 / 13 | 3.29 s / 1.96 s |
| Busreservierungen | 116 / 20 | 5.69 s / 0.84 s |
| Teamplanung | 149 / 20 | 6.30 s / 0.87 s |
| Kalender | 219 / 21 | 10.21 s / 0.93 s |

Aktiv als `20261117-clubunio-trainer-polish`; Rueckweg:
`20261117-clubunio-keycloak-invite-names`. Keine Migration oder
Konfigurationsaenderung. Stage unveraendert.
