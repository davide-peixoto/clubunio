# Parameterinventur: weitere Bereiche

Stand03.11.2026, Verein SV Sissach. Nur lesende Untersuchung, keine Migration,
Loeschung, Parameterkorrektur, Providerabfrage oder Deployment. PHPMaker DEV/PROD
nicht aufgerufen. Parameterwerte wurden weder ausgegeben noch dokumentiert.

## Methode und Grenzen

Metadaten aus ClubUnio-PostgreSQL in einer READ ONLY-Transaktion: area, key,
enabled, is_secret.192 Parameter fuer diesen Verein. Lokaler Anwendungscode,
Konfiguration und Routen sowie die Parameterzugriffe des deployten ClubUnio-Codes
auf dem Pi untersucht. Generische Anzeige/Verwaltung/Import ist kein fachlicher
Verbraucher. Dynamisch zusammengesetzte BEXIO-Schluessel separat nachverfolgt.

39 Parameter haben einen erkennbaren fachlichen Leser,153 derzeit keinen.
Dies ist keine Loeschfreigabe: externe Skripte/Automationen und kuenftige
Funktionen sind dadurch nicht ausgeschlossen. Keine Nutzungsstatistik oder
historische Laufzeitanalyse. enabled=false allein beweist keine Nichtverwendung.

| Bereich | Bestand | Mit fachlichem Leser | Ohne erkennbaren Leser |
|---|---:|---:|---:|
| App |15|0|15|
| Bexio |18|16|2|
| Allgemein |2|2|0|
| Integrationen (historisch iniWhatsApp) |151|15|136|
| Oeffentliche Seiten |2|2|0|
| Signage-Design |3|3|0|
| Sponsorenlauf |1|1|0|

## WhatsApp und Chatwoot:15 Kandidaten

Kein fachlicher Leser lokal oder im deployten ClubUnio-Code gefunden:

- META_ACCESS_TOKEN, META_API_BASE_URL, META_API_VERSION, META_APP_SECRET,
  META_BUSINESS_ACCOUNT_ID, META_PHONE_NUMBER_ID, META_VERIFY_TOKEN (7).
- CHATWOOT_ACCOUNT_ID, CHATWOOT_API_TOKEN, CHATWOOT_BASE_URL, CHATWOOT_INBOX_ID (4).
- MODE, REQUIRE_OPTIN, DEFAULT_COUNTRY, DEFAULT_COUNTRY_CODE (4).

Alle15 haben enabled=false; entscheidend ist jedoch der fehlende Verbraucher.
Die vier unspezifisch benannten Werte passen zum historischen Transportbereich,
ihre ausschliessliche Verwendung fuer WhatsApp wird nicht aus dem Namen bewiesen.
CLUBUNIO-PMP-COVERAGE.md fuehrt WhatsApp-Transporttabellen als ausgenommen.
Empfehlung: nach fachlicher Freigabe verschluesselt archivieren und entfernen,
sofern kein separater WhatsApp-/Chatwoot-Anschluss fuer ClubUnio vorgesehen ist.
Keine Aussage ueber noch benoetigte Werte im parallel laufenden PHPMaker-System.

## Weitere integrations-Kandidaten (121)

| Gruppe | Anzahl | Befund / Empfehlung |
|---|---:|---|
| N8N_* inklusive N8N_TABLE_* |63|Kein Leser; alte Webhook-/Tabellensteuerung. Externe n8n-Flows vor Loeschung bestaetigen.|
| BEXIO_SYNC_* plus BEXIO_CRON_TOKEN |12|Kein Leser; alte Schedulersteuerung, nicht aktuelle Bexio-OIDC-Verbindung.|
| CRON_HUB_* |8|Kein Leser; historische Hosting-Orchestrierung.|
| SFV-Restparameter |15|Kein Leser; Liste unten. Authentisierung erfolgt frisch pro Abruf.|
| FCM_* |4|Kein Leser; Push in ClubUnio-Mobile derzeit deaktiviert. Fuer spaetere Push-Anbindung erst Konzeptentscheid.|
| OPENAI_EXPENSE_* |11|Kein Leser dieser DB-Parameter. Aktueller lokaler OCR-Adapter nutzt eigene Azure-Konfiguration; vor Bereinigung mit Mobile-Paket abgleichen.|
| OPENAI_TRAINING_MAX_FILE_RESULTS, OPENAI_TRAINING_STORE_RESPONSES, OPENAI_TRAINING_TIMEOUT_SECONDS |3|Kein Leser; TrainingPlanAi verwendet feste Werte8/false/85Sekunden.|
| INSTAGRAM_USER_ID, INSTAGRAM_SECRET |2|Kein Leser im aktuellen Feed; ACCESS_TOKEN dagegen behalten. Externe Tokenpflege nicht geprueft.|
| TEAM_PLANNING_REMINDERS_CRON_TOKEN |1|Kein Leser des alten DB-Tokens.|
| WEB_UI_MODERN_ENABLED, WEB_UI_MODERN_USERS |2|Kein Leser; alter PHPMaker-Oberflaechenschalter.|

