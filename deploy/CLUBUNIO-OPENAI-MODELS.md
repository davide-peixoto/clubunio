# OpenAI task models

## Model policy (23.11.2026)

User-approved replacement of the previous models across ClubUnio instances:

| Function | Model |
| --- | --- |
| Mobile translation/writing and curated support summaries | `gpt-6-luna` |
| Backoffice assistant and training generation/review | `gpt-6.1-terra` |
| Explicit complex-task override, no automatic escalation | `gpt-6.2-sol` |

`OpenAiModels` owns the supported models, reasoning parameters and conservative
Standard token budget rates. Sol uses low reasoning, not unsupported none.
Luna/Terra use none to preserve bounded synchronous response/output contracts.
Responses API, strict training JSON, required retrieval, closed assistant tools,
human confirmations, tenant access, privacy gates and no-retry policy remain.
No new provider, secrets, enabled features, financial actions or member-data
transmissions are introduced by changing a model selection.

Prices checked against official model documentation:
[Luna](https://developers.openai.com/api/docs/models/gpt-6-luna),
[Terra](https://developers.openai.com/api/docs/models/gpt-6.1-terra),
[Sol](https://developers.openai.com/api/docs/models/gpt-6.2-sol).
USD/1M input/output: 0.10/0.50, 1/5 and 2/10 respectively. Budget accounting
does not assume cache discounts. Output usage already includes reasoning.
Long-context multipliers are included; current request bounds are below them.
Assistant reservation is USD 1.20 for up to four bounded Sol rounds; monthly
limits stay unchanged. Mobile reserves at least USD 0.025 and increases its
reservation for an explicitly selected dearer model. It caps output at 1000.
Training work retains its separate reviewed reservation and activation gates.

## Migration and rollback

1. Preserve current release, config cache and each touched source file outside
   public directories. Apply only the AI delta; do not replace unrelated dirty
   server sources or publish other chats' work.
2. `prepare-openai-model-config.php <previous-root> <new-root>/bootstrap/cache/config.php`
   updates only model keys, training allowlist and assistant reservation in the
   private active configuration. All other feature/privacy/permission settings
   stay byte-value equivalent, with release paths adjusted on the Pi.
3. `php deploy/upgrade-openai-models.php <database> --inspect`, then `--apply`.
   Supported targets: clubunio_dev, clubunio_stage, clubunio_prod. Update existing
   OPENAI_TRAINING_MODEL rows to Terra, including inactive/empty model rows;
   do not insert missing credentials, vector stores, rows or change enabled flags.
   This is an explicit data migration, not a general schema/import refresh.
4. The transaction locks rows and saves their previous encrypted values in
   storage/app/private/openai-model-upgrades, mode 0600, before writing.
   `--restore <private-backup-path>` restores only unchanged migrated model
   values and refuses intervening edits. Restore the prior code/config together.
5. `php deploy/check-openai-models.php` reports only model names, tenant IDs and
   activation gates. `--live-synthetic` makes three bounded paid JSON-schema
   checks using synthetic text and an existing key, no personal data or tools.
   This proves access for that selected key, not every tenant/project key or
   training retrieval quality. No automatic retries or feature activation.
6. Mobile translation cache identities now include provider and model. Old
   cached drafts are not mistaken for new-model responses. Durable training
   work prepared against old configuration fails its fingerprint check rather
   than silently dispatching another model; review outstanding work manually.

Source configuration maps legacy GPT-3/4/5 environment model selections to the
new task defaults. Training normalizes old model selections on read too, without
writing to the old local snapshot database. Explicit supported GPT-6 choices remain configurable via
CLUBUNIO_ASSISTANT_OPENAI_MODEL, CLUBUNIO_MOBILE_OPENAI_MODEL and
CLUBUNIO_SUPPORT_OPENAI_MODEL. Unknown models fail closed. API contracts stay
unchanged; no Flutter update is required.

## Acceptance

Local tests cover new defaults, legacy selection migration, all model costs,
Sol reasoning, mobile transport, strict training retrieval/schema and preserved
assistant privacy/tool limits. Translation catalogue check remains required.
Record instance releases, rollback paths and live-check outcomes below.

### Instance acceptance 23.11.2026

- DEV: active `20261123-clubunio-openai-models`; rollback
  `20261119-clubunio-social-insights-v3`. Tenant4 training moved from 5.6 Luna
  to Terra. Real synthetic strict-JSON calls to Luna/Terra/Sol all completed;
  input/output usage respectively45/13,45/13,55/13. No personal data transmitted.
  DEV PostgreSQL schema, tenant/account reads and push outbox gate passed.
- Stage: two existing inactive training-model rows (tenants1/3) moved to Terra.
  Source/config backup `release-stage-20261123-203441` in the private model
  upgrade directory. No usable existing provider key; real call intentionally
  not run. Existing disabled AI gates retained; no credentials copied from DEV.
- PROD: tenant12 inactive training-model row moved to Terra. Private backup
  `release-prod-20261123-203514`. Same lack of provider key and disabled gates;
  model selections prepared, no live AI activation or real member transmissions.
- HTTPS login entry on DEV, Stage and app.clubunio.com returned302 (existing
  identity-provider redirect). No login/account/role changes.
- Server PHPCLI `/usr/bin/php` has PDO-PgSQL. RunCloud PHP8.4CLI lacked it;
  first attempt was reversed before data writes. Deployment now checks the
  driver before changes and uses explicit failure exit codes. No PHP installed
  or shared service changed to work around that CLI mismatch.
- Focused local suite:56 tests/438 assertions passed; expanded assistant suite
  77 passed/1092 assertions,47 database-dependent tests skipped. Broader legacy
  MobileCommunication fixtures hit pre-existing SQLite/PostgreSQL interval and
  login-schema incompatibilities; do not claim the complete mobile suite passed.
- DE/EN catalogue check:3075 keys verified. Synthetic schema checks establish
  API/model compatibility, not full training quality, file-search retrieval or
  access through each tenant's distinct project key. Those remain separate checks.
