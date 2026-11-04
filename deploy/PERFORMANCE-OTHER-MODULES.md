# Übrige persönliche Appmodule: Ladeperformance (04.11.2026)

Abgleich des tatsächlich verdrahteten persönlichen App-Einstiegs mit dessen
ClubUnio-Backend. Spesen, Teamplanung, My Teams und gemeinsamer Auth-/GET-
Readcontext gehören den parallelen Root-/Fachpaketen und werden hier nicht
geändert. Die folgenden Änderungen sind Quellstand bis Roots geprüftem Release;
die bestehenden Module sind auf DEV bereits intern freigegeben. Externe
SMTP/FCM/Azure/Igloo-Ausgabe bleibt getrennt geschlossen. Keine reale
Netz-/Endgerätlatenz wird aus synthetischen Request-/Querycounts erfunden.

## Vollständige Prüfungsmatrix

| Modul / tatsächlicher Einstieg und Server | Konkreter Befund | Änderung / Grenze / Nachweis |
| --- | --- | --- |
| Mitglieder: Original MembersScreen/PersonalMemberDirectoryRepository; persönliche members-Routen, native_members_enabled | Verzeichnis paginiert mit ausgewählten Namens-/Kontaktfeldern, kein Blob; Beziehungen der Antragsliste eager-loaded. Dossierbündelung gehört dem bereits getrennten My-Teams-/Mitgliederpaket. Einzelne Vergleichslabels in Änderungsanträgen haben noch Country/Gender/Billing-Lookups pro verändertem Wert. | Kein zusätzlicher Eingriff in fremde Dossier-/Membersquellen in dieser Abschlussrunde. Kein globaler Verzeichniscache; verbleibender begrenzter Vergleichslookup ist ausdrücklich bekannt und kein bereits beseitigter Befund. |
| Anmeldungen: Original MemberRegistrationsScreen/PersonalMemberRegistrationsRepository; registrations/options und Liste, native_registrations_enabled | Editor ruft Status/Teams/Länder über Future.wait auf. Alle drei laden dieselben vollständigen Optionen, da der vorhandene Formularcache erst nach Abschluss gesetzt wird. Backendliste hat Status/Team bereits eager-loaded, keine Portrait-/Ausweisbytes. | Ein in-flight-Future pro konto-/formulargebundener Instanz;3 gleichzeitige OPTIONS-Projektionen werden1 Request. Vorhandener Cache, Invalidierung, HEIC-/Datei-/Kontokontext bleiben erhalten. Fehler werden nicht behalten; expliziter Retry neu. Detail-Lookups sind kleine konstante Einzeldaten, keine Listen-Blobreads. |
| Trainingsplaner: Original TrainingPlannerScreen/PersonalTrainingRepository; training-plans, native_training_enabled | Unsichtbare Liste der gespeicherten Pläne lädt bereits beim Öffnen des Erstellen-Tabs und auf Resume. Backend prüft Downloadrecht und Sharezuordnung pro Listenzeile erneut. | Persönlicher Saved-Tab lädt erst bei tatsächlichem Öffnen; Erstellen/Resume spart den versteckten GET. Änderungen/Background/Kontowechsel invalidieren weiter, sichtbarer Refresh liest frisch. Backend lädt Downloadrecht einmal und Sharemetadaten je maximal50er-Seite gemeinsam. Keine PDF-/plan_json-/Providerlesung in der Übersicht. |
| Sponsorenlauf: Original SponsorRunScreen/PersonalSponsorRepository; sponsor-run/events/participants, native_sponsor_enabled, Abrechnung separat | EventDTO lädt Event nochmals für Operationscaps, Team-IDs/Saisonname und Modulrechte pro Event. TeilnehmerDTO lädt Modelle, eigene/Teams-Sicht und Sharefreigabe pro Person. CHF-Summaries sind schon gruppiert. | Pure Readprojektionen pro20er-Seite: aktuelle Metadaten/Funktionsrechte, event- und zeitgebundene Helperrechte sowie tatsächliche Mine/Teams/Own-Bereiche gebündelt. Keine Schreib-/Zusage-/Abrechnungs-/Freigabepolicy geändert. Alte Einzelprojektionen bleiben als Paritätsreferenz und für Einzel-/Mutationsantworten bestehen. |
| Dokumente: Original MemberDocumentsScreen/PersonalMemberDocumentsRepository; member-documents, native_documents_enabled | Union paginiert korrekt, danach aber view/Scope/Namen/Signerzuordnung je Zeile. Receiptmetadaten schliessen private PDFcipher bereits aus; file/archive lesen beim Listenaufruf keinen Blob. | Pro maximal50er-Seite getrennte frisch autorisierte receipt/file/archive-Abfragen, gemeinsame Namen/Own-Signerzuordnungen. Archiveforward-Quellhashprüfung bleibt. Kein PDF-/Signatur-/Blobcache, Einzelview/PDF/Upload/Signatur unverändert frisch. Typisierte Quell-ID-Räume bleiben getrennt. |
| Anlagen: Original PitchAvailabilityScreen/PersonalFacilitiesRepository; facilities/fields, native_facilities_enabled | Ein List-/Statusvertrag, Feldnamen/Statusoptionen bereits gesammelt. Keine externen Dateien und keine neue proFeld-SQLschleife. | Kein weiterer Umbau gerechtfertigt; bestehende echte Statusoptionen/Revisionsprüfung und Refresh erhalten. |
| Bus: Original BusReservationsScreen/PersonalBusRepository; bus/reservations+options, native_bus_enabled | Übersicht besitzt bereits gruppierte Counts, gemeinsame Bus-/Teamlabels und page-lokale Readrechte. Formular startet Bus/Team-Optionen parallel; Repository teilt denselben bestehenden Optionenfuture. | Kein neuer Backendumbau; Reservation-/Status-/Versions-/Keyboxaktionen behalten frische Rechte. Bestehenden Optionscache nicht über Konten/Instanzen erweitert. |
| Benachrichtigungen: Original NotificationsScreen/PersonalNotificationsRepository; notifications/Count, persönliche MobileMiddleware, unabhängig von Provideroptin | Jeder Aufruf scannt/materialisiert alte eigene Quellen erneut, macht Inserts/Readupdates je Quelle und prüft dieselben Objekte mehrmals. Sichtbare Textquellen werden proZeile gelesen. | Nur noch nicht indizierte echte eigene Quellen, Insert je100er-Chunk, gemeinsamer Source-Readsync; pro page/count-Aufruf lokale frische Targetprüfung je(kind,id), danach verworfen. Textquelle nur für sichtbare Page gemeinsam. Jede spätere Anfrage prüft aktuelle Objekt-/Tenant-/Kontorechte erneut; read/read-all und Pushzustellung unverändert. |
| Profil: Original ProfileScreen/Backendcallbacks, persönliche profile-Route | Ein kompakter Profilvertrag; Konten-/Biometrie-/Diagnose-/Keybox-/Adminaktionen erst ausdrücklich. Kein Profil-Blob-/Dateisammelread im persönlichen Ablauf. | Kein zusätzlicher Profilumbau. Lokale Diagnose bleibt redigierter100er-Ring ohne private Inhalte; Root bearbeitet Shell-/Shared-GET-Kosten getrennt. |
| Bootstrap/Banner/Rollenhilfe: PersonalBootstrapRepository, öffentliche public/bootstrap bzw. persönliche clubs/{tenant}/bootstrap, bootstrap.enabled/native_role_guide_enabled | Kleine aktuelle Betriebs-/Banner-/Rollencapabilityantworten; serverseitig begrenzte Bannerliste, keine Blobdaten. Notwendige Auth-/Customhost-/Maintenancerechte machen zusätzliche globale TTLs riskant. | Keine neue Cache-/Pollingebene. Root optimiert gemeinsames aktuelles GET-Rechtesnapshot und misst reale DEV-Latenz; Wartung/RegistrationActive bleiben frisch. |
| Keybox: Original IglooKeyboxScreen/PersonalKeyboxRepository, keyboxes; native_keybox_enabled/Adminflag | Profil/Bus öffnen nur autorisierte eigene Boxen; Optionen und expliziter at-most-once Auftrag mit begrenztem Polling. Kein Backgroundprovider beim Listenlesen. | Kein kosmetischer Umbau. Echte Ausgabe bleibt separat geschlossen; keine Provideraktion/Testsendung. |
| Challenge: Original PenaltyGameScreen/PersonalPenaltyRepository, penalty/options/leaderboard; native_penalty_enabled | Kleine explizite Optionen-/paginierte Ranglistenreads; keine Beleg-/Blob-/Listenrelationsschleife. Start/Finish/Reset sind echte Fachaktionen. | Keine neue Cacheebene. Generation, Serverzeit, Replay-/Score-/Broadcastrechte bleiben unverändert. |

