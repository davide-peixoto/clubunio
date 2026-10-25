# Sponsorenlauf: ClubUnio Web

Stand 25.10.2026. Eigenstaendige ClubUnio-Implementierung; Legacy ausschliesslich
als lesende Fachreferenz. Keine App-API, SaaS- oder Betriebsumstellung, kein
PHPMaker-Import, keine Bestandsauffrischung, keine Bankzahlung. Billing wird
separat ueber SponsorRunBillingService/Migration75 integriert.

## Schema und Fachvertrag

Migration74 ist additiv und nach Parent-Bestaetigung gezielt angewendet.
Das Schema ist eingefroren; keine nachtraegliche Aenderung der Migration.
Alle Fachtabellen liegen in clubora, IDs bigint, Mandant explizit tenant_id.

- sponsor_run_statuses: tenant_id,code,name,sort_order,active. Codes
  draft/open/closed/cancelled; Beschriftungen und Auswahl aus der Tabelle.
- sponsor_run_events: id,tenant_id,season_id,title,place,event_date,
  registration_deadline,status_code,revision,created_by,created_at,updated_at.
- sponsor_run_event_teams: tenant_id,event_id,team_season_id; eindeutige Auswahl.
- sponsor_run_participants: id,tenant_id,event_id,member_id,team_season_id,
  required,checked_in_at,checked_in_by,draft_laps,final_laps,approved_at,
  approved_by,revision,created_at,updated_at. Unique event_id/member_id.
- sponsor_run_pledges: id,tenant_id,event_id,participant_id,sponsor_name,
  payment_mode (invoice/collective), amount_type (fixed/per_lap),amount_cents,
  cap_cents,recipient_type (person/company),recipient_encrypted,
  collector_account_id,collector_confirmed_at,request_key,payload_hash,
  created_by,revision,created_at,updated_at. Unique tenant_id/request_key.
- sponsor_run_helpers: tenant_id,event_id,account_id,can_checkin,
  can_record_laps,valid_from,valid_until,revision.
- sponsor_run_audits: tenant_id,event_id,actor_id,action,reason,before_value,
  after_value,created_at. Keine Adress-/Tokenkopie in Audits.
- sponsor_run_links: participant_id eindeutig; alias,token_hash,
  token_encrypted,expires_at,revoked_at,consented_at,created_by,revision.
- sponsor_run_public_pending: link_id,link_revision,request_key,payload_hash,
  token_hash,payload_encrypted,expires_at,pledge_id,confirmed_at.
- sponsor_run_public_rates: bucket_hash,hits,expires_at; keine Klartext-IP.
- sponsor_run_public_outbox: tenant_id,pending_id,payload_encrypted,
  delivery_state (held/sending/accepted/failed),created_at,finished_at.

recipient_encrypted ist Laravel-Crypt-JSON mit company,first_name,last_name,
street,house_number,postal_code,city,country (ISO2),email. Keine Mitgliederanlage
fuer Sponsoren. Sammelzahlung verlangt ausdrueckliche Uebernahme durch den
angemeldeten Actor; keine Bestaetigung eines fremden Sammlers.

SponsorRunService::billingSource(Account,Tenant,int pledgeId) liefert:
event_id,season_id,pledge_id,participant_id,title,description,total_cents,
recipient mit type und den obigen Adressfeldern; zusaetzlich amount_type,
amount_cents,cap_cents,payment_mode,collector_account_id,
collector_confirmed_at,final_laps,approved_at und drei revision-Felder.
Der Aufrufer muss eine aeussere ClubUnio-Transaktion halten, damit die Sperren
event -> participant -> pledge bis zum Rechnungssnapshot erhalten bleiben.
Nur Pflichtteilnahmen mit finaler Freigabe, kein Entwurf/abgesagter Anlass.
billablePledges(Account,Tenant,int eventId) liefert Collection-Eintraege
id,label,total_cents; bei Rechnungserstellung immer billingSource frisch pruefen.
totalCents(object|array pledge,?int finalLaps) rechnet ausschliesslich ganzzahlige
Rappen, Obergrenze je Zusage, vor Freigabe null. Nullsummen sind keine Rechnung.

## Web und Rechte

Filament-Page SponsorRuns, /sponsor-runs. Saison-/Statussuche, Anlasspflege,
Pflichtmannschaften, Teilnehmerfilter, Helfer, Zusagen, Check-in, Rundenentwurf,
separate endgueltige Freigabe. Billing-Link /sponsor-run-billing?event=ID
nur mit sponsor_runs.approve. Bestehender ModulePermissionService prueft
view/create/update/approve/send/cancel getrennt, keine neuen Profilgrants.

