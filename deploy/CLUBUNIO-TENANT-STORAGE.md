# Tenant Document Storage and Logo Upload

## Available on ClubUnio DEV

Released to `dev.clubunio.ch` as `20261107-clubunio-tenant-storage` from
`961afff`. The logo upload is available. The storage tables exist, are empty
and have no public grants. All storage flags are off and the existing SV
Sissach destination is unchanged. Rollout details and rollback: [PI.md](PI.md).

- `/platform/tenants/{id}` > Vereinsdesign accepts PNG, JPEG and WebP, maximum
  5 MiB and 2048 by 2048 pixels. GD decodes and re-encodes to PNG. SVG upload
  is not accepted. An uploaded file takes precedence over the URL field in the
  same form; existing HTTPS and bundled logo paths continue working.
- Only the current logo is served via a versioned, tenant-specific public image
  route. The underlying bytes are on the private shared disk. The file route
  does not expose other uploads. A previous logo stops resolving after change
  (browser cache up to five minutes). Superseded/orphan bytes are not deleted
  automatically; review cleanup and include the logo tree in backup/restore.
- Storage profiles support Azure Blob, Amazon S3, Google Workspace shared
  drives, Microsoft 365 OneDrive/SharePoint and a private ClubUnio disk.
  Only platform operators can select a profile or rotate its encrypted credentials;
  no credentials in the UI, redirect input, audit log, download URL or Git.
  Audit records profile ID/provider and reason, not secrets.
  The provider forms and destination overview are available in DE/EN.
- Covers the document upload journal: member documents, registrations and
  receipts, including mobile entry points and Bexio attachment reads. Independent
  features such as chat media, branding assets and generated PDFs retain their
  existing storage contracts; this is not a global filesystem switch.

## References, Switching and Privacy

New files record `clubunio/storage/{profile}/{kind}/{tenant}/{object}/` plus a
generated filename. Resolution checks tenant ID in both the path and the DB
profile; expense reads additionally check the line, and direct registration
reads check the application. Accepted registrations retain the original
document reference when copied into the member dossier. The selected profile
applies only to new uploads; pending and published files use their stored
profile even when no longer selected. **Update credentials only** rotates the
secret for an existing location without selecting it or changing its profile
ID. One external destination cannot be assigned to two tenants. No historical
files are moved or rewritten. Storage remains transparent to the mobile API.

