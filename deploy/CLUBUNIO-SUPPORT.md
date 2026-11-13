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

Two-tenant DEV acceptance, actual OpenAI project check, authenticated mobile
UI, embeddable widget, anonymous login-help intake, attachments, assignment,
notifications, private knowledge retrieval, article editing, video content,
conversational chat and queued ticket triage are not part of this initial slice.
Do not enable autonomous responses or actions.
