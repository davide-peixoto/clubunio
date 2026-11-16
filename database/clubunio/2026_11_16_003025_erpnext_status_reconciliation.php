<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        DB::connection('clubunio')->statement('ALTER TABLE clubora.erpnext_invoice_transfers
            ADD COLUMN last_status_attempt_at timestamptz,
            ADD COLUMN status_sync_failed boolean NOT NULL DEFAULT false');
        DB::connection('clubunio')->statement("CREATE INDEX erpnext_invoice_transfers_status_due
            ON clubora.erpnext_invoice_transfers(last_status_attempt_at, invoice_id)
            WHERE state <> 'cancelled'");
    }

    public function down(): void
    {
        DB::connection('clubunio')->statement('DROP INDEX clubora.erpnext_invoice_transfers_status_due');
        DB::connection('clubunio')->statement('ALTER TABLE clubora.erpnext_invoice_transfers
            DROP COLUMN last_status_attempt_at,
            DROP COLUMN status_sync_failed');
    }
};
