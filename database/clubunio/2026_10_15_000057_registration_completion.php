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
        $db->statement('SET LOCAL search_path TO clubora');
        $db->unprepared(<<<'SQL'
ALTER TABLE registration_documents ADD original_filename text,
 ADD file_size bigint CHECK(file_size > 0), ADD mime_type varchar(100),
 ADD sha256 varchar(64), ADD created_by bigint,
 ADD FOREIGN KEY(tenant_id,created_by) REFERENCES tenant_accounts(tenant_id,account_id);
ALTER TABLE registrations ADD acceptance_summary jsonb;
CREATE INDEX registrations_review_queue ON registrations(tenant_id,status_id,submitted_at) WHERE active;
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
