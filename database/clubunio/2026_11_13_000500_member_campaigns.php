<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if (! \App\ClubUnio\Services\CampaignMigrationTarget::allows($db)) {
            throw new LogicException('Unexpected campaign migration target');
        }
        $db->unprepared(<<<'SQL'
ALTER TABLE clubunio.member_change_requests ALTER COLUMN requested_by DROP NOT NULL;
CREATE TABLE clubunio.campaigns (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 tenant_id bigint NOT NULL REFERENCES clubunio.tenants(id),
 title varchar(160) NOT NULL, kind varchar(40) NOT NULL DEFAULT 'member_update',
 channel varchar(10) NOT NULL CHECK (channel IN ('email','post','both')),
 created_by bigint NOT NULL, created_at timestamptz NOT NULL DEFAULT now(),
 released_at timestamptz, expires_at timestamptz, revoked_at timestamptz,
 UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,created_by) REFERENCES clubunio.tenant_accounts(tenant_id,account_id)
);
CREATE TABLE clubunio.campaign_recipients (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 tenant_id bigint NOT NULL, campaign_id bigint NOT NULL, member_id bigint NOT NULL,
 channel varchar(10) NOT NULL CHECK (channel IN ('email','post','both')),
 token_hash char(64) UNIQUE, token_encrypted text,
 request_id bigint, acknowledged_at timestamptz, posted_at timestamptz,
 mail_state varchar(20) NOT NULL DEFAULT 'pending',
 UNIQUE(tenant_id,campaign_id,member_id), UNIQUE(tenant_id,request_id),
 FOREIGN KEY(tenant_id,campaign_id) REFERENCES clubunio.campaigns(tenant_id,id),
 FOREIGN KEY(tenant_id,member_id) REFERENCES clubunio.members(tenant_id,id),
 FOREIGN KEY(tenant_id,request_id) REFERENCES clubunio.member_change_requests(tenant_id,id)
);
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Campaign history requires a reviewed restore');
    }
};
