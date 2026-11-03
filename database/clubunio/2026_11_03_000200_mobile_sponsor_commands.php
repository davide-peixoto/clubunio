<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';
    public function up(): void {
        $db = DB::connection('clubunio');
        if ($db->getDriverName() !== 'pgsql' || $db->getDatabaseName() !== 'db_acdf8d_dclubora') throw new LogicException('Reviewed ClubUnio PostgreSQL required');
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.mobile_sponsor_commands (
 tenant_id bigint NOT NULL, account_id bigint NOT NULL, command_key uuid NOT NULL,
 operation varchar(30) NOT NULL CHECK(operation IN ('create_event','create_link','rotate_link','revoke_link')),
 fingerprint char(64) NOT NULL, event_id bigint NOT NULL, participant_id bigint, result_revision integer NOT NULL CHECK(result_revision>=0),
 created_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(tenant_id,account_id,command_key),
 FOREIGN KEY(tenant_id,account_id) REFERENCES clubora.tenant_accounts(tenant_id,account_id),
 FOREIGN KEY(tenant_id,event_id) REFERENCES clubora.sponsor_run_events(tenant_id,id),
 FOREIGN KEY(tenant_id,event_id,participant_id) REFERENCES clubora.sponsor_run_participants(tenant_id,event_id,id),
 CHECK((operation='create_event' AND participant_id IS NULL) OR (operation<>'create_event' AND participant_id IS NOT NULL))
);
REVOKE ALL ON clubora.mobile_sponsor_commands FROM PUBLIC;
SQL);
    }
    public function down(): void { throw new LogicException('Preserve personal sponsor command history; reviewed restore required'); }
};
