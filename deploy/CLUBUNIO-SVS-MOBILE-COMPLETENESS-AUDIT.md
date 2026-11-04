# Vollstaendigkeitsaudit der Original-SVS-App (04.11.2026)

Unabhaengiger Quellenabgleich, kein Abnahmetest und keine pauschale Aussage
vollstaendiger Migration. Original nur gelesen: C:/Projects/SVS/app/lib/features
mit17 Featureordnern und72 Dartdateien. Abgeglichen wurden die dortigen
Repositorymethoden, sichtbaren Fachaktionen und persoenlichen Adapter unter
C:/ClubUnio/mobile/lib/clubunio/mobile plus echte Backendrouten/Capabilities.
Der initiale Audit war read-only; die bestätigten Lücken sind inzwischen gezielt
umgesetzt. Externe Provider und echte Geräte bleiben getrennte Abnahmen.

## Verbindliche Fortschreibung nach Nachaudit (04.11.2026)

Diese Tabelle ersetzt die historischen Offenstände der ursprünglichen Matrix
und der G1–G8-Befunde weiter unten. Quellenstand und reale Betriebsabnahme
bleiben getrennt. DEV ist jetzt releases/20261104-clubunio-mobile-parity-v1,
Backendquelle3560c6e, Toolingcdf8889, Aktivierunga5b253f.800/900/1000 sind
erfolgreich angewendet; interne Flags für Bootstrap/Rollenhilfe/Provenienz/
Pushadmin/Challenge/Keybox samt Verwaltung sind aktiv. SMTP/FCM/Azure/
IglooIssuance bleiben geschlossen. Finale Flutter-Gesamtsuite468/468 grün
(storage/app/mobile-full-tests-final.log), finaler Android-Debugbuild erfolgreich
(storage/app/mobile-final-android-build.log). Kein iOS-/Geräte-/Providererfolg
wird daraus abgeleitet; die Laufzeitlogs gehören nicht in Git.

| Befund | Aktueller verbindlicher Quellenstand | Nachweis / verbleibende Grenze |
| --- | --- | --- |
| G1 Challenge | Persönliches Originalspiel, Reset/Broadcastquelle, Launcher und tatsächliche Shell-/Mitglieder-Einstiege umgesetzt | CLUBUNIO-MOBILE-PENALTY.md; echte Pushzustellung separat |
| G2 Benachrichtigungen / Pushadmin | Original-Liste, Pagination/Watermark/Lesen/Fachziele und persönlicher Admineditor mit dauerhafter1000-Quelle umgesetzt | CLUBUNIO-MOBILE-ADMIN-NOTICES.md, mobile/docs/PERSONAL-NOTIFICATIONS.md; FCM/APNs/Endgerät separat |
| G3 Probetrainingsdetails | Original-Detailmaske mit echter persönlicher Sicht und privatem Portrait umgesetzt | mobile/docs/PERSONAL-TEAM-REGISTRATION.md |
| G4 Keybox | Persönlicher echter at-most-once Auftrags-/Pollingvertrag, Profil-/Bus-Einstieg und Verwaltung vorhanden | CLUBUNIO-MOBILE-KEYBOX.md; operative Providerfreigabe/Hardware separat, kein simulierter Erfolgsnachweis |
| G5 Spesen-HEIC | Native lokale Konversion vor persönlichem Upload, Generation-/Grössengrenzen und Mehrfachanhänge umgesetzt | receipt_image.dart / PersonalExpenseRepository; reale Kamera-/HEIC-/iPhoneabnahme separat |
| G6 OCR-Betragsherkunft | Erfolgreiches OCR an tatsächlichen Draft/Scan/Quelle und gespeicherte Position gebunden, manuelle Änderung false, Regelvorschläge separat;900 und interne Provenienz auf DEV aktiv | CLUBUNIO-MOBILE-EXPENSE-PROVENANCE.md; echte Azure-/Scanner-/Blobabnahme separat |
| G7 Appbetrieb/Banner | Anonymer persönlicher Bootstrap, Wartung/RegistrationActive und Originalbanner sicher angeschlossen | CLUBUNIO-MOBILE-BOOTSTRAP.md; letzter Agentenlauf6/133 grün |
| G8 Rollenhilfe | Originalhilfe durch persönliche echte Capabilities im Profil/Today angebunden | Bootstrap-/Rollenhilfepaket; keine erfundenen Legacy-Userlevels |
| G9 Heute-Kalender | Persönlicher vollständiger Planungskalender statt regulärer Team-Teillisten, inklusive Einladungen/Absagefilter/vor Pagination begrenztem14-Tagefenster | CLUBUNIO-MOBILE-TODAY-CALENDAR.md; Flutter9 und HTTP2/29 grün |
| G10 Anmeldung: HEIC/Dateikontext | Persönlicher Originalpicker, begrenzte lokale HEIC-Konversion und ausdrückliche Konto-/Tenantgeneration vor/nach asynchronem Dateilesen/Upload umgesetzt |44 kombinierte Fälle grün; reale Kamera-/HEIC-/iPhoneabnahme separat |
| G11 Sponsor-Shareidentität | Echter Tenantname und verifizierte gleiche API-Origin statt festem SVS-Namen/zentraler URL umgesetzt und auf DEV | CLUBUNIO-MOBILE-SHARE-IDENTITY.md; Agentennachweis Flutter5+6, PHP3/12, PG1/30 inklusive Customport |
| G12 Lokale Profildiagnose | Tatsächlicher Siebenfachtipp, Originalsheet, redigierter persönlicher JSON-Export und Pufferleeren umgesetzt | CLUBUNIO-MOBILE-DIAGNOSTICS.md; keine Legacylogs/privaten Dateien/freien URLs im Export |

