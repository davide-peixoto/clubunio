# Persoenliche SVS-Teamplanung: in Arbeit

Stand03.11.2026. Gesamtauftrag: alle Pakete fortlaufend umsetzen, beginnend mit
Teamplanung/My Teams. Noch kein fertiger Release und noch nicht auf DEV deployed.

MobilePlanningData/Controller plus drei GET-Routen teams/events/events/{event}
unter /api/mobile/v1/clubs/{tenant}/planning implementiert. Explizite DTOs fuer
die bestehende SVS-Darstellung, mit bestehenden TeamPlanningService-/Calendar-
Rechten, begrenzter Pagination, eingeladenen Terminen und privaten Kommentaren
nur fuer aktuelle Manager/vertretene Personen. Fremde Teams erweitern die
Grundabfrage nicht. MobileMiddleware/Origin-/Sitzungsgrenzen bleiben bestehen.

28 Tests/316 Assertions bestanden, einschliesslich eigener/fremder
Teilnehmerkommentare, Eltern-/Adminrechten, Fremdteam404, fremdem Teamfilter
ohne Daten und zu grosser Seitengroesse422. Nur isolierte synthetische Fixtures.
Keine produktiven Daten oder Datenbankmigration veraendert.

Offene Schreib-/Revisions-/Serien-/Statistik-/SFV-/MyTeams-Arbeit und
Weiterarbeitsnotizen: mobile/docs/PLANNING-MIGRATION-WORK.md. Keine Behauptung,
dass die bisherigen API-/Store-/Geraeteabnahmen damit ersetzt waeren.
