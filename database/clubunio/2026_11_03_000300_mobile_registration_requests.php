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
CREATE TABLE clubora.mobile_registration_requests (
 tenant_id bigint NOT NULL REFERENCES clubora.tenants(id), account_id bigint NOT NULL,
 request_key uuid NOT NULL, payload_sha256 char(64) NOT NULL CHECK(payload_sha256 ~ '^[0-9a-f]{64}$'),
 registration_id bigint NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(tenant_id,account_id,request_key),
 FOREIGN KEY(tenant_id,account_id) REFERENCES clubora.tenant_accounts(tenant_id,account_id),
 FOREIGN KEY(tenant_id,registration_id) REFERENCES clubora.registrations(tenant_id,id)
);
REVOKE ALL ON clubora.mobile_registration_requests FROM PUBLIC;
SQL);
    }
    public function down(): void { throw new LogicException('Keep intake idempotency evidence; reviewed restore required'); }
};
