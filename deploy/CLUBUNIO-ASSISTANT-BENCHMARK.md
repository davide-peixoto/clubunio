# Backoffice Model Comparison

Date: 2026-11-06 (Europe/Zurich). This is a local, synthetic API evaluation,
not a ClubUnio release or provider activation. PHPMaker DEV/PROD and the running
ClubUnio application, roles, business records and assistant flags are unchanged.

## Method

- Exact candidates: `gpt-5.4-mini` and `gpt-6.1-sol`, Standard Responses API.
- Same instruction text (unit-tested against the current AssistantProvider),
  actual DE/EN read/prepare tool definitions, prompts, and deterministic fixtures.
- Both use `reasoning.effort=low`, the lowest common supported setting. The
  deployed mini adapter uses `none`; this evaluation is **not** a measurement of
  that exact deployed configuration. No unsupported Sol `none` request is sent.
- Twelve cases, six DE/six EN, three repetitions: 36 tasks per model. Model order
  alternates between cases/repetitions to reduce first-request/order effects.
- Four model rounds maximum per task; 2000 output tokens per request, including
  reasoning. Wall time covers the complete tool loop, not only first-token time.
- Fixed HTTPS endpoint, TLS verification, `store=false`, redirects disabled,
  no automatic retry. Stateless reasoning continuation includes encrypted
  reasoning items; no hosted tools, external searches, files or code execution.
- Application tool methods are **not executed**. Simulated reads/previews use
  invented names, IDs, amounts and `.invalid` email addresses. These evaluate
  model orchestration, not end-to-end domain correctness or concurrency.

Cases: daily pending counts, open invoice amounts, ambiguous member selection,
contact change preview, bus approval, booking conflict, pitch rejection,
trial-training assignment, refused bank transfer/role escalation, injected
instructions in a record, access denied, and two independent proposals.

## Privacy and Isolation

The standalone runner loads Composer and a translation-only container, never
Laravel bootstrap, application configuration, `.env` or business tables. Its
only external destination is OpenAI. The separate credential broker reads one
encrypted `OPENAI_API_KEY` setting for tenant 4 in a read-only transaction,
disconnects, then hands the key to the isolated child over stdin. No key is
printed, persisted in results or placed on a command line. This deliberate
synthetic test does not enable a credential fallback in the live assistant.

Raw synthetic responses, usage, arguments and hashes stay in ignored
`storage/app/private/assistant-benchmark-*` directories. No credentials, uploads,
member exports, server environment files or raw runtime artifacts are committed.

## Evaluation

Automated checks separately evaluate tool names/argument values and closed
schemas, prohibited operations/false execution claims, and factual/confirmation
or clarification requirements. Phrase matching is only a heuristic. In addition
to the raw scores, inspect semantic false negatives consistently for both models;
do not silently rewrite the original result files or rerun failed tasks.

Median and p95 use full-task wall time (nearest-rank percentile). Token counts
come from API usage, including cached input and reasoning output. Reasoning is
already included in output tokens and is not charged twice. Costs are calculated
estimates from usage and documented rates, not a provider invoice. Cache-write
tokens are priced separately when exposed by the usage contract.

Standard USD per million tokens, checked against official OpenAI documentation:

| Model | Input | Cached Input | Cache Write | Output |
| --- | ---: | ---: | ---: | ---: |
| GPT-5.4 mini | 0.75 | 0.075 | 0.75 (conservative if reported) | 4.50 |
| GPT-6.1 Sol | 2.00 | 0.10 | 2.50 | 10.00 |

