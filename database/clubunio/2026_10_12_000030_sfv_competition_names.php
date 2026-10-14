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
        $db->statement('ALTER TABLE clubora.sfv_schedule ADD COLUMN host_name varchar(255), ADD COLUMN guest_name varchar(255)');
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
