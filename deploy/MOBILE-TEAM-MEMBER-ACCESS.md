# Persönlicher Mannschafts- und Mitgliederzugriff

04.11.2026, explizite Rollenpräzisierung des Benutzers. Quellen ohne neue Migration; DEV-Freigabe und Commit/Sync erfolgen separat. Original-SVS-Quellen wurden nur gelesen. Keine bestehenden Personen, Elternlinks, Rollen oder Provider wurden durch diese Implementierung geändert.

## Rechte pro tatsächlicher Mannschaft

Die bestehende Mannschaftsauflösung bleibt Voraussetzung: aktuelle konfigurierte Saison, aktive Mannschaft, aktuelle eigene Vertretung oder aktuelle Verantwortlichkeit, aktives Konto und persönlicher Appzugang. Der neue `MemberTeamAccessService` ergänzt einen expliziten, frischen Detailkontext; `MemberAccessService::represented/visibleMembers` und globale Modulberechtigungen bleiben unverändert.

| Tatsächlicher Kontext | Spieler | Staff | Kontaktänderung |
| --- | --- | --- | --- |
| Trainer/Assistenztrainer in der konkret verantworteten Mannschaft | Mitgliederdetails | Mitgliederdetails | Nur Antrag, keine direkte Änderung |
| Eltern-/Spielerzugang zur Mannschaft | Ausschließlich Name, auch beim eigenen Kind | Kontaktdaten | Keine |
| Trainer in einer anderen Mannschaft seines Kindes | Elternmodus | Elternmodus | Keine |
| Technische Leitung im eigenen bestehenden Zuständigkeitsumfang | Bestehende Kontaktsicht | Bestehende Kontaktsicht | Keine zusätzliche Berechtigung |
| Berechtigte Mitgliederverwaltung | Vorhandene Verwaltungsrechte | Vorhandene Verwaltungsrechte | Bestehende Rechte |

Eltern behalten das vollständige persönliche Kinderdossier im Mitgliederverzeichnis außerhalb des Mannschaftskontexts. Aus der Trainerfunktion entstehen keine Rechnungs-, Dokument-, Ausweis- oder Antragsprüfrechte. Rechnungsempfängerfelder und -optionen werden im Trainerkontext nicht ausgegeben oder angenommen. AHV-Daten und private Anhänge werden nicht hinzugefügt.

Die neue Trainerberechtigung benötigt zusätzlich eine tatsächlich aktive, tenantgleiche Stafffunktion an einem genehmigten eigenen SELF-Mitglied: Code exakt `TRAINER`, `ASSISTANT_TRAINER` oder `TRAINER_ASSISTANT`, alternativ Name exakt `Trainer` oder `Assistenztrainer` (Leerzeichen außen/Schreibweise normalisiert). `Coach`, Helfer und fehlende Funktion genügen nicht. Die Original-App klassifizierte über Legacy-Funktions-ID 1 oder Namen Trainer; Assistenztrainer ist die ausdrücklich genehmigte Erweiterung. Keine importierte Funktions-ID oder globale Trainerrolle allein erteilt die neue Berechtigung. `IsTrainer` im DTO nutzt dieselbe enge Namens-/Codeklassifikation; die tatsächliche Rechteprüfung verlangt zusätzlich aktive Funktion, Zuordnung und frischen Teamkontext.

## Bestehende API, expliziter Kontext

Optionaler positiver `team_id`-Queryparameter ausschließlich an:

- `GET clubs/{tenant}/members/{member}`
- `GET clubs/{tenant}/members/{member}/contact-options`
- `POST clubs/{tenant}/members/{member}/contact`

Der Queryparameter wird separat validiert und niemals in die Mitgliedsänderungsfelder gemischt. Ein `team_id` im JSON-Änderungspayload ist unzulässig. Der POST verarbeitet ausschließlich den tatsächlichen Body und prüft den Kontext nochmals unter dem bestehenden Tenant-/Sitzungsschreiblock. Ohne Kontext entstehen keine neuen Antragsrechte; die bisherige Trainerkontaktsicht wird zusätzlich auf tatsächlich klassifizierte aktuelle Trainerteams eingegrenzt, damit Helfer-/Coachzuordnungen keine alternative Member-GET-Kontaktsicht auf fremde Spieler öffnen. Persönliche und bestehende Verwaltungsabläufe bleiben erhalten. 

