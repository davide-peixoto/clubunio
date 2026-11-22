<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';

    public function sql(): string
    {
        return <<<'SQL'
ALTER TABLE clubora.member_statuses ADD COLUMN member_card_eligible boolean NOT NULL DEFAULT false;
UPDATE clubora.member_statuses SET member_card_eligible=true
 WHERE active=true AND restores_membership=true AND ends_membership=false;
CREATE TABLE clubora.member_cards (
 id uuid PRIMARY KEY, tenant_id bigint NOT NULL, member_id bigint NOT NULL,
 token_hash char(64) NOT NULL UNIQUE, token_encrypted text NOT NULL,
 revoked_at timestamptz, revision integer NOT NULL DEFAULT 1 CHECK(revision > 0),
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,member_id), UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,member_id) REFERENCES clubora.members(tenant_id,id)
);
REVOKE ALL ON clubora.member_cards FROM PUBLIC;
SQL;
    }

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if (! \App\ClubUnio\Services\CampaignMigrationTarget::allows($db)) throw new LogicException('Reviewed member-card target required');
        $db->unprepared($this->sql());
    }

    public function down(): void
    {
        throw new LogicException('Retain issued card and revocation history; reviewed restore required');
    }
};
