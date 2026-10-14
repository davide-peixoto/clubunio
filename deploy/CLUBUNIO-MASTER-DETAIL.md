# ClubUnio: PMP relationship migration

11.10.2026. Source: operative svs2026_prerelease_v3.pmp. Metadata only is
exported by tools/export-pmp-relations.php to pmp-relations.json. The export
contains 31 master/detail definitions and field captions, lookup targets,
static option seeds, parent filters and autofill mappings. No credentials,
connections or event bodies are exported. It is a reviewed migration reference,
not executable configuration or an automatic permissions generator.

## Member dossier

All 13 explicit member detail relationships now have read views, plus TL:

| PMP detail / key | ClubUnio member tab | Current scope |
| --- | --- | --- |
| BankkontoStaff.MitgliederID | Bankkonten | Bank lookup, masked encrypted IBAN |
| Bemerkungen.MitgliederID | Bemerkungen | Escaped text |
| FamilieMitglieder.FamilieMID | Familien | Family and discount |
| Funktionaere.MitgliederID | Funktionen | Function and multiple qualifications |
| Gaeste.MitgliederID | Veranstaltungen | Event, place and season |
| Rechnung.MitgliederID | Rechnungen | Detail page with Positionen |
| Schluesselliste.MitgliederID | Schluessel | Number, description, colour |
| Spesenbelege.MitgliederID | Spesen | Detail page with SpesenPositionen |
| SPONSOR.MitgliederID | Sponsoring | Amount, term, notes |
| TeamStaff.MitgliederID | Staff | Team/season and staff function |
| MitgliederAnmeldungen.MitgliederID | Anmeldungen | Status, team and submitted date |
| TeamList.MitgliederID | Spieler | Team/season |
| MitgliederAnhaenge.MitgliederID | Anhaenge | Metadata only; files still pending |
| TechnischeLeiterTeams.MitgliederID (additional) | Technische Leitung | Team responsibilities |

Full member page replaces the old view modal; editing still opens a slide-over.
Child tables are paginated, searchable where relevant, filterable and lazily loaded.
Inactive/soft-deleted source rows are preserved and hidden by the default active
filter, not deleted. The filter can expose them for review by admin/office.

Other master/detail pages: Familie -> family memberships, Teams -> team seasons,
Saison -> team seasons/invoices/expenses, TeamsSaison -> players/staff/TL/
registrations/expenses. Team training times and remaining event/bus/master-detail
modules are not delivered in this batch.

## Lookups and field dependencies

- All displayed status/function/type labels resolve through tenant tables.
  Expense statuses, rounds and game types seed from PMP user values. Bexio
  invoice status labels seed from BexioStatusMapping. Unknown historic codes
  remain explicit inactive catalog entries, never silently remapped.
- Spesenbelege.TeamSaisonID depends on SaisonID; BankkontoStaffID depends on
  MitgliederID. These are retained as separate typed foreign keys. Expense
  write forms are deliberately NOT enabled until same-season and same-owner
  service validation and status workflows are implemented.
- Positionen.bexioProductId autofills Bezeichnung and BruttoBetrag in PMP.
  Invoice writes/product catalog migration are still pending; no substitute
  free-text posting form is exposed.
- TeamList.PositionsID is a financial line link. invoice_line_id now references
  the migrated line; the original legacy value remains for reconciliation.
- Function QualiID is multi-select: normalized member_function_qualifications
  pivot, original reference retained privately for diagnostics.
- Expense opponent lookup links on Vereinsname, not numeric club ID. Existing
  text is preserved. Future editing must supply the clubs lookup.
- Existing editable member, family, team and catalog forms continue through
  their domain services. The dossier's child tables are currently read-only.
- Some PMP master/detail declarations represent lookups in reverse direction
  (e.g. BankkontoStaff -> Institut). These become belongsTo lookups, not editable
  nested bank catalog rows. PMP cascade-delete flags are NOT copied blindly.

## Data and safety

Migration 000005 creates typed ClubUnio projections with composite tenant FKs
and indexes, links registrations to members and players to invoice lines.
clubunio:import-member-dossier is one-time, transactional, advisory-locked and
idempotent. It reads only the existing legacy_prod snapshot, never live PROD.
It rejects unresolved references; no source row is removed or overwritten.
New bank IBANs are encrypted with APP_KEY and excluded from serialization.
Neither integration errors nor secrets are copied into visible finance fields.

Totals use active positions only and decimal database arithmetic. No Bexio
status calls, payment actions, business mail, push or new mobile APIs
are enabled. Release06 adds read-only member Azure attachments and fixed-admin
sample mail (see CLUBUNIO-DOCUMENTS.md and CLUBUNIO-COMMUNICATION.md).
Detail managers authorize the parent and the current tenant on
each Livewire request; forged parent IDs and cross-tenant references are tested.

Verification: tools/clubunio-verify-dossier.php checks counts, financial totals,
unresolved financial references and encrypted bank values without displaying
personal data. Automated tests use synthetic tenants and rollback.

## Still pending

Finance write workflows and full lookup maintenance UI for the newly imported
finance catalogs; bank validation/editing; additional note/function/key/event/
sponsor editing services; uploads, photos and invoice PDFs; remaining registration and team
planning workflows. This batch is a complete member relationship read model,
not the complete migration of all business actions.
