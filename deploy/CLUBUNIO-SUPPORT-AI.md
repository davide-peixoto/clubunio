# ClubUnio Support AI Policy

- Use a dedicated OpenAI project and server-only key. Never put the key in
  clients, queue payloads or Git. Provider requests disable response storage;
  this setting is not a claim about the project's retention terms.
- Only bundled, reviewed public FAQ text goes to the provider. Raw messages,
  prior turns, ticket metadata, private articles, notes and attachments never
  go to it. The application classifies input locally into fixed topic IDs.
- The guide keeps at most a topic ID for recognized generic follow-ups. The
  caller should hold that ID in its own authenticated or anonymous session
  context, clear it on handoff or context changes, and rate-limit requests.
- Triage reloads only the open ticket subject in its exact tenant and
  audience. A platform job never follows the source ticket. Unknown topics
  are marked for manual review. Suggestions are staff-only in the respective
  inbox. A suggestion is not a reply and does not change a ticket or account.
- Render model output as escaped plain text, labelled as AI. No tools,
  account actions, auto-publishing or automatic closures are enabled.
- Apply the additive support triage migration before enabling
  `CLUBUNIO_SUPPORT_TRIAGE_ENABLED`. Run a worker for `support-triage` on
  `CLUBUNIO_SUPPORT_TRIAGE_CONNECTION` (default: database; provision its
  jobs table). A real worker and provider are not required by automated tests.