SFV-Restparameter: SFV_CRON_TOKEN, SFV_HTTP_TIMEOUT_SECONDS, SFV_SYNC_ACTIVE,
SFV_SYNC_DATE_FROM, SFV_SYNC_DATE_UNTIL, SFV_SYNC_DEBUG_ERRORS,
SFV_SYNC_LAST_RESULT, SFV_SYNC_LAST_RUN_AT, SFV_SYNC_LAST_RUN_SLOT,
SFV_SYNC_LAST_SUCCESS_AT, SFV_SYNC_RUN_HOURS, SFV_SYNC_TEAM_ID,
SFV_SYNC_TIMEZONE, SFV_USER_TOKEN, SFV_USER_TOKEN_EXPIRES_AT.

## Integrationen:15 Werte behalten

- SFV_API_BASE_URL, SFV_APPLICATION_KEY, SFV_APPLICATION_PASS, SFV_CLUB_ID,
  SFV_LANGUAGE, SFV_SEASON_ID, SFV_SEASON_ID_AUTO (7).
  Leser: SfvPlanningService::setting/refreshNow. Saison-ID bedingt verwendet,
  wenn automatische Saisonberechnung ausgeschaltet ist.
- OPENAI_API_KEY, OPENAI_TRAINING_VECTOR_STORE_ID, OPENAI_TRAINING_MODEL (3).
  Leser: TrainingPlanAi::generate, unabhaengig vom enabled-Flag.
- INSTAGRAM_ACCESS_TOKEN (1) und SIGNAGE_WEATHER_ENABLED/LABEL/LAT/LON (4).
  Leser: SignageFeedService. enabled wird hier beruecksichtigt.

Damit darf integrations/iniWhatsApp keinesfalls pauschal entfernt werden.

## App:15 Werte ohne aktuellen Parameterleser

IglooBodID_1/2/3, IglooEmergCode_1/2/3, igCode (7),
MaintenanceMode, MaintenanceMessage (2),
ModulesSpieler, ModulesTechnischeLeiter, ModulesTrainer (3),
RegistrationActive, TeamPlanningTeams, TrainingPlannerUsers (3).

Empfehlung: zunaechst zurueckstellen. Rollen-/Modulfreigaben und Mobile-Funktionen
werden im neuen Code anders bestimmt; das beweist nicht, dass alle historischen
Funktionen fachlich entfallen sollen. Insbesondere Schlosszugang, Registrierung,
Wartungsmodus und Team-Pilot benoetigen eine bewusste Produktentscheidung.

## Bexio

Kandidaten ohne Leser: BEXIO_ACCOUNT_ID und BEXIO_OWNER_ID.
Nicht mit dem verwendeten BEXIO_BANK_ACCOUNT_ID oder BEXIO_USER_ID verwechseln.
Behalten: BEXIO_ACTIVE; BEXIO_CLIENT_ID/CLIENT_SECRET/AUTHORIZE_SCOPES;
BEXIO_OIDC_CONNECTION; BEXIO_BANK_ACCOUNT_ID/BILL_CONTACT_PARTNER_ID/BILL_DUE_DAYS/
CURRENCY_ID/INVOICE_DUE_DAYS/LANGUAGE_ID/MWST_TYPE/PAYMENT_TYPE_ID/TAX_ID/UNIT_ID/USER_ID.
Leser: BexioConnectionService, BexioPayloadBuilder, BexioReconciliationService,
BexioFinancialActionService. BEXIO_-Schluessel im PayloadBuilder teils dynamisch.

## Uebrige Bereiche

Allgemein: SaisonID und SIGNAGE_PERIOD_ID bleiben verwendet.
Public: REGISTRATION_PUBLIC_ENABLED und SIGNAGE_PUBLIC_ENABLED bleiben verwendet.
Signage: SIGNAGE_BRAND_COLOR/LOGO/TITLE bleiben verwendet.
Sponsor_run: BEXIO_BILLING bleibt verwendet.
Leser: MobileSeasonScope, ActiveTrainingPlan, PublicWebService,
SignageBrandService und SponsorRunBillingService.

## Naechster Schritt erst nach Freigabe

1. WhatsApp/Chatwoot sowie n8n fachlich bestaetigen; moegliche externe Verbraucher
   klaeren. Niemals alte PHPMaker-Parameter mitbereinigen.
2. Eine explizite Schluesselliste je Bereich festlegen; keine Loeschung nach
   Namenspraefix oder enabled=false allein.
3. Verschluesselte Sicherung mit Auditzeilen, gezielte Migration, Tests und
   Importfilter, damit die Altparameter bei spaeterer Migration nicht zurueckkommen.
4. App/FCM und parallele Mobile-/OCR-Entwicklung gesondert abnehmen.

Diese Inventur fuehrt keinen dieser Schritte aus. Kein Commit/Deployment der
laufenden Anwendung und keine DB-Aenderung im Rahmen dieser Untersuchung.