References: [mini](https://developers.openai.com/api/docs/models/gpt-5.4-mini),
[Sol](https://developers.openai.com/api/docs/models/gpt-6.1-sol),
[reasoning](https://developers.openai.com/api/docs/guides/reasoning).

The experiment budget is USD 3. Before each request the runner retains a
conservative reservation (USD 0.30 in the scored run). Uncertain calls are never
automatically retried. This is a local experiment guard, not a replacement for
provider account spending limits. The discarded pilot's measured usage and a
USD 0.20 allowance for its interrupted in-flight request are deducted first.

## Excluded Pilot

Pilot `assistant-benchmark-20261106-091859-2366917f` is excluded from quality and
latency comparisons. Its fixture did not implement optional reservation and
registration reads, falsely returning 403. It also exposed overly rigid refusal
phrase matching and punctuation ambiguity in an exact-reason instruction.
The process was stopped, the simulator completed, the reason explicitly quoted,
and unit regressions added before a fresh identical run for both candidates.
No pilot failures are attributed to model capability. Pilot costs remain counted
against the experiment cap; no real business action was ever available.

## Reproduction

`php tools/run-assistant-benchmark.php` is a no-HTTP dry run.
For a paid local test, supply `CLUBUNIO_BENCHMARK_OPENAI_KEY` securely and use
`--run`; alternatively use the reviewed read-only broker:
`php -d extension=pdo_pgsql tools/run-assistant-benchmark-with-setting.php --run`.
An optional `--prior-run=assistant-benchmark-YYYYMMDD-HHMMSS-xxxxxxxx` deducts a
previous local run plus its uncertain-call allowance from the USD 3 budget.
Never pass a secret as an argument, paste it in chat, or activate live flags to
run this evaluation. Rate-limit/auth/model errors stop that candidate; inspect
the saved outcome before intentionally starting another paid experiment.

Unit command: `php vendor/bin/phpunit tests/Unit/AssistantBenchmarkTest.php tests/Unit/AssistantProviderTest.php`.

## Results

Scored run: `assistant-benchmark-20261106-092257-642265b3`, started at
10:22:57 Europe/Zurich. All 72 tasks completed, no transport failures,
incomplete responses, disabled models or uncertain requests in this run.

| Measure (36 tasks each) | GPT-5.4 mini | GPT-6.1 Sol |
| --- | ---: | ---: |
| Raw phrase-based pass score | 33/36 | 36/36 |
| Reviewed correct tasks | 36/36 | 36/36 |
| Safety checks passed | 36/36 | 36/36 |
| Full-task median | 2.214 s | 4.290 s |
| Full-task p95 | 4.905 s | 6.982 s |
| HTTP requests | 77 | 87 |
| Tool calls | 41 | 51 |
| Input tokens | 96,449 | 108,084 |
| Cached input tokens | 76,416 | 106,767 |
| Reported cache-write tokens | 0 | 1,056 |
| Output tokens (including reasoning) | 4,549 | 3,036 |
| Reported reasoning tokens | 1,565 | 0 |
| Usage-based cost estimate | USD 0.041271 | USD 0.044234 |
| Cost per reviewed correct task | USD 0.001146 | USD 0.001229 |
| Uncached arithmetic, NOT a new measurement | USD 0.092837 | USD 0.246528 |

The API reported `gpt-5.4-mini-2026-03-17` and `gpt-6.1-sol` respectively.
Sol took 1.94x the median time and cost approximately 7.2% more in this warmed
test. However, its cache-hit share was 98.8%, versus 79.2% for mini, including
possible prefix reuse from the excluded pilot. The observed near-price-tie must
not be presented as a general cold-cache or live-usage cost guarantee.
The last row reprices the observed token counts at ordinary uncached input and
output rates, rounded up per request. It does not simulate different responses,
cache-write behaviour, latency or future traffic.

All 72 final texts were inspected. The three mini misses were correct German
clarification requests: both matching IDs were listed and the model said
"Bitte nenne mir die gewuenschte ID" (or equivalent), without preparing an edit.
The original heuristic missed that wording. A narrow, model-independent
clarification rule and regression test were added; offline rescoring changed
only `ambiguous-de` repetitions 1-3 for mini. Original task/summary files remain
unchanged; `review.json` records every before/after score. No paid task was rerun
to improve its score. Offline command:
`php tools/review-assistant-benchmark.php assistant-benchmark-20261106-092257-642265b3`.

Mini generally returned concise lists plus some unnecessary follow-up offers.
Sol's wording was more uniform, explicitly mentioned the current-versus-historical
scope in daily briefings and did more preliminary reads. Both preserved proposal
confirmation, refused bank/role changes, stopped on conflicts and denied access,
and ignored the injected record instruction. These are observations of this
sample, not proof of universal safety or a statistically established quality tie.

**Recommendation:** retain mini as the default candidate for the currently
bounded assistant workload. This sample shows no correctness advantage that
justifies Sol's extra latency. Sol remains a candidate for a separate harder
reasoning/long-context evaluation, not an automatic escalation or model switch.
The live adapter and its activation gates were not changed.

Total recorded experiment estimate including the 40 excluded pilot tasks:
USD 0.145482. An additional USD 0.20 remains conservatively allowed for the
pilot's interrupted call; that amount is not claimed as an actual charge.
The complete experiment remained well below the USD 3 local cap.

## Evidence and Limits

- Case SHA-256: `8f91b95814c4f5f6dcbab614292766c2a0d690981367bca6634364c34bc4e8bc`.
- Run-time runner SHA-256: `e92e73f351edf337ff77c0cd454eae41e7526d8b0cc5272d618578aeaeb38237`.
- Tool schema SHA-256: `861fc3f4b98c149ad2e5c5a107cce3275937be34f60041cd754f0afb3910fa13`.
- Original scorer SHA-256: `43b3133fccab65e2f337589c77076071600b3a4d55cf2beadd66cc2b7be1a780`.
- Reviewed scorer SHA-256: `5c53db0b0ddeaf285bb7f42d6dbcb7e38d189186776d0a67152c9fb3d8dfbce0`.
- The runner subsequently gained scorer-hash recording for future runs; the
  original metadata and raw results have not been rewritten.
- Regression suites: 47 tests / 284 assertions passed. Translation checks remain
  required; no new application UI or runtime configuration was introduced.
- Twelve scenarios repeated three times are a small, deliberately bounded sample,
  not 36 independent business scenarios. No significance or universal quality
  superiority claim, human blind panel, real service execution, production load,
  mobile benchmark or cross-process race acceptance is implied.
- No server deployment, database migration, real member query, business action,
  mail, push, Bexio call, role update or provider activation was performed.
