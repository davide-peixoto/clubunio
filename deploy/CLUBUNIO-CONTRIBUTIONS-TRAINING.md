# Contributions, training times and dashboard

11.10.2026, ClubUnio staging only. PHPMaker DEV/PROD remain unchanged.

## Delivered

- Tenant-scoped contribution prices and rules with season, gender and inclusive
  birth-year ranges. Active overlapping rules for the same season/gender are
  rejected. Bexio product/article references are preserved, not executed.
- Training times with team/season, summer/winter, weekday, start/end, pitch,
  changing room, hall, side and existing availability/website flags.
- Training selection values are database rows imported from the PMP metadata,
  with editable labels and activation. Existing option codes remain immutable.
- Training times also appear in the team-season detail relationship.
- Search and filters persist together. Create/edit is admin/office only, with
  tenant foreign-key validation, revision checks, transactions and change audit.
- Initial admin dashboard: active members, registrations under review, expenses
  for review/payment and active training times. Training count includes all
  seasons; it is not a count of today's sessions. Automatic polling is disabled.

Migration: `2026_10_11_000015_contributions_training.php`.
Import: `clubunio:import-contributions-training`, marker `contributions-training-v1`.
Source: existing read-only `legacy_prod` snapshot, never live production.
Imported 9 prices, 117 rules, 648 training rows and 21 lookup options.
Re-running returns already_imported and preserves subsequent edits.
Legacy times such as 19.45 are normalized to 19:45:00; invalid times abort the
transaction. Soft-deleted source rows are retained as inactive.
Legacy weekday codes are 1=Sunday through 7=Saturday, NOT ISO weekday numbers.

## Explicitly pending

- Contribution calculation/preview and invoice generation: family discount,
  honorary/free membership, duplicate prevention per member/season, rounding,
  invoice positions, Bexio submission and reconciliation.
- Training portal/mobile endpoints and field-conflict handling.
- Advanced dashboard drill-downs and role-specific dashboards.
- Full Signage migration, including matches from other clubs, club-number logos,
  pitch/changing rooms, SFV updates without duplicate matches, messages, facility
  status, summer/winter/current-season training and availability modes, weather
  and Instagram, tenant-scoped kiosk access and last-good-data/error behavior.
  Existing Signage is unchanged. Do not treat the training editor as Signage.

## Verification

Focused tests cover create/edit forms, rule overlap, stale revision, time
validation, option-category mismatch, cross-tenant denial, trainer write denial,
audit and dashboard rendering. Local browser verifies dashboard and populated
training list. No real business record was edited for these checks.
All outbound test mail remains redirected to admin@svsissach.ch; external push,
Bexio writes and Azure writes remain disabled.

Full suite: 84 tests / 782 assertions passed. Snapshot verification: 84 tables,
49,946 rows unchanged. Deployed as 20261011-clubora-12 to Pi staging; HTTPS login
200 and protected training/contribution routes 302 verified. Rollback: release11.

## Package19: contribution calculation and draft invoice

The calculation/preview and individual draft creation listed above are now
implemented at ClubUnio > Finanzen > Beitragsberechnung. Admin/office only.
No new migration. Rules from TeamListCreateInvoicePosition were reviewed.

- Active member and active player assignment in the selected active season.
  Birth date/gender must be present. Exactly one active matching season/gender/
  inclusive-birth-year rule with active price is required.
- Integer cents, decimal half-up rounding to two places; no binary float
  calculations. Family discount capped at gross amount. Honorary/free members
  receive full discount. Negative/malformed source amounts are rejected.
- Multiple active family assignments or matching tariffs are rejected instead
  of the legacy nondeterministic TOP1 selection. No assumptions about discounts.
- Preview shows member, season, tariff, gross, discount/reason and net. Confirm
  creates one invoice with one contribution line in P (Pendent), INCLUDING
  zero invoices. No pretending a payment occurred and no Bexio submission.
  Existing active P status is required; no hardcoded status primary key.
- Under tenant/member locks the calculation is rechecked against its preview
  fingerprint. Changed input requires a new preview. Existing active contribution
  for member/season is returned, never duplicated; multiple existing positions
  require manual cleanup. Existing invoices are never recalculated/overwritten.
- New contribution line is linked to all active, not-yet-linked player
  assignments for the season. Conflicting pre-existing links block creation.
  Invoice and player-link changes are audited in the same transaction.
- Permission and tenant checks occur on preview and confirmation. Client-supplied
  calculations cannot replace the locked Livewire preview. No API added.

Still pending: bulk contribution run, adoption/reconciliation of existing player
links, invoice editing/release/cancellation, invoice PDF/Bexio, notices and exact
zero-invoice reporting semantics. Read-only legacy dashboard zero/net fallback
is not changed silently by this package. This is NOT the finished finance module.
No PHPMaker source/data changes, no external writes or real notifications.
