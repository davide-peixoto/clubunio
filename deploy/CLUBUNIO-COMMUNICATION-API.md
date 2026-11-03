# ClubUnio Kommunikation: Implementierung und API

03.11.2026: Persoenliche App-Abfragen fuer Teams/Teamchats/Teamplanung sind
serverseitig auf die eindeutig aktuelle datierte Saison begrenzt, auch bei
Admin- und direkten ID-Aufrufen. Keine Client-Filter erforderlich. Genaue
Grenzen (saisonunabhaengige Dokumente/Finanzlinks), Tests und Migration:
[App-Saisonfilter](CLUBUNIO-MOBILE-SEASON-SCOPE.md).

Stand02.11.2026. Implementierter Entwicklungsstand, noch kein Server-Release.
Folgepaket: [Chatkontrolle und private Dateien](CLUBUNIO-COMMUNICATION-PACKAGE2.md).
App: eigenes Repository `davide-peixoto/clubunio_mobile`, `lib/clubunio/mobile`.
Verbindlicher Gesamtumfang/Abnahme: dort `docs/COMMUNICATION-PLAN.md` und
`docs/COMMUNICATION-ACCEPTANCE.md`. Die Pakete P0-P6 sind noch nicht vollstaendig abgeschlossen.

## Vorhanden

Persoenlicher Login ueber bestehenden Account und Passwortprovider; technische
Integrationskonten ausgeschlossen. Eigene zufaellige Geraetesitzungen, nur
SHA256 des Tokens in SQL, Ablauf nach 30 Tagen und einzelner Widerruf.
Keine zweite Registrierung, keine Kopie des Passworts. Domain-/Vereinsbindung,
Login- und Requestlimits, frische bestehende MemberAccess-/TeamPlanning-Rechte.
Elternvertretung nur mit aktueller genehmigter Beziehung und minderjaehrigem Kind.

Eindeutige Chats pro TeamSaison, Trainerkanal, berechtigte Direktkontakte,
Admin-Ankuendigungen und Terminunterhaltungen. Neue Eloquent-TeamSaison erzeugt
Team-/Trainerchat automatisch; SQL-basierte Bestandsanlage wird beim Listenaufruf
idempotent ergaenzt. Private Direktchats haben genau zwei Beteiligte. Admin ist
kein privater Mitleser. Juniorenkontakte verlangen zustaendigen Trainer oder
berechtigte Familienbeziehung. Teamrollen vermitteln keine Rechnungsrechte.

Nachrichtentext verschluesselt mit Laravel Crypt in der eigenen ClubUnio-Datenbank;
keine Ende-zu-Ende-Verschluesselung. Umfragefrage/-optionen und Metadaten liegen
aktuell lesbar in der Datenbank; deren Verschluesselung ist noch offen. Client-UUID plus Inhaltsfingerprint verhindern
Doppelversand; abweichende Wiederverwendung ergibt409. Nachrichten seitenweise50,
`before` fuer Verlauf und `after` aufsteigend fuer lueckenloses Nachladen.
Lesecursor monoton; Chat-Stummschaltung serverseitig gespeichert.
Umfragen:2-10 stabile Optionen, einfache/mehrfache Auswahl, eine aenderbare
Kontostimme, Serverfrist. Erstellen nur Admin/zustaendiger Trainer.
Teilnahme bleibt im bestehenden TeamPlanningService; Kindwahl, Frist und
Revisionspruefung werden dort ausgefuehrt. Kein zweiter Teilnahmebestand.

## Vertrag

Basis `/api/mobile/v1`, HTTPS auf freigegebener ClubUnio-/Vereinsdomain.
JSON `{data: ...}`; Nachrichten zusaetzlich `{meta: {first_id,last_id,has_more}}`.
Fehler bestehender redigierter API-Vertrag mit Request-ID und no-store.
Kein technischer `/api/v1`-Integrationstoken auf diesem Vertrag.

| Methode/Pfad relativ zur Basis | Verwendung |
| --- | --- |
| POST session | username, password, device_name; persoenlichen Token ausgeben |
| GET context / GET sessions | Account, Vereine, Faehigkeiten / eigene Geraetesitzungen |
| DELETE session / DELETE sessions/{uuid} | Eigene Sitzung widerrufen |
| GET clubs/{t}/teams / contacts | Berechtigte TeamSaison/Vertretung / bis100 erlaubte Kontakte |
| GET clubs/{t}/chats / POST clubs/{t}/direct | Chatliste / Direktchat mit account_id |
| GET clubs/{t}/chats/{c}/messages | before oder after; niemals beide |
| POST clubs/{t}/chats/{c}/messages | client_id(UUID), text, kind, optional poll oder event_id |
| POST clubs/{t}/chats/{c}/read / mute | message_id / muted |
| POST clubs/{t}/polls/{p}/vote | choices: stabile Options-IDs |
| GET clubs/{t}/events / events/{e} | team_season_id / aktueller Termin und vertretene Teilnehmer |
| POST clubs/{t}/events/{e}/participate | bestehende Teilnahme mit Kind/Revision |
| POST clubs/{t}/events/{e}/chat | Unterhaltung zum bestehenden Termin |
| GET clubs/{t}/inbox | persoenliche Hinweise, Rechte erneut geprueft |
| GET clubs/{t}/invoices/{i} / invoices/{i}/pdf | berechtigte Rechnung / private PDF-Bytes |
| POST clubs/{t}/ai | siehe CLUBUNIO-COMMUNICATION-AI.md |

