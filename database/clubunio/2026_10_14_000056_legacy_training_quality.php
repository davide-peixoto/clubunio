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
        $db->unprepared('ALTER TABLE clubora.legacy_training_plans ADD COLUMN requires_quality_review boolean NOT NULL DEFAULT false;
            ALTER TABLE clubora.training_plans ADD COLUMN legacy_quality_pending boolean NOT NULL DEFAULT false;');
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