Team-Personen und frische Memberantworten liefern `TeamSaisonID`, `ContactOnly` und `CanRequestChanges`; Team-Personen liefern weiterhin `CanOpenDossier`. Staff und Spieler werden getrennt projiziert. Im Elternmodus enthält jede Spielerzeile ausschließlich Namen, technische Identität/Zuordnungsrevision und negative Zugangsfähigkeiten, ohne E-Mail, Mobile, Wohnort, Geburt oder Elternkontakte. Der direkte Member-GET mit diesem Teamkontext ist für Spieler ebenfalls geschlossen, auch beim eigenen Kind. Staffkontaktdetails werden erneut über den persönlichen Teamkontext geladen.

## Persistenter Änderungsantrag

Der vorhandene `MemberChangeService` erhält einen optionalen expliziten Teamparameter. Trainerzugriff wird vor und unter dem Tenantlock frisch geprüft. Der echte bestehende Antrag speichert die Teamkennung ausschließlich als verschlüsselte Snapshot-Metadaten neben `before/changes`; keine Tabellen-/Schemaänderung. Vergleich und Verwaltungsanzeige lesen weiterhin ausschließlich die echten Änderungsfelder.

Die Mitgliedszeile bleibt bei Einreichung unverändert. Genehmigung nutzt den vorhandenen Verwaltungsdienst und prüft den ursprünglichen Trainerumfang erneut: Konto, Rolle, Saison/Mannschaft, aktive Zuordnung und Mitglied im Kader. Nach Entzug kann die Verwaltung nicht genehmigen, aber ausdrücklich ablehnen. Normale persönliche/Elternanträge ohne Teammetadaten bleiben mit ihrem vorhandenen Nachweis gültig; E-Mailtreffer werden niemals als Freigabe interpretiert.

## Prüfnachweise

Neue synthetische HTTP-Fälle in `MobileTeamMemberAccessTest`: Trainerdetails/-optionen/-antrag, echte nachgelagerte Genehmigung, verbotene direkte Änderung/Prüfung/Finanzfelder, Elternnamen-/Staffprojektion, direkte Detailversuche, Doppelrolle, Pending/Widerruf und Verwaltungsablehnung nach Widerruf. **4 neue Rechtefälle / 106 Assertions** sowie **12 bestehende Mitglieder-/MyTeams-Paritäts-/Verzeichnis-/Leistungsfälle / 254 Assertions** bestanden seriell auf der finalen Source (zusammen 16/360). Zusätzlich 3 bestehende Common-MemberChangefälle / 22 Assertions grün. Rosterprojektion bei 2/40 Mitgliedern konstant 23/23 SQL, Teamauflösung 1/1. Positive Trainerfixtures verwenden eine echte Trainerfunktion; die Helper-/Coach-/NULL-Negative wurden nicht durch globale Trainerrollen ersetzt.

Neues eigenständiges DEV-Gate `deploy/check-mobile-team-member-access.php --synthetic-rollback`: keine PHPUnit-Abhängigkeit; exakte DEV-/PostgreSQL-/Featuregrenzen, geschlossene externe Provider, ausschließlich neue synthetische Mandanten/Konten/Sitzungen/Mitglieder, tatsächliche HTTP-Routen und anschließend verifizierter Rollback. Prüft auch genau die aktuelle eigene Sitzung. Mail-/HTTP-Nachweise erfolgen über Fakes/gesammelte Ergebnisse, ohne echte Zustellung. Keine Datenimporte, Schemaoperationen, Bank- oder Provideraktionen. Lokal schloss der Gateversuch bereits vor Fixtureanlage an der strikten Bootstrap-/DEV-Konfigurationsgrenze; der tatsächliche DEV-Kernellauf ist daher Bestandteil des separaten Releaseprotokolls, kein lokal behaupteter Erfolgsnachweis.