## Sicherheits- und Frischegrenzen

Alle neuen Backendfacts existieren nur als lokale Variablen der einen begrenzten
Leseprojektion und sind an aktuellen Actor, Tenant und selektierte IDs gebunden.
Kein TTL, Singleton-, Job- oder Konto-/Mandanten-übergreifender Cache. Dokumente
autorisieren jeden Quelltyp weiterhin mit dessen eigener echter Sichtquery;
Sponsor-Helferrechte behalten Gültigkeitsfenster und echte Funktionsrechte.
Notificationrechte werden nur innerhalb dieser einen Readprojektion für dieselbe
typisierte Objektidentität geteilt, niemals für Schreibaktionen oder Provider.
Read-all-Watermark, neue spätere Meldungen und fremde Quelltexte bleiben geschützt.
Die gemessene konstante Notificationqueryzahl betrifft viele Mitteilungen zum
gleichen typisierten Ziel. Für verschiedene Ziele bleiben deren unterschiedliche
frische Fachrechte erforderlich. Die bestehende Inbox zählt weiterhin die ganze
autorisierte Historie; es gibt keinen unzulässigen SQL-Limit-Trick vor der
Objektprüfung und keine Behauptung konstanter Kosten bei beliebig vielen
unterschiedlichen Objekten.

