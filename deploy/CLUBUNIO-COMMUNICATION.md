# ClubUnio communication and member notes

Stand: 11.10.2026. ClubUnio only; no PHPMaker DEV/PROD or mobile API changes.

## Available in the UI

- `Nachrichtenvorlagen`: tenant-specific email subjects/bodies and push titles/
  bodies. Initial catalog: 39 de-CH templates for 34 events. Includes expenses,
  registration review/trial/acceptance/rejection/waitlist/staff, invoices, pitches,
  bus decisions, documents, team events/reminders, account messages and announcements.
- Edit, activate/deactivate, filter by event/channel/language, search titles,
  preview with synthetic data, inspect the latest 50 revisions and restore an
  older version. Restoring creates a new revision; it does not erase history.
- Add language variants (de-CH, de-DE, fr-CH, it-CH, en-GB). No automatic
  translation. Missing locale falls back to de-CH; explicitly disabled locale
  does not. Missing/disabled templates produce no message.
- The catalog contains starter texts, not a byte-for-byte migration of all
  historical PHPMaker HTML. Existing PHP templates are never executed/imported
  as executable code. Additional event types require a reviewed catalog/handler
  change, not an arbitrary rule entered by an administrator.
- Expense detail: `Nachrichtenvorschau` renders the current state's template
  with real season/round/team/amount and shows the domain recipients. This is
  NOT a send button, a transition, or a financial approval.
- Member dossier / Bemerkungen: add, edit and deactivate internal notes.
  Parent membership and tenant are immutable; archived members cannot be edited.
  Changes record actor, before/after values and an optimistic revision.

## Safety and architecture

`MessageEventCatalog` declares stable event names, channels and allowed
placeholders. `MessageTemplateService` owns validation, tenant-scoped storage,
revision checks, history, locale fallback and rendering. Text is plain text with
`{{ variable }}` placeholders only. No Blade/PHP execution or editable headers,
addresses, routes, recipient rules or credentials. All browser output is escaped;
substituted text is not recursively evaluated. Missing required values fail.

Admin and office accounts only. Services authorize independently of Filament.
Tables `clubora.message_templates` and `clubora.message_template_revisions` have
composite tenant keys; template identity is unique by tenant/event/channel/locale.
All queries are schema-qualified. Saving serializes on the tenant and checks
revision, preventing lost changes and duplicate language variants. No-op edits
do not create a revision. Seeding never overwrites edits or re-enables templates.

`ExpenseNotificationService` currently plans, but does not deliver:

| Existing status | Event | Domain recipients |
| --- | --- | --- |
| F | expense.review | active office accounts + active TL assignments for the exact TeamSaison |
| Z | expense.payment_released | expense owner |
| B | expense.paid | expense owner |
| R | expense.rejected | expense owner |
| O | expense.reopened | expense owner; preview of reopening message, not initial creation |

No owner copy for F, no unrelated TL teams and no administrator broadcast.
The preview lists domain identities, NOT verified deliverable addresses/devices.
Legacy central-mailbox configuration, account/member identity unification and
cross-role deduplication must be resolved by the future delivery adapter.
Inactive/archived member handling and real token/address eligibility need full
workflow tests before delivery is enabled. Other catalog events are prepared
texts only; their respective business handlers are not yet migrated.

## Deliberately not activated

No business email delivery, FCM push, persistent app notifications, retries or external Bexio calls.
The template `active` flag is not an outbound switch. Existing production
PHPMaker messages continue unchanged. Finance remains read-only. No queue worker
or scheduler is added. Before production, implement the transactional outbox,
immutable rendered snapshots, idempotency/retry, recipient authorization at send
time, private routing/payload rules and successful provider delivery tests.
Apply provider payload limits to rendered bytes (not template character counts).
All newly migrated handlers must use this service rather than hard-coded text.

## Test email exception (release06)

The user authorized real test email exclusively to admin@svsissach.ch. Active
email templates now have a Testmail action with confirmation and synthetic data.
TestMailGuard intercepts every Laravel MessageSending event, replaces To with
that fixed address, removes CC/BCC/resent headers, sets Reply-To to the same
address and prefixes the subject with [ClubUnio TEST]. The SMTP envelope is built
after this interception. Disabling TEST_MAIL_ENABLED blocks all Laravel mail.
Do not bypass Laravel Mail with direct SMTP transports/provider SDKs.

SMTP values live only in ignored .env.clubora-integrations.local (outside the
web root), symlinked from shared on Pi. They are not editable template values.
Push and business workflows remain disabled. No real members receive test mail.
Migration 000007 adds test_mail_attempts: tenant, actor, template revision,
request UUID, fixed recipient, timestamps, status and sanitized error code.
Accepted means SMTP acceptance, not confirmed inbox delivery. The same request
UUID does not resend; a sending row after a crash requires manual investigation.
Five attempts per minute per actor/tenant; no automatic retry of uncertain sends.
The one-time local setup tool only copies allowlisted SMTP/Azure settings and
refuses to overwrite an existing integration file. No credentials are logged.

Verified: actual array-transport envelope with To+CC+BCC contains only admin;
disabled switch blocks delivery; tenant/idempotency tests; two real samples
(local and Pi) accepted by SMTP on 11.10.2026. User mailbox confirmation remains outstanding.

## Installation and verification

Apply migration 000006 via `php artisan clubunio:foundation`, then
`php artisan clubunio:seed-message-templates`. Both target only the new ClubUnio DB.
Local Windows CLI needs `-d extension=pdo_pgsql`. Local and Pi share this DB:
apply once, then deploy code. No source snapshot refresh/import is involved.
Previous release04 remains compatible with the additive tables/notes column.

Automated coverage: editing and restoring templates, immutable identity,
duplicate/channel validation, all starter templates rendering, locale fallback,
disabled translation, untrusted placeholder content, HTML escaping, concurrent
revision rejection, tenant/role rejection, Filament save paths and filter/search,
expense office/TL vs owner routing, no outbound requests, note history and parent
isolation. Tests use synthetic tenants and roll back all fixture data.

Audit retention and settings/communication-specific role separation are still
production-readiness tasks. No sensitive rendered messages are written to logs.
