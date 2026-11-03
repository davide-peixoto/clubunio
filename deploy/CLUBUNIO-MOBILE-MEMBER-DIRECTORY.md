# Persoenliches Mitgliederverzeichnis

Stand 03.11.2026, lokal geprueft, DEV-Freigabe durch den Hauptlauf.

GET /api/mobile/v1/clubs/{tenant}/members verwendet exakt den vorhandenen
MobileMemberData-/MemberAccess-/Modulscope. Ohne allgemeines members.view
bleiben ausschliesslich wirksame eigene/vertretene Mitgliedschaften und echte
verantwortete Teamkontakte sichtbar. Suche ist auf Namen begrenzt und behandelt
SQL-Wildcards woertlich. Pagination maximal 50; keine Eltern-, Geburts-,
AHV-, Finanz- oder Anhangdaten in der Liste. Der Detailendpunkt autorisiert
erneut und entscheidet unabhaengig ueber Kontakt-/Vollansicht und Schreibrechte.

Der vorhandene MembersScreen bleibt erhalten. PersonalMemberDirectoryRepository
wird injiziert; persoenliche Karten oeffnen einen neuen gebundenen
PersonalMembersRepository im bestehenden MemberDetailScreen. Alte SVS-Direkt-
aktionen sind nur im Legacybetrieb vorhanden; persoenliche Bearbeitung,
Rechnungen und Aenderungspruefung laufen ueber das frisch geladene Dossier.
Der vorhandene globale Reiter Aenderungsantraege wird durch den echten
GET members/change-requests-Vertrag angebunden. Er verlangt zusaetzlich
member_changes.view und denselben echten Mitgliederscope; ein sichtbarer Name
erteilt keine Einsicht in private Aenderungen. CanApprove und CanReject werden
getrennt berechnet und durch die Originalmaske getrennt ausgewertet.
Entscheidungen verwenden den dargestellten Antrag mit Mitglieds-ID/Revision
und den vorhandenen MemberChangeService. Keine Legacy-AppState-/HTTP-Abhaengigkeit im persoenlichen Verzeichnis und
kein Namenszugriff als Vollrecht. Bei Repositorywechsel werden ausgewaehltes
Dossier, Liste und ausstehende alte Ergebnisse verworfen.

Nachweise: ein echter PostgreSQL-/HTTP-Test mit 29 Assertions, vollstaendig
synthetisch und zurueckgerollt (Scope, minimierte Felder, woertliche Suche,
Grenzen, fremdes Dossier, private Pruefqueue, echter Ablehnungsdienst,
Rollenwechsel und entzogener Eigenzugriff). Bestehende profile-Konten erhalten
dadurch keinen persoenlichen Appzugang; MobileAccess-/MemberAccess-Sperre bleibt
unveraendert. Vier Flutterfaelle pruefen frische Dossierfactory, bestaetigte
Faehigkeiten, reale SDK-/Queue-/Entscheidungspfade, getrennte Schaltflaechen
und originale Liste/Karten ohne AppState. Dart-Analyse ohne Befund.
Keine Migration, echte Datenanlage oder externe Nachricht.
