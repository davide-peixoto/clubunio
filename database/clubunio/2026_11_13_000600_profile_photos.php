<?php

use App\ClubUnio\Services\StageMigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if (! StageMigrationTarget::allows($db)) {
            throw new LogicException('Unexpected photo migration target');
        }
        $db->unprepared(<<<'SQL'
CREATE TABLE clubunio.account_profile_photos (
 account_id bigint PRIMARY KEY REFERENCES clubunio.accounts(id),
 image_encrypted text NOT NULL
);
CREATE TABLE clubunio.member_profile_photos (
 tenant_id bigint NOT NULL, member_id bigint NOT NULL,
 image_encrypted text, pending_encrypted text, submitted_by bigint,
 revision bigint NOT NULL DEFAULT 1,
 PRIMARY KEY(tenant_id,member_id),
 FOREIGN KEY(tenant_id,member_id) REFERENCES clubunio.members(tenant_id,id),
 FOREIGN KEY(tenant_id,submitted_by) REFERENCES clubunio.tenant_accounts(tenant_id,account_id)
);
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Profile photos require a reviewed restore');
    }
};