Im ergänzenden read-only Audit wurden keine weiteren bestätigten verlorenen
Fachaktionen gefunden. Basis-Throws in Members/Registrations/Documents sind
durch tatsächliche persönliche Spezialmethoden ersetzt oder sperren bewusst
Legacytransport; sie werden nicht als fertige Dummyfunktionen gewertet.
Sponsor readOnlyBilling der Basis wird im echten Abrechnungseinstieg durch
billingRepository ersetzt. Der Dokumente-Profilshortcut ist nicht persönlich
sichtbar, sein Fachablauf ist im echten Modulmenü vorhanden. Die unaufgerufene
Reminderdispatch-Methode besitzt keinen ursprünglichen UI-Trigger. Diese
Prüfung ist keine native Geräte-, Store- oder externe Provider-Gesamtabnahme.

## Ursprüngliche Aktionsmatrix (historischer Erstbefund)

| Originalpaket | Tatsaechliche Originalablaeufe | Persoenlicher Quellenstand / offene Punkte |
| --- | --- | --- |
| app_config | anonymer Betriebs-/Registrierungsstatus, modulbezogene Rollenfreigaben, aktive Appankuendigungen | Rollen-/Profil-/Mandantenmodule echt via MobileModuleSettings/MobileAccountController; Wartungsmaske und altes Appbanner nicht gleichwertig angeschlossen (G7). RegistrationActive folgt neuem PublicAuthauftrag. |
| auth, registration | Login, Konten, Biometrie, Passwortwechsel, Kontoanmeldung/Aktivierung/Reset, Mitgliedschaft | Persoenlicher Vault/Wechsel/Passwortkern vorhanden; PublicIdentity/Biometrie in paralleler Arbeit. Kein abgeschlossenes Gesamtpaket behauptet. |
| home | Todaytermine, persoenliche Modulnavigation, Benachrichtigungen, Rollenhilfe | TodaySource/Originalscreen und Navigation vorhanden; persoenliche Rollenhilfe fehlt (G8), Badge/Notificationfunktionen siehe G2. |
| team_planning | Events/Details, Erstellen/Aendern/Serien/Entfernen, SFV-Uebernahme, Zu-/Absagen, Anwesenheit/Vorschlaege, Gaeste, Abwesenheiten, Statistik, Kaderteilen/-push | Echte persoenliche Adapter und Operationcaps vorhanden. Reminderdispatch-Methode ohne Original-UI-Aufrufer ist kein verlorener UI-Ablauf; echter Pushtransport ist eigener Freigabepunkt. |
| teams | Mannschaften/Details/Trainings, Staff/Spieler suchen/hinzufuegen/entfernen, Mitgliederdossier, Probetrainingsdetails/-foto | Echte Teamzuordnungen/Dossieradapter vorhanden. Probetrainingsfoto/-freshness nicht persoenlich angeschlossen (G3). |
| members | Verzeichnis, Dossier, Kontaktbearbeitung/Aenderungsantrag, globale/individuelle Pruefung, Rechnungen/PDF, grafischer Ausweis/PNG | Echte Adapter und Rechte vorhanden, Ausweispaket separat bereitgestellt. Kein urspruenglicher Mitglieder-/Profilbild-Upload vorhanden; Portraitluecke betrifft Anmeldung/MyTeams. |
| member_registrations | Liste/Statusfilter, Neu/Bearbeiten, Portrait-/Ausweisdateien, Vorschau/Download, Annahme/Abweisung/Zuordnung | Persoenliche Review-/Intake-/Attachmentadapter vorhanden, Revisionen/Retry/Entfernen ergaenzt. Teamansichtpfad G3; PublicMode durch PublicAuthadapter in Arbeit. AHV wird bewusst nicht ausgegeben. |
| member_documents | Listen/Typen/Upload, Datei/PDF, gezeichnete Unterschrift, Original/Signed, offene Archivquittungen | Persoenliche receipt/file/archive-Vertraege mit Quellen-/Revisionsbindung vorhanden; nativer Gesamt-/Providerlauf offen. Keine Checkboxersatzsignatur. |
| expenses | eigene Belege, Bankdaten, Positionen/Anhaenge, Laundry, OCR-Vorschlag/Review, Freigabe/Zurueckweisen | Persoenlich verbunden, Mehrfachbelege nachgezogen. HEIC ist code- und geraeteseitig offen (G5), AI-Herkunftsindikator nicht erhalten (G6). B-Aufzeichnung ist ausdruecklich gesperrt gemaess Auftrag keine echten Bankzahlungen. |
| sponsor_run | Ereignis/Teams/Teilnahme, Zusagen, Helfer/Check-in/Runden/Ergebnis, private Sharelinks, Abrechnung/Provider/PDF | Persoenliche Kern- und Abrechnungsadapter vorhanden. readOnlyBilling der Basis allein ist kein Gap: originale Billingroute benutzt billingRepository(eventId) und den echten persoenlichen Folgeadapter. Providerfreigabe/Realabnahme bleibt getrennt. |
| bus_reservations | Listen/Kalender/Filter, Beantragen, Genehmigen/Ablehnen/Storno, private Keybox | Originalreservationsaktionen persoenlich verbunden. Keybox ist explizit nur Simulation, echte Ausgabe fehlt als Codevertrag und Konfiguration (G4). |
| pitch_availability | Felder/Status lesen und mit echten Statusoptionen aendern | Persoenlicher Facilitiesadapter vorhanden, keine zusaetzliche sichtbare Originalaktion gefunden. |
| notifications | eigene paginierte Liste, ungelesen/alle, Zaehler, einzeln/alle gelesen, Fachzielnavigation | Neue Inbox ist kein vollstaendiger Ersatz (G2); Pushagent bearbeitet Geraete-/Transportbasis parallel. |
| penalty | shoot/save, Uebungsrunde, gewerteter Start/Finish, Bestenliste/Paginierung, Adminreset mit Broadcast, Loader-/Langdruckeinstieg | Echte Migrationsluecke G1. Root hat Umsetzung jetzt zugewiesen,600 sichtbar reserviert, noch nicht angewendet. |
| profile | Passwort/Konten/Biometrie, Push ein/aus, Pushadmin, Igloo7Tagecode, MeineDokumente, Rollenhilfe | Grundkonto/Biometrie vorhanden/in Arbeit; Pushadmin und Schluesselbox nicht persoenlich migriert (G2/G4), Rollenhilfe G8. Dokumente im Hauptmenue vorhanden, Profilshortcut allein kein verlorener Fachpfad. |

