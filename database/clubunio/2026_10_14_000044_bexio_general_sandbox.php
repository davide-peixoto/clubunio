<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if (!\App\ClubUnio\Services\StageMigrationTarget::allows($db)) {
            throw new LogicException('Unexpected target');
        }
        $db->unprepared(<<<'SQL'
ALTER TABLE clubunio.bexio_sandbox_dispatches
 DROP CONSTRAINT bexio_sandbox_dispatches_tenant_id_kind_key,
 ADD COLUMN source_trial_id bigint UNIQUE REFERENCES clubunio.bexio_dispatch_trials(id),
 ADD COLUMN expected_cents bigint,
 ADD COLUMN uploaded_ids jsonb NOT NULL DEFAULT '[]'::jsonb;
CREATE UNIQUE INDEX sandbox_fixed_kind ON clubunio.bexio_sandbox_dispatches(tenant_id,kind) WHERE source_trial_id IS NULL;
CREATE UNIQUE INDEX sandbox_invoice_document ON clubunio.bexio_sandbox_dispatches(tenant_id,invoice_id) WHERE invoice_id IS NOT NULL;
CREATE UNIQUE INDEX sandbox_expense_document ON clubunio.bexio_sandbox_dispatches(tenant_id,expense_id) WHERE expense_id IS NOT NULL;
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