Exakte Pflichtfelder/Validierungen: routes/mobile.php, MobileController und
ChatService. Noch keine eigenstaendige OpenAPI-Spezifikation dieses Vertrages.
Listen sind begrenzt; vollstaendige Kontakt-/Chat-/Termin-/Posteingangspagination
ueber die Startlimits hinaus ist offen. Direkte Objektberechtigung ist davon unabhaengig.

## Rechnungsausloeser

Ausschliesslich frisch angewendeter Bexio-Statusabgleich ruft InvoiceNotifications
auf. Keine Meldung bei lokaler Anlage/Freigabe, PDF-Erstellung oder blosser
Uebertragung. Bexio-Check muss applied, maximal10 Minuten alt, passende Remote-ID
und Status8/31 haben; aktueller Bexio-Balanceeintrag muss ebenfalls passende ID
und Status8/31 aufweisen. Draft7, bezahlt, Teilzahlung, Storno, unbekannt,
Altimport und Sandbox loesen nichts aus. Status8 ist der bereits im bestehenden
Issue-/Reconciliation-Vertrag benutzte offene Zustand;31 wird dort ebenfalls als
unbezahlter ausgestellter Zustand gefuehrt, nicht als Nachweis einer E-Mail-Zustellung.
Vor Aktivierung Statusmapping am synthetischen Bexio-Beleg bestaetigen.
Quelle fuer Aktionen: https://docs.bexio.com/ (Issue/Mark invoice as sent).

Ein Ereignis je Rechnung; ein privater Posteingangseintrag je aktuell berechtigtem
Spieler-/Elternkonto. Mehrere Rollen erzeugen keine Dublette. Keine Finanzdaten
im Teamchat. Detail und PDF kontrollieren aktuelle Rechte erneut; Status kann sich
seit Hinweis geaendert haben. Noch kein Betriebssystem-Push oder externer Deep Link.

## Schema, Aktivierung und Rueckweg

Additive Migration `database/clubunio/2026_11_02_000082_mobile_communication.php`:
Sessions, Chats/Mitgliedschaften/Nachrichten, Umfragen/Stimmen, Posteingang,
Rechnungsausloeser und KI-Journal. Nur vorhandene ClubUnio-PG-Datenbank erlaubt;
keine Legacy-Tabellen oder Refreshs. Keine Migration auf dem gemeinsamen Server ausgefuehrt.

Feature-Flag `CLUBUNIO_MOBILE_ENABLED` bleibt false. Vor einer Aktivierung:
Backup inklusive APP_KEY, isolierter PostgreSQL-Migrationstest/FK- und
Parallelitaetspruefung, C01-C36 passend zum auszuliefernden Umfang, bestehende
Web-/API-Regression, synthetischer HTTPS-Login und Rechnungstest.
Danach gezielte Migration ueber den bestehenden ClubUnio-Deploymentprozess,
Konfigurations-/Routencache erneuern und Feature erst nach diesen Gates aktivieren.
Kein allgemeines `migrate:fresh`, kein Import und kein SaaS-/Store-/Betriebswechsel.
Rueckweg: Flag deaktivieren, vorherigen Code bereitstellen; Daten/Schluessel
behalten. down() ist absichtlich gesperrt. Backup-Restore noch nicht erprobt.

## Nachweis und offene Gates

Basisnachweis vor dem Folgepaket:25 gezielte PHPUnit-Tests/191 Assertions bestanden, davon14 Featuretests dieses
Moduls,4 Provider-Unitests und7 vorhandene Administration/Dokumenten-Regressionen.
Externe Aufrufe ausschliesslich gefaked und unbekannte HTTP-Aufrufe gesperrt.
Testbefehl in normaler Umgebung:
`php artisan test tests/Feature/MobileCommunicationTest.php tests/Unit/MobileTextProvidersTest.php tests/Feature/DocumentSeriesHistoryTableTest.php tests/Unit/AdministrationSurfaceTest.php`.
Hier PHPUnit mit lokalem Bootstrap-Helfer wegen Windows-is_readable-/Cache-
Einschraenkungen, SQLite mit expliziter PG-Dialektanpassung. Kein Nachweis fuer
PG-Sperren, echte FK-Ausfuehrung, Leap-Day-Altersgrenze oder Produktionslast.

Inzwischen umgesetzt: Edit/Delete, Gruppenmeldungen/Moderation, Direktkontaktsperren,
Admin-Empfaengervorschau, Gelesen im Posteingang und private Anhaenge mit Quarantaene.
Dazu additive Migration83, nicht angewendet. Details/Nachweis im Folgepaket.
Noch offen: WebSocket/Reverb, Push/FCM/APNs, Offline-Dauerablage und automatischer Mitgliedschaftsabgleich bei
jeder fachlichen Aenderung, exakte Eintrittszeit vor erstem App-Aufruf, automatische
Termin-Aenderungsmeldungen, externe Deep Links,
Loeschfristen/Retention, vollstaendige Oberflaechensprachen und Geraeteabnahme.
Der Entwicklungsstand nutzt sechssekundiges Vordergrund-Polling; kein
Hintergrunddienst, keine WebSocket- oder Push-Zusage.