## Historische bestätigte Lücken mit Quellen (vor Umsetzung)

**G1 – Vereinschallenge und Loaderabsturz.** Original
C:/Projects/SVS/app/lib/features/penalty/data/penalty_repository.dart:10/32/56/72
bietet reset/start/finish/leaderboard. Originalregeln und Serverzeit-/Replaygrenzen
stehen in C:/Projects/SVS/backend/application/controllers/PenaltyGameAppController.php
und src/PenaltyRules.php: zehn Versuche,8s Mindestdauer,30min Ablauf,30Starts/10min,
Score100+20 je Serie,max2100, zwei Listen, Resetgeneration. In ClubUnio fehlen
persoenlicher Adapter und Routen; MobileAccountController mappt kein Penalty.
Zusatz: mobile/lib/features/penalty/presentation/penalty_game_screen.dart:15-20
liest zwingend Legacy-AppState/ApiClient; PlayfulLoadingIndicator ruft diesen
Launcher auch in persoenlichen Screens auf. Daher moeglicher ProviderNotFound.
Erforderlich: sichere persoenliche Launcher-Injection, echte tenantgebundene
Daten/Score/Reset/Broadcast und Modulrechte, neutrale Mandantenmarke.

**G2 – eigene Benachrichtigungen und Pushadmin.** Originalrepository
C:/Projects/SVS/app/lib/features/notifications/data/notifications_repository.dart:9,
18,44,54: Unreadcount, Pagination/Unreadfilter, Einzel-/Alle-gelesen. Persoenlich:
app/ClubUnio/Mobile/MobileController.php:191-209 liefert nur bis50 Invoice-/Chat-
Notices, ohne Pagination/Count/Unreadfilter; :288 bietet nur Einzellesen.
MobileApp zeigt eine eigene einfache Inbox statt der OriginalNotificationsScreen.
Original notification_navigation.dart routet ausser Teams auch Spesen, Mitglieder,
Anmeldungen, Bus, Dokumente und Challenge. Neuer push_envelope.dart akzeptiert
bislang nur chat/announcement/invoice/planning. Original PushAdminScreen bietet
All/Office/Trainer+Office/User/Member/UserLevel und manuelle Titel/Nachricht;
ProfileBackend persoenlich hat diesen Fachpfad nicht. Erforderlich: echte
persoenliche Notificationrepository-/Originalscreen-Injection und typisierte
berechtigte Ziele, Readall und belastbare Empfaengerscopes; Pushagent koordinieren.

