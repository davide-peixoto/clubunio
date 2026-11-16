# ClubUnio Support Center

15.11.2026 DEV: the existing SSH tunnel recovered; PostgreSQL and the
synthetic OpenAI project smoke test passed (HTTP 200). Support staff roles
no longer depend on the runtime's permission-service constant. A rolled-back
PostgreSQL check with two synthetic clubs verified web/mobile controller
isolation, the approved escalation summary, and both synchronous triage
handlers. This was not a browser/device or live queue-delivery test.

Disabled by default in new environments. Apply the four additive support
migrations (002000, 002010, 002090, 002200) and the dedicated queue migration
`2026_11_15_002300_support_queue` before enabling the module with triage.
No existing data is imported. ClubUnio remains the system of record. The
optional Zammad handoff uses migration `2026_11_15_002310_support_zammad`.

| Surface | Access |
| --- | --- |
| Public help | Published public articles only |
| Club help | Public and published articles for the current club |
| Club administration | Club admins and office; additionally club staff articles, inbox, notes and escalation |
| Platform administration | Separate central platform authorization; public and platform articles, escalated ticket copies only |

Members see only their own tickets. Escalation copies only the new subject and
explicitly entered summary; no messages or internal notes are copied.
Platform notes are not shown to the club. Support excludes impersonation.
Articles are plain text and render escaped; public publishing is platform-only.

## Agent policy

Set `CLUBUNIO_SUPPORT_OPENAI_KEY`, `CLUBUNIO_SUPPORT_OPENAI_PROJECT`, and
`CLUBUNIO_SUPPORT_OPENAI_MODEL` in private server configuration for a dedicated
OpenAI project. Do not reuse the backoffice assistant key. Never send these
values to the browser or put them in Git. Until configured, return the
curated FAQ directly.

## DEV deployment, 15.11.2026

Active release: `20261115-clubunio-support-center`, based on
`20261115-clubunio-sfv-hour` (rollback target). The four support migrations
were applied together as reviewed PostgreSQL DDL to `clubunio_dev` after a
schema-only backup. No data import. Stage unchanged. Keep the additive
tables on application rollback.

Public help, sign-in help, widget script and login returned HTTP 200.
Anonymous platform inbox redirects to sign-in; anonymous club inbox
returns HTTP 401. Authenticated acceptance with two clubs remains open.
The dedicated OpenAI project and triage worker were not activated. Public
FAQ fallback works without a provider. Flutter is available in source;
no native test build was distributed by this deploy.

The initial guide classifies only known topics locally. Only curated public
FAQ text is sent to the Responses API, with `store: false`. Never send raw
questions, tickets, attachments, account data, or private knowledge. The model
has no tools, database access, or ability to write, close, or escalate tickets.
An unmatched question is referred to human support. Provider failures fall back
to the curated source.

## Pending acceptance and functionality

Implemented in follow-up: public widget and login intake, Flutter support UI,
ticket assignment, encrypted attachments, explicit attachment escalation,
portal notifications, article editing and HTTPS video links, bounded FAQ
follow-ups, and queued triage suggestions.

The assistant also resolves exact published article titles in the current
language, using the same audience query as the knowledge base. It returns
matching content directly from ClubUnio, without invoking OpenAI. This
includes club-staff or platform documentation only in its authorized scope.

Pending: integrated two-tenant authenticated DEV acceptance, dedicated provider
configuration, mobile push and attachment UI, semantic private knowledge retrieval,
free-form conversational support and automatic resolution. Do not enable
autonomous responses or actions.

## Follow-up integration

Public sign-in help stores encrypted, unverified requests without checking
account existence. Central-host requests go to the platform intake inbox;
verified club-host requests go to that club's staff inbox. No email or reset
is triggered by submission. Review retention before enabling public intake.
Embed `/js/clubunio-help-widget.js` from the ClubUnio origin to expose the
public help panel. Private support is not embedded on external sites.

DEV provider smoke test: existing SV Sissach training project key,
synthetic text only, HTTP 200 and completed. No key copied to support
configuration. See [agent policy and worker setup](CLUBUNIO-SUPPORT-AI.md).

## DEV credentials

On `svs-pi-server`, edit the private file resolved by
`readlink -f /var/www/svs-laravel/current/.env`. As of 16.11.2026 this is
`/var/www/svs-laravel/shared/.env.clubora-platform-oidc`. The previous
`.env.clubora-support-center` file is no longer the active configuration.

```dotenv
CLUBUNIO_SUPPORT_OPENAI_KEY=
CLUBUNIO_SUPPORT_OPENAI_PROJECT=
CLUBUNIO_SUPPORT_OPENAI_MODEL=gpt-5.4-mini
```

