<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        abort_unless(\App\ClubUnio\Services\StageMigrationTarget::allows($db), 409);
        $db->statement("ALTER TABLE clubora.tenants ADD COLUMN facility_planning_mode varchar(12) NOT NULL DEFAULT 'legacy'
            CHECK (facility_planning_mode IN ('legacy', 'neutral'))");
        $db->statement("ALTER TABLE clubora.training_options ADD COLUMN facility_kind varchar(12)
            CHECK (facility_kind IS NULL OR (category = 'field' AND facility_kind IN ('field', 'hall', 'court', 'other')))");
    }

    public function down(): void
    {
        throw new LogicException('Retain facility types and planning modes; use a reviewed restore.');
    }
};
