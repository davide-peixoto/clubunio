# ClubUnio Support Center

Disabled by default in new environments. Apply the four additive support
migrations (002000, 002010, 002090, 002200) and the dedicated queue migration
`2026_11_15_002300_support_queue` before enabling the module with triage.
No existing data is imported. This version is native ClubUnio, not Zammad.

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

Pending: integrated two-tenant DEV acceptance, dedicated provider and worker
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

Edit the private DEV configuration on `svs-pi-server`:
`/var/www/svs-laravel/shared/.env.clubora-support-center`.

```dotenv
CLUBUNIO_SUPPORT_OPENAI_KEY=
CLUBUNIO_SUPPORT_OPENAI_PROJECT=
CLUBUNIO_SUPPORT_OPENAI_MODEL=
```

Use the key and the `proj_` ID of a dedicated OpenAI project and a model
available to that project. Leave these values out of Git, Flutter and
the widget. Empty values keep local triage and the reviewed FAQ working.
After changes, recache the live DEV configuration and restart the support
worker. Preserve the DEV support configuration via the shared env file
on the next release.

The embedded public widget shows FAQ and search. The support form opens
on the ClubUnio domain, since third-party cookies are not reliable in iframes.
Private tickets and articles are available only inside authenticated ClubUnio.
