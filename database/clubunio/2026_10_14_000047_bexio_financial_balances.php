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
ALTER TABLE clubora.bexio_checks ADD COLUMN financials jsonb;
CREATE TABLE clubora.bexio_balances (
 id bigserial PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubora.tenants(id),
 object_type text NOT NULL CHECK(object_type IN ('invoice','expense')), object_id bigint NOT NULL,
 remote_id text NOT NULL, remote_status text NOT NULL,
 total_cents bigint NOT NULL CHECK(total_cents >= 0),
 paid_cents bigint NOT NULL CHECK(paid_cents >= 0),
 credit_cents bigint NOT NULL CHECK(credit_cents >= 0),
 remaining_cents bigint NOT NULL CHECK(remaining_cents >= 0),
 observed_at timestamptz NOT NULL,
 UNIQUE(tenant_id,object_type,object_id),
 CHECK(total_cents = paid_cents + credit_cents + remaining_cents)
);
CREATE TABLE clubora.bexio_financial_actions (
 id bigserial PRIMARY KEY, dispatch_id bigint NOT NULL REFERENCES clubora.bexio_sandbox_dispatches(id),
 actor_id bigint NOT NULL REFERENCES clubora.accounts(id),
 operation text NOT NULL CHECK(operation IN ('payment_partial','payment_full','bill_book','bill_pay')),
 state text NOT NULL CHECK(state IN ('running','uncertain','accepted','rejected')),
 reference uuid NOT NULL UNIQUE, connection_hash text NOT NULL,
 amount_cents bigint NOT NULL CHECK(amount_cents >= 0), bank_id integer, payment_date date NOT NULL,
 previous_ids jsonb NOT NULL DEFAULT '[]', remote_id text, http_status integer,
 created_at timestamptz NOT NULL DEFAULT now(), checked_at timestamptz,
 UNIQUE(dispatch_id,operation)
);
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
