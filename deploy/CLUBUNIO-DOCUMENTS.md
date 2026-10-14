# ClubUnio member document access

Release06 adds authenticated preview (right-side panel) and download for the
70 imported member attachments, both in the document list and member dossier.
This is read-only: no new uploads, moves, deletes or writes to production Azure.

GET /clubora-documents/{tenant-slug}/{document-id}?download=0|1 requires the
ClubUnio session, active admin/office tenant membership, an active document in
that tenant and an unarchived parent member. Cross-tenant IDs are rejected.
The historical SV Sissach adapter is limited to the sv-sissach tenant. New
tenants will need their own storage configuration, never this shared prefix.

AzureDocumentReader uses SDK-signed, HTTPS-only read SAS valid for two minutes,
only server-side, against svs-prod-private. No signed URL goes to the browser.
One GET, no HEAD, redirects or retries; connection/read/deadline and 20MiB limits.
No local fallback and no persistent local file cache. Supported snapshot paths:

- upload/mitglieder/anhaenge/ -> upload/mitglieder/anhaenge/
- var/member-documents/ -> member-documents/

Filenames may contain Unicode but no separators, controls, colon or dot traversal.
Unknown folders are rejected, not guessed. MIME is detected from bytes, not the
database or file extension. Only PDF/JPEG/PNG/WebP/GIF are inline; other formats
are attachments. Responses use private/no-store, nosniff, same-origin framing
and a restrictive CSP. Original filenames are safely encoded in disposition.

Errors contain a reference UUID, never paths, signed URLs or provider content.
The server logs reference, actor/tenant/document IDs and a safe status. Missing
Azure objects return 404; other storage failures 503. A broad account credential
was inherited for reads; least-privilege per-tenant credentials remain required
before production onboarding. Configuration is outside webroot and Git.

Verification: path/traversal, bounded responses, sanitization, tenant/session and
inactive record tests; actual PDF and JPEG samples read from both Azure prefixes;
authenticated desktop PDF slide-over renders successfully. tools/clubunio-check-
document-storage.php --read-sample checks one active sample per folder without
printing contents or filenames. Not a full audit of all 70 objects.

Still pending: isolated ClubUnio uploads, photos, expense attachments, invoice PDF
adapter, antivirus/content policy, retention and all non-office document access.