Es gibt keine neue Migration, keinen Import/Refresh, keinen echten Provideraufruf,
keine Mail-/Push-/Bank-/Bexioaktion und kein Deployment/Commit durch diesen Agenten.

## Nachweise

Neue Flutterfälle in performance_other_modules_test.dart prüfen echte
Anmeldungs-Optionsrequests, Fehlerretry und alten Kontokontext sowie originale
Trainingstab-/Refresh-/Background-/Kontowechselpfade. Bestehende Trainings-,
Anmeldungs- und HEIC-/Dateigrenzfälle laufen mit: final35/35 grün, davon4 neue.
Gezielte Dartanalyse sauber.

MobileOtherModulesPerformanceTest nutzt ausschliesslich synthetische eigene
Mandanten/Konten/Fachdatensätze in einer zurückgerollten PG-Transaktion mit
explizitem Opt-in und exaktem Datenbankguard. Sechs Fälle vergleichen
Notificationsdelta/Readsync/Privacy, konstante Trainings-/Dokument-/Sponsor-
Listenqueryzahl und Sponsor-Einzel-/Batchparität für Status/Parent/Trainer/Helper/
Administration samt Widerruf. Provider-/Dateisignaturabnahmen werden damit nicht
vorgetäuscht. Nach ausdrücklicher serieller Freigabe: neue Suite6/204 vollständig
grün in1:24.732. Notifications4/65, Dokumente3/120 und Training1/40 bestehende
Regressionsfälle grün. Sponsorenlauf6 bestehende Fachfälle grün; der alte
Readonlybillingfall erwartete für eine inzwischen real registrierte, noch
geschlossene Folgepaketroute404. Nach explizit freigegebener enger Fixturekorrektur
(native_sponsor_billing_enabled=false und erwartetes ReadyGate503) gezielt1/14
grün. Alle privaten Readonly-/NoProviderassertionen erhalten, kein Billingprodukt
geändert. Keine dauerhafte DDL, alle synthetischen Zeilen zurückgerollt; PG
ausdrücklich an den Planungsagenten für dessen einzelnen Menüfall zurückgegeben.

## Stabile Übergabe

Backend: MobileNotificationService, MobileTrainingController,
MobileMemberDocuments, MobileSponsorData und MobileSponsorController unter
app/ClubUnio/Mobile; neue MobileOtherModulesPerformanceTest und die explizite
Readonlybilling-Fixturekorrektur in MobileSponsorTest. App:
member_registrations_repository.dart, Original-TrainingPlannerScreen und neue
performance_other_modules_test.dart. Dieses Dokument enthält die vollständige
Modulmatrix. Keine Änderungen an MobileApi/mobile_app/Middleware, Shared-
MemberAccess-/Permissiondiensten, Spesen, Teamplanung oder My Teams. Root
übernimmt gezielten Commit/Sync, Gesamtsuite und geprüfte DEV-Auslieferung.
