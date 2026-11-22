<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';

    public function up(): void
    {
        if (! \App\ClubUnio\Services\CampaignMigrationTarget::allows(DB::connection('clubunio'))) {
            throw new LogicException('Reviewed member-wallet target required');
        }
        DB::connection('clubunio')->unprepared($this->sql());
    }

    public function sql(): string
    {
        return <<<'SQL'
CREATE TABLE clubunio.member_wallet_passes (
 serial uuid PRIMARY KEY, card_id uuid NOT NULL UNIQUE,
 tenant_id bigint NOT NULL REFERENCES clubunio.tenants(id), member_id bigint NOT NULL,
 pass_type varchar(180) NOT NULL, auth_hash char(64) NOT NULL, auth_encrypted text NOT NULL,
 snapshot_encrypted text NOT NULL, artwork_encrypted text NOT NULL,
 fingerprint char(64) NOT NULL, revision bigint NOT NULL DEFAULT 1,
 update_tag bigint NOT NULL DEFAULT 0, package_encrypted text,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), scanned_at timestamptz,
 UNIQUE(tenant_id,member_id), CHECK(revision>0),
 FOREIGN KEY(tenant_id,card_id) REFERENCES clubunio.member_cards(tenant_id,id),
 FOREIGN KEY(tenant_id,member_id) REFERENCES clubunio.members(tenant_id,id)
);
CREATE TABLE clubunio.member_wallet_changes (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 serial uuid NOT NULL REFERENCES clubunio.member_wallet_passes(serial) ON DELETE CASCADE,
 created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE clubunio.member_wallet_registrations (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 serial uuid NOT NULL REFERENCES clubunio.member_wallet_passes(serial) ON DELETE CASCADE,
 device_hash char(64) NOT NULL, push_encrypted text NOT NULL,
 notified_revision bigint NOT NULL DEFAULT 0, attempts integer NOT NULL DEFAULT 0,
 next_attempt_at timestamptz, created_at timestamptz NOT NULL DEFAULT now(),
 updated_at timestamptz NOT NULL DEFAULT now(), UNIQUE(serial,device_hash)
);
CREATE INDEX member_wallet_registration_device ON clubunio.member_wallet_registrations(device_hash);
CREATE INDEX member_wallet_update_tag ON clubunio.member_wallet_passes(update_tag);
REVOKE ALL ON clubunio.member_wallet_passes, clubunio.member_wallet_changes, clubunio.member_wallet_registrations FROM PUBLIC;
REVOKE ALL ON SEQUENCE clubunio.member_wallet_changes_id_seq, clubunio.member_wallet_registrations_id_seq FROM PUBLIC;
SQL;
    }

    public function down(): void
    {
        throw new LogicException('Installed wallet passes require a reviewed revocation/restore plan.');
    }
};