**G3 – MyTeams-Anmeldungsfoto und frisches Dossier.** Original-/migrierte
features/teams/presentation/registration_detail_screen.dart:59,208,273,297 nutzen
PhotoUrl und alten Provider<ApiClient>; my_teams_screen.dart:885 oeffnet nur einen
DTO-Snapshot ohne persoenliches Detailrepository. MobileTeamsData.php:106ff gibt
bewusst kein PhotoUrl/privaten Filevertrag aus. Folge: echtes Portrait erscheint
nicht, obwohl neue RegistrationDocumentService/private Dateien vorhanden sind.
Erforderlich: frischer, berechtigter Anmeldungsvertrag und injizierter privater
Dateileser in genau dieser Originaldetailmaske, ohne freie URLs/Integrationstoken
oder AHV-Erweiterung. Falls Portrait wirklich fehlt, nur Initialen anzeigen.

**G4 – Keybox ist keine abgeschlossene Migration.** Original Profilseite
C:/Projects/SVS/app/lib/features/profile/presentation/profile_screen.dart:480
bietet Igloo7Tagecode; igloo_keybox_repository.dart erzeugt/liest echte PINs.
MobileBusController.php:59 gibt hart available=false/issuanceEnabled=false/
simulationMode=true zurueck, PersonalBusRepository.fetchKeybox:302ff akzeptiert
sogar ausschliesslich diesen geschlossenen Zustand. Allgemeiner Profil-Keybox-
Adapter fehlt. Ein blosses Setzen echter Providercredentials wuerde die Funktion
also nicht herstellen. Codevertrag/Operationsrechte/Originalmaskenadapter fehlen;
echte Schluessel-/Providerfreigabe bleibt danach separat autorisierungspflichtig.

**G5 – HEIC angeboten, unveraendert hochgeladen.**
new_expense_position_screen.dart:291/382 bietet HEIC. PersonalExpenseRepository
uebernimmt die Datei ohne HEIC-Konversion; serverseitiger gescannter
MobileExpenseReceipts-Vertrag erlaubt nur PDF/JPEG/PNG. Das ist vor einer echten
Geraeteabnahme bereits eine Quellluecke, nicht nur fehlende Azurekonfiguration.
Erforderlich: begrenzte getestete native Konversion oder ehrlicher Formatvertrag
im persoenlichen Picker; Android/iOS-HEIC-Abnahme danach weiter erforderlich.

**G6 – Anzeige der OCR-Betragsherkunft fehlt.** Originalposition speichert/zeigt
AiBetragUebernommen, Original-/aktuelle Detailmaske rendert den Indikator bei
position.aiAmountAccepted (aktuell :645ff). MobileExpenseData.line setzt das Feld
hart null; persoenliche OCR-Review-/Budgetevidenz ersetzt diese Darstellung nicht.
Erforderlich: echte bestaetigte Positionsherkunft aus dem Review-/Bindevertrag
ableiten, nicht true/false erfinden. Keine automatische KI-Uebernahme einfuehren.

**G7 – Appbetriebs-/Bannervertrag unvollstaendig.** Original RemoteConfigRepository
fetch():12 laedt anonym RegistrationActive/MaintenanceMode/-Message; fetchAnnouncements():25
laedt aktive Banner. Der persoenliche Einstieg benutzt keinen entsprechenden
Read-/MaintenanceScreen-Vertrag; API.enabled=false liefert nur einen Fehler.
Chatankuendigungen sind vorhanden, aber die originale aktive Banneranzeige ist
nicht angeschlossen. Erforderlich: sicherer oeffentlicher Bootstrap-/Betriebsvertrag
und bestehende Wartungs-/Bannerdarstellung; keine permissiven Legacyfallbacks.

