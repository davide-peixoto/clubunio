<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';
    public function up(): void {
        $db = DB::connection('clubunio');
        if ($db->getDriverName() !== 'pgsql' || !\App\ClubUnio\Services\StageMigrationTarget::allows($db)) {
            throw new LogicException('Reviewed ClubUnio PostgreSQL required');
        }
        $db->statement("CREATE TABLE clubora.mobile_planning_commands (
            tenant_id bigint NOT NULL REFERENCES clubora.tenants(id),
            account_id bigint NOT NULL REFERENCES clubora.accounts(id),
            command_key uuid NOT NULL, operation varchar(30) NOT NULL,
            fingerprint char(64) NOT NULL, event_ids jsonb NOT NULL,
            created_at timestamptz NOT NULL DEFAULT now(),
            PRIMARY KEY (tenant_id, account_id, command_key),
            CHECK (operation IN ('batch_create'))
        )");
    }
    public function down(): void { throw new LogicException('Retain command identities; reviewed restore required'); }
};
