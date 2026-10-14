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
ALTER TABLE clubora.bexio_dispatch_trials
 ADD COLUMN attachment_count integer CHECK(attachment_count >= 0),
 ADD COLUMN attachment_bytes bigint CHECK(attachment_bytes >= 0);
SQL);
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
