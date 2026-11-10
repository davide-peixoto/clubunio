<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';
    public function up(): void
    {
        $db = DB::connection('clubunio');
        if ($db->getDriverName() !== 'pgsql' || !\App\ClubUnio\Services\StageMigrationTarget::allows($db)) { throw new LogicException('Reviewed ClubUnio PostgreSQL required'); }
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.mobile_identity_challenges (
 id uuid PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubora.tenants(id),
 purpose varchar(16) NOT NULL CHECK(purpose IN ('register','reset','enroll')),
 request_key uuid NOT NULL, request_fingerprint char(64) NOT NULL CHECK(request_fingerprint ~ '^[0-9a-f]{64}$'),
 email_hash char(64) NOT NULL CHECK(email_hash ~ '^[0-9a-f]{64}$'),
 secret_hash char(64) NOT NULL CHECK(secret_hash ~ '^[0-9a-f]{64}$'),
 account_id bigint, state varchar(16) NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','completed','invalidated')),
 payload_encrypted text CHECK(payload_encrypted IS NULL OR octet_length(payload_encrypted) <= 32768),
 delivery_encrypted text CHECK(delivery_encrypted IS NULL OR octet_length(delivery_encrypted) <= 32768),
 delivery_state varchar(16) NOT NULL DEFAULT 'held' CHECK(delivery_state IN ('held','sending','sent','attention','skipped')),
 expires_at timestamptz NOT NULL, completed_at timestamptz, created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,purpose,request_key),
 FOREIGN KEY(tenant_id,account_id) REFERENCES clubora.tenant_accounts(tenant_id,account_id),
 CHECK(purpose<>'enroll' OR account_id IS NOT NULL),
 CHECK(state='pending' OR (payload_encrypted IS NULL AND delivery_encrypted IS NULL))
);
CREATE INDEX mobile_identity_email ON clubora.mobile_identity_challenges(tenant_id,purpose,email_hash,created_at);
CREATE TABLE clubora.mobile_account_emails (
 account_id bigint PRIMARY KEY REFERENCES clubora.accounts(id),
 email_hash char(64) NOT NULL CHECK(email_hash ~ '^[0-9a-f]{64}$'), email_encrypted text NOT NULL CHECK(octet_length(email_encrypted) BETWEEN 1 AND 8192),
 revision integer NOT NULL DEFAULT 1 CHECK(revision > 0), verified_at timestamptz NOT NULL,
 source varchar(32) NOT NULL CHECK(source IN ('self_registration','authenticated_enrollment')),
 proof_challenge_id uuid NOT NULL UNIQUE REFERENCES clubora.mobile_identity_challenges(id)
);
CREATE INDEX mobile_account_email_recovery ON clubora.mobile_account_emails(email_hash);
CREATE TABLE clubora.mobile_public_intake_commands (
 tenant_id bigint NOT NULL REFERENCES clubora.tenants(id), request_key uuid NOT NULL,
 payload_sha256 char(64) NOT NULL CHECK(payload_sha256 ~ '^[0-9a-f]{64}$'),
 registration_id bigint NOT NULL, pending_attachments integer NOT NULL CHECK(pending_attachments BETWEEN 0 AND 4),
 scan_evidence_encrypted text CHECK(scan_evidence_encrypted IS NULL OR octet_length(scan_evidence_encrypted) <= 32768),
 created_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(tenant_id,request_key),
 FOREIGN KEY(tenant_id,registration_id) REFERENCES clubora.registrations(tenant_id,id)
);
REVOKE ALL ON clubora.mobile_identity_challenges,clubora.mobile_account_emails,clubora.mobile_public_intake_commands FROM PUBLIC;
SQL);
    }
    public function down(): void { throw new LogicException('Preserve identity proof and intake idempotency evidence; reviewed restore required'); }
};
