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
        $db->unprepared('CREATE TABLE clubora.bexio_sandbox_fixtures (
            tenant_id bigint PRIMARY KEY REFERENCES clubora.tenants(id),
            connection_hash varchar(64) NOT NULL,
            member_id bigint NOT NULL, parent_id bigint NOT NULL, supplier_id bigint NOT NULL,
            invoice_id bigint NOT NULL, remote_invoice_id bigint NOT NULL,
            remote_contact_id bigint NOT NULL,
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(tenant_id,member_id) REFERENCES clubora.members(tenant_id,id),
            FOREIGN KEY(tenant_id,parent_id) REFERENCES clubora.members(tenant_id,id),
            FOREIGN KEY(tenant_id,supplier_id) REFERENCES clubora.members(tenant_id,id),
            FOREIGN KEY(tenant_id,invoice_id) REFERENCES clubora.invoices(tenant_id,id))');
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
