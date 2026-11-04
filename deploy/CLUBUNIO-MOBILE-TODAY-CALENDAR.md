# Heute: persönlicher Kalender statt Team-Teillisten

Stand:04.11.2026, G9 aus abschliessendem SVS-Paritätsaudit.

Der ursprüngliche Heute-Bildschirm erwartet die nächsten vier aktiven Termine
innerhalb seines14-Tagefensters. Der bisherige persönliche Adapter fragte erst
reguläre Mannschaften und dann deren alte Teillisten mit jeweils50 Einträgen ab.
Dadurch fehlten ausschliesslich eingeladene Mitglieder und spätere aktive
Termine hinter vielen Absagen.

ClubUnioTodaySource nutzt jetzt direkt den bestehenden persönlichen
planning/events-Vertrag und das originale TeamPlanningEvent-Format. from/to,
start=0 und recperpage=limit werden explizit übertragen. Der optionale
active_only-Filter in MobilePlanningData.events wird vor Zählung und Pagination
angewandt; die vollständige reguläre Terminliste ohne diesen Filter bleibt
unverändert. Rechte und Saisonbegrenzung kommen weiterhin ausschliesslich aus
PlanningCalendarService.visible/TeamPlanningService, inklusive echter Einladungen.
Es werden keine zusätzlichen Mannschaftsrechte erteilt.

Der Adapter prüft seine persönliche Sitzungsgeneration und Custom-Tenantgrenze
vor Anfrage/Öffnen sowie nach Rückkehr. Ungültiges Fenster oder Limit ausserhalb
1–100 wird vor HTTP abgewiesen. Abgesagte, doppelte, zeitlich falsche oder
unvollständige Servereinträge werden nicht als erfolgreicher Leerstand angezeigt.
Navigation nutzt weiterhin den bereits bestehenden persönlichen Originaltermin-
Einstieg mit erneuter Fachautorisierung.

Prüfmatrix: Flutter9 Fälle grün, ursprünglicher Heute-Bildschirm inklusive
vier kompakter Einträge/Textskalierung, exakter Kalenderrequest und Stale-/
Fehlerfälle. Zwei persönliche HTTP-/Rollbackfälle prüfen ausschliessliche
Einladungen, fehlende weitere Teamrechte, verifizierte Custom-Tenantgrenze,
Einladungswiderruf, mehr als50 vorgezogene Absagen, inklusive untere/exklusive
obere14-Tagegrenze und serverseitige Limit-/Filtervalidierung. Keine Migration,
kein Legacy-/Providerfallback, keine echten Mail-/Push-/Provideraufrufe.
PG-Abschluss2/29 grün, alle synthetischen Fixtures zurückgerollt. Analyse und
PHP-Lint ohne Befund. Root übernimmt Commit/Sync und DEV.
