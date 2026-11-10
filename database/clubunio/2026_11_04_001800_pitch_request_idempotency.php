<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if ($db->getDriverName() !== 'pgsql' || !\App\ClubUnio\Services\StageMigrationTarget::allows($db)) {
            throw new LogicException('Reviewed ClubUnio PostgreSQL required');
        }
        $db->statement("SET LOCAL lock_timeout = '15s'");
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.pitch_request_idempotency (
 tenant_id bigint NOT NULL REFERENCES clubora.tenants(id),
 account_id bigint NOT NULL REFERENCES clubora.accounts(id),
 key_hash char(64) NOT NULL CHECK (key_hash ~ '^[a-f0-9]{64}$'),
 payload_hash char(64) NOT NULL CHECK (payload_hash ~ '^[a-f0-9]{64}$'),
 response_encrypted text NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY (tenant_id,account_id,key_hash)
);
REVOKE ALL ON clubora.pitch_request_idempotency FROM PUBLIC;
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Retain request idempotency history; reviewed restore required');
    }
};
