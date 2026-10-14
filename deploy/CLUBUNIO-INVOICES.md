# ClubUnio invoice workflow, package20

## Release41: Wiederherstellung und Archiv

Entfernte manuelle Entwurfspositionen koennen mit Begruendung wiederhergestellt
werden (Positionen: Filter Aktiv = Nein). Beitrags-/Spielerzuordnungen bleiben
geschuetzt. Stornierte, eigene Rechnungen ohne Legacy-/Bexio-ID koennen mit
Begruendung archiviert und ueber Aktiv = Nein wieder geoeffnet/wiederhergestellt
werden. Wiederherstellung hebt den Stornostatus NICHT auf und erzeugt keine
neue Beitragsforderung. Zeilen/Teamverknuepfungen bleiben unveraendert erhalten.

Alle Mutationen mit Revision, Transaktion und Audit; Kontoberechtigung wird
frisch geprueft. Interne Freigabe prueft jetzt auch Positionsmandant und
Brutto minus Rabatt = Netto centgenau. Keine neue Finanzmigration erforderlich.
Importierte Rechnungen und echte Bexio-Belege bleiben schreibgeschuetzt.
Kein Versand, keine Zahlung und keine realen Benutzerbenachrichtigungen.

Noch offen: expliziter Ersatzlauf stornierter Beitraege, steuerpflichtige
Rechnungen nach fachlicher Klaerung, Zahlungsinstruktionen/QR-Rechnung und
echte Bexio-Buchung. Bestehende interne PDF-Vorschau ist keine Zahlungsrechnung.

12.10.2026. ClubUnio only; PHPMaker DEV/PROD and the source snapshot are unchanged.

## Implemented

- Local workflow is separate from imported Bexio status. New lookup table
  invoice_workflow_statuses contains draft/released/cancelled; labels and active
  flags editable in master data, colors use central status appearance.
- Migration000021 adds workflow_status_id and revision to invoices. Only local
  invoices without legacy/Bexio IDs receive draft status. Imported records stay
  read-only. No assertion that Bexio has received or paid an invoice.
- Admin/office can edit draft notes and add/edit positions. Integer-cent amounts,
  capped discounts, transaction locks, revision checks and before/after audit.
- Internal release locks editing. Cancellation needs a reason and retains all
  positions/player links. This is not Bexio cancellation. Cancelled contributions
  block accidental re-billing until explicitly reconciled.
- Portal readers do not see new drafts/cancelled invoices. Imported visibility
  and existing member access rules are retained.
- Seasonal contribution preview uses keyset pages of at most25 members; every
  member appears once even with multiple teams. Each confirmed draft has its own
  transaction and rechecks the preview. Missing/ambiguous tariffs are shown per
  member. Existing invoices are not overwritten. Retrying cannot duplicate a
  seasonal contribution. Unexpected failures stop the request; retry reuses
  already-created invoices. No mass release, mail or Bexio writes.
- PDF preview opens from the right, with download. Local PDF uses Dompdf3.1.6,
  escaped Blade data, no PHP/JavaScript/remote fetching; explicitly an internal
  preview without payment instructions or QR bill.

## Bexio PDF: independent of activation

Authorized GET of https://api.bexio.com/2.0/kb_invoice/{id}/pdf with
Accept: application/json; base64 content is validated as PDF. Activation of
Bexio synchronization and imported setting enabled flag do not block this read.
Missing/invalid credentials still fail; authorization and tenant checks remain.
No browser exposure of bearer tokens, no redirects, short timeouts, private/
no-store responses and safe error reference logging. Source contract:
https://docs.bexio.com/ (Show invoice PDF).

Release25 adds independent OpenID onboarding/rotation, using the same Jumbojett
connector as PHPMaker. See CLUBUNIO-BEXIO-OIDC.md. Old imported tokens are removed
by migration26, never refreshed or revoked at the provider. The new encrypted
BEXIO_OIDC_CONNECTION is populated only after a successful administrator login.
PDF refreshes expired credentials and retries once after401. The old live401
was from the imported token. Consent is now complete; live PDF invoice6790
works locally and on Pi (200,1893246 bytes), including after a real token refresh.

## Remaining finance work

- Broader invoice PDF acceptance, external invoice submission,
  reconciliation, payment notices and external cancellation.
- Scheduled contribution runs and explicit replacement/reconciliation of
  cancelled contributions. Current batches require operator confirmation.
- Final invoice/QR layout and billing-recipient/address semantics.
- Manuelle Entwurfspositionen koennen nun mit Begruendung soft-archiviert werden;
  Beitrags-/Spielerzuordnungen bleiben geschuetzt. Vollstaendige Wiederherstellung
  und Rechnungsarchiv-Lifecycle weiterhin offen. Finanz-CSV und Mappingeditoren:
  CLUBUNIO-FINANCE-EDITORS-EXPORTS.md.

## Verification

Tests cover draft creation/editing, cents, stale revisions, release/cancellation,
imported write protection, PDF signatures, invalid provider content/401 redaction,
disabled activation, role/tenant checks, batch retry and Livewire preview.
Final regression/deployment outcome is recorded in PI.md after completion.
