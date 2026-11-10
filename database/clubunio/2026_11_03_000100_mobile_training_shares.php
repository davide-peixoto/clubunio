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
CREATE TABLE clubora.mobile_training_plan_shares (
 tenant_id bigint NOT NULL REFERENCES clubora.tenants(id),
 plan_id bigint NOT NULL,
 team_season_id bigint NOT NULL, shared_by bigint NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(tenant_id,plan_id),
 FOREIGN KEY(tenant_id,plan_id) REFERENCES clubora.training_plans(tenant_id,id),
 FOREIGN KEY(tenant_id,team_season_id) REFERENCES clubora.team_seasons(tenant_id,id),
 FOREIGN KEY(tenant_id,shared_by) REFERENCES clubora.tenant_accounts(tenant_id,account_id)
);
CREATE INDEX mobile_training_shares_team ON clubora.mobile_training_plan_shares(tenant_id,team_season_id);
REVOKE ALL ON clubora.mobile_training_plan_shares FROM PUBLIC;
SQL);
    }
    public function down(): void { throw new LogicException('Reviewed restore required to preserve sharing policy'); }
};
