# Backoffice Assistant

## Scope and Release Gate

Web route: `/backoffice-assistant`, integrated in the ClubUnio shell, DE/EN.
Initial access is restricted to active, non-API Admin/Office accounts of the
active tenant. Custom profiles, trainers, players and impersonated sessions do
not gain access. Existing account assignments are not changed.

The local daily overview works without an AI provider. External AI is disabled
by default and remains disabled for the initial release. No real member data is
sent to a model during this acceptance test. Automated tests use synthetic data
and mocked HTTP. The module is not a public or mobile API.

## Three Packages

1. Read-only daily overview and bounded queries: members, registrations,
   expenses, invoices, bus reservations and pitch requests. Maximum 20 records
   per result; counts and coverage disclose truncation. Invoice overdue status
   is not inferred from an open balance when no authoritative due date exists.
   Pitch request results are not a complete training/match/block calendar.
2. Human-approved operations through existing domain services: member contact
   changes, registration review/trial/pool/rejection with team assignment,
   bus and pitch approval/rejection, and internal expense reopening/rejection.
   No membership acceptance/creation, financial release, payments, role changes,
   deletion, arbitrary SQL, uploaded documents or remote network tools.
3. Bounded multi-step orchestration: up to four model rounds combining reads
   and independent proposals. No write tool is exposed to the model. Each
   proposal needs its own confirmation. Dependent changes require a new read
   after the preceding confirmation; there is no unattended workflow runner.

## Confirmation and Audit

- Conversation, cost and action records are owner- and tenant-scoped.
- Proposals contain normalized arguments, current-record fingerprint, translated
  before/after preview and expected side effects. They expire after ten minutes.
- Confirmation rechecks identity, membership, granular domain permission,
  current revision and dependencies. A changed preview cannot be confirmed.
- The durable execution claim is committed before calling the domain service.
  A repeated confirmation of a succeeded action returns the recorded state.
  Interrupted/uncertain writes remain `running` or `attention`, never replayed.
- Existing domain services still enforce conflicts, state transitions and
  notification rules. This module does not bypass business validation.
- Request UUIDs prevent paid-call replay. Unknown/failed tool calls cannot
  trigger arbitrary execution. Model prose is not evidence of a completed write;
  only the separate recorded action status is authoritative.
- Conversations, proposal arguments/previews and results use Laravel encryption.
  Exception details/provider bodies are not logged; sanitized error codes and
  usage counters remain available for investigation.

## Privacy and Activation

Before activation, review the processor agreement, processing location, account
data-sharing settings and retention requirements. OpenAI API data is not used
for training by default, but `store:false` is **not** a zero-retention guarantee:
abuse monitoring retention can still apply. Request ZDR eligibility if required.

References checked 2026-11-06:
- [OpenAI data controls](https://developers.openai.com/api/docs/guides/your-data)
- [GPT-5.4 mini](https://developers.openai.com/api/docs/models/gpt-5.4-mini)

Only after that review, set the dedicated secret outside Git and enable both
`CLUBUNIO_ASSISTANT_PRIVACY_REVIEWED` and
`CLUBUNIO_ASSISTANT_PROVIDER_ENABLED`. The key is
`CLUBUNIO_ASSISTANT_OPENAI_KEY`; no existing OCR or training-planner key is reused.
The transport is fixed to the OpenAI Responses endpoint. Regional/Azure routing
would require a separately reviewed implementation, not an arbitrary URL setting.

The bounded tool results and confirmation previews are sent for inference once
enabled; they can include names and contact changes. This is not anonymization.
No whole-database export, bank account details, private chats, attachments or
provider credentials are supplied as tools. User-entered text can itself contain
personal information, which is another reason activation remains gated.

## Cost and Retention

Default estimated budget is USD 30 per tenant/month, with USD 0.40 reserved per
request under a tenant lock. Failed/uncertain requests retain that reservation.
The fixed model's input/output rates are USD 0.75/4.50 per million tokens;
cached input is conservatively billed as uncached in the estimate. This is an
application guard, not a replacement for provider billing limits or invoices.
Model, rates and request bounds must be reviewed together before changing them.

The existing Laravel scheduler includes `clubunio:assistant:purge --apply`
daily at 03:35 (scheduler timezone). Default retention: 30 days after the last
conversation update. The command without `--apply` previews the bounded batch.
It removes at most 500 expired conversations and their action payloads per run,
retaining payload-free cost rows and existing domain audit records. Busy or
uncertain executions are held for manual review rather than silently discarded.
An old running claim must be investigated against the domain audit before any
new proposal is made; never reset it to pending. Backups have their own retention.

## Migration and Rollback

Additive migration: `2026_11_06_000100_backoffice_assistant.php` creates
`clubora.assistant_conversations`, `assistant_runs`, `assistant_actions` and
indexes. No existing business rows, account roles or legacy databases are changed.

Deployment uses `release-assistant.sh`, a normalized baseline and SHA-256 allowlist.
The candidate is copied from the current ClubUnio release, preserving unrelated
concurrent releases, private environment and shared storage. The fixed migration
helper exports an encrypted parent/ledger snapshot with decrypt/hash readback,
checks freshness under transaction locks, and applies only this migration.
Never use `clubunio:foundation` to deploy this package.

Rollback: switch the ClubUnio symlink back to the preserved previous release and
reload PHP-FPM. Keep additive assistant tables and execution evidence. The
migration deliberately refuses destructive `down()`; cleanup needs reviewed
retention/export. PHPMaker DEV/PROD remain unchanged.

## Acceptance

Provider and action acceptance runs with synthetic records, transaction rollback
and HTTP fakes. The package suites cover 173 tests / 3155 assertions: provider
boundaries, owner/tenant isolation, fresh authorization, confirmations, replay,
uncertain outcomes, encryption, retention, domain services and web/navigation.
Three timezone regressions were found and fixed: expiry, the Zurich monthly
budget boundary and retention now bind explicit ISO-8601 offsets to PostgreSQL.
DE/EN validation: 2806 keys, no uncovered literal UI text.

Live-provider/member-data testing and a full cross-process race acceptance test
are intentionally pending before provider activation. Mocked reentrant/repeated
calls are covered, but are not proof of all multi-process interleavings.
The release gate is read-only and prevents outgoing HTTP; an authenticated
browser pass is recorded in the migration plan separately from automated tests.
