# Explizite persoenliche Planungsfaehigkeiten

Fortsetzung03.11.2026. Teamoptionen enthalten getrennt CanManage und CanCreate.
Termine liefern CanEdit, CanAddPeople, CanCancel, CanRemove, CanShareSquad und
CanSendSquadPush. Jede Faehigkeit stammt aus derselben Team-/Funktionsgrenze
wie die echte Operation. Die Datenuebertragung ersetzt keine erneute Pruefung
beim Schreiben. Die Teamrechte werden nur innerhalb dieser DTO-Instanz gebuendelt,
nicht fuer spaetere Requests oder Sitzungen gespeichert.

Die bestehenden Masken verwenden diese persoenlichen Faehigkeiten, ohne einem
Konto eine alte numerische SVS-Rolle zuzuweisen. Anlegen, Bearbeiten, Hinzufuegen,
Absagen und Entfernen sind unabhaengig. Texte zum Aufgebot duerfen persoenliche
Verantwortliche mit Exportrecht weiterhin vorbereiten. Nicht implementierte
native Pushzustellung liefert ausdruecklich false und keinen Sendenbutton.
Der unveraenderte Legacy-Vertrag ohne diese Felder behaelt seine Altsemantik.

Persoenliche Profilhinweise geben Backofficeprofilen keinen Mobilezugang:
Login bleibt ohne Portalrecht gesperrt.21 PG-Assertions fuer getrennte echte
Funktionsprofile, Portalgrenze und persoenlichen Trainer erfolgreich. Zwei
Flutterfaelle pruefen tatsaechliche Originalmaskenbuttons und Aufgebot ohne
Zahlrollen; die23 vorhandenen Anwesenheitsablaeufe bleiben erfolgreich.

Offsetdatumswerte werden vor der Anzeige in die lokale Geraetezeit ueberfuehrt;
naive SVS-Datumswerte bleiben lokale Werte. JSON-Zeitpunkte behalten ihren
absoluten Zeitpunkt. DEV-Freigabe gemeinsam mit dem SFV-/Einladungsvertrag
noch ausstehend; keine native Gesamtfertigstellung.
