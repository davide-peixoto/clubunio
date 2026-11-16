<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        DB::connection('clubunio')->statement("CREATE TABLE clubora.erpnext_invoice_transfers (
            tenant_id bigint NOT NULL REFERENCES clubora.tenants(id),
            invoice_id bigint PRIMARY KEY,
            reference varchar(100) NOT NULL UNIQUE,
            origin varchar(255) NOT NULL,
            total_cents bigint NOT NULL CHECK (total_cents > 0),
            remote_id varchar(140),
            state varchar(24) NOT NULL DEFAULT 'pending' CHECK (state IN ('pending','draft','open','part_paid','paid','cancelled')),
            created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CHECK ((remote_id IS NULL) = (state = 'pending')),
            FOREIGN KEY (tenant_id, invoice_id) REFERENCES clubora.invoices(tenant_id, id)
        )");
        DB::connection('clubunio')->statement('CREATE INDEX erpnext_invoice_transfers_tenant_state ON clubora.erpnext_invoice_transfers(tenant_id, state)');
    }
};
