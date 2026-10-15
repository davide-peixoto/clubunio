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
        foreach (['member_functions', 'member_keys', 'sponsorships', 'event_guests', 'club_events', 'club_functions', 'qualifications'] as $table) {
            $db->statement('ALTER TABLE clubora.'.$table.' ADD revision integer NOT NULL DEFAULT 1 CHECK(revision > 0)');
        }
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};
