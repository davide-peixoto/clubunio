<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';

    public function sql(): string
    {
        return <<<'SQL'
ALTER TABLE clubora.club_events ADD COLUMN checkin_enabled boolean NOT NULL DEFAULT false,
 ADD COLUMN checkin_opens_at timestamptz, ADD COLUMN checkin_closes_at timestamptz,
 ADD CONSTRAINT club_event_checkin_window CHECK(NOT checkin_enabled OR
   (checkin_opens_at IS NOT NULL AND checkin_closes_at IS NOT NULL AND checkin_closes_at > checkin_opens_at));
CREATE TABLE clubora.event_checkin_codes (
 id uuid PRIMARY KEY, tenant_id bigint NOT NULL, event_id bigint NOT NULL,
 token_hash char(64) NOT NULL UNIQUE, token_encrypted text NOT NULL,
 revision integer NOT NULL DEFAULT 1 CHECK(revision > 0),
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,event_id), UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,event_id) REFERENCES clubora.club_events(tenant_id,id)
);
CREATE TABLE clubora.event_checkins (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 tenant_id bigint NOT NULL, event_id bigint NOT NULL, member_id bigint NOT NULL,
 checked_in_at timestamptz NOT NULL DEFAULT now(), checked_in_by bigint NOT NULL REFERENCES clubora.accounts(id),
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,event_id,member_id), UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,event_id) REFERENCES clubora.club_events(tenant_id,id),
 FOREIGN KEY(tenant_id,member_id) REFERENCES clubora.members(tenant_id,id)
);
CREATE INDEX event_checkin_event_lookup ON clubora.event_checkins(tenant_id,event_id,checked_in_at);
REVOKE ALL ON clubora.event_checkin_codes,clubora.event_checkins FROM PUBLIC;
REVOKE ALL ON SEQUENCE clubora.event_checkins_id_seq FROM PUBLIC;
SQL;
    }

    public function up(): void
    {
        if (! \App\ClubUnio\Services\CampaignMigrationTarget::allows(DB::connection('clubunio'))) throw new LogicException('Reviewed event-checkin target required');
        DB::connection('clubunio')->unprepared($this->sql());
    }

    public function down(): void
    {
        throw new LogicException('Attendance audit history must be retained; reviewed restore required');
    }
};