Saison bleibt unveraenderlich. Aenderungen brauchen Revision und Begruendung.
Absage ist terminal, veroeffentlichte Anlaesse werden nicht wieder Entwurf.
Aktive Spieler aktiver ausgewaehlter Saisonmannschaften werden beim Speichern
einmalig uebernommen, Mehrfachzuordnung auf kleinste TeamSeason-ID reduziert.
Entfallene Spieler erhalten required=false; Zusagen und Erfassungen bleiben.
Keine laufende automatische Kadersynchronisation; vergangene Kader sind fix.
Erfassung ab Lauftag Europe/Zurich, Helfer nur Entwuerfe, Office/Funktionsrecht
approve fuer finale Bestaetigung. Freigabe erstellt keine Rechnung oder Nachricht.

Private Portalrouten unter auth:portal und web/CSRF:

| Methode/Pfad | Controller-Methode | Routenname |
| --- | --- | --- |
| GET /portal/{tenant:slug}/sponsor-runs | index | clubunio.portal.sponsor-runs |
| GET /portal/{tenant:slug}/sponsor-runs/{event} | show | clubunio.portal.sponsor-run |
| POST /portal/{tenant:slug}/sponsor-participants/{participant}/pledge | pledge | clubunio.portal.sponsor-run.pledge |
| POST gleicher Praefix /operations | operations | clubunio.portal.sponsor-run.operations |
| POST gleicher Praefix /share | share | clubunio.portal.sponsor-run.share |

Controller SponsorRunPortalController. mine/teams/helper/manage/all erweitern
keine Schreibrechte. MemberAccessService liefert eigene/U18-Kinder und eigene
aktive Staff-Teams, nie die Teams eines vertretenen Kindes als Staffrecht.
Staff-Zusagen nur minimiert lesend. Helfer sehen Namen, Mannschaft, Check-in und
Runden, keine Sponsorenanzahl/Zusagen/Adressen. Befristete Rechte pro Anlass,
getrennt Check-in/Runden; Entzug bleibt historisch bestehen.
Summen und Sponsorenanzahl ueber eine gebuendelte Aggregatabfrage je Seite.
Filter/Suche werden vor Pagination angewendet. Kein N+1 fuer Sponsorenkennzahlen.

## Public und sichere Testzustellung

SponsorRunPublicController: GET /sponsor-run (landing), POST /sponsor-run/info,
/submit,/confirm. Ausschliesslich web/CSRF, keine neue App-API. Die benannten
Public-Routen duerfen sponsor-run.public.* heissen; der Client nutzt feste Pfade.
Antworten no-store/no-referrer/noindex, CSP nur eigene Ressourcen, HTML escaped.
Tokens 256 Bit, SHA256-Lookup plus verschluesselte Rueckgabekopie. URL ausschliesslich
Fragment, vor Netzaufruf mit history.replaceState entfernt. Kein externer QR-Dienst.

Tenant-Gate default aus: verschluesselte Setting area=sponsor_run,
key=PUBLIC_WEB_ENABLED. SponsorRunPublicService::configurePublic nur aktiver Admin
mit sponsor_runs.send, Audit via setting_changes. Keine Env-Tenantliste und keine
Migration-Aktivierung. Jeder Public-Zugriff prueft Gate, Link/Frist/Widerruf,
Pflichtteilnahme, offenen Anlass und fehlende Ergebnisfreigabe erneut.
Aliase nur nach ausdruecklicher Einwilligung. Elternlinks enden vor dem 18.
Geburtstag. Rotation/Widerruf sperren auch offene Bestaetigungen.

Public nur Rechnung. Pending ist noch keine Zusage; Bestaetigung erst durch
expliziten POST mit dem separaten E-Mail-Token. 24h, maximal Link-/Anlassfrist.
Request-Key/Payloadhash und eindeutige Zuordnung machen Wiederholung idempotent.
Bestaetigte Pending-Adresskopie wird sofort entfernt, abgelaufene Payloads nach
einem Tag Karenz begrenzt bereinigt. Ratenlimits pro Stunde: Info-IP120,
Submit/Confirm-IP20, E-Mail5, Link40; HMAC-gehashte Buckets, Honeypot.

WICHTIG: keine echte E-Mail an den Sponsor. Outbox speichert ausschliesslich
TestMailGuard::RECIPIENT (admin@svsissach.ch), verschluesselten Body/Link.
dispatchTestOutbox ist explizit, nur Admin mit send-Recht, freigegebene Testumgebung und
test_mail_enabled; nutzt vorhandenen clubunio_test-Mailer und TestMailGuard.
Filament-Aktion Testmail senden: anlassbezogene Auswahl offener Auftraege,
explizite Bestaetigung der festen Testadresse, erneute Ablauf-/Widerrufspruefung.
Kein automatischer Versand bei Public-Submit. SMTP-Timeout bleibt failed und
wird nicht automatisch wiederholt. Tests versenden nichts.
Die Testadresse kann die Testbestaetigung ausfuehren; das ist KEIN Nachweis
einer Zustimmung oder Zustellung an eine externe Sponsoradresse und keine
Freigabe fuer Livepublic-Betrieb.

