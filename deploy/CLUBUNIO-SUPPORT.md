# ClubUnio Support Center

Local implementation, disabled by default. Apply the additive
`2026_11_13_002000_support_center` migration to a reviewed DEV target before
setting `CLUBUNIO_SUPPORT_ENABLED=true`. No existing data is imported.

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

Pending: integrated two-tenant DEV acceptance, dedicated provider and worker
configuration, mobile push and attachment UI, private knowledge retrieval,
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
