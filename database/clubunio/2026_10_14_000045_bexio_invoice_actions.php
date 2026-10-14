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
CREATE TABLE clubora.bexio_invoice_actions (
 id bigserial PRIMARY KEY,
 dispatch_id bigint NOT NULL REFERENCES clubora.bexio_sandbox_dispatches(id),
 actor_id bigint NOT NULL REFERENCES clubora.accounts(id),
 operation varchar(12) NOT NULL CHECK (operation IN ('issue','cancel')),
 state varchar(12) NOT NULL CHECK (state IN ('running','uncertain','accepted')),
 connection_hash varchar(64) NOT NULL,
 before_status integer NOT NULL,
 after_status integer,
 http_status integer,
 created_at timestamptz NOT NULL DEFAULT now(),
 checked_at timestamptz,
 UNIQUE(dispatch_id,operation)
);
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