Old references keep the existing Azure configuration, restricted to its legacy
tenant (SV Sissach on ClubUnio). A new club without a profile cannot use that
storage. There is no fallback from a failed new profile to the legacy account.
New Azure writes use the fixed Azure hostname and verify private container
properties before sending bytes. Configure a dedicated, non-public storage
account/container and disable anonymous access at the account level. The
adapter uses the existing Shared Key SDK; Entra/delegation support is not part
of this release. See [Get Container Properties](https://learn.microsoft.com/en-us/rest/api/storageservices/get-container-properties)
and [Azure SAS guidance](https://learn.microsoft.com/en-us/azure/storage/common/storage-sas-overview).

Managed storage uses `storage/app/private/managed-documents`, with tenant and
profile namespaces. Atomic create-without-overwrite requires hard-link support
on the shared volume. It is not a public disk or a commercial service yet.
Before enabling it: verify disk/backup capacity, tenant quotas, encryption at
rest, restore drills, retention and monitoring. No surcharge or billing is
implemented. Operational prerequisites are not satisfied just by setting a flag.

## Migration and Activation

Additive migrations `2026_11_07_000100_tenant_storage_profiles.php` and
`2026_11_07_000200_tenant_storage_providers.php`. The second widens target
identifiers, enforces unique external destinations, and adds a private index
of provider object IDs for Drive and Graph. It will reject existing duplicate
external destinations rather than reassign them. No changes to existing
document rows, credentials, or account roles. The logo needs neither migration.

1. Back up ClubUnio DB, APP_KEY and private storage. Apply only the reviewed
   migrations, install the locked Composer dependencies, deploy all readers
   and writers together, and leave all storage flags off.
2. Check legacy links, new logo upload/replacement, public image MIME and GD
   support on the target host. Check that no credential or private file is exposed.
3. Set `CLUBUNIO_STORAGE_PROFILES_ENABLED=true` only on the intended ClubUnio
   environment after the schema exists. Enable only the intended provider flag:
   `CLUBUNIO_S3_STORAGE_ENABLED`, `CLUBUNIO_GOOGLE_STORAGE_ENABLED`,
   `CLUBUNIO_GRAPH_STORAGE_ENABLED` or `CLUBUNIO_MANAGED_STORAGE_ENABLED`.
   Azure requires only the profiles flag. Refresh the config cache. Use a
   non-SVS test tenant and synthetic files. Keep managed off until operationally
   approved.
4. Exercise stage, write, read, retry, profile switch, old-file download,
   cross-tenant rejection and Bexio preparation. For Azure use a separate private
   test container; verify before rotating a key used by published documents.

For rollback before first profile-backed write, turn off the profile feature.
After first use, preserve both tables, APP_KEY, profile credentials and
compatible readers. Disable the individual provider flag to stop new writes
while keeping reads enabled, or select a different target. Do not revert to
code that cannot resolve profile references. No destructive `down()`.
PHPMaker DEV/PROD are unchanged. ClubUnio DEV already has both migrations;
do not apply them again when configuring a test provider.

## Provider Setup

- **Amazon S3:** specify the AWS region, 12-digit account ID and a dedicated
  private bucket. Supply JSON with `access_key_id` and `secret_access_key`.
  Configure all four [Block Public Access settings](https://docs.aws.amazon.com/AmazonS3/latest/userguide/access-control-block-public-access.html)
  at bucket level, no public bucket policy, and scope IAM to the intended
  bucket and ClubUnio object prefix. The connection check uses
  `s3:GetBucketPublicAccessBlock` and `s3:GetBucketPolicyStatus`. Runtime needs
  `s3:GetObject`, `s3:PutObject` and enough scoped `s3:ListBucket` access to
  distinguish a missing file (404) from denied access (403). Reads use Flysystem
  and requests pin the expected bucket owner. Writes use the SDK with
  [If-None-Match](https://docs.aws.amazon.com/AmazonS3/latest/userguide/conditional-writes.html);
  a conflict is accepted only if the stored bytes match. The integration
  targets AWS endpoints, not user-supplied S3-compatible URLs.
- **Google Workspace Drive:** enable the Drive API, create a dedicated shared
  drive, restrict file access to drive members, and grant a dedicated service
  account just enough access to create/read files in that drive. Provide the
  shared drive ID and the Google service-account JSON. No domain-wide
  delegation or personal Drive. ClubUnio requests fresh OAuth tokens and checks
  the drive and its restrictions before writing. It reserves a
  [provider-generated file ID](https://developers.google.com/workspace/drive/api/guides/create-file#use_a_pre-generated_id_to_upload_files)
  before transfer, so retries reuse the same ID.
- **OneDrive for Business / SharePoint:** use a dedicated Microsoft 365
  document library with external sharing disabled and restricted membership.
  Register an Entra application, grant admin consent for
  [Selected permissions](https://learn.microsoft.com/en-us/graph/permissions-selected-overview)
  and grant write access only on the intended site or library. Provide the
  Entra tenant ID, drive ID, and JSON with `client_id` and `client_secret`.
  The operator must confirm the sharing policy: the Graph drive probe checks
  access and the drive type, not the tenant's external-sharing configuration.
  Verify the scoped grant with a real upload in the test library. ClubUnio uses
  fresh app tokens and a [create-only upload session](https://learn.microsoft.com/en-us/graph/api/driveitem-createuploadsession?view=graph-rest-1.0).
  Upload and download URLs are accepted only from the expected Microsoft
  service domains and do not receive the Graph bearer token. Personal accounts,
  arbitrary URLs and other Microsoft cloud regions are not supported.

Never include keys or provider transfer URLs in tickets or logs. Existing
documents depend on their profile and remote object ID; retain these in backup,
and test the restored database with APP_KEY and private storage. Do not rename
objects during an in-flight upload or move them to another provider outside a
controlled migration. Successful connection checks are not a substitute for
an end-to-end write/read acceptance test.

## Verification Status

Local provider tests use an in-memory database, synthetic documents and mocked
provider APIs/SDK. They cover the switch, rotation, cross-tenant isolation,
privacy checks, resumable requests, conflict handling, and DE/EN forms (9 tests,
61 assertions). Both migrations were also exercised on disposable
PostgreSQL (PGlite), preserving a pre-existing Azure profile and checking the
uniqueness and provider constraints. The server release also passed the
synthetic logo feature test (13 assertions) with a rolled-back database
transaction. Live provider tests and managed storage operations/billing remain
open.
