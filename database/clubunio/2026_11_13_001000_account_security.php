<?php

use App\ClubUnio\Services\StageMigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if ($db->getDriverName() !== 'pgsql' || ! StageMigrationTarget::allows($db)) {
            throw new LogicException('Reviewed ClubUnio PostgreSQL required');
        }
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.account_security (
 account_id bigint PRIMARY KEY REFERENCES clubora.accounts(id),
 totp_encrypted text, totp_last_step bigint, session_version char(32),
 recovery_hashes jsonb NOT NULL DEFAULT '[]',
 invitation_hash char(64), invitation_expires_at timestamptz,
 invitation_recovery boolean NOT NULL DEFAULT false, invitation_email boolean NOT NULL DEFAULT false,
 invitation_add_method boolean NOT NULL DEFAULT false,
 reset_pending boolean NOT NULL DEFAULT false
);
CREATE TABLE clubora.account_passkeys (
 credential_hash char(64) PRIMARY KEY,
 account_id bigint NOT NULL REFERENCES clubora.accounts(id),
 rp_id varchar(253) NOT NULL, public_key text NOT NULL,
 signature_counter bigint NOT NULL DEFAULT 0 CHECK(signature_counter >= 0),
 created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX account_passkeys_owner ON clubora.account_passkeys(account_id, rp_id);
CREATE TABLE clubora.account_security_pairs (
 id uuid PRIMARY KEY, proof_hash char(64) NOT NULL,
 confirmation_hash char(64) NOT NULL, account_id bigint REFERENCES clubora.accounts(id),
 expires_at timestamptz NOT NULL, consumed_at timestamptz, session_stamp char(64)
);
CREATE INDEX account_security_pairs_expiry ON clubora.account_security_pairs(expires_at);
ALTER TABLE clubora.mobile_sessions ADD COLUMN security_stamp char(64);
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Disable the UI without deleting account credentials.');
    }
};