**G8 – Rollenhilfe im persoenlichen Profil/Today fehlt.** Original ProfileScreen:458
und TodayScreen bieten RoleGuideScreen. Die migrierte Profilmaske zeigt sie nur
bei bestehendem Legacy-session-Objekt, persoenlicher ProfileBackend hat keines.
Erforderlich: fachlich echte Rollencapabilities injizieren und Originalhilfe
anpassen; nicht durch fiktives UserLevel mit falschen Rechten ersetzen.

## Bewusste Rechte-/Betriebsgrenzen, keine versteckten Fertigbehauptungen

Keine AHV-/Privat-/Bankdaten aus Trainer-Namenssicht, keine echten B-Zahlungen,
keine Legacy-Integrationsrechte. Diese Grenzen sind ausdruecklich erhalten.
Registration-Portrait/ID-Upload, Dokumente/Signatur, Spesen/OCR und Shareexport
haben eigene echte Vertragsquellen; reale Azure/Scanner/Blob/FCM/APNs,
Kosten-/Providerfreigaben, Keybox und Android-/iPhone-Kamera/HEIC/Teilen/
Biometrie/Coldstart bilden zusaetzliche Abnahmen. Diese duerfen weder als reine
fehlende Screens noch umgekehrt als bereits erledigte Gesamtmigration gemeldet
werden. Sourcepruefung allein beweist keine native Gesamtabnahme.

## Historische Zwischennachweise der Umsetzung

G1 ist als persoenliches Originalspielpaket mit600, echten Score-/Zeit-/Scope-/
Replay-/Resetregeln und sicherem Launcher vorbereitet und getestet; siehe
CLUBUNIO-MOBILE-PENALTY.md. Root integriert/deployt gesondert, keine
Gesamtauslieferung behauptet.

G3 ist mit persoenlicher frischer Original-Probetrainingsdetailmaske und
privatem echten Portraitvertrag nachgezogen (vier neue plusacht bestehende
Flutterfaelle gruen). Vorhandene Registierungsreadiness bleibt erforderlich.
Keine freien PhotoURLs/Legacyprovider und kein erfundenes Portrait. Echte
Anzeigelabels/Lifecyclezeiten werden im bestehenden autorisierten DTO erhalten.
Appbeleg: mobile/docs/PERSONAL-TEAM-REGISTRATION.md.

G2 wird koordiniert bearbeitet: Pushagent implementiert echte getrennte
notifications-Pagination/Count/Readall/Quellensicht, dieser Agent bindet die
Original-NotificationsScreen persoenlich an. Alte Inboxclients bleiben erhalten.
G4-Keybox wurde vom parallelen Training/Authagent uebernommen.

G2-Liste ist jetzt ebenfalls als echtes Originalscreen-Quellpaket vorbereitet:
PersonalNotificationsRepository und NotificationsScreen-Injection mit
Pagination/Ungelesen/Zaehlern/Einzel- und Watermark-Allelesen sowie typisierten
frischen Fachzielen. Fuenf neue plusdrei bestehende Flutterfaelle gruen,
Backend-PG-Nachweis vom Pushagenten. Siehe mobile/docs/PERSONAL-NOTIFICATIONS.md.
G2-Pushadmin ist inzwischen als eigenständiges1000-Quellpaket mit originaler
Form/Suche/Zielgruppen/Vorschau/Bestätigung, verschlüsselter dauerhafter Quelle,
Empfängersnapshot, UUID/Revision, persönlichem Profil und frischem privatem
Informationsdialog nachgezogen. Vier PG-Fälle100 Assertions plus Rollen-/
Bootstrapnachlauf1/37 grün; keine1000-Anwendung oder echte Zustellung.
Siehe CLUBUNIO-MOBILE-ADMIN-NOTICES.md und mobile/docs/PERSONAL-PUSH-ADMIN.md.
G1 tatsächlicher Shell-Scope, Original-Spielmenü und Mitgliederlangdruck sind
zusätzlich positiv/negativ als Appintegration geprüft, nicht nur isolierte Launcher.
Der heutige verbindliche Abschlussstand steht in der oberen Fortschreibung:
G1–G12 sind umgesetzt,800/900/1000 und die genannten internen Flags auf DEV
aktiv. Frühere Aussagen dieses historischen Abschnitts über unapplied1000 oder
noch bearbeitete G5–G8 sind durch diesen Release überholt.468 Fluttertests und
der finale Androidbuild sind grün; externe Geräte-/Providerabnahme bleibt offen.
