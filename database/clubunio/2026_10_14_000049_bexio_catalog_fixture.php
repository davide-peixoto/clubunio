<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
            throw new LogicException('Unexpected target');
        }
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.bexio_catalog_fixture_actions (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubora.tenants(id),
 actor_id bigint NOT NULL REFERENCES clubora.accounts(id), connection_hash text NOT NULL,
 state text NOT NULL CHECK(state IN ('running','uncertain','accepted','rejected')),
 remote_id bigint, http_status integer, created_at timestamptz NOT NULL DEFAULT now(),
 checked_at timestamptz, UNIQUE(tenant_id,connection_hash)
);
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
