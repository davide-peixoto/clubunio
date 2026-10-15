# PMP-Abdeckungsmatrix ClubUnio

Stand15.10.2026, Fachabgleich61. Automatisch aus einer expliziten Metadaten-Allowlist.

Quelle: operative PMP, Version26.14.0. SHA256: `fa8f081a56d37f808c1c354656809ae36061697337d73ab509b548906c66ce4a`.
Umfang: 104 Objekte, 814 Felder, 31 Beziehungen, 218 Eventreferenzen.

**Keine Vollstaendigkeitsquote:** Web bedeutet vorhandener Fachweg, nicht identische Felder/SQL oder bestaetigte Fachabnahme. Event-Hashes sind Inventar, kein Nachweis semantischer Gleichheit. Alle individuellen Freigaben bleiben dokumentiert offen.

Feldmetadaten inklusive Pflicht/Lookup/Elternabhaengigkeit in pmp-coverage.json. Keine Passwoerter, Defaults, SQL, Eventtexte oder Parameterwerte exportiert.

| PMP-Objekt | Felder | Stand | ClubUnio / Nachweis | Abgrenzung |
|---|---:|---|---|---|
|Mitglieder|43|Web|MemberResource, MemberService, CountryResource; ClubUnioTest.php|Eigene IDs/Lookups, Firma-Regel und Austritt; Verwaltungsfelder geschuetzt.|
|ApiDocsController.php|0|Ausgenommen|API/externe Pushzustellung; |API und Betrieb geparkt; interne Notifications bleiben vorhanden.|
|AnmeldungStatus|5|Web|RegistrationResource, RegistrationReviewService; RegistrationCompletionTest.php|TL/Pool/Wiedereintritt, Testmail, private Dokumente.|
|AuditTrail|10|Ersetzt|ObjectChangeResource; ClubUnioTest.php|Neues fachliches Audit, keine Altprotokolle als neue Aenderungen.|
|BankkontoStaff|5|Web|BankAccountsRelationManager, BankResource, NotesRelationManager; ClubUnioTest.php|IBAN verschluesselt, Revision/Audit, eigene Konten im Portal.|
|Beitragspreise|6|Ergaenzt58|SeasonResource, ContributionRulesRelationManager, TrainingSlotResource; ClubUnioTest.php, DossierMaintenanceTest.php|Beitragsregeln im Saisondossier; Trainingsauswahl ueber Tabellen.|
|Beitragstabelle|7|Ergaenzt58|SeasonResource, ContributionRulesRelationManager, TrainingSlotResource; ClubUnioTest.php, DossierMaintenanceTest.php|Beitragsregeln im Saisondossier; Trainingsauswahl ueber Tabellen.|
|Bemerkungen|4|Web|BankAccountsRelationManager, BankResource, NotesRelationManager; ClubUnioTest.php|IBAN verschluesselt, Revision/Audit, eigene Konten im Portal.|
|Bus|3|Teilweise|BusReservations, BusReservationService, BusKeyboxAccessService; BusReservationTest.php, BusKeyboxSimulationTest.php|Webworkflow vorhanden; echte Keybox und historische Zuordnungen offen.|
|BusReservierungen|17|Teilweise|BusReservations, BusReservationService, BusKeyboxAccessService; BusReservationTest.php, BusKeyboxSimulationTest.php|Webworkflow vorhanden; echte Keybox und historische Zuordnungen offen.|
|Events|6|Ergaenzt58|DossierMaintenanceService, ClubEventResource, ClubFunctionResource, QualificationResource; DossierMaintenanceTest.php|Schreibpflege, Pflichtfelder, Mehrfachqualifikationen, Gaeste-Master/Detail, Version/Audit.|
|ExportLog|9|Technisch|Laravel/Filament bzw. ungenutztes Legacy-Objekt; |Keine ungepruefte Uebernahme von Framework-Innentabellen oder ungenutzten Objekten.|
|Familie|7|Web|FamilyResource, FamilyMemberResource, FamilyReportResource; ClubUnioTest.php|Familienrabatte und getrennte genehmigte Elternrechte.|
|FamilieMitglieder|7|Web|FamilyResource, FamilyMemberResource, FamilyReportResource; ClubUnioTest.php|Familienrabatte und getrennte genehmigte Elternrechte.|
|Funktionaere|5|Ergaenzt58|DossierMaintenanceService, ClubEventResource, ClubFunctionResource, QualificationResource; DossierMaintenanceTest.php|Schreibpflege, Pflichtfelder, Mehrfachqualifikationen, Gaeste-Master/Detail, Version/Audit.|
|Funktionen|4|Ergaenzt58|DossierMaintenanceService, ClubEventResource, ClubFunctionResource, QualificationResource; DossierMaintenanceTest.php|Schreibpflege, Pflichtfelder, Mehrfachqualifikationen, Gaeste-Master/Detail, Version/Audit.|
|Gaeste|4|Ergaenzt58|DossierMaintenanceService, ClubEventResource, ClubFunctionResource, QualificationResource; DossierMaintenanceTest.php|Schreibpflege, Pflichtfelder, Mehrfachqualifikationen, Gaeste-Master/Detail, Version/Audit.|
|iniBexio|5|Ersetzt|SettingResource, SettingsService; ClubUnioTest.php|Vereinheitlichte verschluesselte Einstellungen, keine Kopie alter Parameterseiten.|
|iniParameter|7|Ersetzt|SettingResource, SettingsService; ClubUnioTest.php|Vereinheitlichte verschluesselte Einstellungen, keine Kopie alter Parameterseiten.|
|Institut|12|Web|BankAccountsRelationManager, BankResource, NotesRelationManager; ClubUnioTest.php|IBAN verschluesselt, Revision/Audit, eigene Konten im Portal.|
|Liste Offene Beiträge|19|Web|MemberReportResource, PlayerReportResource, StaffReportResource, ReportExportService; ClubUnioTest.php|Funktionsaequivalente gefilterte Berichte statt identischer SQL-Views; Spezialspalten fachlich abnehmen.|
|Liste Sponsor|16|Web|MemberReportResource, PlayerReportResource, StaffReportResource, ReportExportService; ClubUnioTest.php|Funktionsaequivalente gefilterte Berichte statt identischer SQL-Views; Spezialspalten fachlich abnehmen.|
|ListeBankkontenStaff|18|Web|MemberReportResource, PlayerReportResource, StaffReportResource, ReportExportService; ClubUnioTest.php|Funktionsaequivalente gefilterte Berichte statt identischer SQL-Views; Spezialspalten fachlich abnehmen.|
|ListeEhrenmitglied|16|Web|MemberReportResource, PlayerReportResource, StaffReportResource, ReportExportService; ClubUnioTest.php|Funktionsaequivalente gefilterte Berichte statt identischer SQL-Views; Spezialspalten fachlich abnehmen.|
|ListeFreimitglied|16|Web|MemberReportResource, PlayerReportResource, StaffReportResource, ReportExportService; ClubUnioTest.php|Funktionsaequivalente gefilterte Berichte statt identischer SQL-Views; Spezialspalten fachlich abnehmen.|
|ListePassivMitglieder|15|Web|MemberReportResource, PlayerReportResource, StaffReportResource, ReportExportService; ClubUnioTest.php|Funktionsaequivalente gefilterte Berichte statt identischer SQL-Views; Spezialspalten fachlich abnehmen.|
|ListeSaisonSpielerliste|13|Web|MemberReportResource, PlayerReportResource, StaffReportResource, ReportExportService; ClubUnioTest.php|Funktionsaequivalente gefilterte Berichte statt identischer SQL-Views; Spezialspalten fachlich abnehmen.|
|ListeSpielerliste|13|Web|MemberReportResource, PlayerReportResource, StaffReportResource, ReportExportService; ClubUnioTest.php|Funktionsaequivalente gefilterte Berichte statt identischer SQL-Views; Spezialspalten fachlich abnehmen.|
|ListeSpielerOhneBeitrag|6|Web|MemberReportResource, PlayerReportResource, StaffReportResource, ReportExportService; ClubUnioTest.php|Funktionsaequivalente gefilterte Berichte statt identischer SQL-Views; Spezialspalten fachlich abnehmen.|
|ListeSpots|16|Web|MemberReportResource, PlayerReportResource, StaffReportResource, ReportExportService; ClubUnioTest.php|Funktionsaequivalente gefilterte Berichte statt identischer SQL-Views; Spezialspalten fachlich abnehmen.|
|ListeSpotsFamilie|0|Web|MemberReportResource, PlayerReportResource, StaffReportResource, ReportExportService; ClubUnioTest.php|Funktionsaequivalente gefilterte Berichte statt identischer SQL-Views; Spezialspalten fachlich abnehmen.|
|ListeSupporter|16|Web|MemberReportResource, PlayerReportResource, StaffReportResource, ReportExportService; ClubUnioTest.php|Funktionsaequivalente gefilterte Berichte statt identischer SQL-Views; Spezialspalten fachlich abnehmen.|
|ListeZurBezahlung|20|Web|MemberReportResource, PlayerReportResource, StaffReportResource, ReportExportService; ClubUnioTest.php|Funktionsaequivalente gefilterte Berichte statt identischer SQL-Views; Spezialspalten fachlich abnehmen.|
|languages|14|Technisch|Laravel/Filament bzw. ungenutztes Legacy-Objekt; |Keine ungepruefte Uebernahme von Framework-Innentabellen oder ungenutzten Objekten.|
|MeldungenSignage|5|Web|SfvCompetitions, CompetitionResource, SignagePreview; ClubUnioTest.php, SignageFeedsTest.php|SFV-Pruefung bewahrt manuelle Garderoben; Vereinskonflikte fachlich klaeren.|
|MitgliederAnmeldungen|41|Web|RegistrationResource, RegistrationReviewService; RegistrationCompletionTest.php|TL/Pool/Wiedereintritt, Testmail, private Dokumente.|
|MitgliederArt|3|Web|MemberResource, MemberService, CountryResource; ClubUnioTest.php|Eigene IDs/Lookups, Firma-Regel und Austritt; Verwaltungsfelder geschuetzt.|
|MitgliederStatus|3|Web|MemberResource, MemberService, CountryResource; ClubUnioTest.php|Eigene IDs/Lookups, Firma-Regel und Austritt; Verwaltungsfelder geschuetzt.|
|myuserprofile|11|Technisch|Laravel/Filament bzw. ungenutztes Legacy-Objekt; |Keine ungepruefte Uebernahme von Framework-Innentabellen oder ungenutzten Objekten.|
|Platzverfügbarkeit|4|Web|SfvCompetitions, CompetitionResource, SignagePreview; ClubUnioTest.php, SignageFeedsTest.php|SFV-Pruefung bewahrt manuelle Garderoben; Vereinskonflikte fachlich klaeren.|
|Positionen|11|Web/Sandbox|InvoiceResource, BexioReconciliation; ClubUnioTest.php|Keine echten Zahlungen; manueller Bexio-Teilgutschrifttest bleibt offen.|
|Rechnung|10|Web/Sandbox|InvoiceResource, BexioReconciliation; ClubUnioTest.php|Keine echten Zahlungen; manueller Bexio-Teilgutschrifttest bleibt offen.|
|Saison|3|Ergaenzt58|SeasonResource, ContributionRulesRelationManager, TrainingSlotResource; ClubUnioTest.php, DossierMaintenanceTest.php|Beitragsregeln im Saisondossier; Trainingsauswahl ueber Tabellen.|
|Schluesselliste|6|Ergaenzt58|DossierMaintenanceService, ClubEventResource, ClubFunctionResource, QualificationResource; DossierMaintenanceTest.php|Schreibpflege, Pflichtfelder, Mehrfachqualifikationen, Gaeste-Master/Detail, Version/Audit.|
|settings|8|Technisch|Laravel/Filament bzw. ungenutztes Legacy-Objekt; |Keine ungepruefte Uebernahme von Framework-Innentabellen oder ungenutzten Objekten.|
|Spesenbelege|21|Web/Sandbox|ExpenseResource, ExpenseService; ClubUnioTest.php|Status/AI-Vergleich/Dressreinigung/Freigabe; kein echter Auszahlungsbetrieb.|
|SpesenPositionen|14|Web/Sandbox|ExpenseResource, ExpenseService; ClubUnioTest.php|Status/AI-Vergleich/Dressreinigung/Freigabe; kein echter Auszahlungsbetrieb.|
|SpesenTyp|5|Web/Sandbox|ExpenseResource, ExpenseService; ClubUnioTest.php|Status/AI-Vergleich/Dressreinigung/Freigabe; kein echter Auszahlungsbetrieb.|
|Spielerliste_mit_Eltern|29|Web|MemberReportResource, PlayerReportResource, StaffReportResource, ReportExportService; ClubUnioTest.php|Funktionsaequivalente gefilterte Berichte statt identischer SQL-Views; Spezialspalten fachlich abnehmen.|
|SPONSOR|7|Ergaenzt58|DossierMaintenanceService, ClubEventResource, ClubFunctionResource, QualificationResource; DossierMaintenanceTest.php|Schreibpflege, Pflichtfelder, Mehrfachqualifikationen, Gaeste-Master/Detail, Version/Audit.|
|StaffQualifikationen|3|Ergaenzt58|DossierMaintenanceService, ClubEventResource, ClubFunctionResource, QualificationResource; DossierMaintenanceTest.php|Schreibpflege, Pflichtfelder, Mehrfachqualifikationen, Gaeste-Master/Detail, Version/Audit.|
|TeamList|5|Web|TeamResource, TeamSeasonResource, TeamAssignmentService; ClubUnioTest.php|Lookups und Zuordnungen mit Rollen-/Dubletten-/Finanzschutz.|
|Teams|11|Web|TeamResource, TeamSeasonResource, TeamAssignmentService; ClubUnioTest.php|Lookups und Zuordnungen mit Rollen-/Dubletten-/Finanzschutz.|
|TeamSatffFunktion|3|Web|TeamResource, TeamSeasonResource, TeamAssignmentService; ClubUnioTest.php|Lookups und Zuordnungen mit Rollen-/Dubletten-/Finanzschutz.|
|TeamsSaison|4|Web|TeamResource, TeamSeasonResource, TeamAssignmentService; ClubUnioTest.php|Lookups und Zuordnungen mit Rollen-/Dubletten-/Finanzschutz.|
|TeamStaff|5|Web|TeamResource, TeamSeasonResource, TeamAssignmentService; ClubUnioTest.php|Lookups und Zuordnungen mit Rollen-/Dubletten-/Finanzschutz.|
|Trainingszeiten|15|Ergaenzt58|SeasonResource, ContributionRulesRelationManager, TrainingSlotResource; ClubUnioTest.php, DossierMaintenanceTest.php|Beitragsregeln im Saisondossier; Trainingsauswahl ueber Tabellen.|
|userlevelpermissions|4|Ersetzt|TenantAccountResource, AccountMemberLinkResource; ClubUnioTest.php, TenantNavigationTest.php|Explizite Rollen/Objektberechtigungen statt alter Bitmasken; keine SaaS-Erweiterung.|
|userlevels|3|Ersetzt|TenantAccountResource, AccountMemberLinkResource; ClubUnioTest.php, TenantNavigationTest.php|Explizite Rollen/Objektberechtigungen statt alter Bitmasken; keine SaaS-Erweiterung.|
|Users|13|Ersetzt|TenantAccountResource, AccountMemberLinkResource; ClubUnioTest.php, TenantNavigationTest.php|Explizite Rollen/Objektberechtigungen statt alter Bitmasken; keine SaaS-Erweiterung.|
|Vereine|4|Web|SfvCompetitions, CompetitionResource, SignagePreview; ClubUnioTest.php, SignageFeedsTest.php|SFV-Pruefung bewahrt manuelle Garderoben; Vereinskonflikte fachlich klaeren.|
|Wettkaempfe|21|Web|SfvCompetitions, CompetitionResource, SignagePreview; ClubUnioTest.php, SignageFeedsTest.php|SFV-Pruefung bewahrt manuelle Garderoben; Vereinskonflikte fachlich klaeren.|
|DashboardController.php|0|Ersetzt|ClubOverview, SignagePreview, ExpenseService; ClubUnioTest.php|Neue Webmodule statt Legacy-Customseiten; AI-Ausgaben im Test gemockt.|
|ExpenseAiController.php|0|Ersetzt|ClubOverview, SignagePreview, ExpenseService; ClubUnioTest.php|Neue Webmodule statt Legacy-Customseiten; AI-Ausgaben im Test gemockt.|
|ApiExceptionSubscriber.php|0|Ausgenommen|API/externe Pushzustellung; |API und Betrieb geparkt; interne Notifications bleiben vorhanden.|
|Dashboard.php|0|Ersetzt|ClubOverview, SignagePreview, ExpenseService; ClubUnioTest.php|Neue Webmodule statt Legacy-Customseiten; AI-Ausgaben im Test gemockt.|
|BexioStatusMapping|3|Web/Sandbox|InvoiceResource, BexioReconciliation; ClubUnioTest.php|Keine echten Zahlungen; manueller Bexio-Teilgutschrifttest bleibt offen.|
|sysdiagrams|0|Technisch|Laravel/Filament bzw. ungenutztes Legacy-Objekt; |Keine ungepruefte Uebernahme von Framework-Innentabellen oder ungenutzten Objekten.|
|Countries|6|Web|MemberResource, MemberService, CountryResource; ClubUnioTest.php|Eigene IDs/Lookups, Firma-Regel und Austritt; Verwaltungsfelder geschuetzt.|
|vCountriesLookup|7|Web|MemberResource, MemberService, CountryResource; ClubUnioTest.php|Eigene IDs/Lookups, Firma-Regel und Austritt; Verwaltungsfelder geschuetzt.|
|ListeFamilienMitKinder|10|Web|FamilyResource, FamilyMemberResource, FamilyReportResource; ClubUnioTest.php|Familienrabatte und getrennte genehmigte Elternrechte.|
|TeamMaterial|0|Technisch|Laravel/Filament bzw. ungenutztes Legacy-Objekt; |Keine ungepruefte Uebernahme von Framework-Innentabellen oder ungenutzten Objekten.|
|iniWhatsApp|5|Ersetzt|SettingResource, SettingsService; ClubUnioTest.php|Vereinheitlichte verschluesselte Einstellungen, keine Kopie alter Parameterseiten.|
|startupscript.js|0|Technisch|Laravel/Filament bzw. ungenutztes Legacy-Objekt; |Keine ungepruefte Uebernahme von Framework-Innentabellen oder ungenutzten Objekten.|
|ListeMitgliederOhneTeam|7|Web|MemberReportResource, PlayerReportResource, StaffReportResource, ReportExportService; ClubUnioTest.php|Funktionsaequivalente gefilterte Berichte statt identischer SQL-Views; Spezialspalten fachlich abnehmen.|
|SignageController.php|0|Ersetzt|ClubOverview, SignagePreview, ExpenseService; ClubUnioTest.php|Neue Webmodule statt Legacy-Customseiten; AI-Ausgaben im Test gemockt.|
|iniApp|5|Ersetzt|SettingResource, SettingsService; ClubUnioTest.php|Vereinheitlichte verschluesselte Einstellungen, keine Kopie alter Parameterseiten.|
|AppAnkuendigungen|8|Ergaenzt61|AnnouncementResource, AnnouncementService, AnnouncementPortalController; AnnouncementTest.php|Redaktioneller Webentwurf, Vorschau, Empfaengersnapshot, interne Notifications/Rueckzug. Keine externe Zustellung oder Altimporte.|
|WhatsAppWebhookLog|0|Ausgenommen|WhatsApp-Transport/Betrieb; |Keine generierten Backoffice-Felder fuer diese Transporttabellen.|
|vWhatsAppAdressbuch|0|Ausgenommen|WhatsApp-Transport/Betrieb; |Keine generierten Backoffice-Felder fuer diese Transporttabellen.|
|vWhatsAppInbox|20|Ausgenommen|WhatsApp-Transport/Betrieb; |Keine generierten Backoffice-Felder fuer diese Transporttabellen.|
|WhatsAppKontakte|0|Ausgenommen|WhatsApp-Transport/Betrieb; |Keine generierten Backoffice-Felder fuer diese Transporttabellen.|
|WhatsAppKonversationen|0|Ausgenommen|WhatsApp-Transport/Betrieb; |Keine generierten Backoffice-Felder fuer diese Transporttabellen.|
|WhatsAppNachrichten|0|Ausgenommen|WhatsApp-Transport/Betrieb; |Keine generierten Backoffice-Felder fuer diese Transporttabellen.|
|WhatsAppVorlagen|0|Ausgenommen|WhatsApp-Transport/Betrieb; |Keine generierten Backoffice-Felder fuer diese Transporttabellen.|
|WhatsAppVersandQueue|0|Ausgenommen|WhatsApp-Transport/Betrieb; |Keine generierten Backoffice-Felder fuer diese Transporttabellen.|
|DokumentTyp|5|Ergaenzt59|MemberDocumentResource, MemberDocumentMaintenanceService, DocumentsRelationManager; MemberChangesDocumentsTest.php|Allgemeine private Uploadpflege, Lookup-Typ, Dossier/Vorschau, explizite Portal-Freigabe, Revision/Audit; keine Signatur.|
|MitgliederAnhaenge|13|Ergaenzt59|MemberDocumentResource, MemberDocumentMaintenanceService, DocumentsRelationManager; MemberChangesDocumentsTest.php|Allgemeine private Uploadpflege, Lookup-Typ, Dossier/Vorschau, explizite Portal-Freigabe, Revision/Audit; keine Signatur.|
|Liste TeamStaff Saison|21|Web|MemberReportResource, PlayerReportResource, StaffReportResource, ReportExportService; ClubUnioTest.php|Funktionsaequivalente gefilterte Berichte statt identischer SQL-Views; Spezialspalten fachlich abnehmen.|
|AppPushToken|0|Ausgenommen|API/externe Pushzustellung; |API und Betrieb geparkt; interne Notifications bleiben vorhanden.|
|AppPushNachricht|0|Ausgenommen|API/externe Pushzustellung; |API und Betrieb geparkt; interne Notifications bleiben vorhanden.|
|AppPushLog|0|Ausgenommen|API/externe Pushzustellung; |API und Betrieb geparkt; interne Notifications bleiben vorhanden.|
|AppNotification|0|Ausgenommen|API/externe Pushzustellung; |API und Betrieb geparkt; interne Notifications bleiben vorhanden.|
|TechnischeLeiterTeams|10|Web|TeamResource, TeamSeasonResource, TeamAssignmentService; ClubUnioTest.php|Lookups und Zuordnungen mit Rollen-/Dubletten-/Finanzschutz.|
|TrainingPlannerAuditLog|0|Teilweise|TrainingPlanService, LegacyTrainingPlanImporter; TrainingPlanTest.php, LegacyTrainingMigrationTest.php|29 Teamzuordnungen, 18 Qualitaetspruefungen und ein ungueltiger Altplan offen.|
|Verbandsspiele|40|Web|SfvCompetitions, CompetitionResource, SignagePreview; ClubUnioTest.php, SignageFeedsTest.php|SFV-Pruefung bewahrt manuelle Garderoben; Vereinskonflikte fachlich klaeren.|
|TeamTermine|0|Web|TeamPlanningController, PlanningService, PlanningAbsenceService; TeamPlanningTest.php|Termine, Absenzen, Fristsperren und Datenschutz; App-API unveraendert.|
|TeamTerminTeilnahmen|0|Web|TeamPlanningController, PlanningService, PlanningAbsenceService; TeamPlanningTest.php|Termine, Absenzen, Fristsperren und Datenschutz; App-API unveraendert.|
|MitgliederAenderungsantraege|18|Ergaenzt59|MemberChangeRequestResource, MemberChangeService, MemberChangePortalController; MemberChangesDocumentsTest.php|Eigener Web-Pruefweg, Alt/Neu, Rechte/Revision/Konflikt, verschluesselte Snapshots; kein Altantragsimport.|
|TeamAbsenzen|0|Web|TeamPlanningController, PlanningService, PlanningAbsenceService; TeamPlanningTest.php|Termine, Absenzen, Fristsperren und Datenschutz; App-API unveraendert.|
|MitgliederQuittungen|0|Pruefen|Keine PMP-Felder/Generierung definiert; |Fachvertrag und Nutzung vor Implementierung klaeren.|
|AppPenaltyChallenge|0|Ausgenommen|App-Spielmodul; |App-/API-spezifisch, kein Backoffice-Ersatz in diesem Auftrag.|
|AppPenaltySessions|0|Ausgenommen|App-Spielmodul; |App-/API-spezifisch, kein Backoffice-Ersatz in diesem Auftrag.|
|AppPenaltyBest|0|Ausgenommen|App-Spielmodul; |App-/API-spezifisch, kein Backoffice-Ersatz in diesem Auftrag.|

