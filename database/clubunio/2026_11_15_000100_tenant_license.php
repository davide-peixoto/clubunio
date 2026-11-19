<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        DB::connection('clubunio')->statement(
            "ALTER TABLE clubunio.tenants ADD COLUMN license_plan varchar(20)
             CHECK (license_plan IN ('start', 'verein', 'club', 'large', 'custom'))"
        );
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