## Grenzen und Abnahme

Aus Legacy nicht spekulativ erweitert: keine Zusagekorrektur/Storno, keine fremde
Sammlerbestaetigung, keine Offline-Rundenkorrektur nach finaler Freigabe,
keine teamlosen Laeufer, keine automatische historische Saisonzuordnung,
keine Rechnungssammelgruppen nach Namen/E-Mail. Retired-Zusagen vor Billing
gesondert klaeren. Separater Billing-Service darf eigene Freigaben nicht umgehen.
Echte Mailzustellung, mobiles Teilen/Scannen und verbindliche Fachabnahme offen.

Gezielte Suite: CLUBUNIO_TEST_DB=1, php -d extension=pdo_pgsql
vendor/phpunit/phpunit/phpunit tests/Feature/SponsorRunTest.php.
Synthetischer Tenant innerhalb Transaktion, vollstaendiger Rollback, keine
Schemaanwendung durch den Test und keine Bestands-/Profilveraenderung.
Ergebnis: 18 Tests /171 Assertions erfolgreich, keine Skips. Enthalten:
Revision/terminaler Status und separates Nur-cancel-Profil, Kader-Deduplizierung/Retirement, verschluesselte
Empfaenger, Integer-Rappen/Idempotenz, separater Rundenentwurf und Freigabe,
Billing-Vertrag, getrennte Helferrechte/Entzug, minimierte Staffsicht,
U18-Grenze, Public-Pending/Bestaetigung/Rotation/Widerruf/Frist/Rate-Limits,
gebuendelte Summen, Filament-Erfassung/Nur-Lesen-Profil, Portal-HTTP ohne
Adressleck und fremden Tenantzugriff, CSRF419, Admin-/Transportguards der
Testmail-Webaktion. Zusaetzliche Regression fuer offsetlose DateTimePicker-
Helferzeiten in Europe/Zurich: Sommer-/Winterzeit, gespeicherter UTC-Endpunkt,
Zugriff bis einschliesslich lokalem Endzeitpunkt und Sperre eine Sekunde danach
fuer Helferliste, Check-in und Runden. Parent-Zeitzonenfix bestaetigt.
Kein SMTP-Aufruf, kein Bexio-Schreiben, keine Bankzahlung.
Ein interaktiver Browser-/Mobil-End-to-End-Test ist dadurch nicht ersetzt.

## Dateien und gemeinsame Integration

Eigene Dateien (alle neu):
- app/ClubUnio/Services/SponsorRunService.php
- app/ClubUnio/Services/SponsorRunPublicService.php
- app/ClubUnio/Models/SponsorRunEvent.php
- app/ClubUnio/Models/SponsorRunParticipant.php
- app/ClubUnio/Models/SponsorRunPledge.php
- app/ClubUnio/Pages/SponsorRuns.php
- app/ClubUnio/Http/SponsorRunPortalController.php
- app/ClubUnio/Http/SponsorRunPublicController.php
- resources/views/clubunio/sponsor-runs.blade.php
- resources/views/clubunio/sponsor-run-public.blade.php
- resources/views/clubunio/portal/sponsor-runs.blade.php
- resources/views/clubunio/portal/sponsor-run.blade.php
- public/js/sponsor-run-public.js
- public/css/sponsor-run.css
- database/clubunio/2026_10_25_000074_sponsor_runs.php
- tests/Feature/SponsorRunTest.php
- deploy/CLUBUNIO-SPONSOR-RUN.md

Parent verantwortet Provider/Navigation/Modulkatalog/Modellregistrierung,
gemeinsame routes/web.php, Portal-Navigationslink, README/Roadmap und Billing75.
Keine dieser Integrationsdateien durch den Core-Auftrag bearbeitet.
Schemaanwendung erfolgte ausschliesslich gezielt durch Parent; keine Migration,
kein Deployment und kein Commit durch diesen Core-Auftrag.

Gemeinsamer Release20261025-clubunio-sponsor-documents auf ClubUnio aktiv,
alle fuenf Releasechecks erfolgreich. Login/Public/Assets200 und geschuetzte
Verwaltungsseiten anonym302. Angemeldete Desktop-/Mobilabnahme bleibt offen;
keine Aktivierung oeffentlicher Zusagen oder SMTP-/Bexio-Schreibtests beim Deploy.