## Master/Detail und Lookup-Richtung

| PMP | ClubUnio-Einordnung |
|---|---|
|BankkontoStaff -> Institut|Lookup/Referenz; keine invertierte untergeordnete Schreibpflege|
|Familie -> FamilieMitglieder|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|Bus -> BusReservierungen|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|Events -> Gaeste|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|Events -> Teams|Lookup/Referenz; keine invertierte untergeordnete Schreibpflege|
|Funktionaere -> Funktionen|Lookup/Referenz; keine invertierte untergeordnete Schreibpflege|
|Funktionaere -> StaffQualifikationen|Lookup/Referenz; keine invertierte untergeordnete Schreibpflege|
|Rechnung -> Positionen|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|TeamStaff -> TeamSatffFunktion|Lookup/Referenz; keine invertierte untergeordnete Schreibpflege|
|Saison -> Spesenbelege|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|Saison -> Beitragstabelle|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|Spesenbelege -> SpesenPositionen|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|TeamsSaison -> TeamStaff|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|TeamsSaison -> Trainingszeiten|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|TeamsSaison -> TeamList|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|TeamsSaison -> MitgliederAnmeldungen|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|Teams -> TeamsSaison|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|Saison -> TeamsSaison|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|Mitglieder -> BankkontoStaff|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|Mitglieder -> Bemerkungen|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|Mitglieder -> FamilieMitglieder|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|Mitglieder -> Funktionaere|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|Mitglieder -> Gaeste|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|Mitglieder -> Rechnung|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|Mitglieder -> Schluesselliste|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|Mitglieder -> Spesenbelege|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|Mitglieder -> SPONSOR|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|Mitglieder -> TeamStaff|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|Mitglieder -> MitgliederAnmeldungen|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|Mitglieder -> TeamList|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
|Mitglieder -> MitgliederAnhaenge|Dossier/Fachliste; Zuordnung nicht aus Clientdaten ableiten|
