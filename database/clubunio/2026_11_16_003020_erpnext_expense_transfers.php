<?php

use App\ClubUnio\Services\StageMigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        $dev = $db->getDriverName() === 'pgsql'
            && $db->getDatabaseName() === 'clubunio_dev'
            && $db->selectOne('SELECT current_database() AS name')->name === 'clubunio_dev';
        abort_unless($dev || StageMigrationTarget::allows($db), 409);
        $db->unprepared(<<<'SQL'
CREATE TABLE clubunio.erpnext_expense_transfers (
    tenant_id bigint NOT NULL REFERENCES clubunio.tenants(id),
    expense_id bigint PRIMARY KEY,
    source_revision integer NOT NULL,
    reference varchar(100) NOT NULL UNIQUE,
    origin varchar(255) NOT NULL,
    total_cents bigint NOT NULL CHECK (total_cents > 0),
    remote_id varchar(140),
    state varchar(24) NOT NULL DEFAULT 'pending' CHECK (state IN ('pending','draft','open','part_paid','paid','cancelled')),
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK ((remote_id IS NULL) = (state = 'pending')),
    FOREIGN KEY (tenant_id, expense_id) REFERENCES clubunio.expenses(tenant_id, id)
);
CREATE INDEX erpnext_expense_transfers_tenant_state ON clubunio.erpnext_expense_transfers(tenant_id, state);
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required to preserve ERPNext references.');
    }
};