Use the key and the `proj_` ID of a dedicated OpenAI project and a model
available to that project. Leave these values out of Git, Flutter and
the widget. Empty values keep local triage and the reviewed FAQ working.
After changes, recache the live DEV configuration and restart the support
worker. Preserve the DEV support configuration via the shared env file
on the next release.

```bash
cd "$(readlink -f /var/www/svs-laravel/current)"
umask 027
VIEW_COMPILED_PATH="$PWD/bootstrap/cache/dev-views" php artisan config:cache
sudo chgrp www-data bootstrap/cache/config.php
sudo chmod 0640 bootstrap/cache/config.php
sudo systemctl reload php8.3-fpm
sudo systemctl restart clubunio-dev-support-triage.service
```

16.11.2026: after recaching DEV and restarting the worker, one synthetic
expense ticket ran through the live triage queue. The worker persisted a
nonempty draft with `provider_generated=true` (8.7 seconds including queue
wait). Only the reviewed public FAQ went to the provider. The synthetic
ticket and draft were removed; Stage was not changed.

The embedded public widget shows FAQ and search. The support form opens
on the ClubUnio domain, since third-party cookies are not reliable in iframes.
Private tickets and articles are available only inside authenticated ClubUnio.

DEV follow-up release `20261115-clubunio-support-triage`: schema-only backup
before adding the queue, supervised worker active, and public help/widget
HTTP checks passed. A synthetic PostgreSQL queue job produced a local
suggestion inside a rolled-back transaction. The dedicated provider was
not configured. Roll back by stopping the worker, restoring the prior
DEV release `20261115-clubunio-support-center` and retaining the tables.
Stage unchanged.

## Zammad handoff on DEV

After a club escalates a ticket, a platform operator may review and send
its subject and initial escalation summary from the platform inbox.
ClubUnio sends an internal note on behalf of a neutral Zammad customer.
Original club posts, attachments, contact data and later replies are not
synchronized. Zammad does not host the ClubUnio widget or knowledge base.

Set the following in the same private DEV env file. Provide the HTTPS
origin only (no `/api/v1`), a Zammad API token for a dedicated agent
restricted to the test group, the numeric group ID, and the numeric ID
of a neutral test customer. Do not use the token of a Zammad administrator.
Keep the DEV group free of notification or auto-forward triggers.

```dotenv
CLUBUNIO_SUPPORT_ZAMMAD_ENABLED=true
CLUBUNIO_SUPPORT_ZAMMAD_URL=
CLUBUNIO_SUPPORT_ZAMMAD_API_TOKEN=
CLUBUNIO_SUPPORT_ZAMMAD_GROUP_ID=
CLUBUNIO_SUPPORT_ZAMMAD_CUSTOMER_ID=
```

Recache and reload PHP as above. The link is not usable until every
required value is set. Send only a synthetic first ticket. ClubUnio
keeps its own immutable ticket reference in the Zammad title; leave that
prefix intact if reconciling. If the result of a write is unknown, ClubUnio
will not send a second POST. After one minute the operator can check an
existing Zammad ticket ID against the reference, group and customer.
Retain `support_zammad_exports` on rollback; disable the integration
without discarding receipts. No remote ticket is deleted by a rollback.

DEV rollout 15.11.2026: `20261115-clubunio-support-zammad`, rollback
`20261115-clubunio-support-triage`. Only `clubunio_dev` received the
additive table after a schema backup. Public help and sign-in responded;
anonymous platform inbox redirected to sign-in and the triage worker
remained active. A synthetic DEV PostgreSQL handoff with a mocked Zammad
response was verified and rolled back. No live Zammad request was sent.
Stage was not changed.

## Platform inbox layout

The platform UI uses the shared ClubUnio platform layout. Tickets open by
default, with search by subject or club, status and assignee filters,
pagination and a detail pane for the history, reply or internal note,
assignment and Zammad handoff. Knowledge management and the assistant have
separate URLs under the platform support workspace; assistant citations link
to the corresponding article in the knowledge tab.

This is a web-only change, with no migration, data update or provider change.
Club and public help, the embedded widget and the mobile API keep their
existing behavior. UI text is maintained in German and English.

DEV release: `20261115-clubunio-support-workspace`. Rollback:
`20261115-clubunio-support-zammad`. Verified against DEV PostgreSQL with a
synthetic ticket, filtered list, detail pane and both secondary tabs; the
test transaction was rolled back. Stage was not changed.
Authenticated Chrome visual acceptance is pending: the DEV session reached
the secure sign-in screen and the user could not sign back in at the time.
Resume the DEV login check separately, then verify the three tabs in Chrome.
