# Persoenliche Spielerstatistik und Anwesenheitsstapel

Fortsetzung03.11.2026. Der vorhandene Statistikscreen verwendet den persoenlichen
Vertrag GET planning/statistics, mit Schweizer Kalendertagen, Pagination und
einer ausschliesslich serverseitigen Eingrenzung auf sichtbare beendete aktive
Termine. Eigene/vertretene Spieler oder verantwortete Teams; Staff, Gaeste,
abgesagte, entfernte und noch laufende Termine werden nicht mitgezaehlt.
Aktuelle Spieler ohne Antwort und historische Teilnehmer bleiben enthalten.
UNION/EXISTS und deduplizierte Kaderzuordnung verhindern Mehrfachzaehlungen.
Automatische Zusagen im Abmeldemodus gelten fuer aktuelle Spieler ohne Antwort;
sie stellen keine bestaetigte Anwesenheit dar. Private Gruende werden nie gelesen.

POST planning/events/{id}/attendance/confirm-suggestions bestaetigt ausschliesslich
explizit angezeigte unbestaetigte DABEI/ABWESEND-Antworten. Gastspieler sind vom
Stapel ausgeschlossen. Terminrevision und jede Teilnehmerrevision sind erforderlich.
Eine abweichende Antwort, bestaetigte Anwesenheit oder veraltete Revision rollt
den kompletten Stapel zurueck. Tenant-/Sessionlocks und frische persoenliche
Autorisierung gelten wie bei den anderen Planungswrites. Kein automatischer Retry.

Auch einzelne Anwesenheitskorrekturen aendern jetzt ausschliesslich die
Anwesenheitsfelder und Version. Private Teilnahmegruende, Absenzherkunft und
urspruengliche Antworten bleiben erhalten; keine erneute Kapazitaetspruefung
fuer eine unveraenderte Teilnahme. Keine Privattexte im Aenderungsprotokoll.
Der aktuelle bestehende SVS-Screen zeigt Vorschlaege unmittelbar und hat bewusst
keinen zusaetzlichen Bestaetigungsbutton. Dieser Ablauf bleibt erhalten; der
Repository-Stapelvertrag ist trotzdem vollstaendig und versioniert angebunden.

Nachweise:48 Flutterfaelle fuer neue Adapter, vorhandene Statistik-/Planungsansichten,
Identitaetsauswahl und Anwesenheitsablaeufe bestanden. Gezielte Dart-Analyse ohne
Befund;19 API-/Origin-/Sitzungsszenarien bestanden. Sechs persoenliche PG-Fachtests
mit169 Assertions fuer Statistik, atomaren Stapel, private Einzelkorrektur, Gaeste,
Absenzen und Entfernen bestanden; synthetische Transaktionen zurueckgerollt.
Schemafixtures beruecksichtigen die inzwischen permanenten Migrationen84-87:
Tests veraendern deren bereits vorhandene Bestands-DDL nicht erneut.

Keine neue Migration. DEV20261103-clubunio-planning-statistics-v2 aus204d1c6
aktiv; Rueckweg20261103-clubunio-mobile-season-scope ausa9e056e.
Der erste vorbereitete Kandidat wurde absichtlich nicht aktiviert, weil der
aktive Stand waehrend der Tests durch einen anderen Chat wechselte. V2 kopiert
die neue Saisonversion vollstaendig und ueberlagert ausschliesslich sechs
gepruefte Dateien. Vier Baselinehashes gegena9e056e und sechs Ergebnisdateien
identisch; Saisondateien erhalten. Kein fremder Commit zurueckgesetzt.
Zwoelf bestehende Gates plus Saison-/17-Routen-Freigabegate erfolgreich;
persoenlicher synthetischer HTTP-Kerneltest einschliesslich Statistik und
zweimaligem Anwesenheitsstapel direkt auf DEV bestanden und zurueckgerollt.
HTTPS Statistik401 anonym, PHP-FPM aktiv; Quellhashes nach Wechsel bestaetigt.
SFV, Managerfaehigkeiten und persoenliche Dossiers sind im parallelen Anschluss;
native Gesamtfreischaltung und Gesamtauftrag bleiben offen.
