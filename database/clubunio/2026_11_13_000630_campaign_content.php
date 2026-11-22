<?php

use App\ClubUnio\Services\StageMigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if (! \App\ClubUnio\Services\CampaignMigrationTarget::allows($db)) throw new LogicException('Unexpected migration target');
        $db->statement('ALTER TABLE clubunio.campaigns ADD COLUMN content jsonb');
        $db->statement("ALTER TABLE clubunio.campaign_recipients
            ADD COLUMN guardian_account_id bigint, ADD COLUMN guardian_email_hash text,
            ADD FOREIGN KEY (tenant_id, guardian_account_id) REFERENCES clubunio.tenant_accounts (tenant_id, account_id),
            ADD CHECK ((guardian_account_id IS NULL AND guardian_email_hash IS NULL)
                OR (guardian_account_id IS NOT NULL AND guardian_email_hash IS NOT NULL AND channel = 'email'))");
    }

    public function down(): void
    {
        throw new LogicException('Released campaigns require a reviewed restore');
    }
};
