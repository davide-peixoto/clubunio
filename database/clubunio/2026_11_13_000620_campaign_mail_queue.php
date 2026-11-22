<?php

use App\ClubUnio\Services\StageMigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if (! \App\ClubUnio\Services\CampaignMigrationTarget::allows($db)) throw new LogicException('Unexpected campaign queue target');
        $db->unprepared(<<<'SQL'
ALTER TABLE clubunio.campaign_recipients
 ADD COLUMN mail_attempts integer NOT NULL DEFAULT 0,
 ADD COLUMN mail_due_at timestamptz,
 ADD COLUMN mail_attempted_at timestamptz,
 ADD COLUMN mail_code integer,
 ADD COLUMN mail_address_hash char(64);
CREATE INDEX campaign_mail_due ON clubunio.campaign_recipients (mail_due_at,id) WHERE mail_state='queued';
CREATE INDEX campaign_mail_attempted ON clubunio.campaign_recipients (mail_attempted_at DESC) WHERE mail_attempted_at IS NOT NULL;
CREATE INDEX campaign_mail_bounces ON clubunio.campaign_recipients (tenant_id,mail_address_hash) WHERE mail_state='bounced';
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Campaign delivery history requires a reviewed restore');
    }
};
