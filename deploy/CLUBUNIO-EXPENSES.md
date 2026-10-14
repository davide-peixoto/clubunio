# ClubUnio expenses and bank accounts

11.10.2026, release10 deployed locally and on Pi staging. New ClubUnio database only.
No PHPMaker DEV/PROD change, no Bexio financial action, no external FCM.

## Implemented

- Admin/office create open claims and edit headers/positions. Once positions
  exist the owner/team identity cannot be rewritten. Member bank account must
  belong to the owner. Lookups are tenant-scoped and active.
- Separate portal lists own claims; technical leads additionally see responsible
  teams. Parents do not inherit a child's expense/bank rights automatically.
- Open own claims can be submitted (O -> F). Assigned TL can approve payment
  or reject (F -> Z/R), never reopen. Office can F -> O/Z/R, R -> O and Z -> B.
  Paid is terminal. Changes require fresh revision and a review note. The new
  service deliberately does not implement unrestricted historical status jumps.
- Release requires positive active positions, required receipt references and
  an active owner bank account. Receipt availability is checked on read; release
  does not make an unbounded external existence call inside the transaction.
- Position updates lock the claim and check both revisions. Technical flags and
  suggested amounts cannot be submitted by clients. New laundry positions use
  Teams.laundry_allowance; both Filament and portal prefill the amount. A manually
  overwritten amount retains the suggestion and sets the indicator yellow. An
  edit back to the suggestion returns green. Existing suggestions remain snapshots.
- Existing attachments are read through authenticated/authorized endpoints from
  svs-prod-private/spesen. Legacy azure.blob://files references are translated;
  no public URL, local fallback or public legacy-container fallback is used.
- Status events and per-user notifications are transactional. F goes to office
  and responsible team TL; other status messages to the owner. Editable templates
  use season/round/team names. An email outbox captures intended recipients
  encrypted. Actual staging delivery ONLY to admin@svsissach.ch, no intended CC.
- Shared TestOutboxDispatcher consumes committed registration/expense events,
  with locked claims and no automatic replay after ambiguous SMTP results.
- Bank accounts: own accounts only even for TL; admin/office manage member
  accounts in the dossier. IBAN normalized, format/checksum validated, encrypted
  in storage and masked in lists/audit. Duplicate active owner accounts rejected.
  Used account identity cannot be overwritten; create a new account instead.
- Editable lookup screens: banks, expense types, rounds, game types, expense
  status labels. Supported transition codes remain O/F/Z/B/R; inventing new
  state-machine behavior requires implementation, not merely a new label.

## Database and operations

Additive migrations 000012..000014 on the shared NEW ClubUnio DB. Existing
suggestions and statuses are not recomputed. Laundry type mapping uses SV Sissach
legacy type2 only. Existing snapshots and Azure blobs remain unchanged.
Minute scheduler also runs clubunio:send-expense-mail. Inspect event rows on
failures; accepted means SMTP accepted, not confirmed mailbox delivery.

## Still pending

- Isolated file uploads and new OpenAI receipt-analysis requests. Existing
  imported suggestions are preserved, but ClubUnio does not yet generate new AI
  proposals. Receipt-required new lines cannot be released without attachment.
- Bexio bill creation/payment-status reconciliation with isolated credentials.
  Z currently records approval only; it does NOT create a Bexio payable.
- Full mobile API compatibility, external push activation, final-role user tests.
- Portal header corrections after create and improved multi-identity selection.
  Administrative header corrections already use the shared service.

Do not describe this release as the completed PHPMaker replacement.

## Verification

79 tests / 708 assertions passed, using synthetic tenants and rolled-back data.
Coverage includes role/tenant boundaries, TL status restrictions, stale writes,
laundry amount comparison, required attachments, notification recipients,
encrypted bank accounts, duplicate/invalid IBAN and finance catalog forms.
Browser verified laundry prefill and configurable expense-type form. Private
Azure attachment read succeeded locally and on Pi. No real claim was changed.
