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
ALTER TABLE clubora.bexio_sandbox_dispatches
 DROP CONSTRAINT bexio_sandbox_dispatches_tenant_id_kind_key,
 ADD COLUMN source_trial_id bigint UNIQUE REFERENCES clubora.bexio_dispatch_trials(id),
 ADD COLUMN expected_cents bigint,
 ADD COLUMN uploaded_ids jsonb NOT NULL DEFAULT '[]'::jsonb;
CREATE UNIQUE INDEX sandbox_fixed_kind ON clubora.bexio_sandbox_dispatches(tenant_id,kind) WHERE source_trial_id IS NULL;
CREATE UNIQUE INDEX sandbox_invoice_document ON clubora.bexio_sandbox_dispatches(tenant_id,invoice_id) WHERE invoice_id IS NOT NULL;
CREATE UNIQUE INDEX sandbox_expense_document ON clubora.bexio_sandbox_dispatches(tenant_id,expense_id) WHERE expense_id IS NOT NULL;
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
