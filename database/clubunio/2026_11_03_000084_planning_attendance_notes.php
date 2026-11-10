<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';
    public function up(): void {
        $db = DB::connection('clubunio');
        if ($db->getDriverName() !== 'pgsql' || !\App\ClubUnio\Services\StageMigrationTarget::allows($db)) {
            throw new LogicException('Reviewed ClubUnio PostgreSQL required');
        }
        foreach (['team_participations', 'team_guests'] as $table) {
            $db->statement('ALTER TABLE clubora.'.$table.' ADD COLUMN attendance_comment_encrypted text, ADD COLUMN attendance_updated_at timestamptz');
        }
    }
    public function down(): void { throw new LogicException('Retain private attendance history; reviewed restore required'); }
};
